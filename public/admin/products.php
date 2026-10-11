<?php

declare(strict_types=1);

/**
 * ?preview=1: zeigt die ECHTE Merchant-Ansicht, siehe admin/dashboard.php.
 */

/** @var \Connect\AppContext $app */
$app = require dirname(__DIR__, 2) . '/admin_bootstrap.php';

$isAdmin = !isset($_GET['preview']);
require dirname(__DIR__) . '/app/_products_body.php';
