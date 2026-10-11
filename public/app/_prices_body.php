<?php

declare(strict_types=1);

/**
 * connect/public/app/_prices_body.php
 *
 * Merchant-Seite "Preise" (Migration 0087/0088), geteilt mit admin/prices.php:
 *   ?tab=sync     Preissynchronisation Kasse <-> Shopify: Richtung, Grenze, Vorschau aller
 *                 Unterschiede, Bestaetigen, Ausnahmen (Connect\Sync\PriceSyncRunner)
 *   ?tab=bulk     Sammelaenderung: Filter, Aktion, Rundung, Ziel -> Vorschau -> Ausfuehren im
 *                 Hintergrund (Connect\Sync\BatchEditService)
 *   ?tab=history  Verlauf aller Preisaenderungen (Sammelaenderungen + Synchronisation), Rueckgaengig
 * Freigabe je Abo: Connect\Billing\Plans::allowsPriceTools (Admin immer).
 *
 * Erwartet vor dem Include: $app (Connect\AppContext), optional $isAdmin.
 */

use Connect\Billing\Plans;
use Connect\Connector\ConnectorFactory;
use Connect\Http\HttpClient;
use Connect\Shopify\TokenRefresher;
use Connect\Sync\BatchEditService;
use Connect\Sync\PriceSyncRunner;
use Connect\Sync\PriceWriter;
use Connect\Sync\ProductMatchContext;
use Connect\Sync\ShopStockRules;

$isAdmin = $isAdmin ?? false;
$pdo = $app->pdo;
$shop = $app->shop;
$previewSuffix = isset($_GET['preview']) ? '&preview=1' : '';
$q = '?shop=' . urlencode($shop) . $previewSuffix;
$e = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES);
$money = static fn (?float $v): string => $v === null ? '–' : number_format($v, 2, ',', '.');

$factory = new ConnectorFactory($pdo, $app->crypto, new HttpClient(), new TokenRefresher(
    new HttpClient(),
    $app->config->optional('CONNECT_SHOPIFY_API_KEY'),
    $app->config->optional('CONNECT_SHOPIFY_API_SECRET')
));
$batchService = new BatchEditService($pdo, $factory);
$syncRunner = new PriceSyncRunner($pdo, $factory);

$plan = Plans::forShop($pdo, $shop);
$allowed = $isAdmin || Plans::allowsPriceTools($plan);
$createdBy = $isAdmin ? (string) (new \Connect\Auth\AdminAuth($pdo))->currentEmail() : 'Händler';
$tab = in_array($_GET['tab'] ?? '', ['sync', 'bulk', 'history'], true) ? (string) $_GET['tab'] : 'sync';

$flashMessage = null;
$flashType = 'info';
$migrationReady = true;
try {
    $pdo->query('SELECT 1 FROM connect_batch_changes LIMIT 1');
    $pdo->query('SELECT price_sync_mode FROM connect_shops LIMIT 1');
} catch (\PDOException) {
    $migrationReady = false;
}

// Kann ins Zielsystem geschrieben werden? (Operation "update_price" im Connector Builder)
$canWriteTarget = false;
$targetName = 'Kassensystem';
try {
    $context = ProductMatchContext::resolve($pdo, $factory, $shop);
    $canWriteTarget = (new PriceWriter($pdo, $context, ShopStockRules::forShop($pdo, $shop)['prices_gross']))->canWriteTarget();
    $targetName = (string) ($pdo->query('SELECT t.name FROM connect_connections c JOIN connect_connector_types t ON t.id = c.connector_type_id WHERE c.id = ' . (int) $context->targetConnectionId)->fetchColumn() ?: $targetName);
} catch (\Throwable) {
    $context = null;
}

