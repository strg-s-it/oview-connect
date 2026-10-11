<?php

declare(strict_types=1);

/**
 * connect/bin/create_admin.php
 *
 * Legt einen Admin-Nutzer an oder aendert dessen Passwort, falls die
 * E-Mail schon existiert. Es gibt bewusst keine Selbstregistrierung ueber
 * die Weboberflaeche -- neue Admin-Zugaenge entstehen ausschliesslich per
 * CLI (auf dem Server, mit Zugriff auf die .env-Zugangsdaten).
 *
 * Aufruf: php connect/bin/create_admin.php admin@example.com "EinStarkesPasswort"
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Nur per CLI ausfuehrbar.');
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Connect\Config;
use Connect\Database;

$email = $argv[1] ?? null;
$password = $argv[2] ?? null;

if ($email === null || $password === null) {
    fwrite(STDERR, "Verwendung: php bin/create_admin.php <email> <passwort>\n");
    exit(1);
}

if (strlen($password) < 12) {
    fwrite(STDERR, "Passwort zu kurz -- mindestens 12 Zeichen.\n");
    exit(1);
}

$config = Config::load();
$db = Database::connect($config);
$pdo = $db->pdo();

$hash = password_hash($password, PASSWORD_DEFAULT);

// Zwei getrennte Platzhalter statt zweimal ":hash" -- mit
// PDO::ATTR_EMULATE_PREPARES=false (echte Prepared Statements) darf
// derselbe benannte Platzhalter nicht mehrfach in einer Query vorkommen.
$stmt = $pdo->prepare(
    'INSERT INTO connect_admin_users (email, password_hash) VALUES (:email, :hash1)
     ON DUPLICATE KEY UPDATE password_hash = :hash2'
);
$stmt->execute(['email' => $email, 'hash1' => $hash, 'hash2' => $hash]);

echo "Admin-Nutzer \"{$email}\" angelegt/aktualisiert.\n";
