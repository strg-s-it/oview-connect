<?php

declare(strict_types=1);

/**
 * connect/public/admin/stock_overview.php
 *
 * Shop-uebergreifende Uebersicht "Lagerbestandsänderungen" -- Gegenstueck zur
 * shop-bezogenen Seite app/stock.php (dort: EIN Shop, Status-Karte +
 * Pagination), hier: ALLE Shops auf einen Blick, dafuer mit fester
 * Obergrenze statt Pagination (gleiches Prinzip wie admin/jobs.php und
 * admin/activity_log.php: "letzte N", kein Deep-Dive -- fuer den vollen
 * Verlauf eines einzelnen Shops gibt es die shop-bezogene Seite).
 *
 * Quelle: connect_stock_sync_log (Migration 0029), gleiche Quelle wie
 * app/_stock_body.php -- bewusst NICHT ueber connect_activity_log (dort steckt
 * derselbe Vorgang nur als generische Freitext-Nachricht, waehrend
 * connect_stock_sync_log strukturierte Spalten hat).
 *
 * Bewusst eine EIGENE Seite statt gemeinsam mit "Rechnungen" (fruehere
 * Version dieser Seite kombinierte beides) -- Nutzer-Entscheidung
 * (2026-09-26): "bitte eigene Seite für Rechnungen und Lagerstandsänderungen".
 *
 * Reines Admin-Feature, kein Shop-Bezug in der URL (Filter stattdessen als
 * Dropdown auf der Seite selbst) -- Muster wie activity_log.php/jobs.php.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Connect\Auth\AdminAuth;
use Connect\Config;
use Connect\Database;

$config = Config::load();
$db = Database::connect($config);
$pdo = $db->pdo();

$auth = new AdminAuth($pdo);
$auth->requireLogin();

$shopFilter = trim((string) ($_GET['shop'] ?? ''));
$limit = 200;

$stockCondition = $shopFilter !== '' ? 'WHERE shop = :shop1' : '';
$stockStmt = $pdo->prepare(
    "SELECT shop, match_value, label, direction, old_quantity, new_quantity, created_at
     FROM connect_stock_sync_log
     {$stockCondition}
     ORDER BY created_at DESC, id DESC
     LIMIT " . $limit
);
$stockStmt->execute($shopFilter !== '' ? ['shop1' => $shopFilter] : []);
$stockRows = $stockStmt->fetchAll();

$shops = $pdo->query('SELECT shop FROM connect_shops ORDER BY shop')->fetchAll(PDO::FETCH_COLUMN);

$directionLabel = [
    'shopify_to_target' => 'Shopify &rarr; Zielsystem',
    'target_to_shopify' => 'Zielsystem &rarr; Shopify',
    'stock_back' => 'Kassenverkauf &rarr; Shopify',
];

$pageTitle = 'Lagerbestandsänderungen';
$homeUrl = 'shops.php';
$adminNavItems = require __DIR__ . '/_nav.php';
$navItems = []; // kein Shop-Bezug auf dieser Seite
$topRightHtml = '<span class="text-secondary me-3">' . htmlspecialchars((string) $auth->currentEmail()) . '</span>'
    . '<a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';

require dirname(__DIR__) . '/app/_header.php';
?>

<div class="row row-cards">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Lagerbestandsänderungen (letzte <?php echo $limit; ?>)</h3>
            </div>
            <div class="card-body py-2">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-auto">
                        <label class="form-label">Shop</label>
                        <select name="shop" class="form-select" onchange="this.form.submit()">
                            <option value="">-- alle Shops --</option>
                            <?php foreach ($shops as $shopOption): ?>
                                <option value="<?php echo htmlspecialchars($shopOption); ?>" <?php echo $shopFilter === $shopOption ? 'selected' : ''; ?>><?php echo htmlspecialchars($shopOption); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
            <?php if (count($stockRows) === 0): ?>
                <div class="card-body text-secondary">Noch keine Änderung protokolliert<?php echo $shopFilter !== '' ? ' für "' . htmlspecialchars($shopFilter) . '"' : ''; ?>.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>Datum</th>
                                <th>Shop</th>
                                <th>Produkt</th>
                                <th>Vergleichswert</th>
                                <th>Richtung</th>
                                <th class="text-end">Lagerbestand</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stockRows as $row): ?>
                                <tr>
                                    <td class="text-secondary text-nowrap"><?php echo htmlspecialchars((string) $row['created_at']); ?></td>
                                    <td><a href="stock.php?shop=<?php echo urlencode($row['shop']); ?>"><?php echo htmlspecialchars($row['shop']); ?></a></td>
                                    <td><?php echo htmlspecialchars((string) ($row['label'] ?? $row['match_value'])); ?></td>
                                    <td class="text-secondary"><?php echo htmlspecialchars((string) $row['match_value']); ?></td>
                                    <td class="text-secondary"><?php echo $directionLabel[$row['direction']] ?? htmlspecialchars((string) $row['direction']); ?></td>
                                    <td class="text-end text-nowrap">
                                        <?php if ($row['old_quantity'] !== null): ?>
                                            <span class="text-secondary"><?php echo (int) $row['old_quantity']; ?> &rarr;</span>
                                        <?php endif; ?>
                                        <strong><?php echo (int) $row['new_quantity']; ?></strong>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require dirname(__DIR__) . '/app/_footer.php'; ?>
