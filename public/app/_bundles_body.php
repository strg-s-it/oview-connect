<?php

declare(strict_types=1);

/**
 * connect/public/app/_bundles_body.php
 *
 * Sets und Bundles (Connect\Sync\BundleService, Migration 0091): Sets anlegen/bearbeiten/loeschen,
 * je Set Einzelteile mit Kassenbestand, daraus verfuegbare Sets und aktueller Shopify-Bestand.
 * Geteilt mit admin/bundles.php. Freigabe je Abo (Plans::FEATURES['bundles']), nur bei Richtung
 * "Kassensystem fuehrt".
 *
 * Erwartet vor dem Include: $app (Connect\AppContext), optional $isAdmin.
 */

use Connect\Billing\Plans;
use Connect\Connector\ConnectorFactory;
use Connect\Http\HttpClient;
use Connect\Shopify\TokenRefresher;
use Connect\Sync\BundleService;
use Connect\Sync\ObjectCache;
use Connect\Sync\ProductMatchContext;
use Connect\Sync\ShopStockRules;
use Connect\Ui\Icon;

$isAdmin = $isAdmin ?? false;
$pdo = $app->pdo;
$shop = $app->shop;
$previewSuffix = isset($_GET['preview']) ? '&preview=1' : '';
$base = '?shop=' . urlencode($shop) . $previewSuffix;
$e = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES);

$service = new BundleService($pdo);
$allowed = $isAdmin || Plans::allowsFeature(Plans::forShop($pdo, $shop), 'bundles');
$flashMessage = null;
$flashType = 'info';

// Kontext: Richtung, Vergleichswert, Bestaende beider Seiten
$context = null;
$shopifyItems = [];   // Vergleichswert => [label, qty]
$targetItems = [];    // Vergleichswert => [label, qty]
$contextError = null;
try {
    $factory = new ConnectorFactory($pdo, $app->crypto, new HttpClient(), new TokenRefresher(
        new HttpClient(),
        $app->config->optional('CONNECT_SHOPIFY_API_KEY'),
        $app->config->optional('CONNECT_SHOPIFY_API_SECRET')
    ));
    $context = ProductMatchContext::resolve($pdo, $factory, $shop);
    $cache = new ObjectCache($pdo);
    foreach ([[$context->shopifyConnectionId, $context->shopifyObject, $context->shopifyMatchField->key, $context->shopifyStockField->key, &$shopifyItems],
              [$context->targetConnectionId, $context->targetObject, $context->targetMatchField->key, $context->targetStockField->key, &$targetItems]] as [$connectionId, $object, $matchKey, $stockKey, &$target]) {
        $titleField = ProductMatchContext::titleField($object);
        foreach ($cache->getCached($connectionId, $object->key) as $record) {
            $value = (string) ($record[$matchKey] ?? '');
            if ($value === '' || ($record['_status'] ?? 'active') === 'orphaned') {
                continue;
            }
            $variant = (string) ($record['variant_title'] ?? '');
            $target[$value] = [
                'label' => ($titleField !== null ? (string) ($record[$titleField->key] ?? '') : '') . ($variant !== '' && $variant !== 'Default Title' ? ' – ' . $variant : ''),
                'qty' => array_key_exists($stockKey, $record) && $record[$stockKey] !== null ? (int) $record[$stockKey] : null,
            ];
        }
        unset($target);
    }
} catch (\Throwable $ex) {
    $contextError = $ex->getMessage();
}
$direction = $context?->direction ?? '';
$rules = ShopStockRules::forShop($pdo, $shop);

if ($allowed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (($_POST['action'] ?? '') === 'save_bundle') {
            $components = [];
            foreach ((array) ($_POST['component'] ?? []) as $i => $match) {
                $components[] = ['match' => trim((string) $match), 'quantity' => max(0, (int) ($_POST['component_qty'][$i] ?? 1))];
            }
            $bundleMatch = trim((string) ($_POST['bundle_match'] ?? ''));
            $unknown = array_filter($components, static fn (array $c): bool => $c['match'] !== '' && !isset($targetItems[$c['match']]));
            if ($unknown !== []) {
                throw new \RuntimeException('Diese Einzelteile gibt es im Kassensystem nicht: ' . implode(', ', array_column($unknown, 'match')) . '.');
            }
            if (!isset($shopifyItems[$bundleMatch])) {
                throw new \RuntimeException('Den Set-Artikel „' . $bundleMatch . '“ gibt es in Shopify nicht – bitte zuerst dort anlegen (Vergleichswert ' . ($context?->comparisonKey ?? '') . ').');
            }
            if (isset($targetItems[$bundleMatch])) {
                throw new \RuntimeException('„' . $bundleMatch . '“ gibt es auch im Kassensystem – ein Set darf dort nicht als eigener Artikel existieren, sonst gleicht Connect ihn normal ab.');
            }
            $service->save($shop, $bundleMatch, (string) ($_POST['label'] ?? ''), $components);
            $flashMessage = 'Set gespeichert. Der Bestand in Shopify wird beim nächsten Lagerabgleich gesetzt.';
            $flashType = 'success';
        } elseif (($_POST['action'] ?? '') === 'delete_bundle') {
            $service->delete($shop, (int) ($_POST['bundle_id'] ?? 0));
            $flashMessage = 'Set gelöscht. Der Shopify-Bestand des Artikels bleibt, wie er ist.';
            $flashType = 'success';
        }
    } catch (\Throwable $ex) {
        $flashMessage = $ex->getMessage();
        $flashType = 'danger';
    }
}
$definitions = $service->definitions($shop);
$editKey = (string) ($_GET['edit'] ?? '');
$edit = $definitions[$editKey] ?? null;

