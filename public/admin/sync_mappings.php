<?php

declare(strict_types=1);

/**
 * connect/public/admin/sync_mappings.php
 *
 * Admin-Ansicht derselben Sync-Mapping-Verwaltung: erfordert ein echtes
 * Login (admin_bootstrap.php), $shop kommt aus der Auswahl in shops.php.
 * Die eigentliche Seiten-Logik ist identisch zu app/sync_mappings.php und
 * lebt in app/_sync_mappings_body.php, um Duplikation zu vermeiden.
 *
 * ?preview=1: zeigt stattdessen die ECHTE Merchant-Ansicht, siehe
 * dashboard.php-Kommentar.
 */

/** @var \Connect\AppContext $app */
$app = require dirname(__DIR__, 2) . '/admin_bootstrap.php';

$isAdmin = !isset($_GET['preview']);
require dirname(__DIR__) . '/app/_sync_mappings_body.php';
