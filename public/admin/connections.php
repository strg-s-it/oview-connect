<?php

declare(strict_types=1);

/**
 * connect/public/admin/connections.php
 *
 * Admin-Ansicht derselben Connections-Verwaltung: erfordert ein echtes
 * Login (admin_bootstrap.php), $shop kommt aus der Auswahl in shops.php.
 * Die eigentliche Seiten-Logik ist identisch zu app/connections.php und
 * lebt in app/_connections_body.php, um Duplikation zu vermeiden.
 *
 * ?preview=1: zeigt stattdessen die ECHTE Merchant-Ansicht, siehe
 * dashboard.php-Kommentar.
 */

/** @var \Connect\AppContext $app */
$app = require dirname(__DIR__, 2) . '/admin_bootstrap.php';

$isAdmin = !isset($_GET['preview']);
require dirname(__DIR__) . '/app/_connections_body.php';
