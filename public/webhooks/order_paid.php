<?php

declare(strict_types=1);

use Connect\Config;
use Connect\Connector\ConnectorFactory;
use Connect\Http\HttpClient;
use Connect\Security\Crypto;
use Connect\Shopify\TokenRefresher;
use Connect\Sync\SyncRunner;

/** @var \Connect\Shopify\WebhookContext $ctx */
$ctx = require dirname(__DIR__, 2) . '/webhook_bootstrap.php';

$defaultTopic = 'orders/paid';
require __DIR__ . '/_store_event.php';

/** @var int $eventId von _store_event.php gesetzt */

// Sucht aktive, webhook-getriggerte Mapping-Regeln fuer diese Connection +
// dieses Topic (siehe admin/mappings.php) und uebersetzt die Bestellung
// sofort -- analog zu product_update.php's Einzel-Update, nur dass hier
// tatsaechlich ins Zielsystem geschrieben wird (Connect\Sync\SyncRunner::
// runTranslationForRecord()), nicht nur der eigene Cache aktualisiert wird.
// Gibt es keine passende Mapping-Regel (der ueblichste Fall, solange kein
// Admin eine angelegt hat), passiert bewusst nichts weiter ausser dem
// Roh-Speichern von oben -- kein Fehler.
//
// Laeuft der Shop in der alten Welt (connect_shops.live_in_connect = 0), ist
// SIE fuer Bestellungen zustaendig -- Connect verarbeitet dann nichts, auch
// wenn noch ein eigenes Abo existiert (z.B. nach dem Zurueckschalten, bevor
// Connect\Routing\LiveSwitch es entfernt hat). Sonst entstuenden Rechnungen in
// beiden Welten; die Dedup-Sperre (SyncRunner) wirkt nur innerhalb von Connect.
$liveStmt = $ctx->pdo->prepare('SELECT live_in_connect FROM connect_shops WHERE shop = :shop');
$liveStmt->execute(['shop' => $ctx->shop]);
if ((int) $liveStmt->fetchColumn() !== 1) {
    $ctx->pdo->prepare(
        "UPDATE connect_webhook_events SET status = 'ignored', error_message = :msg, processed_at = CURRENT_TIMESTAMP WHERE id = :id"
    )->execute(['msg' => 'Shop läuft in der alten Welt (live_in_connect = 0) -- nicht verarbeitet.', 'id' => $eventId]);
    http_response_code(200);
    echo 'ok (not live in connect)';
    exit;
}

if ($ctx->connectionId !== null) {
    try {
        $payload = json_decode($ctx->rawBody, true);
        $orderGid = is_array($payload) ? (string) ($payload['admin_graphql_api_id'] ?? '') : '';

        if ($orderGid === '') {
            throw new \RuntimeException('Kein admin_graphql_api_id im Webhook-Payload gefunden.');
        }

        $mappingsStmt = $ctx->pdo->prepare(
            "SELECT * FROM connect_sync_mappings
             WHERE source_connection_id = :connection_id AND trigger_type = 'webhook' AND trigger_topic = 'orders/paid'
               AND enabled = 1 AND target_connection_id IS NOT NULL"
        );
        $mappingsStmt->execute(['connection_id' => $ctx->connectionId]);
        $mappings = $mappingsStmt->fetchAll();

        if (count($mappings) > 0) {
            $config = Config::load();
            $crypto = new Crypto($config->require('CONNECT_ENCRYPTION_KEY'));
            $tokenRefresher = TokenRefresher::fromConfig($config);
            $factory = new ConnectorFactory($ctx->pdo, $crypto, new HttpClient(), $tokenRefresher);
            $connector = $factory->forConnection($ctx->connectionId);

            // Volle Bestellung inkl. Zeilenpositionen gezielt nachladen --
            // der Webhook-Payload selbst reicht dafuer nicht (andere
            // Feldform, siehe Migration 0018_shopify_order_read_one.sql).
            $orderRow = $connector->readOne('order', ['id' => $orderGid]);

            if ($orderRow === null) {
                throw new \RuntimeException("Bestellung {$orderGid} konnte nicht geladen werden (readOne lieferte null).");
            }

            $runner = new SyncRunner($ctx->pdo, $factory);
            foreach ($mappings as $mapping) {
                $runner->runTranslationForRecord($mapping, $orderRow);
            }
        }

        $updateStmt = $ctx->pdo->prepare(
            "UPDATE connect_webhook_events SET status = 'processed', processed_at = CURRENT_TIMESTAMP WHERE id = :id"
        );
        $updateStmt->execute(['id' => $eventId]);
    } catch (\Throwable $e) {
        $updateStmt = $ctx->pdo->prepare(
            "UPDATE connect_webhook_events SET status = 'error', error_message = :error, processed_at = CURRENT_TIMESTAMP WHERE id = :id"
        );
        $updateStmt->execute(['error' => $e->getMessage(), 'id' => $eventId]);
    }
}

// Immer HTTP 200 an Shopify zurueckgeben, auch bei einem Verarbeitungsfehler
// -- sonst deaktiviert Shopify den Webhook nach wiederholten Fehlern. Der
// Fehler ist in connect_webhook_events und (bei einer angelegten Mapping-
// Regel) in connect_sync_runs nachvollziehbar.
http_response_code(200);
echo 'ok';
