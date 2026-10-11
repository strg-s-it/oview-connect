<?php

declare(strict_types=1);

/**
 * connect/bin/purge_privacy_data.php
 *
 * Setzt die Aufbewahrungsfristen um (DSGVO-Datenminimierung und Begrenzung des
 * Datenwachstums), siehe Connect\Maintenance\Retention -- dort stehen alle
 * Regeln, Standardfristen und die .env-Schluessel (CONNECT_RETENTION_*).
 *
 * Seit 2026-10-04 laeuft dieselbe Bereinigung einmal taeglich automatisch aus
 * bin/scheduler.php mit. Dieses Skript bleibt fuer Hand-Aufrufe:
 *   php connect/bin/purge_privacy_data.php            sofort bereinigen
 *   php connect/bin/purge_privacy_data.php --dry-run  nur zaehlen, nichts aendern
 * Eine bestehende taegliche Plesk-Aufgabe schadet nicht (laeuft dann einfach
 * ein zweites Mal ohne Wirkung).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Nur per CLI ausfuehrbar.');
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Connect\Config;
use Connect\Database;
use Connect\Maintenance\Retention;

$config = Config::load();
$pdo = Database::connect($config)->pdo();
$retention = new Retention($pdo, $config);
$dryRun = in_array('--dry-run', $argv, true);

echo '[' . date('Y-m-d H:i:s') . '] Bereinigung gestartet' . ($dryRun ? ' -- DRY RUN (nur Zaehlung)' : '') . "\n";

$days = $retention->days();
if ($dryRun) {
    foreach ($retention->countDue() as $key => $count) {
        echo sprintf("  %-20s %4d Tage  %s faellig\n", $key, $days[$key], $count === null ? '(Tabelle fehlt)' : (string) $count);
    }
} else {
    $result = $retention->runAndRecord('manual', (string) gethostname(), 600);
    foreach ($result['counts'] as $key => $count) {
        echo sprintf("  %-20s %4d Tage  %d\n", $key, $days[$key], $count);
    }
    foreach ($result['errors'] as $key => $error) {
        echo "  FEHLER {$key}: {$error}\n";
    }
    echo "  Status: {$result['status']}\n";
}

echo '[' . date('Y-m-d H:i:s') . "] Fertig.\n";
