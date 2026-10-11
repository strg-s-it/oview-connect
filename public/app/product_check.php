<?php

declare(strict_types=1);

/**
 * connect/public/app/product_check.php
 *
 * Merchant-Einstieg "Produkt-Check" (Datenqualitaet und SEO). Die Logik steckt
 * in _product_check_body.php und wird von admin/product_check.php geteilt.
 */

/** @var \Connect\AppContext $app */
$app = require dirname(__DIR__, 2) . '/bootstrap.php';

require __DIR__ . '/_product_check_body.php';