// ---- Live-Zaehlung fuer die Sammelaenderung (?bulk_count=1, nur lesend) -------------
// Die Filter der Sammelaenderung fragen beim Tippen/Auswaehlen nur die Anzahl ab (JSON), damit
// sofort sichtbar ist, wie viele Artikel betroffen waeren -- ohne "Vorschau anzeigen".
if (isset($_GET['bulk_count'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!$allowed || !$migrationReady) {
        echo json_encode(['error' => 'nicht verfügbar']);
        exit;
    }
    try {
        $countFilter = [];
        foreach (['q', 'vendor', 'product_type', 'status', 'price_min', 'price_max'] as $key) {
            $countFilter[$key] = trim((string) ($_GET[$key] ?? ''));
        }
        $countRows = BatchEditService::filter($batchService->candidates($shop), $countFilter);
        echo json_encode([
            'count' => count($countRows),
            'with_price' => count(array_filter($countRows, static fn (array $r): bool => $r['shopify_price'] !== null)),
        ]);
    } catch (\Throwable $ex) {
        echo json_encode(['error' => $ex->getMessage()]);
    }
    exit;
}

// ---- Aktionen -------------------------------------------------------------------------
$bulkPreview = null;
$bulkInput = [];
if ($allowed && $migrationReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'save_sync' && !\Connect\Sync\TargetCapabilities::supports($pdo, $shop, 'price_sync')) {
            throw new \RuntimeException(\Connect\Sync\TargetCapabilities::reason($pdo, $shop, 'price_sync'));
        } elseif ($action === 'save_sync') {
            $mode = in_array($_POST['price_sync_mode'] ?? '', ['off', 'target_to_shopify', 'shopify_to_target'], true) ? (string) $_POST['price_sync_mode'] : 'off';
            if ($mode === 'shopify_to_target' && !$canWriteTarget) {
                throw new \RuntimeException('In das ' . $targetName . ' kann Connect noch keine Preise schreiben – bitte melde dich bei uns, dann richten wir das ein.');
            }
            $old = ShopStockRules::forShop($pdo, $shop);
            $pdo->prepare('UPDATE connect_shops SET price_sync_mode = :mode, price_sync_max_change_pct = :pct, price_sync_confirmed_at = IF(:changed = 1, NULL, price_sync_confirmed_at) WHERE shop = :shop')
                ->execute(['mode' => $mode, 'pct' => max(0, min(1000, (int) ($_POST['price_sync_max_change_pct'] ?? 30))), 'changed' => $old['price_mode'] !== $mode ? 1 : 0, 'shop' => $shop]);
            $flashMessage = $mode === 'off' ? 'Preissynchronisation ausgeschaltet.' : 'Gespeichert. Bitte prüfe unten die Unterschiede und bestätige sie – erst dann gleicht Connect Preise an.';
            $flashType = 'success';
        } elseif ($action === 'confirm_sync') {
            $pdo->prepare("UPDATE connect_shops SET price_sync_confirmed_at = CURRENT_TIMESTAMP, price_sync_last_run_at = NULL WHERE shop = :shop AND price_sync_mode <> 'off'")->execute(['shop' => $shop]);
            $flashMessage = 'Bestätigt – Connect gleicht die Preise ab dem nächsten Lauf (innerhalb weniger Minuten) an. Den Fortschritt siehst du unter „Verlauf“.';
            $flashType = 'success';
        } elseif ($action === 'toggle_exclusion') {
            $syncRunner->setExcluded($shop, (string) ($_POST['match_value'] ?? ''), ($_POST['excluded'] ?? '') === '1');
            $flashMessage = ($_POST['excluded'] ?? '') === '1' ? 'Artikel wird nicht mehr angeglichen.' : 'Artikel wird wieder angeglichen.';
            $flashType = 'success';
        } elseif ($action === 'bulk_preview' || $action === 'bulk_queue') {
            $tab = 'bulk';
            $bulkInput = [
                'q' => trim((string) ($_POST['q'] ?? '')),
                'vendor' => trim((string) ($_POST['vendor'] ?? '')),
                'product_type' => trim((string) ($_POST['product_type'] ?? '')),
                'status' => trim((string) ($_POST['status'] ?? '')),
                'price_min' => trim((string) ($_POST['price_min'] ?? '')),
                'price_max' => trim((string) ($_POST['price_max'] ?? '')),
                'type' => isset(BatchEditService::ACTIONS[$_POST['type'] ?? '']) ? (string) $_POST['type'] : 'percent',
                'value' => (float) str_replace(',', '.', (string) ($_POST['value'] ?? '0')),
                'rounding' => isset(BatchEditService::ROUNDING[$_POST['rounding'] ?? '']) ? (string) $_POST['rounding'] : 'none',
                'apply_to' => in_array($_POST['apply_to'] ?? '', ['shopify', 'target', 'both'], true) ? (string) $_POST['apply_to'] : 'shopify',
            ];
            if ($bulkInput['apply_to'] !== 'shopify' && !$canWriteTarget) {
                throw new \RuntimeException('In das ' . $targetName . ' kann Connect noch keine Preise schreiben – bitte nur „Shopify“ wählen oder melde dich bei uns.');
            }
            $filterInput = array_intersect_key($bulkInput, array_flip(['q', 'vendor', 'product_type', 'status', 'price_min', 'price_max']));
            $actionInput = ['type' => $bulkInput['type'], 'value' => $bulkInput['value'], 'rounding' => $bulkInput['rounding']];
            $rows = BatchEditService::filter($batchService->candidates($shop), $filterInput);
            $items = BatchEditService::preview($rows, $actionInput, $bulkInput['apply_to']);
            if ($action === 'bulk_queue') {
                $excluded = array_flip(array_map('strval', (array) ($_POST['exclude'] ?? [])));
                $items = array_values(array_filter($items, static fn (array $i): bool => !isset($excluded[$i['side'] . '|' . $i['match_value']])));
                if (empty($_POST['confirm'])) {
                    $bulkPreview = $items;
                    throw new \RuntimeException('Bitte bestätigen (Häkchen), dass die Preise geändert werden sollen.');
                }
                if ($items === []) {
                    throw new \RuntimeException('Keine Preise zu ändern.');
                }
                $title = BatchEditService::ACTIONS[$actionInput['type']] . ' ' . str_replace('.', ',', (string) $actionInput['value'])
                    . ($actionInput['rounding'] !== 'none' ? ', ' . BatchEditService::ROUNDING[$actionInput['rounding']] : '') . ' – ' . count($items) . ' Preise';
                $batchId = $batchService->queue($shop, 'batch', $title, $filterInput, $actionInput, $bulkInput['apply_to'], $items, $createdBy);
                $flashMessage = 'Sammeländerung #' . $batchId . ' gestartet: ' . count($items) . ' Preise werden im Hintergrund geändert (meist wenige Minuten). Fortschritt und Rückgängig unter „Verlauf“.';
                $flashType = 'success';
                $tab = 'history';
            } else {
                $bulkPreview = $items;
            }
        } elseif ($action === 'undo') {
            $undoId = $batchService->undo($shop, (int) ($_POST['batch_id'] ?? 0), $createdBy);
            $flashMessage = $undoId !== null ? 'Wird rückgängig gemacht (Auftrag #' . $undoId . ').' : 'Diese Änderung kann nicht (mehr) rückgängig gemacht werden.';
            $flashType = $undoId !== null ? 'success' : 'warning';
            $tab = 'history';
        }
    } catch (\Throwable $ex) {
        $flashMessage = $ex->getMessage();
        $flashType = 'danger';
    }
}

