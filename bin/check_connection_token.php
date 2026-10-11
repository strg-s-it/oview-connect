<?php

declare(strict_types=1);

/**
 * connect/bin/check_connection_token.php
 *
 * Diagnose fuer gespeicherte Bearer-Tokens (z.B. MGM "Invalid Token", E02):
 * entschluesselt die Zugangsdaten EINER Connection und prueft das Token auf
 * typische Copy/Paste-Fehler (Leerzeichen/Zeilenumbrueche, unsichtbare
 * Zeichen, doppeltes "Bearer "-Praefix, abgeschnittener JWT) und zeigt bei
 * einem JWT Header + Claims (Ablaufdatum etc.) an. Das Token selbst wird nie
 * vollstaendig ausgegeben, nur Anfang/Ende + SHA-256.
 *
 * Aufruf:
 *   php connect/bin/check_connection_token.php <connection_id>
 *
 * Optional Vergleich mit dem Original-Token (z.B. aus der Mail vom Anbieter),
 * ueber stdin, damit es nicht in der Shell-History landet:
 *   php connect/bin/check_connection_token.php <connection_id> --compare < token.txt
 *
 * Nur lesend, aendert nichts an der Datenbank.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Nur per CLI ausfuehrbar.');
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Connect\Config;
use Connect\Database;
use Connect\Security\Crypto;

$connectionId = (int) ($argv[1] ?? 0);
$compare = in_array('--compare', $argv, true);
if ($connectionId <= 0) {
    fwrite(STDERR, "Aufruf: php connect/bin/check_connection_token.php <connection_id> [--compare < token.txt]\n");
    exit(1);
}

$config = Config::load();
$pdo = Database::connect($config)->pdo();
$crypto = new Crypto($config->require('CONNECT_ENCRYPTION_KEY'));

$stmt = $pdo->prepare(
    'SELECT c.id, c.shop, c.label, c.status, c.credentials, c.updated_at, t.name AS type_name, t.auth_type
     FROM connect_connections c
     JOIN connect_connector_types t ON t.id = c.connector_type_id
     WHERE c.id = :id'
);
$stmt->execute(['id' => $connectionId]);
$connection = $stmt->fetch();
if ($connection === false) {
    fwrite(STDERR, "Connection #{$connectionId} nicht gefunden.\n");
    exit(1);
}

$credentials = $crypto->decryptArray((string) $connection['credentials']);

echo "Connection #{$connection['id']} \"{$connection['label']}\" ({$connection['type_name']}), Shop {$connection['shop']}\n";
echo "Auth-Typ: {$connection['auth_type']}, Status: {$connection['status']}, zuletzt geaendert: {$connection['updated_at']}\n";
echo 'Gespeicherte Schluessel: ' . implode(', ', array_keys($credentials)) . "\n";
echo 'Basis-URL: ' . var_export($credentials['base_url'] ?? null, true) . "\n\n";

$token = $credentials['token'] ?? null;
if (!is_string($token)) {
    echo "FEHLER: kein Feld \"token\" gespeichert -- GenericHttpConnector schickt dann \"Authorization: Bearer \" (leer).\n";
    exit(1);
}

$problems = [];
$length = strlen($token);
echo "Token-Laenge: {$length} Bytes (" . mb_strlen($token, 'UTF-8') . " Zeichen)\n";
echo 'Anfang/Ende: ' . substr($token, 0, 8) . ' ... ' . substr($token, -6) . "\n";
echo 'SHA-256: ' . hash('sha256', $token) . "\n";

if ($token !== trim($token)) {
    $problems[] = 'Leerzeichen/Zeilenumbruch am Anfang oder Ende (wird 1:1 im Header mitgeschickt).';
}
if (preg_match('/\s/', trim($token))) {
    $problems[] = 'Leerzeichen/Zeilenumbruch MITTEN im Token (z.B. aus einer umgebrochenen Mail kopiert).';
}
if (stripos(ltrim($token), 'bearer ') === 0) {
    $problems[] = 'Token beginnt mit "Bearer " -- gesendet wird dann "Bearer Bearer ...".';
}
if (preg_match('/[^\x21-\x7E\s]/', $token, $m)) {
    $problems[] = 'Nicht-ASCII-/unsichtbares Zeichen enthalten (z.B. weiches Trennzeichen, Zero-Width-Space aus Outlook/Word): 0x' . bin2hex($m[0]);
}

$parts = explode('.', trim($token));
if (count($parts) === 3) {
    $decodePart = static function (string $part): ?array {
        $json = base64_decode(strtr($part, '-_', '+/') . str_repeat('=', (4 - strlen($part) % 4) % 4), true);
        $data = $json === false ? null : json_decode($json, true);
        return is_array($data) ? $data : null;
    };
    $header = $decodePart($parts[0]);
    $payload = $decodePart($parts[1]);

    echo "\nJWT erkannt (3 Teile, Signatur " . strlen($parts[2]) . " Zeichen)\n";
    if ($header === null || $payload === null) {
        $problems[] = 'JWT-Header oder -Payload nicht dekodierbar -- Token vermutlich beschaedigt/abgeschnitten.';
    } else {
        echo 'Header: ' . json_encode($header, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        echo "Claims:\n";
        foreach ($payload as $claim => $value) {
            $display = is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (in_array($claim, ['exp', 'iat', 'nbf'], true) && is_numeric($value)) {
                $display .= ' (' . date('Y-m-d H:i:s', (int) $value) . ')';
            }
            echo "  {$claim}: {$display}\n";
        }
        if (isset($payload['exp']) && is_numeric($payload['exp']) && (int) $payload['exp'] < time()) {
            $problems[] = 'Token ist ABGELAUFEN (exp ' . date('Y-m-d H:i:s', (int) $payload['exp']) . ').';
        }
        if (isset($payload['nbf']) && is_numeric($payload['nbf']) && (int) $payload['nbf'] > time()) {
            $problems[] = 'Token ist noch nicht gueltig (nbf in der Zukunft) -- evtl. Serverzeit pruefen.';
        }
    }
} else {
    echo "\nKein JWT-Format (" . count($parts) . " Teile statt 3)";
    echo $connection['type_name'] === 'MGM/AGILA ERP' ? " -- MGM erwartet laut Connector Type ein JWT, Token also vermutlich unvollstaendig.\n" : ".\n";
}

if ($compare) {
    $original = stream_get_contents(STDIN);
    $original = $original === false ? '' : trim($original);
    echo "\nVergleich mit Original (stdin, getrimmt): Laenge " . strlen($original) . ', SHA-256 ' . hash('sha256', $original) . "\n";
    if ($original === $token) {
        echo "=> IDENTISCH mit dem gespeicherten Token.\n";
    } elseif ($original === trim($token)) {
        echo "=> Nur Leerzeichen/Zeilenumbrueche am Rand unterscheiden sich.\n";
    } else {
        $common = 0;
        $max = min(strlen($original), $length);
        while ($common < $max && $original[$common] === $token[$common]) {
            $common++;
        }
        echo "=> UNTERSCHIEDLICH, erste Abweichung an Position {$common}.\n";
    }
}

echo "\nGesendeter Header: \"Authorization: Bearer <Token>\" (" . strlen('Bearer ' . $token) . " Bytes Wert)\n";
echo $problems === [] ? "\nKeine Auffaelligkeiten am gespeicherten Token gefunden.\n" : "\nAUFFAELLIGKEITEN:\n  - " . implode("\n  - ", $problems) . "\n";
