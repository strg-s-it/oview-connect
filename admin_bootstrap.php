<?php

declare(strict_types=1);

/**
 * connect/admin_bootstrap.php
 *
 * Einstiegspunkt fuer Seiten unter connect/public/admin/. Im Unterschied zu
 * bootstrap.php (Merchant-Seiten, Vertrauen kommt/soll kuenftig aus der
 * Shopify-Signatur kommen) wird hier zuerst ein echtes Login geprueft
 * (Connect\Auth\AdminAuth, eigene Tabelle connect_admin_users) -- der
 * Shop-Parameter wird danach vertraut, weil der ADMIN authentifiziert ist,
 * nicht weil Shopify die Anfrage signiert hat. Zwei unterschiedliche
 * Vertrauensmodelle, deshalb zwei getrennte Bootstrap-Dateien statt einer
 * verzweigenden.
 *
 * Verwendung in einer admin/-Seite:
 *   $app = require dirname(__DIR__, 2) . '/admin_bootstrap.php';
 *
 * Ist noch kein Shop gewaehlt (kein ?shop= in der URL), wird zur
 * Shop-Auswahl (shops.php) umgeleitet statt mit einem Fehler abzubrechen --
 * anders als bei bootstrap.php, wo ein fehlender Shop ein harter Fehler ist.
 */

require __DIR__ . '/vendor/autoload.php';

use Connect\AppContext;
use Connect\Auth\AdminAuth;
use Connect\Config;
use Connect\Database;
use Connect\Security\Crypto;

$config = Config::load();
$db = Database::connect($config);
$pdo = $db->pdo();

// Vertrauen kommt hier ausschliesslich aus dem Login -- es gibt bewusst keine
// Shopify-Signatur (hmac/sig) im Admin-Pfad. Security-Header und CSRF-Pruefung
// stecken in AdminAuth (Konstruktor bzw. requireLogin()), weil nicht alle
// Admin-Seiten diese Bootstrap-Datei nutzen.
$adminAuth = new AdminAuth($pdo);
$adminAuth->requireLogin(); // leitet zu login.php um und beendet, falls nicht eingeloggt

$crypto = new Crypto($config->require('CONNECT_ENCRYPTION_KEY'));

$shop = $_GET['shop'] ?? null;
if (!is_string($shop) || $shop === '') {
    header('Location: shops.php');
    exit;
}

return new AppContext($pdo, $crypto, $shop, $config);
