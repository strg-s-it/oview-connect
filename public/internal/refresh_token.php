<?php

declare(strict_types=1);

/**
 * connect/public/internal/refresh_token.php
 *
 * Wird von der alten Welt am Ende von generate_token.php aufgerufen, wenn ein Shop die App
 * (neu) installiert hat und in Connect schon bekannt ist (inc/connect_routing.php,
 * oview_connect_refresh_token_after_install()). Hintergrund: bei einer Deinstallation wird der
 * Shopify-Token ungueltig, Connect haelt aber noch den alten -- ohne diesen Aufruf bliebe die
 * Shopify-Verbindung auf "HTTP 401", bis jemand "Token uebernehmen" klickt.
 *
 * Uebernimmt den frischen Token aus der alten Welt (Connect\Legacy\ShopProvisioner, idempotent,
 * testet die Verbindung) und gleicht bei einem Shop, der in Connect laeuft, die Webhook-Abos
 * neu ab (Shopify loescht sie bei der Deinstallation). Der Schalter live_in_connect bleibt
 * unveraendert.
 *
 * Body (JSON): {"shop": "<shop>", "ts": <unix>}, Signatur: Connect\Routing\InternalRequest.
 * Antwort: {"ok": true, "refreshed": bool, "reason"?: string, "status"?: string, "notes"?: array}
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Connect\Config;
use Connect\Database;
use Connect\Http\HttpClient;
use Connect\Legacy\ShopProvisioner;
use Connect\Routing\InternalRequest;
use Connect\Routing\LiveSwitch;
use Connect\Security\Crypto;

$config = Config::load();
$data = InternalRequest::verifyOrDie($config->require('CONNECT_SHOPIFY_API_SECRET'));

$shop = strtolower(trim((string) ($data['shop'] ?? '')));
$shop = preg_replace('/\.myshopify\.com$/', '', $shop) ?? '';
if ($shop === '' || !preg_match('/^[a-z0-9][a-z0-9\-]*$/', $shop)) {
    InternalRequest::respond(400, ['ok' => false, 'error' => 'Ungültiger Shop.']);
}

$pdo = Database::connect($config)->pdo();

// Nur fuer Shops, die Connect schon kennt -- alles andere ist eine Neuinstallation (provision_shop.php)
$known = $pdo->prepare('SELECT live_in_connect FROM connect_shops WHERE shop = :shop');
$known->execute(['shop' => $shop]);
$row = $known->fetch();
if ($row === false) {
    InternalRequest::respond(200, ['ok' => true, 'refreshed' => false, 'reason' => 'Shop ist in Connect nicht bekannt.']);
}

try {
    $result = (new ShopProvisioner($pdo, $config))->provisionFromLegacy($shop);

    $status = $pdo->prepare('SELECT status FROM connect_connections WHERE id = :id');
    $status->execute(['id' => $result['connection_id']]);
    $connectionStatus = (string) $status->fetchColumn();

    $notes = [];
    if ((int) $row['live_in_connect'] === 1 && $connectionStatus === 'active') {
        try {
            $notes = (new LiveSwitch(
                $pdo,
                new Crypto($config->require('CONNECT_ENCRYPTION_KEY')),
                new HttpClient(),
                $config->require('CONNECT_APP_URL'),
                $config->optional('CONNECT_SHOPIFY_API_VERSION', '2026-07'),
                \Connect\Shopify\TokenRefresher::fromConfig($config)
            ))->syncWebhooks($result['shop']);
        } catch (\Throwable $e) {
            $notes[] = 'Webhook-Abgleich fehlgeschlagen: ' . $e->getMessage();
        }
    }
} catch (\Throwable $e) {
    InternalRequest::respond(500, ['ok' => false, 'error' => 'Token übernehmen fehlgeschlagen: ' . $e->getMessage()]);
}

InternalRequest::respond(200, ['ok' => true, 'refreshed' => true, 'status' => $connectionStatus, 'notes' => $notes]);
