<?php

declare(strict_types=1);

/**
 * connect/public/brand.php
 *
 * Liefert Logo und Favicon aus connect_brand_assets (Admin -> Betrieb -> Branding,
 * Connect\Setup\Branding). Oeffentlich ohne Anmeldung, weil Mails und der Admin-Login das
 * Logo ebenfalls zeigen. Nur lesend, nur die bekannten Schluessel.
 * ?v= wechselt mit jedem Upload, deshalb darf der Browser lange zwischenspeichern.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Connect\Config;
use Connect\Database;
use Connect\Setup\Branding;

$key = (string) ($_GET['a'] ?? '');
if (!isset(Branding::ASSETS[$key])) {
    http_response_code(404);
    exit;
}

try {
    $pdo = Database::connect(Config::load())->pdo();
    $stmt = $pdo->prepare('SELECT mime, data, hash FROM connect_brand_assets WHERE asset_key = :key');
    $stmt->execute(['key' => $key]);
    $row = $stmt->fetch();
} catch (\Throwable) {
    $row = false;
}
if ($row === false || !isset(Branding::ASSET_MIME[(string) $row['mime']])) {
    http_response_code(404);
    exit;
}

$etag = '"' . $row['hash'] . '"';
header('ETag: ' . $etag);
header('Cache-Control: public, max-age=' . (isset($_GET['v']) ? '31536000, immutable' : '3600'));
header('X-Content-Type-Options: nosniff');
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Type: ' . $row['mime']);
if ($row['mime'] === 'image/svg+xml') {
    // SVG kann Skripte enthalten: beim direkten Aufruf nichts ausfuehren
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
}
header('Content-Length: ' . strlen((string) $row['data']));
echo $row['data'];
