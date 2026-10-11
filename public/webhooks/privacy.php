<?php

declare(strict_types=1);

use Connect\Privacy\PrivacyService;

/**
 * connect/public/webhooks/privacy.php
 *
 * Empfaenger fuer die Shopify-Pflicht-Webhooks (DSGVO) und den Lebenszyklus-
 * Webhook app/uninstalled:
 *
 *   customers/data_request  -- Datenauskunft: gefundene Daten werden im Protokoll
 *                              (connect_privacy_requests.result) abgelegt und im
 *                              Admin unter "Datenschutz" angezeigt
 *   customers/redact        -- Kundendaten loeschen
 *   shop/redact             -- alle Daten des Shops loeschen (48 h nach Deinstallation)
 *   app/uninstalled         -- Automatisierung stoppen, Daten bleiben bis shop/redact
 *
 * Diese URLs sind in der Shopify-App-Konfiguration fest auf die alte Welt
 * eingetragen (webhooks/cust_data_*.php, shop_data_erasure.php). Die alte Welt
 * reicht die bereits signaturgeprueften Requests unveraendert hierher weiter
 * (siehe inc/connect_routing.php dort) -- die Signatur prueft webhook_bootstrap.php
 * hier selbst noch einmal.
 *
 * Anders als die Betriebs-Webhooks antwortet dieser Empfaenger bei einem Fehler mit
 * HTTP 500: Shopify wiederholt dann. Alle Aktionen sind idempotent.
 */

/** @var \Connect\Shopify\WebhookContext $ctx */
$ctx = require dirname(__DIR__, 2) . '/webhook_bootstrap.php';

$topic = $ctx->topic;
$shop = $ctx->shop;

$payload = json_decode($ctx->rawBody, true);
$payload = is_array($payload) ? $payload : [];

// Shop aus dem signierten Body muss zum (ungesigneten) Header passen -- sonst koennte ein
// gueltig signierter Body mit fremdem Shop-Header Daten eines anderen Shops loeschen.
// Fehlt das Feld im Body, entscheidet allein die Signaturpruefung.
$bodyShopDomain = \Connect\Shopify\WebhookVerifier::bodyShopDomain($ctx->rawBody);
if ($bodyShopDomain !== '' && strstr($bodyShopDomain . '.', '.', true) !== strtolower($shop)) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

$knownTopics = ['customers/data_request', 'customers/redact', 'shop/redact', 'app/uninstalled'];
if (!in_array($topic, $knownTopics, true)) {
    // Kein Fehler: ein unbekanntes Topic wird nur nicht bearbeitet.
    http_response_code(200);
    echo 'ignored';
    exit;
}

$customerId = null;
if (isset($payload['customer']['id']) && ctype_digit((string) $payload['customer']['id'])) {
    $customerId = (string) $payload['customer']['id'];
}

// Protokoll-Eintrag anlegen. Scheitert das (z.B. fehlt die Tabelle aus Migration 0051),
// bricht der Empfaenger mit einem klaren Grund ab -- der Text steht in der Antwort und
// ist nur fuer Aufrufer mit gueltiger Signatur sichtbar (Shopify, Weiterleitung der
// alten Welt, Selbsttest). Shopify wiederholt den Webhook dann.
try {
    $insert = $ctx->pdo->prepare(
        'INSERT INTO connect_privacy_requests (shop, topic, shopify_customer_id) VALUES (:shop, :topic, :customer_id)'
    );
    $insert->execute(['shop' => $shop, 'topic' => $topic, 'customer_id' => $customerId]);
    $requestId = (int) $ctx->pdo->lastInsertId();
} catch (\Throwable $e) {
    http_response_code(500);
    echo 'error: Protokoll-Eintrag nicht moeglich: ' . $e->getMessage();
    exit;
}

try {
    $service = new PrivacyService($ctx->pdo);
    $resultJson = null;

    switch ($topic) {
        case 'shop/redact':
            $counts = $service->eraseShop($shop);
            break;

        case 'customers/redact':
            $counts = $service->redactCustomer($shop, $payload);
            break;

        case 'customers/data_request':
            $found = $service->collectCustomerData($shop, $payload);
            $counts = [
                'webhook_events' => count($found['webhook_events']),
                'order_cache' => count($found['order_cache']),
            ];
            $resultJson = json_encode($found, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR);
            break;

        default: // app/uninstalled
            $counts = $service->handleUninstall($shop);
            break;
    }

    $update = $ctx->pdo->prepare(
        "UPDATE connect_privacy_requests
         SET status = 'processed', summary = :summary, result = :result, processed_at = CURRENT_TIMESTAMP
         WHERE id = :id"
    );
    $update->execute([
        'summary' => json_encode($counts),
        'result' => $resultJson,
        'id' => $requestId,
    ]);

    http_response_code(200);
    echo 'ok';
} catch (\Throwable $e) {
    $update = $ctx->pdo->prepare(
        "UPDATE connect_privacy_requests SET status = 'error', error_message = :error, processed_at = CURRENT_TIMESTAMP WHERE id = :id"
    );
    $update->execute(['error' => $e->getMessage(), 'id' => $requestId]);

    http_response_code(500);
    echo 'error: ' . $e->getMessage();
}
