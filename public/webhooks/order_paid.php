<?php

declare(strict_types=1);

/** @var \Connect\Shopify\WebhookContext $ctx */
$ctx = require dirname(__DIR__, 2) . '/webhook_bootstrap.php';

$defaultTopic = 'orders/paid';
require __DIR__ . '/_store_event.php';

/** @var int $eventId von _store_event.php gesetzt */

// Verarbeitung (Aktivierungen mit diesem Thema) gemeinsam mit orders/create und orders/fulfilled
$processTopic = 'orders/paid';
require __DIR__ . '/_process_order.php';
