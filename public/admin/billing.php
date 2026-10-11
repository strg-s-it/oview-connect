<?php

declare(strict_types=1);

/**
 * connect/public/admin/billing.php
 *
 * Admin-Ansicht der Seite "Abo" (nur lesend -- wechseln kann nur der Merchant in Shopify).
 * ?preview=1: Merchant-Ansicht.
 */

/** @var \Connect\AppContext $app */
$app = require dirname(__DIR__, 2) . '/admin_bootstrap.php';

$isAdmin = !isset($_GET['preview']);
require dirname(__DIR__) . '/app/_billing_body.php';
