<?php

declare(strict_types=1);

/** @var \Connect\Shopify\WebhookContext $ctx */
$ctx = require dirname(__DIR__, 2) . '/webhook_bootstrap.php';

$defaultTopic = 'orders/create';
require __DIR__ . '/_store_event.php';

http_response_code(200);
echo 'ok';
