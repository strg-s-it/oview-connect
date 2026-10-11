<?php

declare(strict_types=1);

/**
 * connect/public/app/dashboard.php
 *
 * Merchant-Dashboard (Shopify-iFrame-Kontext). Logik in _dashboard_body.php,
 * geteilt mit admin/dashboard.php.
 */

/** @var \Connect\AppContext $app */
$app = require dirname(__DIR__, 2) . '/bootstrap.php';

require __DIR__ . '/_dashboard_body.php';