$rules = ShopStockRules::forShop($pdo, $shop);

$pageTitle = 'Preise' . ($isAdmin ? ' — ' . $shop : '');
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

<?php if (!$migrationReady): ?>
    <div class="alert alert-warning">Migrationen 0087/0088 fehlen noch.</div>
<?php elseif (!$allowed): ?>
    <div class="card"><div class="card-body">
        <h3 class="card-title"><?php echo \Connect\Ui\Icon::svg('tag', 20, 'me-1'); ?>Preise synchronisieren und gesammelt ändern</h3>
        <p class="text-secondary">Preise zwischen <?php echo $e($targetName); ?> und Shopify automatisch angleichen, Preise für viele Artikel auf einmal erhöhen oder einen Sale starten – mit Vorschau und Rückgängig. Enthalten ab dem Abo <strong><?php echo $e(Plans::lowestPlanFor('price_tools')); ?></strong>.</p>
        <a href="billing.php<?php echo $e($q); ?>" class="btn btn-primary">Abo ansehen</a>
    </div></div>
<?php else: ?>

<ul class="nav nav-tabs mb-3">
    <?php foreach (['sync' => ['refresh', 'Preissynchronisation'], 'bulk' => ['tag', 'Sammeländerung'], 'history' => ['history', 'Verlauf']] as $key => [$icon, $label]): ?>
        <li class="nav-item"><a class="nav-link<?php echo $tab === $key ? ' active' : ''; ?>" href="<?php echo $e($q . '&tab=' . $key); ?>"><?php echo \Connect\Ui\Icon::svg($icon, 16, 'me-1'); ?><?php echo $e($label); ?></a></li>
    <?php endforeach; ?>
