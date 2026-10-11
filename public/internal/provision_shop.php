<?php

declare(strict_types=1);

/**
 * connect/public/internal/provision_shop.php
 *
 * Neuinstallation direkt in Connect (Schalter "Neuinstallationen" unter Admin -> Shops,
 * connect_settings.new_installs_to_connect). Aufgerufen von der alten Welt (index.php,
 * inc/connect_routing.php oview_connect_provision_new_install()), wenn ein Shop die App
 * oeffnet, der dort noch keine Einstellungen hat und in Connect unbekannt ist.
 *
 * Legt den Shop mit dem Token der alten Welt an (Connect\Legacy\ShopProvisioner), stellt
 * ihn auf live und gleicht die Shopify-Webhooks ab (Connect\Routing\LiveSwitch). Danach
 * leitet die alte Welt auf Connect weiter, dort kommt das erste Fenster (app/welcome.php).
 *
 * Body (JSON): {"shop": "<shop>", "ts": <unix>}, Signatur: Connect\Routing\InternalRequest.
 * Antwort: {"ok": true, "provisioned": bool, "reason"?: string}
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Connect\Config;
use Connect\Database;
use Connect\Http\HttpClient;
use Connect\Legacy\ShopProvisioner;
use Connect\Routing\InternalRequest;
use Connect\Routing\LiveSwitch;
use Connect\Security\Crypto;
use Connect\Setup\Settings;

$config = Config::load();
$data = InternalRequest::verifyOrDie($config->require('CONNECT_SHOPIFY_API_SECRET'));

$shop = strtolower(trim((string) ($data['shop'] ?? '')));
$shop = preg_replace('/\.myshopify\.com$/', '', $shop) ?? '';
if ($shop === '' || !preg_match('/^[a-z0-9][a-z0-9\-]*$/', $shop)) {
    InternalRequest::respond(400, ['ok' => false, 'error' => 'Ungültiger Shop.']);
}

$pdo = Database::connect($config)->pdo();

// Schalter hier nochmal pruefen -- die alte Welt darf das nicht allein entscheiden
if (Settings::get($pdo, Settings::NEW_INSTALLS_TO_CONNECT) !== '1') {
    InternalRequest::respond(200, ['ok' => true, 'provisioned' => false, 'reason' => 'Neuinstallationen sind nicht auf Connect geschaltet.']);
}

$known = $pdo->prepare('SELECT 1 FROM connect_shops WHERE shop = :shop');
$known->execute(['shop' => $shop]);
if ($known->fetchColumn() !== false) {
    InternalRequest::respond(200, ['ok' => true, 'provisioned' => false, 'reason' => 'Shop ist in Connect schon bekannt.']);
}

try {
    $result = (new ShopProvisioner($pdo, $config))->provisionFromLegacy($shop);

    $status = $pdo->prepare('SELECT status FROM connect_connections WHERE id = :id');
    $status->execute(['id' => $result['connection_id']]);
    if ($status->fetchColumn() !== 'active') {
        // Ohne funktionierende Shopify-Verbindung nicht umschalten -- Shop bleibt in der alten Welt
        InternalRequest::respond(200, ['ok' => true, 'provisioned' => false, 'reason' => 'Shopify-Verbindung in Connect nicht erfolgreich getestet.']);
    }

    $pdo->prepare('UPDATE connect_shops SET live_in_connect = 1, live_changed_at = CURRENT_TIMESTAMP WHERE shop = :shop')
        ->execute(['shop' => $result['shop']]);

    $notes = [];
    try {
        $notes = (new LiveSwitch(
            $pdo,
            new Crypto($config->require('CONNECT_ENCRYPTION_KEY')),
            new HttpClient(),
            $config->require('CONNECT_APP_URL'),
            $config->optional('CONNECT_SHOPIFY_API_VERSION', '2026-07')
        ))->syncWebhooks($result['shop']);
    } catch (\Throwable $e) {
        $notes[] = 'Webhook-Abgleich fehlgeschlagen: ' . $e->getMessage();
    }
} catch (\Throwable $e) {
    InternalRequest::respond(500, ['ok' => false, 'error' => 'Anlegen fehlgeschlagen: ' . $e->getMessage()]);
}

InternalRequest::respond(200, ['ok' => true, 'provisioned' => true, 'notes' => $notes]);
