<?php

declare(strict_types=1);

/**
 * connect/public/internal/live_switch.php
 *
 * Wird von der alten Welt aufgerufen, nachdem sie den Routing-Schalter eines
 * Shops umgelegt hat (inc/connect_routing.php, oview_connect_set_live()) --
 * gleicht die Shopify-Webhook-Abos beider Welten zum aktuellen Schalterstand
 * ab (Connect\Routing\LiveSwitch). Der Schalterstand selbst wird aus der
 * Datenbank gelesen, nicht aus dem Aufruf uebernommen.
 *
 * Body (JSON): {"shop": "<shop ohne .myshopify.com>", "ts": <unix>}
 * Signatur: siehe Connect\Routing\InternalRequest.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Connect\Config;
use Connect\Database;
use Connect\Http\HttpClient;
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

try {
    $notes = (new LiveSwitch(
        $pdo,
        new Crypto($config->require('CONNECT_ENCRYPTION_KEY')),
        new HttpClient(),
        $config->require('CONNECT_APP_URL'),
        $config->optional('CONNECT_SHOPIFY_API_VERSION', '2026-07'),
        \Connect\Shopify\TokenRefresher::fromConfig($config)
    ))->syncWebhooks($shop);
} catch (\Throwable $e) {
    InternalRequest::respond(500, ['ok' => false, 'error' => 'Abgleich fehlgeschlagen: ' . $e->getMessage()]);
}

InternalRequest::respond(200, ['ok' => true, 'notes' => $notes]);
