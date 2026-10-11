<?php

declare(strict_types=1);

/**
 * connect/public/app/status.php
 *
 * Merchant-Seite "Status" (Shopify-iFrame-Kontext). Logik in _status_body.php,
 * geteilt mit admin/status.php.
 */

/** @var \Connect\AppContext $app */
$app = require dirname(__DIR__, 2) . '/bootstrap.php';

require __DIR__ . '/_status_body.php';