$pageTitle = 'Sets' . ($isAdmin ? ' — ' . $shop : '');
$navItems = require __DIR__ . '/_shop_nav.php';
$breadcrumbs = [['products.php' . $base, 'Produkte'], [null, 'Sets']];
if ($isAdmin) {
    $homeUrl = 'shops.php';
    $adminNavItems = require dirname(__DIR__) . '/admin/_nav.php';
    $adminEmail = (new \Connect\Auth\AdminAuth($pdo))->currentEmail();
    $topRightHtml = '<span class="text-secondary me-3">' . htmlspecialchars((string) $adminEmail) . '</span>'
        . '<a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';
}
require __DIR__ . '/_header.php';
?>

<?php if (!$allowed): ?>
    <div class="card"><div class="card-body">
        <h3 class="card-title"><?php echo Icon::svg('packages', 20, 'me-1'); ?>Sets und Bundles</h3>
        <p class="text-secondary">Verkaufe Sets aus mehreren Artikeln: Connect berechnet den Bestand des Sets aus den Einzelteilen und bucht sie bei jedem Verkauf im Kassensystem ab. Enthalten ab dem Abo <strong><?php echo $e(Plans::lowestPlanFor('bundles')); ?></strong>.</p>
        <a href="billing.php<?php echo $e($base); ?>" class="btn btn-primary">Abo ansehen</a>
    </div></div>
<?php elseif ($contextError !== null): ?>
    <div class="alert alert-warning"><?php echo $e($contextError); ?></div>
<?php elseif (!\Connect\Sync\TargetCapabilities::supports($pdo, $shop, 'bundles')): ?>
    <div class="alert alert-info"><?php echo $e(\Connect\Sync\TargetCapabilities::reason($pdo, $shop, 'bundles')); ?></div>
    <?php if ($direction !== 'target_to_shopify'): ?>
        <div class="text-secondary small">Bei „Shopify führt“ würde der Abgleich die Abbuchung der Einzelteile sofort wieder überschreiben, weil Shopify die Einzelteile beim Set-Verkauf nicht senkt.</div>
    <?php endif; ?>