</ul>

<?php if ($tab === 'sync' && !\Connect\Sync\TargetCapabilities::supports($pdo, $shop, 'price_sync')): ?>
    <div class="alert alert-info">
        <?php echo $e(\Connect\Sync\TargetCapabilities::reason($pdo, $shop, 'price_sync')); ?>
        <a href="<?php echo $e($q . '&tab=bulk'); ?>" class="ms-1">Zur Sammeländerung</a>
    </div>
<?php elseif ($tab === 'sync'): ?>
    <?php $syncPreview = $syncRunner->preview($shop, $rules['price_mode'] === 'off' ? 'target_to_shopify' : $rules['price_mode']); ?>
    <div class="row row-cards">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><h3 class="card-title">Einstellung</h3></div>
                <div class="card-body">
                    <form method="post" class="d-grid gap-3">
                        <input type="hidden" name="action" value="save_sync">
                        <div>
                            <?php foreach ([
                                'off' => ['Aus', 'Preise werden nicht angeglichen (Unterschiede siehst du trotzdem rechts und unter Produkte).'],
                                'target_to_shopify' => [$targetName . ' führt', 'Preise aus dem ' . $targetName . ' werden nach Shopify übernommen.'],
                                'shopify_to_target' => ['Shopify führt', 'Preise aus Shopify werden ins ' . $targetName . ' übernommen.' . ($canWriteTarget ? '' : ' (noch nicht eingerichtet)')],
                            ] as $mode => [$label, $help]): ?>
                                <label class="form-check">
                                    <input type="radio" class="form-check-input" name="price_sync_mode" value="<?php echo $mode; ?>" <?php echo $rules['price_mode'] === $mode ? 'checked' : ''; ?> <?php echo $mode === 'shopify_to_target' && !$canWriteTarget ? 'disabled' : ''; ?>>
                                    <span class="form-check-label"><strong><?php echo $e($label); ?></strong><br><span class="text-secondary small"><?php echo $e($help); ?></span></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div>
                            <label class="form-label" for="pct">Große Sprünge zur Prüfung zurückhalten ab</label>
                            <div class="input-group" style="max-width: 200px">
                                <input id="pct" type="number" min="0" max="1000" name="price_sync_max_change_pct" class="form-control" value="<?php echo (int) $rules['price_max_pct']; ?>">
                                <span class="input-group-text">%</span>
                            </div>
                            <small class="text-secondary">Schutz vor Tippfehlern: Änderungen über diesem Wert gleicht Connect nicht automatisch an. 0 = keine Grenze.</small>
                        </div>
                        <div><button type="submit" class="btn btn-primary">Speichern</button></div>
                    </form>
                </div>
                <?php if ($rules['price_mode'] !== 'off'): ?>
                    <div class="card-footer">
                        <?php if ($rules['price_confirmed']): ?>
                            <span class="text-success"><?php echo \Connect\Ui\Icon::svg('circle-check', 16, 'me-1'); ?>Aktiv – Connect gleicht die Preise bei jedem Abgleich an.</span>
                        <?php else: ?>
                            <form method="post" class="d-flex gap-2 align-items-center flex-wrap m-0">
                                <input type="hidden" name="action" value="confirm_sync">
                                <span class="text-warning">Noch nicht bestätigt – es wird nichts geändert.</span>
                                <button type="submit" class="btn btn-primary btn-sm ms-auto" onclick="return confirm('Die angezeigten Unterschiede angleichen? Zurückgehaltene und ausgenommene Artikel bleiben unverändert.')">Unterschiede geprüft – einschalten</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Unterschiede (<?php echo count($syncPreview['rows']); ?>)</h3>
                    <div class="card-actions small text-secondary"><?php echo $rules['price_mode'] === 'shopify_to_target' ? 'Shopify → ' . $e($targetName) : $e($targetName) . ' → Shopify'; ?></div>
                </div>
                <?php if ($syncPreview['error'] !== null): ?>
                    <div class="card-body text-secondary"><?php echo $e($syncPreview['error']); ?></div>
                <?php elseif ($syncPreview['rows'] === []): ?>
                    <div class="card-body text-success"><?php echo \Connect\Ui\Icon::svg('circle-check', 16, 'me-1'); ?>Alle zugeordneten Preise stimmen überein.</div>
                <?php else: ?>
                    <div class="table-responsive" style="max-height: 560px; overflow-y: auto;">
                        <table class="table table-vcenter card-table table-sm">
                            <thead><tr><th>Artikel</th><th class="text-end">Shopify</th><th class="text-end"><?php echo $e($targetName); ?></th><th class="text-end">Änderung</th><th></th></tr></thead>
                            <tbody>
                                <?php foreach (array_slice($syncPreview['rows'], 0, 500) as $row): ?>
                                    <tr class="<?php echo $row['excluded'] ? 'text-secondary' : ''; ?>">
                                        <td><?php echo $e($row['label']); ?><div class="small text-secondary"><?php echo $e($row['match_value']); ?></div></td>
                                        <td class="text-end<?php echo $row['side'] === 'shopify' ? ' fw-bold' : ''; ?>"><?php echo $money($row['shopify_price']); ?></td>
                                        <td class="text-end<?php echo $row['side'] === 'target' ? ' fw-bold' : ''; ?>"><?php echo $money($row['target_price']); ?></td>
                                        <td class="text-end small">
                                            <?php if ($row['excluded']): ?><span class="badge bg-secondary-lt">ausgenommen</span>
                                            <?php elseif ($row['held']): ?><span class="badge bg-yellow-lt" title="über der Grenze – wird nicht automatisch angeglichen"><?php echo str_replace('.', ',', (string) $row['change_pct']); ?> % · zur Prüfung</span>
                                            <?php else: ?><?php echo str_replace('.', ',', (string) $row['change_pct']); ?> %<?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <form method="post" class="m-0">
                                                <input type="hidden" name="action" value="toggle_exclusion">
                                                <input type="hidden" name="match_value" value="<?php echo $e($row['match_value']); ?>">
                                                <input type="hidden" name="excluded" value="<?php echo $row['excluded'] ? '0' : '1'; ?>">
                                                <button type="submit" class="btn btn-sm btn-ghost-secondary"><?php echo $row['excluded'] ? 'wieder angleichen' : 'nie angleichen'; ?></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer small text-secondary">Fett = der Preis, der geändert wird. Zurückgehaltene Sprünge kannst du über „Sammeländerung“ gezielt anpassen oder die Grenze erhöhen.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php elseif ($tab === 'bulk'): ?>
    <?php
    try {
        $allCandidates = $batchService->candidates($shop);
    } catch (\Throwable $ex) {
        $allCandidates = [];
        echo '<div class="alert alert-warning">' . $e($ex->getMessage()) . '</div>';
    }
    $vendors = array_values(array_unique(array_filter(array_column($allCandidates, 'vendor'))));
    $types = array_values(array_unique(array_filter(array_column($allCandidates, 'product_type'))));
    sort($vendors, SORT_NATURAL | SORT_FLAG_CASE);
    sort($types, SORT_NATURAL | SORT_FLAG_CASE);
    $in = $bulkInput + ['q' => '', 'vendor' => '', 'product_type' => '', 'status' => '', 'price_min' => '', 'price_max' => '', 'type' => 'percent', 'value' => 0, 'rounding' => 'none', 'apply_to' => 'shopify'];
    ?>
    <form method="post">
        <div class="row row-cards">
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">1 · Artikel auswählen</h3>
                        <div class="card-actions small text-secondary" id="bulk-count" data-total="<?php echo count($allCandidates); ?>"><?php echo count($allCandidates); ?> zugeordnete Artikel</div>
                    </div>
                    <div class="card-body row g-2">
                        <div class="col-12"><input type="search" name="q" class="form-control" placeholder="Name, SKU oder Vergleichswert enthält …" value="<?php echo $e($in['q']); ?>"></div>
                        <div class="col-md-6">
                            <select name="vendor" class="form-select" aria-label="Hersteller"><option value="">Hersteller: alle</option>
                                <?php foreach ($vendors as $v): ?><option <?php echo $in['vendor'] === $v ? 'selected' : ''; ?>><?php echo $e($v); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <select name="product_type" class="form-select" aria-label="Produkttyp"><option value="">Produkttyp: alle</option>
                                <?php foreach ($types as $t): ?><option <?php echo $in['product_type'] === $t ? 'selected' : ''; ?>><?php echo $e($t); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <select name="status" class="form-select" aria-label="Status">
                                <?php foreach (['' => 'Status: alle', 'active' => 'aktiv', 'draft' => 'Entwurf', 'archived' => 'archiviert'] as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo $in['status'] === $k ? 'selected' : ''; ?>><?php echo $e($l); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4"><input type="text" name="price_min" class="form-control" placeholder="Preis ab" value="<?php echo $e($in['price_min']); ?>"></div>
                        <div class="col-md-4"><input type="text" name="price_max" class="form-control" placeholder="Preis bis" value="<?php echo $e($in['price_max']); ?>"></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header"><h3 class="card-title">2 · Änderung</h3></div>
                    <div class="card-body row g-2">
                        <div class="col-md-8">
                            <select name="type" class="form-select" aria-label="Aktion">
                                <?php foreach (BatchEditService::ACTIONS as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo $in['type'] === $k ? 'selected' : ''; ?>><?php echo $e($l); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4"><input type="text" name="value" class="form-control" placeholder="z. B. 5 oder -10" value="<?php echo $e(str_replace('.', ',', (string) $in['value'])); ?>" aria-label="Wert"></div>
                        <div class="col-md-6">
                            <select name="rounding" class="form-select" aria-label="Rundung">
                                <?php foreach (BatchEditService::ROUNDING as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo $in['rounding'] === $k ? 'selected' : ''; ?>>Rundung: <?php echo $e($l); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <select name="apply_to" class="form-select" aria-label="Wo ändern">
                                <option value="shopify" <?php echo $in['apply_to'] === 'shopify' ? 'selected' : ''; ?>>nur in Shopify</option>
                                <option value="both" <?php echo $in['apply_to'] === 'both' ? 'selected' : ''; ?> <?php echo $canWriteTarget ? '' : 'disabled'; ?>>Shopify und <?php echo $e($targetName); ?></option>
                                <option value="target" <?php echo $in['apply_to'] === 'target' ? 'selected' : ''; ?> <?php echo $canWriteTarget ? '' : 'disabled'; ?>>nur im <?php echo $e($targetName); ?></option>
                            </select>
                        </div>
                        <div class="col-12 small text-secondary">Prozent und Betrag: positiv = teurer, negativ = günstiger. Sale: der bisherige Preis wird als durchgestrichener Vergleichspreis gezeigt, „Rückgängig“ beendet den Sale.</div>
                        <div class="col-12"><button type="submit" name="action" value="bulk_preview" class="btn btn-outline-primary">Vorschau anzeigen</button></div>
                    </div>
                </div>
            </div>

            <?php if ($bulkPreview !== null): ?>
                <div class="col-12">
                    <div class="card">
                        <div class="card-header"><h3 class="card-title">3 · Vorschau: <?php echo count($bulkPreview); ?> Preise</h3></div>
                        <?php if ($bulkPreview === []): ?>
                            <div class="card-body text-secondary">Mit dieser Auswahl ändert sich kein Preis.</div>
                        <?php else: ?>
                            <div class="table-responsive" style="max-height: 520px; overflow-y: auto;">
                                <table class="table table-vcenter card-table table-sm">
                                    <thead><tr><th class="w-1"></th><th>Artikel</th><th>Wo</th><th class="text-end">bisher</th><th class="text-end">neu</th><th class="text-end">Vergleichspreis</th></tr></thead>
                                    <tbody>
                                        <?php foreach (array_slice($bulkPreview, 0, 1000) as $item): ?>
                                            <?php $key = $item['side'] . '|' . $item['match_value']; ?>
                                            <tr>
                                                <td>
                                                    <input type="checkbox" class="form-check-input" checked onchange="this.nextElementSibling.disabled = this.checked" aria-label="einbeziehen">
                                                    <input type="hidden" name="exclude[]" value="<?php echo $e($key); ?>" disabled>
                                                </td>
                                                <td><?php echo $e($item['label']); ?><div class="small text-secondary"><?php echo $e($item['match_value']); ?></div></td>
                                                <td class="small"><?php echo $item['side'] === 'shopify' ? 'Shopify' : $e($targetName); ?></td>
                                                <td class="text-end"><?php echo $money($item['old_price']); ?></td>
                                                <td class="text-end fw-bold"><?php echo $money($item['new_price']); ?></td>
                                                <td class="text-end text-secondary"><?php echo $item['set_compare_at'] ? $money($item['new_compare_at']) : '–'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="card-footer d-flex gap-3 align-items-center flex-wrap">
                                <label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="confirm" value="1"> <span class="form-check-label">Diese Preise ändern</span></label>
                                <button type="submit" name="action" value="bulk_queue" class="btn btn-primary ms-auto">Ausführen</button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </form>

    <script>
        // Live-Zaehlung: nach jeder Filter-Aenderung (verzoegert) die Anzahl betroffener Artikel holen
        (function () {
            var box = document.getElementById('bulk-count');
            if (!box) { return; }
            var form = box.closest('form');
            var total = box.dataset.total;
            var names = ['q', 'vendor', 'product_type', 'status', 'price_min', 'price_max'];
            var timer = null;
            var seq = 0;
            function update() {
                var params = new URLSearchParams(window.location.search);
                params.set('bulk_count', '1');
                var any = false;
                names.forEach(function (n) {
                    var el = form.elements[n];
                    var v = el ? el.value.trim() : '';
                    params.set(n, v);
                    if (v !== '') { any = true; }
                });
                if (!any) { box.textContent = total + ' zugeordnete Artikel'; return; }
                box.textContent = 'zähle …';
                var mine = ++seq;
                fetch(window.location.pathname + '?' + params.toString(), { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (mine !== seq) { return; } // eine neuere Eingabe ist unterwegs
                        if (data.error) { box.textContent = total + ' zugeordnete Artikel'; return; }
                        box.innerHTML = '<strong>' + data.count + '</strong> von ' + total + ' Artikeln betroffen'
                            + (data.with_price < data.count ? ' <span class="text-secondary">(' + (data.count - data.with_price) + ' ohne Preis)</span>' : '');
                    })
                    .catch(function () { box.textContent = total + ' zugeordnete Artikel'; });
            }
            names.forEach(function (n) {
                var el = form.elements[n];
                if (!el) { return; }
                el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', function () {
                    clearTimeout(timer);
                    timer = setTimeout(update, 400);
                });
            });
            update(); // nach "Vorschau anzeigen" stehen die Filter schon im Formular
        })();
    </script>

<?php else: ?>
    <?php $history = $batchService->history($shop); ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Wann</th><th>Änderung</th><th>Wo</th><th>Fortschritt</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($history as $b): ?>
                        <tr>
                            <td class="small text-secondary"><?php echo $e(date('d.m.Y H:i', (int) strtotime((string) $b['created_at']))); ?><div><?php echo $e((string) $b['created_by']); ?></div></td>
                            <td>
                                <?php echo $e((string) $b['title']); ?>
                                <?php if ($b['kind'] === 'sync'): ?><span class="badge bg-blue-lt ms-1">Synchronisation</span><?php elseif ($b['kind'] === 'undo'): ?><span class="badge bg-secondary-lt ms-1">Rückgängig</span><?php endif; ?>
                            </td>
                            <td class="small"><?php echo $e(['shopify' => 'Shopify', 'target' => $targetName, 'both' => 'Shopify + ' . $targetName][$b['apply_to']] ?? $b['apply_to']); ?></td>
                            <td class="small">
                                <?php echo ['queued' => '<span class="badge bg-secondary-lt">wartet</span>', 'running' => '<span class="badge bg-blue-lt">läuft</span>', 'done' => '<span class="badge bg-green-lt">fertig</span>', 'failed' => '<span class="badge bg-red-lt">fehlgeschlagen</span>', 'undone' => '<span class="badge bg-secondary-lt">rückgängig gemacht</span>'][$b['status']] ?? $e($b['status']); ?>
                                <?php echo (int) $b['done']; ?> / <?php echo (int) $b['total']; ?><?php echo (int) $b['failed'] > 0 ? ' · <span class="text-danger">' . (int) $b['failed'] . ' Fehler</span>' : ''; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($b['status'] === 'done' && $b['kind'] !== 'undo' && (int) $b['done'] > 0): ?>
                                    <form method="post" class="m-0">
                                        <input type="hidden" name="action" value="undo">
                                        <input type="hidden" name="batch_id" value="<?php echo (int) $b['id']; ?>">
                                        <button type="submit" class="btn btn-sm" onclick="return confirm('Alle Preise dieser Änderung auf den vorherigen Stand zurücksetzen?')">Rückgängig</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($history === []): ?>
                        <tr><td colspan="5" class="text-secondary">Noch keine Preisänderungen.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
