<?php

declare(strict_types=1);

/**
 * connect/public/admin/status.php
 *
 * Admin-Ansicht der Merchant-Seite "Status" (fuer den gewaehlten Shop).
 * ?preview=1: echte Merchant-Ansicht (siehe _shop_nav.php "Als Merchant ansehen").
 */

/** @var \Connect\AppContext $app */
$app = require dirname(__DIR__, 2) . '/admin_bootstrap.php';

$isAdmin = !isset($_GET['preview']);
require dirname(__DIR__) . '/app/_status_body.php';