<?php else: ?>

    <div class="row row-cards">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header"><h3 class="card-title"><?php echo Icon::svg('packages', 18, 'me-1'); ?>Deine Sets (<?php echo count($definitions); ?>)</h3></div>
                <?php if ($definitions === []): ?>
                    <div class="card-body text-secondary">Noch keine Sets. Lege rechts das erste an.</div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($definitions as $bundleMatch => $def): ?>
                            <?php
                            $qtys = [];
                            foreach ($def['components'] as $c) {
                                $qtys[$c['match']] = $targetItems[$c['match']]['qty'] ?? null;
                            }
                            $available = BundleService::availableSets($def['components'], $qtys);
                            $desired = $available !== null ? ShopStockRules::shopifyQuantity($available, $rules['buffer'], $direction) : null;
                            $shopifyQty = $shopifyItems[$bundleMatch]['qty'] ?? null;
                            ?>
                            <div class="list-group-item">
                                <div class="d-flex align-items-start gap-2 flex-wrap">
                                    <div class="flex-fill">
                                        <div class="fw-bold"><?php echo $e($def['label'] ?? ($shopifyItems[$bundleMatch]['label'] ?? $bundleMatch)); ?></div>
                                        <div class="small text-secondary"><?php echo $e($bundleMatch); ?><?php echo !isset($shopifyItems[$bundleMatch]) ? ' · <span class="text-danger">in Shopify nicht gefunden</span>' : ''; ?></div>
                                    </div>
                                    <div class="text-end small">
                                        <div>verfügbar: <strong><?php echo $available !== null ? (int) $available : '?'; ?></strong><?php echo $rules['buffer'] > 0 && $desired !== null ? ' → Shopify ' . (int) $desired : ''; ?></div>
                                        <div class="text-secondary">Shopify jetzt: <?php echo $shopifyQty !== null ? (int) $shopifyQty : '–'; ?><?php echo $desired !== null && $shopifyQty !== null && $desired !== $shopifyQty ? ' <span class="badge bg-yellow-lt">wird angepasst</span>' : ''; ?></div>
                                    </div>
                                </div>
                                <div class="mt-2 d-flex flex-wrap gap-1">
                                    <?php foreach ($def['components'] as $c): ?>
                                        <?php $q = $targetItems[$c['match']]['qty'] ?? null; ?>
                                        <span class="badge <?php echo isset($targetItems[$c['match']]) ? 'bg-secondary-lt' : 'bg-red-lt'; ?>" title="<?php echo $e($targetItems[$c['match']]['label'] ?? 'im Kassensystem nicht gefunden'); ?>">
                                            <?php echo (int) $c['quantity']; ?> × <?php echo $e($c['match']); ?> (Kasse: <?php echo $q !== null ? (int) $q : '?'; ?>)
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                                <div class="mt-2 d-flex gap-2">
                                    <a href="<?php echo $e($base . '&edit=' . rawurlencode($bundleMatch)); ?>" class="btn btn-sm">Bearbeiten</a>
                                    <form method="post" class="m-0">
                                        <input type="hidden" name="action" value="delete_bundle">
                                        <input type="hidden" name="bundle_id" value="<?php echo (int) $def['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Set löschen?')">Löschen</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><h3 class="card-title"><?php echo $edit !== null ? 'Set bearbeiten' : 'Neues Set'; ?></h3></div>
                <div class="card-body">
                    <form method="post" class="d-grid gap-2">
                        <input type="hidden" name="action" value="save_bundle">
                        <div>
                            <label class="form-label" for="bundle-match">Set-Artikel in Shopify (<?php echo $e($context->comparisonKey); ?>)</label>
                            <input id="bundle-match" name="bundle_match" list="shopify-only" class="form-control" required value="<?php echo $e($edit !== null ? $editKey : ''); ?>" <?php echo $edit !== null ? 'readonly' : ''; ?>>
                            <small class="text-secondary">Lege das Set zuerst in Shopify als eigenes Produkt an. Im Kassensystem darf es nicht als Artikel existieren.</small>
                        </div>
                        <div>
                            <label class="form-label" for="bundle-label">Bezeichnung (optional)</label>
                            <input id="bundle-label" name="label" class="form-control" value="<?php echo $e($edit['label'] ?? ''); ?>">
                        </div>
                        <label class="form-label mb-0 mt-2">Einzelteile (<?php echo $e($context->comparisonKey); ?> im Kassensystem) und Menge je Set</label>
                        <?php $rows = $edit['components'] ?? []; ?>
                        <?php for ($i = 0; $i < BundleService::MAX_COMPONENTS; $i++): ?>
                            <?php $c = $rows[$i] ?? ['match' => '', 'quantity' => 1]; ?>
                            <div class="input-group input-group-sm<?php echo $i >= max(3, count($rows) + 1) ? ' d-none extra-row' : ''; ?>">
                                <input name="component[<?php echo $i; ?>]" list="target-items" class="form-control" placeholder="Einzelteil" value="<?php echo $e($c['match']); ?>">
                                <span class="input-group-text">×</span>
                                <input name="component_qty[<?php echo $i; ?>]" type="number" min="1" class="form-control" style="max-width: 80px" value="<?php echo (int) $c['quantity']; ?>">
                            </div>
                        <?php endfor; ?>
                        <button type="button" class="btn btn-sm btn-ghost-secondary" onclick="var r=document.querySelector('.extra-row.d-none'); if(r){r.classList.remove('d-none');}">+ weiteres Einzelteil</button>
                        <div class="d-flex gap-2 mt-2">
                            <button type="submit" class="btn btn-primary">Speichern</button>
                            <?php if ($edit !== null): ?><a href="<?php echo $e($base); ?>" class="btn">Abbrechen</a><?php endif; ?>
                        </div>
                    </form>
                    <datalist id="shopify-only">
                        <?php foreach ($shopifyItems as $value => $item): ?>
                            <?php if (!isset($targetItems[$value])): ?><option value="<?php echo $e((string) $value); ?>"><?php echo $e($item['label']); ?></option><?php endif; ?>
                        <?php endforeach; ?>
                    </datalist>
                    <datalist id="target-items">
                        <?php foreach (array_slice($targetItems, 0, 5000, true) as $value => $item): ?>
                            <option value="<?php echo $e((string) $value); ?>"><?php echo $e($item['label']); ?></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="card-footer small text-secondary">
                    So funktioniert es: Der Lagerabgleich setzt den Shopify-Bestand des Sets auf die Anzahl, die sich aus den Einzelteilen im Kassensystem zusammenstellen lässt<?php echo $rules['buffer'] > 0 ? ' (minus Sicherheitsbestand ' . (int) $rules['buffer'] . ')' : ''; ?>. Wird ein Set in Shopify verkauft, bucht Connect nach der Rechnung die Einzelteile im Kassensystem ab.
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
