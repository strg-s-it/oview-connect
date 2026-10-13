<?php

declare(strict_types=1);

/**
 * connect/public/app/_header.php
 *
 * Gemeinsames Tabler-Grundgerüst für app/- UND admin/-Seiten (von dort per
 * "../app/_header.php" eingebunden). Erwartet vor dem Include gesetzt:
 *   $pageTitle    string
 * Optional:
 *   $flashMessage string|null
 *   $flashType    "success"|"danger"|"info" (Default "info")
 *   $homeUrl      string, Ziel des Logos (Default "index.php" -- im Admin-
 *                 Kontext auf "shops.php" setzen, da es dort kein
 *                 gleichrangiges index.php gibt)
 *   $navItems     array<array{0:string,1:string}> Liste [url, label] fuer die
 *                 SHOP-BEZOGENE Navigation, d.h. Seiten, die sich auf den
 *                 aktuell gewaehlten Shop beziehen (Dashboard, Connections,
 *                 Daten ansehen, Sync/Einstellungen). Leer lassen auf
 *                 Admin-Seiten ohne Shop-Bezug (z.B. shops.php,
 *                 connector_types.php). Default: nur "Connections".
 *                 Ausser [url, label] auch ['group', label, [[url, label], ...]]
 *                 (Dropdown) und ['button', url, label] (Button rechts), siehe
 *                 _shop_nav.php. Im Admin zusaetzlich Shop-Auswahl (braucht $pdo).
 *   $adminNavItems array<array{0:string,1:string}> Liste [url, label] fuer
 *                 die ALLGEMEINE Admin-Navigation (Shops, Connector Types) --
 *                 nicht shop-bezogen, wird getrennt von $navItems oben im
 *                 Header angezeigt. Auf Merchant-Seiten leer lassen.
 *   $topRightHtml string, bereits fertig gerendertes HTML fuer die rechte
 *                 obere Ecke (z.B. "eingeloggt als X · Logout" im Admin)
 *   $breadcrumbs  array<array{0:?string,1:string}> Liste [url, label] fuer
 *                 den "Wo bin ich"-Pfad UNTER der Hauptnavigation (z.B. bei
 *                 tief verschachtelten Admin-Seiten: Connector Types >
 *                 Shopify > Produkt > read_list). url=null beim letzten
 *                 Eintrag (aktuelle Seite, nicht verlinkt).
 *
 * Bewusst kein Templating-System -- bei der ueberschaubaren Seitenzahl in
 * Phase 1 waere das Overengineering. Einfache PHP-Includes reichen.
 */

// Name, Logo, Farbe, Schrift aus Admin -> Branding (Migration 0083), sonst Standard
$brand = \Connect\Setup\Branding::load(
    isset($pdo) && $pdo instanceof \PDO ? $pdo : (isset($app) && isset($app->pdo) ? $app->pdo : null),
    // $config nur, wenn es wirklich die Konfiguration ist -- manche Seiten verwenden den Namen
    // auch fuer eigene Arrays (z.B. transform_config in admin/mapping_template_edit.php)
    isset($app) && isset($app->config) ? $app->config : (isset($config) && $config instanceof \Connect\Config ? $config : null)
);
$pageTitle = $pageTitle ?? $brand->name;
$flashMessage = $flashMessage ?? null;
$flashType = $flashType ?? 'info';
$breadcrumbs = $breadcrumbs ?? [];
// Im Vorschau-Modus (siehe unten) gibt es kein "index.php" -- die Seite laeuft
// physisch unter admin/, dort existiert nur "dashboard.php" als Merchant-
// Einstieg. &preview=1 angehaengt, damit ein Klick auf das Logo nicht aus der
// Vorschau herausfaellt.
$homeUrl = $homeUrl ?? (isset($_GET['preview'])
    ? 'dashboard.php?shop=' . urlencode($_GET['shop'] ?? '') . '&preview=1'
    : 'index.php?shop=' . urlencode($_GET['shop'] ?? ''));
