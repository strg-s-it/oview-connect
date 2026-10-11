<?php

declare(strict_types=1);

use Connect\Config;
use Connect\Connector\ConnectorFactory;
use Connect\Http\HttpClient;
use Connect\Security\Crypto;
use Connect\Shopify\TokenRefresher;
use Connect\Sync\ObjectCache;

/** @var \Connect\Shopify\WebhookContext $ctx */
$ctx = require dirname(__DIR__, 2) . '/webhook_bootstrap.php';

$defaultTopic = 'products/update';
require __DIR__ . '/_store_event.php';

/** @var int $eventId von _store_event.php gesetzt */

// Anders als order_paid/order_created wird hier tatsaechlich verarbeitet:
// die betroffenen Varianten werden gezielt neu geladen und im Cache
// aktualisiert, statt nur roh gespeichert zu werden -- damit der Produkt-
// Cache aktuell bleibt, ohne auf den naechsten stuendlichen Job zu warten.
//
// Seit Migration 0030 ist das Shopify-Produkt-Object auf Varianten-Ebene
// modelliert (1 Zeile = 1 Variante, ueber die Wurzelabfrage "productVariants"
// statt "products") -- ein einzelnes Produkt kann mehrere Varianten haben,
// die alle bei einem products/update-Webhook aktualisiert werden muessen.
// Deshalb ObjectCache::refresh() (nicht mehr refreshOne(), das genau EINE
// Zeile erwartet) mit dem read_list-Filterparameter "productQuery",
// eingeschraenkt auf dieses eine Produkt -- UND markOrphaned=false, sonst
// wuerde dieser auf ein Produkt eingeschraenkte Durchlauf faelschlich den
// gesamten uebrigen Katalog-Cache als verwaist markieren (siehe
// ObjectCache::refresh()-Kommentar).
if ($ctx->connectionId !== null) {
    try {
        $payload = json_decode($ctx->rawBody, true);
        $productGid = is_array($payload) ? (string) ($payload['admin_graphql_api_id'] ?? '') : '';

        if ($productGid === '') {
            throw new \RuntimeException('Kein admin_graphql_api_id im Webhook-Payload gefunden.');
        }

        $numericProductId = preg_replace('~\D~', '', $productGid);
        if ($numericProductId === '' || $numericProductId === null) {
            throw new \RuntimeException("Konnte keine numerische Produkt-ID aus \"{$productGid}\" extrahieren.");
        }

        $config = Config::load();
        $crypto = new Crypto($config->require('CONNECT_ENCRYPTION_KEY'));
        $tokenRefresher = new TokenRefresher(
            new HttpClient(),
            $config->optional('CONNECT_SHOPIFY_API_KEY'),
            $config->optional('CONNECT_SHOPIFY_API_SECRET')
        );
        $factory = new ConnectorFactory($ctx->pdo, $crypto, new HttpClient(), $tokenRefresher);
        $connector = $factory->forConnection($ctx->connectionId);

        $objectIdStmt = $ctx->pdo->prepare(
            'SELECT o.id FROM connect_objects o
             JOIN connect_connections c ON c.connector_type_id = o.connector_type_id
             WHERE c.id = :connection_id AND o.object_key = :key'
        );
        $objectIdStmt->execute(['connection_id' => $ctx->connectionId, 'key' => 'product']);
        $productObjectId = $objectIdStmt->fetchColumn();

        if ($productObjectId === false) {
            throw new \RuntimeException('Produkt-Object fuer diese Connection nicht gefunden.');
        }

        $productObject = $factory->objectDefinitionById((int) $productObjectId);

        $cache = new ObjectCache($ctx->pdo);
        $cache->refresh($connector, $ctx->connectionId, $productObject, ['productQuery' => 'product_id:' . $numericProductId], null, false);

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
// Fehler ist in connect_webhook_events nachvollziehbar.
http_response_code(200);
echo 'ok';
