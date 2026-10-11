<?php

declare(strict_types=1);

/**
 * connect/public/app/prices.php
 *
 * Merchant-Einstieg "Preise" (Preissynchronisation, Sammelaenderungen, Verlauf). Die Logik steckt
 * in _prices_body.php und wird von admin/prices.php geteilt.
 */

/** @var \Connect\AppContext $app */
$app = require dirname(__DIR__, 2) . '/bootstrap.php';

require __DIR__ . '/_prices_body.php';