$navItems = $navItems ?? [['connections.php?shop=' . urlencode($_GET['shop'] ?? ''), 'Connections']];
$adminNavItems = $adminNavItems ?? [];
// Welcher admin/*.php-Eintrag gilt als "aktiv" (siehe admin/_nav.php): bei einer
// tief verschachtelten Seite (z.B. object_edit.php) ist das NICHT die aktuelle
// Datei selbst, sondern die oberste Listen-Seite der Gruppe -- die steht bereits
// als erster Breadcrumb-Eintrag fest (jede Admin-Seite mit Breadcrumbs setzt sie
// genau so, siehe z.B. object_edit.php), muss also hier nicht nochmal separat
// gepflegt werden.
$activeAdminScript = basename((string) parse_url(
    (!empty($breadcrumbs) && $breadcrumbs[0][0] !== null) ? $breadcrumbs[0][0] : (string) ($_SERVER['SCRIPT_NAME'] ?? ''),
    PHP_URL_PATH
));
// Die Konfigurations-Listen haben keinen eigenen Menuepunkt mehr, sie gehoeren
// zur Landkarte (siehe admin/_nav.php) -- dort "Konfiguration" aktiv markieren.
if (in_array($activeAdminScript, ['connector_types.php', 'connector_type_edit.php', 'object_edit.php', 'operation_edit.php', 'sync_job_templates.php',
    'mapping_templates.php', 'mapping_template_edit.php', 'functions.php', 'lookup_tables.php', 'lookup_table_edit.php'], true)) {
    $activeAdminScript = 'config_map.php';
}
// Default nur fuer Merchant-Seiten relevant (Admin-Seiten setzen $topRightHtml
// immer selbst mit E-Mail+Logout) -- zeigt den Shop-Namen, damit erkennbar
// ist, welcher Shop gerade angezeigt wird.
$topRightHtml = $topRightHtml ?? ('<span class="text-secondary me-3">' . htmlspecialchars($app->shop ?? '') . '</span><span class="badge bg-yellow-lt">Beta</span>');

// Shop-Leiste ($navItems, siehe _shop_nav.php): aktive Seite. Detailseiten zaehlen zur
// Seite, von der aus man sie oeffnet (z.B. mapping_edit.php -> Mappings).
$activeShopScript = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$activeShopScript = [
    'mapping_edit.php' => 'mappings.php',
    'shopify_activity.php' => 'dashboard.php',
    'stock_history.php' => 'products.php',
    'product_check.php' => 'products.php',
    'bundles.php' => 'products.php',
    'connections.php' => isset($adminNavItems) && $adminNavItems !== [] ? 'connections.php' : 'status.php',
][$activeShopScript] ?? $activeShopScript;
$shopNavIsActive = static fn (string $url): bool => basename((string) parse_url($url, PHP_URL_PATH)) === $activeShopScript;

