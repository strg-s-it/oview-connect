<?php

declare(strict_types=1);

/**
 * connect/public/webhooks/_store_event.php
 *
 * Gemeinsame Speicherlogik fuer alle Webhook-Empfaenger. Erwartet vor dem
 * Include: $ctx (Connect\Shopify\WebhookContext), $defaultTopic (string,
 * falls Shopify den X-Shopify-Topic-Header ausnahmsweise nicht mitsendet).
 *
 * Speichert nur -- die eigentliche Verarbeitung (z.B. daraus einen
 * MGM-Auftrag anlegen) ist bewusst nicht Teil von Phase 1, siehe Plan: dafuer
 * fehlen noch die generischen Sync Mappings inkl. Lookup-Tabellen (Phase 3).
 * Ausnahme: product_update.php verarbeitet direkt (Einzelprodukt-Cache-
 * Update), nutzt dafuer die hier exponierte $eventId, um den Verarbeitungs-
 * status im Nachhinein auf demselben Event-Datensatz zu vermerken.
 *
 * Gibt KEINEN HTTP-Response aus (kein http_response_code()/echo hier) --
 * das macht der jeweilige Empfaenger selbst am Ende, damit product_update.php
 * nach dieser Datei noch weiterverarbeiten kann, bevor die Antwort rausgeht.
 */

/** @var \Connect\Shopify\WebhookContext $ctx */
/** @var string $defaultTopic */

$topic = $ctx->topic !== '' ? $ctx->topic : $defaultTopic;

$insert = $ctx->pdo->prepare(
    'INSERT INTO connect_webhook_events (shop, connection_id, topic, payload) VALUES (:shop, :connection_id, :topic, :payload)'
);
$insert->execute([
    'shop' => $ctx->shop,
    'connection_id' => $ctx->connectionId,
    'topic' => $topic,
    'payload' => $ctx->rawBody,
]);

$eventId = (int) $ctx->pdo->lastInsertId();
