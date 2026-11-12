<?php

declare(strict_types=1);

/** @var \Connect\Shopify\WebhookContext $ctx */
$ctx = require dirname(__DIR__, 2) . '/webhook_bootstrap.php';

$defaultTopic = 'orders/fulfilled';
require __DIR__ . '/_store_event.php';

/** @var int $eventId von _store_event.php gesetzt */

// Rechnungs-Ausloeser "Bestellung erfuellt" (connect_shops.invoice_trigger = fulfilled)
$processTopic = 'orders/fulfilled';
require __DIR__ . '/_process_order.php';
