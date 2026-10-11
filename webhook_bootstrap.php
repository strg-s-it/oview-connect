<?php

declare(strict_types=1);

/**
 * connect/webhook_bootstrap.php
 *
 * Einstiegspunkt fuer alle Webhook-Empfaenger unter connect/public/webhooks/.
 * Drittes Vertrauensmodell neben bootstrap.php (Merchant) und
 * admin_bootstrap.php (Admin-Login): hier kommt das Vertrauen aus der
 * HMAC-Signatur von Shopify selbst, nicht aus einer Session.
 *
 * Loest den Shop ueber den X-Shopify-Shop-Domain-Header auf (Shopify sendet
 * den bei jedem Webhook-Aufruf mit) und sucht dazu die passende
 * Shopify-Connection -- keine Connection-ID muss in der Webhook-Adresse
 * mitgegeben werden (siehe WebhookRegistrar).
 *
 * Verwendung in einem Webhook-Empfaenger:
 *   $ctx = require dirname(__DIR__, 2) . '/webhook_bootstrap.php';
 */

require __DIR__ . '/vendor/autoload.php';

// Webhooks kommen entweder direkt von Shopify oder weitergereicht aus der alten
// Welt (Routing, siehe inc/connect_routing.php dort). Legt der Aufrufer vor dem
// Ende auf (Shopify und die Weiterleitung warten nur wenige Sekunden), soll die
// Verarbeitung trotzdem fertig laufen -- sonst ginge ein Event halb verarbeitet
// verloren. Die Empfaenger antworten erst am Ende.
ignore_user_abort(true);

use Connect\Config;
use Connect\Database;
use Connect\Shopify\WebhookContext;
use Connect\Shopify\WebhookVerifier;

$config = Config::load();
$db = Database::connect($config);
$pdo = $db->pdo();

$rawBody = file_get_contents('php://input') ?: '';
$hmacHeader = $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? '';
$topicHeader = $_SERVER['HTTP_X_SHOPIFY_TOPIC'] ?? '';
$shopHeader = $_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN'] ?? '';

$verifier = new WebhookVerifier($config->require('CONNECT_SHOPIFY_API_SECRET'));
$verifier->verifyOrDie($rawBody, $hmacHeader);

$dotPosition = strpos($shopHeader, '.');
$shop = $dotPosition !== false ? substr($shopHeader, 0, $dotPosition) : $shopHeader;

$connectionStmt = $pdo->prepare(
    "SELECT c.id FROM connect_connections c
     JOIN connect_connector_types t ON t.id = c.connector_type_id
     WHERE t.type_key = 'shopify' AND c.shop = :shop
     ORDER BY (c.status = 'active') DESC, c.id DESC
     LIMIT 1"
);
$connectionStmt->execute(['shop' => $shop]);
$connectionId = $connectionStmt->fetchColumn();

return new WebhookContext(
    $pdo,
    $shop,
    $connectionId !== false ? (int) $connectionId : null,
    $rawBody,
    $topicHeader
);
