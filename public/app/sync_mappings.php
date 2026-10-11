<?php

declare(strict_types=1);

/**
 * connect/public/app/sync_mappings.php
 *
 * Merchant-Einstieg (Shopify-iFrame-Kontext, Vertrauen kommt aus
 * bootstrap.php). Die eigentliche Seiten-Logik steckt in
 * _sync_mappings_body.php und wird von admin/sync_mappings.php geteilt.
 */

/** @var \Connect\AppContext $app */
$app = require dirname(__DIR__, 2) . '/bootstrap.php';

require __DIR__ . '/_sync_mappings_body.php';
