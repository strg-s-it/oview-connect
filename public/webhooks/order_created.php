<?php

declare(strict_types=1);

/** @var \Connect\Shopify\WebhookContext $ctx */
$ctx = require dirname(__DIR__, 2) . '/webhook_bootstrap.php';

$defaultTopic = 'orders/create';
require __DIR__ . '/_store_event.php';

/** @var int $eventId von _store_event.php gesetzt */

// Verarbeitet nur, wenn eine Aktivierung auf dieses Thema lauscht (Rechnungs-Ausloeser "Bestellung
// angelegt", connect_shops.invoice_trigger). Sonst bleibt es beim Speichern des Ereignisses.
$processTopic = 'orders/create';
require __DIR__ . '/_process_order.php';
