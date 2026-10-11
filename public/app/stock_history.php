<?php

declare(strict_types=1);

/**
 * connect/public/app/stock_history.php
 *
 * Merchant-Einstieg "Bestandsverlauf" eines Artikels. Die Logik steckt
 * in _stock_history_body.php und wird von admin/stock_history.php geteilt.
 */

/** @var \Connect\AppContext $app */
$app = require dirname(__DIR__, 2) . '/bootstrap.php';

require __DIR__ . '/_stock_history_body.php';
