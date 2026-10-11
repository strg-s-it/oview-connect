<?php

declare(strict_types=1);

/**
 * connect/bin/install.php
 *
 * CLI-Installer fuer Connect. Aufruf: php connect/bin/install.php
 *
 * Macht bewusst nur EINE Sache pro Lauf nachvollziehbar: legt (falls noetig)
 * das Tracking fuer bereits eingespielte Migrationen an und fuehrt alle noch
 * ausstehenden .sql-Dateien aus connect/migrations/ der Reihe nach aus.
 * Gefahrlos mehrfach ausfuehrbar -- bereits angewandte Migrationen werden
 * uebersprungen.
 *
 * Saeen der eingebauten Connector-Type-Vorlagen (Shopify/HelloCash/MGM) ist
 * bewusst NICHT Teil dieses Skripts, sondern ein eigener, spaeterer Schritt.
 */

// Nur per CLI ausfuehrbar -- dieses Skript liegt bewusst ausserhalb von
// public/ und ist damit ueber HTTP ohnehin nicht erreichbar, dieser Check
// ist eine zusaetzliche Absicherung falls es doch einmal per Webserver
// aufgerufen wird (z.B. Fehlkonfiguration).
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Nur per CLI ausfuehrbar.');
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Connect\Config;
use Connect\Database;

/**
 * Gibt eine Zeile auf stdout aus. Eigene kleine Funktion statt direktem
 * echo, damit spaeter leicht ein Log-Level/Prefix ergaenzt werden kann.
 */
function installer_log(string $message): void
{
    echo $message . PHP_EOL;
}

installer_log('=== Connect Installer ===');

try {
    $config = Config::load();
    $db = Database::connect($config);
} catch (\Throwable $e) {
    installer_log('FEHLER beim Verbindungsaufbau: ' . $e->getMessage());
    exit(1);
}

$pdo = $db->pdo();

// -----------------------------------------------------------------------
// Schritt 1: Tracking-Tabelle fuer bereits angewandte Migrationen.
// Bewusst hier im Installer angelegt (nicht als nummerierte Migration),
// da sie die Voraussetzung dafuer ist, Migrationen ueberhaupt verfolgen
// zu koennen.
// -----------------------------------------------------------------------
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS connect_schema_migrations (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        migration VARCHAR(190) NOT NULL,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_schema_migrations_migration (migration)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

installer_log('Migrations-Tracking bereit.');

// -----------------------------------------------------------------------
// Schritt 2: bereits angewandte Migrationen ermitteln.
// -----------------------------------------------------------------------
$applied = [];
$stmt = $pdo->query('SELECT migration FROM connect_schema_migrations');
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $migration) {
    $applied[$migration] = true;
}

// -----------------------------------------------------------------------
// Schritt 3: alle .sql-Dateien aus migrations/ in Dateinamen-Reihenfolge
// durchgehen (daher die Nummerierung 0001_, 0002_, ...) und ausstehende
// Migrationen einspielen.
// -----------------------------------------------------------------------
$migrationsDir = dirname(__DIR__) . '/migrations';
$files = glob($migrationsDir . '/*.sql');
sort($files, SORT_STRING);

if ($files === false || count($files) === 0) {
    installer_log('Keine Migrationsdateien gefunden in ' . $migrationsDir);
    exit(0);
}

$appliedCount = 0;

foreach ($files as $file) {
    $migrationName = basename($file);

    if (isset($applied[$migrationName])) {
        installer_log("uebersprungen (bereits angewandt): {$migrationName}");
        continue;
    }

    installer_log("wende an: {$migrationName} ...");

    $sql = file_get_contents($file);
    if ($sql === false) {
        installer_log("FEHLER: Datei konnte nicht gelesen werden: {$file}");
        exit(1);
    }

    $statements = split_sql_statements($sql);

    // Hinweis: DDL-Anweisungen (CREATE TABLE etc.) committen sich in
    // MySQL/MariaDB implizit selbst -- eine Transaktion wuerde hier keine
    // echte Atomaritaet ueber mehrere Statements hinweg bieten. Deshalb
    // wird bei einem Fehler mitten in einer Migration sofort abgebrochen
    // und die Migration NICHT als angewandt vermerkt; bereits ausgefuehrte
    // Statements dieser Datei muessen dann manuell geprueft werden, bevor
    // der Installer erneut laeuft.
    foreach ($statements as $statement) {
        try {
            $pdo->exec($statement);
        } catch (\Throwable $e) {
            installer_log("FEHLER in {$migrationName}: " . $e->getMessage());
            installer_log('Abgebrochen. Bereits ausgefuehrte Statements dieser Datei bitte manuell pruefen.');
            exit(1);
        }
    }

    $insert = $pdo->prepare('INSERT INTO connect_schema_migrations (migration) VALUES (:migration)');
    $insert->execute(['migration' => $migrationName]);

    installer_log("angewandt: {$migrationName}");
    $appliedCount++;
}

installer_log("Fertig. {$appliedCount} neue Migration(en) angewandt.");

/**
 * Zerlegt eine .sql-Datei in einzelne, ausfuehrbare Statements.
 *
 * Bewusst einfach gehalten: entfernt reine Kommentarzeilen (beginnend mit
 * "--") und trennt danach am Semikolon. Das reicht fuer die Migrationen in
 * diesem Projekt (keine Semikolons innerhalb von String-Literalen oder
 * COMMENT-Klauseln) -- ist aber kein vollstaendiger SQL-Parser und sollte
 * bei kuenftigen Migrationen mit komplexerem Inhalt (z.B. Stored Procedures
 * mit DELIMITER-Wechsel) entsprechend erweitert werden.
 *
 * @return string[]
 */
function split_sql_statements(string $sql): array
{
    $lines = explode("\n", $sql);
    $withoutComments = array_filter($lines, static function (string $line): bool {
        return strpos(ltrim($line), '--') !== 0;
    });

    $cleaned = implode("\n", $withoutComments);

    $statements = array_map('trim', explode(';', $cleaned));

    return array_values(array_filter($statements, static function (string $statement): bool {
        return $statement !== '';
    }));
}
