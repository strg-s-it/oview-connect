<?php

declare(strict_types=1);

/**
 * connect/public/app/_stock_body.php
 *
 * Merchant-Ansicht "Lagerbestände": Status des automatischen Abgleichs
 * (Connect\Sync\StockSyncRunner) + paginierte Änderungshistorie aus
 * connect_stock_sync_log (Migration 0029, befüllt von SyncRunner::run()
 * direkt nach jedem stock_sync-Lauf). Zeigt bewusst nur an, WAS bereits
 * geschrieben wurde -- die Vergleichsübersicht (fehlender Vergleichswert,
 * aktuell abweichender Bestand) ist eine eigene Seite ("Produkte", siehe
 * _products_body.php), weil sie eine andere Frage beantwortet.
 *
 * Erwartet vor dem Include: $app (Connect\AppContext), optional $isAdmin.
 */

$isAdmin = $isAdmin ?? false;

$pdo = $app->pdo;
$shop = $app->shop;

// -----------------------------------------------------------------------
// Pagination -- feste Auswahlliste statt freier Zahl, damit ein Merchant
// nicht versehentlich eine riesige Seitengröße in die URL tippt.
// -----------------------------------------------------------------------
$pageSizeOptions = [10, 25, 50, 100];
$pageSize = (int) ($_GET['page_size'] ?? 25);
if (!in_array($pageSize, $pageSizeOptions, true)) {
    $pageSize = 25;
}
$page = max(1, (int) ($_GET['page'] ?? 1));

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM connect_stock_sync_log WHERE shop = :shop');
$countStmt->execute(['shop' => $shop]);
$totalCount = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalCount / $pageSize));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $pageSize;

// $pageSize/$offset sind bereits auf feste Werte geclampt (siehe oben) --
// direkt interpoliert statt gebunden, LIMIT/OFFSET als Platzhalter ist mit
// PDO treiberabhängig nicht immer unterstützt.
$logStmt = $pdo->prepare(
    'SELECT match_value, label, direction, old_quantity, new_quantity, created_at
     FROM connect_stock_sync_log
     WHERE shop = :shop
     ORDER BY created_at DESC, id DESC
     LIMIT ' . $pageSize . ' OFFSET ' . $offset
);
$logStmt->execute(['shop' => $shop]);
$logRows = $logStmt->fetchAll();

// -----------------------------------------------------------------------
// Status-Karte: Konfiguration + letzter Lauf (gleiche Datenquelle wie die
// "Lagerbestand"-Karte in _sync_mappings_body.php, dort aber ohne
// Zahlen -- hier zusätzlich matched/written/failed des letzten Laufs).
// -----------------------------------------------------------------------
$shopSettingsStmt = $pdo->prepare(
    'SELECT s.comparison_key, s.target_connection_id, s.stock_sync_enabled, s.stock_sync_direction,
            c.label AS target_label, t.name AS target_type_name
     FROM connect_shops s
     LEFT JOIN connect_connections c ON c.id = s.target_connection_id
     LEFT JOIN connect_connector_types t ON t.id = c.connector_type_id
     WHERE s.shop = :shop'
);
$shopSettingsStmt->execute(['shop' => $shop]);
$shopSettings = $shopSettingsStmt->fetch();

$lastRunStmt = $pdo->prepare(
    "SELECT r.status, r.started_at, r.records_read AS matched, r.records_written AS written, r.records_failed AS failed
     FROM connect_sync_runs r
     JOIN connect_sync_mappings m ON m.id = r.sync_mapping_id
     WHERE m.shop = :shop AND m.mapping_kind = 'stock_sync'
     ORDER BY r.id DESC LIMIT 1"
);
$lastRunStmt->execute(['shop' => $shop]);
$lastRun = $lastRunStmt->fetch();

$statusBadge = [
    'success' => 'bg-green-lt',
    'error' => 'bg-red-lt',
    'running' => 'bg-yellow-lt',
    'partial' => 'bg-yellow-lt',
];

$directionLabel = [
    'shopify_to_target' => 'Shopify &rarr; Zielsystem',
    'target_to_shopify' => 'Zielsystem &rarr; Shopify',
];

$pageTitle = 'Lagerbestände' . ($isAdmin ? ' — ' . $shop : '');
$navItems = require __DIR__ . '/_shop_nav.php';

if ($isAdmin) {
    $homeUrl = 'shops.php';
    $adminNavItems = require dirname(__DIR__) . '/admin/_nav.php';
    $adminEmail = (new \Connect\Auth\AdminAuth($pdo))->currentEmail();
    $topRightHtml = '<span class="text-secondary me-3">' . htmlspecialchars((string) $adminEmail) . '</span>'
        . '<a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';
}

