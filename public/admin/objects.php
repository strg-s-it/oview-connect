<?php

declare(strict_types=1);

/** @var \Connect\AppContext $app */
$app = require dirname(__DIR__, 2) . '/admin_bootstrap.php';

$isAdmin = true;
require dirname(__DIR__) . '/app/_objects_body.php';
