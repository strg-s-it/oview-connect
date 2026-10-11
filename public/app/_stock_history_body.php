<?php

declare(strict_types=1);

/**
 * connect/public/app/_stock_history_body.php
 *
 * Bestandsverlauf eines Artikels (?match=<Vergleichswert>), Connect\Sync\StockHistory: aktueller
 * Stand beider Seiten, Kurve, Ereignisse der letzten 30 Tage (Abgleich, Bestellungen, Fehler,
 * Preise). Geteilt mit admin/stock_history.php.
 *
 * Erwartet vor dem Include: $app (Connect\AppContext), optional $isAdmin.
 */

use Connect\Connector\ConnectorFactory;
use Connect\Http\HttpClient;
use Connect\Shopify\TokenRefresher;
use Connect\Sync\StockHistory;
use Connect\Ui\Icon;

$isAdmin = $isAdmin ?? false;
$pdo = $app->pdo;
$shop = $app->shop;
$previewSuffix = isset($_GET['preview']) ? '&preview=1' : '';
$e = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES);
$money = static fn (?float $v): string => $v === null ? '–' : number_format($v, 2, ',', '.');
$when = static fn (?string $at): string => $at === null ? '–' : date('d.m.Y H:i', (int) strtotime($at));

$matchValue = trim((string) ($_GET['match'] ?? ''));
$history = null;
$error = null;
if ($matchValue !== '') {
    try {
        $factory = new ConnectorFactory($pdo, $app->crypto, new HttpClient(), new TokenRefresher(
            new HttpClient(),
            $app->config->optional('CONNECT_SHOPIFY_API_KEY'),
            $app->config->optional('CONNECT_SHOPIFY_API_SECRET')
        ));
        $history = (new StockHistory($pdo, $factory))->build($shop, $matchValue);
    } catch (\Throwable $ex) {
        $error = $ex->getMessage();
    }
}

$pageTitle = 'Bestandsverlauf' . ($isAdmin ? ' — ' . $shop : '');
$navItems = require __DIR__ . '/_shop_nav.php';
$breadcrumbs = [['products.php?shop=' . urlencode($shop) . $previewSuffix, 'Produkte'], [null, $history['label'] ?? $matchValue]];
if ($isAdmin) {
    $homeUrl = 'shops.php';
    $adminNavItems = require dirname(__DIR__) . '/admin/_nav.php';
    $adminEmail = (new \Connect\Auth\AdminAuth($pdo))->currentEmail();
    $topRightHtml = '<span class="text-secondary me-3">' . htmlspecialchars((string) $adminEmail) . '</span>'
        . '<a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';
}
require __DIR__ . '/_header.php';

$kindIcon = ['sync' => ['refresh', 'bg-blue-lt'], 'order' => ['cash-register', 'bg-green-lt'], 'error' => ['alert-triangle', 'bg-red-lt'], 'price' => ['tag', 'bg-purple-lt']];
?>

<form method="get" class="mb-3 d-flex gap-2" style="max-width: 520px">
    <input type="hidden" name="shop" value="<?php echo $e($shop); ?>">
    <?php if ($previewSuffix !== ''): ?><input type="hidden" name="preview" value="1"><?php endif; ?>
    <input type="search" name="match" class="form-control" placeholder="Vergleichswert (z. B. SKU) eingeben" value="<?php echo $e($matchValue); ?>">
    <button type="submit" class="btn">Anzeigen</button>
</form>

<?php if ($error !== null): ?>
    <div class="alert alert-warning"><?php echo $e($error); ?></div>
<?php elseif ($history !== null && !$history['found']): ?>
    <div class="alert alert-info">Kein Artikel mit dem Vergleichswert „<?php echo $e($matchValue); ?>“ gefunden.</div>
