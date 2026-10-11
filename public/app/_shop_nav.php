<?php

declare(strict_types=1);

/**
 * connect/public/app/_shop_nav.php
 *
 * Zentrale Liste der SHOP-BEZOGENEN Navigation (Dashboard/Connections/Daten
 * ansehen/Lagerbestände/Produkte/Rechnungen/Sync/Mappings) -- Gegenstueck zu
 * admin/_nav.php (dort: allgemeine,
 * NICHT shop-bezogene Admin-Navigation wie Shops/Connector Types/Jobs/
 * Webhooks). Ersetzt die zuvor in _dashboard_body.php, _connections_body.php,
 * _objects_body.php und _sync_mappings_body.php einzeln duplizierten
 * $navItems-Arrays, damit ein neuer Menuepunkt (wie "Mappings" hier) nicht in
 * vier Dateien einzeln nachgezogen werden muss.
 *
 * "Mappings" ist bewusst nur im Admin-Zweig gelistet -- Feld-Mapping-
 * Bearbeitung bleibt Admin-only (siehe Plan "Mapping Engine v1"), analog zum
 * bestehenden Connector-Builder.
 *
 * "Als Merchant ansehen" (Admin-Zweig): fuehrt zu denselben admin/*.php-
 * Dateien, aber mit ?preview=1 -- die setzen dann $isAdmin=false (siehe deren
 * Kommentare), wodurch genau die Merchant-Ansicht gerendert wird, nur
 * admin-authentifiziert statt ueber Shopify. Im Vorschau-Modus haengen die
 * Merchant-Links unten deshalb selbst &preview=1 an, damit man beim
 * Navigieren (Dashboard/Connections/...) in der Vorschau bleibt, statt bei
 * jedem Klick in die normale Admin-Ansicht zurueckzufallen.
 *
 * Erwartet vor dem Include: $isAdmin (bool), $shop (string), optional $pdo. Verwendung:
 *   $navItems = require __DIR__ . '/_shop_nav.php';
 *
 * Eintraege (siehe _header.php): [url, label] = direkter Link,
 * ['group', label, [[url, label], ...]] = Dropdown, ['button', url, label] = Button rechts.
 * Admin-Leiste seit 2026-10-05 gruppiert (vorher 12 gleichrangige, umbrechende Punkte):
 * Dashboard / Abläufe / Daten ▾ / Einrichtung ▾ / Umzug (nur solange der Shop noch in
 * der alten Welt laeuft, danach unter Einrichtung) / Button "Als Merchant ansehen".
 */

/** @var bool $isAdmin */
/** @var string $shop */

if ($isAdmin) {
    $q = '?shop=' . urlencode($shop);

    // Umzug prominent, solange der Shop noch nicht in Connect live ist
    $shopIsLive = false;
    if (isset($pdo) && $pdo instanceof \PDO) {
        try {
            $liveStmt = $pdo->prepare('SELECT live_in_connect FROM connect_shops WHERE shop = :shop');
            $liveStmt->execute(['shop' => $shop]);
            $shopIsLive = (int) $liveStmt->fetchColumn() === 1;
        } catch (\Throwable) {
            $shopIsLive = false;
        }
    }

    $setupItems = [
        ['connections.php' . $q, 'Connections'],
        ['sync_mappings.php' . $q, 'Jobs & Einstellungen'],
        ['mappings.php' . $q, 'Mappings'],
        ['shop_fields.php' . $q, 'Shop-Felder'],
        ['billing.php' . $q, 'Abo'],
        ['weekly_report.php' . $q, 'Wochenbericht'],
    ];
    if ($shopIsLive) {
        $setupItems[] = ['migrate.php' . $q, 'Umzug'];
    }

    $items = [
        ['dashboard.php' . $q, 'Dashboard'],
        ['flows.php' . $q, 'Abläufe'],
        ['status.php' . $q, 'Status'],
        ['group', 'Daten', [
            ['products.php' . $q, 'Produkte'],
            ['prices.php' . $q, 'Preise'],
            ['stock.php' . $q, 'Lagerbestände'],
            ['invoices.php' . $q, 'Rechnungen'],
            ['objects.php' . $q, 'Daten ansehen'],
        ]],
        ['group', 'Einrichtung', $setupItems],
    ];
    if (!$shopIsLive) {
        $items[] = ['migrate.php' . $q, 'Umzug'];
    }
    $items[] = ['button', 'dashboard.php' . $q . '&preview=1', 'Als Merchant ansehen'];

    return $items;
}

// isset($_GET['preview']) ist nur gesetzt, wenn diese Merchant-Ansicht ueber
// admin/*.php?preview=1 aufgerufen wurde (echte Merchant-Seiten unter app/
// kennen diesen Parameter nicht) -- eindeutiges, konfliktfreies Signal.
$previewSuffix = isset($_GET['preview']) ? '&preview=1' : '';

// Merchant-Leiste (seit 2026-10-05): "Connections" ist kein eigener Punkt mehr, die Technik
// steht unter "Status" (connections.php bleibt von dort fuer "Schlüssel ändern" erreichbar).
return [
    ['dashboard.php?shop=' . urlencode($shop) . $previewSuffix, 'Übersicht'],
    ['stock.php?shop=' . urlencode($shop) . $previewSuffix, 'Lagerbestand'],
    ['invoices.php?shop=' . urlencode($shop) . $previewSuffix, 'Rechnungen'],
    ['products.php?shop=' . urlencode($shop) . $previewSuffix, 'Produkte'],
    ['prices.php?shop=' . urlencode($shop) . $previewSuffix, 'Preise'],
    ['sync_mappings.php?shop=' . urlencode($shop) . $previewSuffix, 'Einstellungen'],
    ['status.php?shop=' . urlencode($shop) . $previewSuffix, 'Status'],
    ['billing.php?shop=' . urlencode($shop) . $previewSuffix, 'Abo'],
    ['wishes.php?shop=' . urlencode($shop) . $previewSuffix, 'Wünsche'],
];
