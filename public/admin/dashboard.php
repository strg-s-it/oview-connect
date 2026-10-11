<?php

declare(strict_types=1);

/**
 * connect/public/admin/dashboard.php
 *
 * Admin-Ansicht desselben Dashboards (fuer den vom Admin ausgewaehlten Shop).
 *
 * ?preview=1: zeigt stattdessen die ECHTE Merchant-Ansicht (siehe
 * _shop_nav.php "Als Merchant ansehen") -- admin-authentifiziert, aber sonst
 * inhaltlich nicht von dem unterscheidbar, was der Merchant selbst sieht.
 */

/** @var \Connect\AppContext $app */
$app = require dirname(__DIR__, 2) . '/admin_bootstrap.php';

$isAdmin = !isset($_GET['preview']);
require dirname(__DIR__) . '/app/_dashboard_body.php';
