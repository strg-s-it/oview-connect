<?php

declare(strict_types=1);

/**
 * connect/public/app/billing.php
 *
 * Merchant-Seite "Abo" (Shopify-iFrame-Kontext). Logik in _billing_body.php,
 * geteilt mit admin/billing.php.
 */

/** @var \Connect\AppContext $app */
$app = require dirname(__DIR__, 2) . '/bootstrap.php';

require __DIR__ . '/_billing_body.php';
