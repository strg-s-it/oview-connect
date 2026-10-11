<?php

declare(strict_types=1);

/**
 * connect/public/app/bundles.php
 *
 * Merchant-Einstieg "Sets" (Sets und Bundles). Die Logik steckt
 * in _bundles_body.php und wird von admin/bundles.php geteilt.
 */

/** @var \Connect\AppContext $app */
$app = require dirname(__DIR__, 2) . '/bootstrap.php';

require __DIR__ . '/_bundles_body.php';
