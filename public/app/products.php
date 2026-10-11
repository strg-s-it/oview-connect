<?php

declare(strict_types=1);

/**
 * connect/public/app/products.php
 *
 * Merchant-Einstieg (Shopify-iFrame-Kontext, Vertrauen kommt aus
 * bootstrap.php). Die eigentliche Seiten-Logik steckt in _products_body.php
 * und wird von admin/products.php geteilt.
 */

/** @var \Connect\AppContext $app */
$app = require dirname(__DIR__, 2) . '/bootstrap.php';

require __DIR__ . '/_products_body.php';