require __DIR__ . '/_header.php';
?>

<div class="row row-cards">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Status</h3>
            </div>
            <div class="card-body">
                <?php if ($shopSettings === false || $shopSettings['target_connection_id'] === null): ?>
                    <div class="alert alert-info mb-0">
                        Noch kein Zielsystem verbunden<?php echo $isAdmin ? '.' : ' — bitte unter "Einstellungen" einrichten.'; ?>
                    </div>
                <?php else: ?>
                    <div class="row">
                        <div class="col-sm-6 col-lg-3">
                            <div class="text-secondary">Zielsystem</div>
                            <div><?php echo htmlspecialchars((string) $shopSettings['target_label'] . ' (' . $shopSettings['target_type_name'] . ')'); ?></div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="text-secondary">Richtung</div>
                            <div><?php echo $directionLabel[$shopSettings['stock_sync_direction']] ?? htmlspecialchars((string) $shopSettings['stock_sync_direction']); ?></div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="text-secondary">Automatischer Abgleich</div>
                            <div>
                                <span class="badge <?php echo ((int) $shopSettings['stock_sync_enabled']) === 1 ? 'bg-green-lt' : 'bg-secondary-lt'; ?>">
                                    <?php echo ((int) $shopSettings['stock_sync_enabled']) === 1 ? 'aktiv' : 'inaktiv'; ?>
                                </span>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="text-secondary">Letzter Lauf</div>
                            <div>
                                <?php if ($lastRun === false): ?>
                                    noch nie gelaufen
                                <?php else: ?>
                                    <span class="badge <?php echo htmlspecialchars($statusBadge[$lastRun['status']] ?? 'bg-secondary-lt'); ?>">
                                        <?php echo htmlspecialchars((string) $lastRun['status']); ?>
                                    </span>
                                    <?php echo htmlspecialchars((string) $lastRun['started_at']); ?>
                                    <div class="text-secondary">
                                        <?php echo (int) $lastRun['matched']; ?> gematcht,
                                        <?php echo (int) $lastRun['written']; ?> geschrieben,
                                        <?php echo (int) $lastRun['failed']; ?> fehlgeschlagen
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between w-100">
                <h3 class="card-title mb-0">Änderungshistorie</h3>
                <form method="get" class="d-flex align-items-center gap-2">
                    <input type="hidden" name="shop" value="<?php echo htmlspecialchars($shop); ?>">
                    <label class="text-secondary mb-0">pro Seite</label>
                    <select name="page_size" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
                        <?php foreach ($pageSizeOptions as $option): ?>
                            <option value="<?php echo $option; ?>" <?php echo $pageSize === $option ? 'selected' : ''; ?>><?php echo $option; ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <?php if (count($logRows) === 0): ?>
                <div class="card-body text-secondary">
                    <?php echo $totalCount === 0 ? 'Noch keine Änderungen protokolliert.' : 'Keine Einträge auf dieser Seite.'; ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table table-striped">
                        <thead>
                            <tr>
                                <th>Datum</th>
                                <th>Produkt</th>
                                <th>Vergleichswert</th>
                                <th>Richtung</th>
                                <th class="text-end">Lagerbestand</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logRows as $row): ?>
                                <tr>
                                    <td class="text-secondary text-nowrap"><?php echo htmlspecialchars((string) $row['created_at']); ?></td>
                                    <td><?php echo htmlspecialchars((string) ($row['label'] ?? $row['match_value'])); ?></td>
                                    <td class="text-secondary"><a href="stock_history.php?shop=<?php echo urlencode($shop); ?><?php echo isset($_GET['preview']) ? '&amp;preview=1' : ''; ?>&amp;match=<?php echo urlencode((string) $row['match_value']); ?>" title="Bestandsverlauf ansehen"><?php echo \Connect\Ui\Icon::svg('history', 14, 'me-1'); ?><?php echo htmlspecialchars((string) $row['match_value']); ?></a></td>
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
                <div class="card-footer d-flex align-items-center justify-content-between">
                    <span class="text-secondary"><?php echo $totalCount; ?> Einträge — Seite <?php echo $page; ?> von <?php echo $totalPages; ?></span>
                    <ul class="pagination mb-0">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?shop=<?php echo urlencode($shop); ?>&amp;page_size=<?php echo $pageSize; ?>&amp;page=<?php echo max(1, $page - 1); ?>">Zurück</a>
                        </li>
                        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?shop=<?php echo urlencode($shop); ?>&amp;page_size=<?php echo $pageSize; ?>&amp;page=<?php echo min($totalPages, $page + 1); ?>">Weiter</a>
                        </li>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
