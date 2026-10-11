<?php

declare(strict_types=1);

/**
 * connect/public/app/connections.php
 *
 * Merchant-Einstieg (Shopify-iFrame-Kontext, Vertrauen kommt aus
 * bootstrap.php). Die eigentliche Seiten-Logik steckt in
 * _connections_body.php und wird von admin/connections.php geteilt.
 */

/** @var \Connect\AppContext $app */
$app = require dirname(__DIR__, 2) . '/bootstrap.php';

require __DIR__ . '/_connections_body.php';
