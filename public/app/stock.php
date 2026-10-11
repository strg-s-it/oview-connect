<?php

declare(strict_types=1);

/**
 * connect/public/app/stock.php
 *
 * Merchant-Einstieg (Shopify-iFrame-Kontext, Vertrauen kommt aus
 * bootstrap.php). Die eigentliche Seiten-Logik steckt in _stock_body.php
 * und wird von admin/stock.php geteilt.
 */

/** @var \Connect\AppContext $app */
$app = require dirname(__DIR__, 2) . '/bootstrap.php';

require __DIR__ . '/_stock_body.php';