<?php elseif ($history !== null): ?>
    <?php
    $s = $history['shopify'];
    $t = $history['target'];
    $mismatch = $s !== null && $t !== null && $s['quantity'] !== null && $history['expected_shopify'] !== null && $s['quantity'] !== $history['expected_shopify'];
    ?>
    <div class="row row-cards mb-3">
        <div class="col-md-4">
            <div class="card h-100"><div class="card-body">
                <div class="text-secondary small"><?php echo Icon::svg('building-store', 16, 'me-1'); ?>Shopify</div>
                <div class="h1 mb-1"><?php echo $s !== null && $s['quantity'] !== null ? (int) $s['quantity'] : '–'; ?></div>
                <div class="small text-secondary">Preis <?php echo $money($s['price'] ?? null); ?> · gelesen <?php echo $e($when($s['fetched_at'] ?? null)); ?></div>
            </div></div>
        </div>
        <div class="col-md-4">
            <div class="card h-100"><div class="card-body">
                <div class="text-secondary small"><?php echo Icon::svg('cash-register', 16, 'me-1'); ?>Kassensystem</div>
                <div class="h1 mb-1"><?php echo $t !== null && $t['quantity'] !== null ? (int) $t['quantity'] : '–'; ?></div>
                <div class="small text-secondary">Preis <?php echo $money($t['price'] ?? null); ?> · gelesen <?php echo $e($when($t['fetched_at'] ?? null)); ?></div>
            </div></div>
        </div>
        <div class="col-md-4">
            <div class="card h-100"><div class="card-body">
                <div class="text-secondary small"><?php echo Icon::svg('info-circle', 16, 'me-1'); ?>Einordnung</div>
                <?php if ($s === null || $t === null): ?>
                    <div class="mt-1"><?php echo $s === null ? 'Fehlt in Shopify' : 'Fehlt im Kassensystem'; ?> – wird nicht abgeglichen.</div>
                <?php elseif ($s['quantity'] === null || $t['quantity'] === null): ?>
                    <div class="mt-1">Bestand auf einer Seite unbekannt – wird übersprungen, bis er gelesen ist.</div>
                <?php elseif ($mismatch): ?>
                    <div class="mt-1 text-warning fw-bold">Weicht ab</div>
                    <div class="small text-secondary">Erwartet in Shopify: <?php echo (int) $history['expected_shopify']; ?><?php echo $history['buffer'] > 0 ? ' (Kasse ' . (int) $t['quantity'] . ' − Sicherheitsbestand ' . (int) $history['buffer'] . ')' : ''; ?>. Der nächste Abgleich gleicht an – vorher prüft Connect beide Werte noch einmal live.</div>
                <?php else: ?>
                    <div class="mt-1 text-success fw-bold"><?php echo Icon::svg('circle-check', 16, 'me-1'); ?>Synchron</div>
                    <?php if ($history['buffer'] > 0): ?><div class="small text-secondary">mit Sicherheitsbestand <?php echo (int) $history['buffer']; ?></div><?php endif; ?>
                <?php endif; ?>
                <div class="small text-secondary mt-2">Führend: <?php echo $history['direction'] === 'target_to_shopify' ? 'Kassensystem' : 'Shopify'; ?></div>
            </div></div>
        </div>
    </div>

    <?php $chart = StockHistory::svg($history['series']); ?>
    <?php if ($chart !== ''): ?>
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title"><?php echo Icon::svg('activity', 18, 'me-1'); ?>Verlauf (vom Abgleich gesetzte Bestände, <?php echo StockHistory::DAYS; ?> Tage)</h3></div>
            <div class="card-body"><?php echo $chart; ?></div>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><h3 class="card-title"><?php echo Icon::svg('history', 18, 'me-1'); ?>Ereignisse (<?php echo StockHistory::DAYS; ?> Tage)</h3></div>
        <?php if ($history['events'] === []): ?>
            <div class="card-body text-secondary">In diesem Zeitraum keine Änderungen durch Connect, keine Bestellungen mit diesem Artikel.</div>
        <?php else: ?>
            <div class="list-group list-group-flush">
                <?php foreach ($history['events'] as $event): ?>
                    <?php [$icon, $badge] = $kindIcon[$event['kind']] ?? ['info-circle', 'bg-secondary-lt']; ?>
                    <div class="list-group-item d-flex gap-3 align-items-start">
                        <span class="badge <?php echo $badge; ?> p-2"><?php echo Icon::svg($icon, 16); ?></span>
                        <div class="flex-fill">
                            <div class="fw-bold"><?php echo $e($event['title']); ?></div>
                            <div class="text-secondary"><?php echo $e($event['text']); ?></div>
                        </div>
                        <span class="text-secondary small text-nowrap"><?php echo $e($when($event['at'])); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="card-footer small text-secondary">Bestellungen kommen aus den Shopify-Benachrichtigungen; Verkäufe direkt an der Kasse sieht Connect nur als geänderten Bestand im Kassensystem.</div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