// Shop-Auswahl im Admin: Liste aller Shops. Beim Wechsel bleibt man auf derselben
// Seite, wenn sie in der Shop-Leiste steht (sonst Dashboard -- Detailseiten wie
// mapping_edit.php?id= gehoeren zum alten Shop).
$shopSwitchList = [];
$shopSwitchTarget = 'dashboard.php';
if (count($navItems) > 0 && count($adminNavItems) > 0 && isset($pdo) && $pdo instanceof \PDO) {
    try {
        $shopSwitchList = $pdo->query('SELECT shop FROM connect_shops ORDER BY shop')->fetchAll(\PDO::FETCH_COLUMN);
    } catch (\Throwable) {
        $shopSwitchList = [];
    }
    $currentScript = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    foreach ($navItems as $navEntry) {
        $navUrls = ($navEntry[0] ?? null) === 'group' ? array_column($navEntry[2], 0) : [($navEntry[0] ?? null) === 'button' ? '' : (string) $navEntry[0]];
        foreach ($navUrls as $navUrl) {
            if ($navUrl !== '' && basename((string) parse_url($navUrl, PHP_URL_PATH)) === $currentScript) {
                $shopSwitchTarget = $currentScript;
            }
        }
    }
}
$currentShopName = (string) ($app->shop ?? ($_GET['shop'] ?? ''));
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($pageTitle); ?> · <?php echo htmlspecialchars($brand->name); ?></title>
    <link rel="stylesheet" href="../assets/vendor/tabler/css/tabler.min.css">
    <?php echo $brand->headHtml(); ?>
    <?php require __DIR__ . '/_loading_overlay_head.php'; ?>
    <style>
        /* Menuepunkte nicht umbrechen ("Daten ansehen" stand sonst zweizeilig) */
        .navbar-nav .nav-link, .navbar-nav .btn { white-space: nowrap; }
        /* Sprung aus dem Einrichtungs-Assistenten (Dashboard) auf eine Einstellungs-Karte */
        .setup-anchor { scroll-margin-top: 1rem; }
        /* Icons in den Menues (Connect\Ui\Icon) */
        .nav-ic { display: inline-flex; align-items: center; margin-right: .4rem; opacity: .75; }
        .nav-ic:empty { display: none; }
        .nav-link.active .nav-ic, .dropdown-item.active .nav-ic { opacity: 1; }
        .dropdown-header { font-size: .7rem; text-transform: uppercase; letter-spacing: .04em; padding-top: .6rem; }
        .setup-anchor:target > .card { box-shadow: 0 0 0 3px var(--tblr-primary, #206bc4); transition: box-shadow .3s; }
    </style>
    <?php if (isset($app) && $app->host !== ''): ?>
        <!-- Nur auf Merchant-Seiten gesetzt (siehe connect/bootstrap.php) --
             haelt die Seite im Shopify-Admin-iframe eingebettet, analog zum
             App-Bridge-Setup in der alten Welt (index.php). -->
        <!-- App Bridge 3.7.12 selbst ausgeliefert (vorher unpkg.com): keine IP-Adresse an
             einen weiteren Anbieter, siehe Datenschutzerklaerung. Version 4 liegt nur auf
             cdn.shopify.com und funktioniert anders (kein createApp, host aus der URL). -->
        <script src="../assets/vendor/app-bridge/app-bridge-3.js"></script>
        <script>
            (function() {
                var AppBridge = window['app-bridge'];
                AppBridge.createApp({
                    apiKey: <?php echo json_encode($app->config->require('CONNECT_SHOPIFY_API_KEY')); ?>,
                    host: <?php echo json_encode($app->host); ?>,
                    forceRedirect: true
                });
            })();
        </script>
    <?php endif; ?>
</head>
<body>
    <?php require __DIR__ . '/_loading_overlay_body.php'; ?>
    <div class="page">
        <header class="navbar navbar-expand-md navbar-light d-print-none">
            <div class="container-xl">
                <h1 class="navbar-brand navbar-brand-autodark">
                    <a href="<?php echo htmlspecialchars($homeUrl); ?>" class="d-flex align-items-center text-reset text-decoration-none"><?php echo $brand->logoHtml(32); ?></a>
                </h1>
                <?php if (count($adminNavItems) > 0): ?>
                    <!-- Allgemeine Admin-Navigation (nicht shop-bezogen, z.B.
                         Shops, Connector Types) -- bewusst getrennt von der
                         shop-bezogenen $navItems-Leiste weiter unten, damit
                         auf einen Blick klar ist, was zum aktuell
                         angezeigten Shop gehoert und was nicht. Struktur
                         (Direktlink vs. Dropdown-Gruppe) kommt aus admin/_nav.php,
                         siehe dortigen Kommentar. -->
                    <div class="navbar-nav flex-row ms-3">
                        <?php foreach ($adminNavItems as $entry): ?>
                            <?php if ($entry[0] === 'group'): ?>
                                <?php
                                [, $groupLabel, $groupItems] = $entry;
                                $groupActive = false;
                                foreach ($groupItems as [$itemUrl, ]) {
                                    if ($itemUrl !== null && basename((string) parse_url($itemUrl, PHP_URL_PATH)) === $activeAdminScript) {
                                        $groupActive = true;
                                        break;
                                    }
                                }
                                ?>
                                <div class="nav-item dropdown">
                                    <a class="nav-link px-2 dropdown-toggle<?php echo $groupActive ? ' active' : ''; ?>" href="#" data-bs-toggle="dropdown" role="button" aria-expanded="false">
                                        <span class="nav-ic"><?php echo \Connect\Ui\Icon::forGroup($groupLabel); ?></span><?php echo htmlspecialchars($groupLabel); ?>
                                    </a>
                                    <div class="dropdown-menu">
                                        <?php foreach ($groupItems as [$itemUrl, $itemLabel]): ?>
                                            <?php if ($itemUrl === null): ?>
                                                <h6 class="dropdown-header"><?php echo htmlspecialchars($itemLabel); ?></h6>
                                            <?php else: ?>
                                                <a class="dropdown-item<?php echo basename((string) parse_url($itemUrl, PHP_URL_PATH)) === $activeAdminScript ? ' active' : ''; ?>" href="<?php echo htmlspecialchars($itemUrl); ?>"><span class="nav-ic"><?php echo \Connect\Ui\Icon::forPage($itemUrl); ?></span><?php echo htmlspecialchars($itemLabel); ?></a>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <?php [, $url, $label] = $entry; ?>
                                <div class="nav-item"><a class="nav-link px-2<?php echo basename((string) parse_url($url, PHP_URL_PATH)) === $activeAdminScript ? ' active' : ''; ?>" href="<?php echo htmlspecialchars($url); ?>"><span class="nav-ic"><?php echo \Connect\Ui\Icon::forPage($url); ?></span><?php echo htmlspecialchars($label); ?></a></div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="navbar-nav flex-row order-md-last d-flex align-items-center flex-wrap gap-2">
                    <?php echo $topRightHtml; ?>
                </div>
            </div>
        </header>
        <?php if (isset($_GET['preview'])): ?>
            <!-- Nur gesetzt, wenn ein Admin diese Seite ueber admin/*.php?preview=1
                 aufruft (siehe admin/dashboard.php-Kommentar, _shop_nav.php) --
                 echte Merchant-Seiten unter app/ kennen diesen Parameter nicht,
                 daher hier kein zusaetzliches Flag noetig. -->
            <div class="alert alert-warning mb-0 rounded-0 text-center py-2">
                Vorschau: So sieht diese Seite für den Merchant aus.
                <a href="dashboard.php?shop=<?php echo urlencode($_GET['shop'] ?? ''); ?>" class="ms-2">Zurück zur Admin-Ansicht</a>
            </div>
        <?php endif; ?>
        <?php if (count($navItems) > 0): ?>
            <div class="navbar-expand-md">
                <div class="collapse navbar-collapse">
                    <!-- Dieser innere .navbar-Wrapper ist noetig, damit Tablers
                         Flexbox-Styling fuer .navbar-nav ueberhaupt greift --
                         ohne ihn laufen die Nav-Links ohne Abstand zusammen. -->
                    <div class="navbar navbar-light">
                        <div class="container-xl">
                            <ul class="navbar-nav shop-nav flex-grow-1">
                                <?php if (count($adminNavItems) > 0): ?>
                                    <!-- Shop-Kennzeichnung nur im Admin-Kontext (dort
                                         ist $adminNavItems gesetzt) -- macht klar, dass
                                         die folgenden Tabs sich auf DIESEN Shop beziehen.
                                         Mit Shop-Liste zum direkten Wechseln. -->
                                    <?php if (count($shopSwitchList) > 1): ?>
                                        <li class="nav-item dropdown me-2">
                                            <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown" role="button" aria-expanded="false" title="Shop wechseln">
                                                <span class="text-secondary me-1">Shop:</span><strong><?php echo htmlspecialchars($currentShopName); ?></strong>
                                            </a>
                                            <div class="dropdown-menu" style="max-height: 60vh; overflow-y: auto;">
                                                <?php foreach ($shopSwitchList as $switchShop): ?>
                                                    <a class="dropdown-item<?php echo $switchShop === $currentShopName ? ' active' : ''; ?>" href="<?php echo htmlspecialchars($shopSwitchTarget . '?shop=' . urlencode((string) $switchShop)); ?>"><?php echo htmlspecialchars((string) $switchShop); ?></a>
                                                <?php endforeach; ?>
                                                <div class="dropdown-divider"></div>
                                                <a class="dropdown-item" href="shops.php">Alle Shops verwalten</a>
                                            </div>
                                        </li>
                                    <?php else: ?>
                                        <li class="nav-item d-flex align-items-center me-2">
                                            <span class="text-secondary px-2">Shop: <strong><?php echo htmlspecialchars($currentShopName); ?></strong></span>
                                        </li>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php foreach ($navItems as $navEntry): ?>
                                    <?php if (($navEntry[0] ?? null) === 'group'): ?>
                                        <?php $navGroupActive = count(array_filter($navEntry[2], static fn (array $i): bool => $shopNavIsActive((string) $i[0]))) > 0; ?>
                                        <li class="nav-item dropdown">
                                            <a class="nav-link dropdown-toggle<?php echo $navGroupActive ? ' active' : ''; ?>" href="#" data-bs-toggle="dropdown" role="button" aria-expanded="false"><span class="nav-ic"><?php echo \Connect\Ui\Icon::forGroup((string) $navEntry[1]); ?></span><?php echo htmlspecialchars((string) $navEntry[1]); ?></a>
                                            <div class="dropdown-menu">
                                                <?php foreach ($navEntry[2] as [$itemUrl, $itemLabel]): ?>
                                                    <a class="dropdown-item<?php echo $shopNavIsActive((string) $itemUrl) ? ' active' : ''; ?>" href="<?php echo htmlspecialchars((string) $itemUrl); ?>"><span class="nav-ic"><?php echo \Connect\Ui\Icon::forPage((string) $itemUrl); ?></span><?php echo htmlspecialchars((string) $itemLabel); ?></a>
                                                <?php endforeach; ?>
                                            </div>
                                        </li>
                                    <?php elseif (($navEntry[0] ?? null) === 'button'): ?>
                                        <li class="nav-item ms-md-auto d-flex align-items-center">
                                            <a class="btn btn-sm btn-outline-secondary" href="<?php echo htmlspecialchars((string) $navEntry[1]); ?>"><span class="nav-ic"><?php echo \Connect\Ui\Icon::svg('eye', 16); ?></span><?php echo htmlspecialchars((string) $navEntry[2]); ?></a>
                                        </li>
                                    <?php else: ?>
                                        <?php [$url, $label] = $navEntry; ?>
                                        <li class="nav-item">
                                            <a class="nav-link<?php echo $shopNavIsActive((string) $url) ? ' active' : ''; ?>" href="<?php echo htmlspecialchars((string) $url); ?>"><span class="nav-ic"><?php echo \Connect\Ui\Icon::forPage((string) $url); ?></span><?php echo htmlspecialchars((string) $label); ?></a>
                                        </li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <div class="page-wrapper">
            <div class="page-header d-print-none">
                <div class="container-xl">
                    <?php if (count($breadcrumbs) > 0): ?>
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb breadcrumb-arrows mb-2">
                                <?php foreach ($breadcrumbs as [$crumbUrl, $crumbLabel]): ?>
                                    <li class="breadcrumb-item">
                                        <?php if ($crumbUrl !== null): ?>
                                            <a href="<?php echo htmlspecialchars($crumbUrl); ?>"><?php echo htmlspecialchars($crumbLabel); ?></a>
                                        <?php else: ?>
                                            <span class="text-secondary"><?php echo htmlspecialchars($crumbLabel); ?></span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        </nav>
                    <?php endif; ?>
                    <h2 class="page-title"><?php echo htmlspecialchars($pageTitle); ?></h2>
                </div>
            </div>
            <div class="page-body">
                <div class="container-xl">
                    <?php if ($flashMessage !== null): ?>
                        <div class="alert alert-<?php echo htmlspecialchars($flashType); ?>" role="alert">
                            <?php echo htmlspecialchars($flashMessage); ?>
                        </div>
                    <?php endif; ?>
