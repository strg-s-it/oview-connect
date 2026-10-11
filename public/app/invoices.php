<?php

declare(strict_types=1);

/**
 * connect/public/app/invoices.php
 *
 * Merchant-Einstieg (Shopify-iFrame-Kontext, Vertrauen kommt aus
 * bootstrap.php). Die eigentliche Seiten-Logik steckt in _invoices_body.php
 * und wird von admin/invoices.php geteilt.
 */

/** @var \Connect\AppContext $app */
$app = require dirname(__DIR__, 2) . '/bootstrap.php';

require __DIR__ . '/_invoices_body.php';
