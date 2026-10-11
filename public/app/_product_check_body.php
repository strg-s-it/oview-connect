<?php

declare(strict_types=1);

/**
 * connect/public/app/_product_check_body.php
 *
 * Produkt-Check (Connect\Sync\ProductCheck, Migration 0090): Punktwert fuer Datenqualitaet und SEO
 * der Shopify-Produkte, Zaehler je Pruefung, Liste der Produkte mit Befunden (Direktlink in den
 * Shopify-Admin) und Textansicht mit markierten Problemstellen (live aus Shopify, ?text=<gid>).
 *
 * Abo (Plans::FEATURES, ProductCheck::GROUPS): Punktwert und Anzahl je Pruefung sehen alle. Liste
 * und Filter gibt es je Gruppe nur, wenn sie im Abo enthalten ist -- Grunddaten in jedem Abo, SEO
 * und Textqualitaet ab +. Die Textansicht gehoert zur Textqualitaet. Admin sieht alles, die
 * Merchant-Vorschau (?preview=1) zeigt die Sperren wie beim Merchant.
 * Geteilt mit admin/product_check.php.
 *
 * Erwartet vor dem Include: $app (Connect\AppContext), optional $isAdmin.
 */

use Connect\Billing\Plans;
use Connect\Connector\ConnectorFactory;
use Connect\Http\HttpClient;
use Connect\Shopify\TokenRefresher;
use Connect\Sync\ProductCheck;
use Connect\Ui\Icon;

$isAdmin = $isAdmin ?? false;
$pdo = $app->pdo;
$shop = $app->shop;
$previewSuffix = isset($_GET['preview']) ? '&preview=1' : '';
$e = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES);

$factory = new ConnectorFactory($pdo, $app->crypto, new HttpClient(), new TokenRefresher(
    new HttpClient(),
    $app->config->optional('CONNECT_SHOPIFY_API_KEY'),
    $app->config->optional('CONNECT_SHOPIFY_API_SECRET')
));
$check = new ProductCheck($pdo, $factory);

// Freigabe je Gruppe
$plan = Plans::forShop($pdo, $shop);
$groupAllowed = [];
foreach (ProductCheck::GROUPS as $group => [, $feature]) {
    $groupAllowed[$group] = $isAdmin || $feature === null || Plans::allowsFeature($plan, $feature);
}
$checkAllowed = static fn (string $key): bool => $groupAllowed[ProductCheck::groupOf($key)] ?? false;
$textAllowed = $groupAllowed['text'];

// ---- Textansicht (JSON, nur lesend) ---------------------------------------------------------
if (isset($_GET['text'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $gid = (string) $_GET['text'];
    $stored = $check->stored($shop);
    $known = in_array($gid, array_column($stored['issues'] ?? [], 'id'), true);
    if (!$textAllowed || !$known) {
        echo json_encode(['error' => $textAllowed ? 'Produkt nicht in der Liste.' : 'Die Textansicht ist ab dem Abo ' . Plans::lowestPlanFor('product_check_text') . ' enthalten.']);
        exit;
    }
    try {
        echo json_encode($check->productText($shop, $gid), JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $ex) {
        echo json_encode(['error' => $ex->getMessage()]);
    }
    exit;
}

$flashMessage = null;
$flashType = 'info';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start_check') {
    $error = $check->start($shop);
    $flashMessage = $error ?? 'Produkt-Check gestartet – Shopify sammelt die Produkte im Hintergrund, das dauert meist ein bis wenige Minuten. Diese Seite aktualisiert sich selbst.';
    $flashType = $error === null ? 'success' : 'danger';
}
$state = $check->advance($shop);
$result = $check->stored($shop);

// Liste: nur freigegebene Pruefungen, gesperrte Befunde aus jedem Produkt entfernen
$filterCheck = isset(ProductCheck::CHECKS[$_GET['check'] ?? '']) && $checkAllowed((string) $_GET['check']) ? (string) $_GET['check'] : '';
$issues = [];
foreach ($result['issues'] ?? [] as $issue) {
    $visible = array_values(array_filter($issue['issues'], $checkAllowed));
    if ($visible === [] || ($filterCheck !== '' && !in_array($filterCheck, $visible, true))) {
        continue;
    }
    $issue['issues'] = $visible;
    $issues[] = $issue;
}
$anyListAllowed = in_array(true, $groupAllowed, true);
$pageSize = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));
$totalPages = max(1, (int) ceil(count($issues) / $pageSize));
$page = min($page, $totalPages);
$pageIssues = array_slice($issues, ($page - 1) * $pageSize, $pageSize);
$base = '?shop=' . urlencode($shop) . $previewSuffix;
$billingUrl = 'billing.php' . $base;

$pageTitle = 'Produkt-Check' . ($isAdmin ? ' — ' . $shop : '');
$navItems = require __DIR__ . '/_shop_nav.php';
$breadcrumbs = [['products.php' . $base, 'Produkte'], [null, 'Produkt-Check']];
if ($isAdmin) {
    $homeUrl = 'shops.php';
    $adminNavItems = require dirname(__DIR__) . '/admin/_nav.php';
    $adminEmail = (new \Connect\Auth\AdminAuth($pdo))->currentEmail();
    $topRightHtml = '<span class="text-secondary me-3">' . htmlspecialchars((string) $adminEmail) . '</span>'
        . '<a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';
}
require __DIR__ . '/_header.php';

$score = $result !== null && $result['score'] !== null ? (int) $result['score'] : null;
$scoreColor = $score === null ? 'secondary' : ($score >= 85 ? 'green' : ($score >= 65 ? 'yellow' : 'red'));
$lockedGroups = array_keys(array_filter($groupAllowed, static fn (bool $a): bool => !$a));
?>

<style>
    .pc-text { max-height: 420px; overflow: auto; background: var(--tblr-bg-surface-secondary, #f6f8fb); border-radius: 6px; padding: .75rem 1rem; font-size: .875rem; line-height: 1.55; }
    .pc-text mark { background: #ffe08a; padding: 0 .1em; border-radius: 2px; }
    .pc-locked { opacity: .65; }
</style>

<?php if (!$isAdmin): ?>
    <div class="alert alert-info d-flex flex-wrap align-items-center gap-2 py-2">
        <span>
            Dein Abo<?php echo $plan !== null ? ' <strong>' . $e(Plans::PLANS[$plan]['name']) . '</strong>' : ''; ?>:
            <?php if ($lockedGroups === []): ?>
                alle Prüfungen mit Produktliste und Textansicht enthalten.
            <?php else: ?>
                Liste für <strong><?php echo $e(implode(', ', array_map(static fn (string $g): string => ProductCheck::GROUPS[$g][0], array_keys(array_filter($groupAllowed))))); ?></strong> enthalten.
                <?php echo $e(implode(' und ', array_map(static fn (string $g): string => ProductCheck::GROUPS[$g][0], $lockedGroups))); ?>
                (mit Textansicht) siehst du nur als Anzahl – die betroffenen Produkte gibt es ab <strong><?php echo $e(Plans::lowestPlanFor((string) ProductCheck::GROUPS[$lockedGroups[0]][1])); ?></strong>.
            <?php endif; ?>
        </span>
        <?php if ($lockedGroups !== []): ?><a href="<?php echo $e($billingUrl); ?>" class="btn btn-sm btn-primary ms-auto">Abo ansehen</a><?php endif; ?>
    </div>
<?php endif; ?>

<div class="row row-cards">
    <div class="col-md-4">
        <div class="card h-100"><div class="card-body text-center d-flex flex-column justify-content-center">
            <div class="text-secondary"><?php echo Icon::svg('list-check', 18, 'me-1'); ?>Produkt-Qualität</div>
            <div class="display-5 fw-bold text-<?php echo $scoreColor; ?>"><?php echo $score !== null ? $score . ' %' : '–'; ?></div>
            <div class="progress progress-sm my-2"><div class="progress-bar bg-<?php echo $scoreColor; ?>" style="width: <?php echo (int) $score; ?>%"></div></div>
            <div class="small text-secondary">
                <?php if ($state === 'running'): ?>
                    <?php echo Icon::svg('refresh', 14, 'me-1'); ?>Prüfung läuft …
                <?php elseif ($result !== null && $result['finished_at'] !== null): ?>
                    <?php echo (int) $result['products']; ?> aktive Produkte · geprüft <?php echo $e(date('d.m.Y H:i', (int) strtotime((string) $result['finished_at']))); ?>
                <?php else: ?>
                    Noch nicht geprüft.
                <?php endif; ?>
            </div>
            <div class="small text-secondary mt-1">Der Punktwert zählt alle Prüfungen – unabhängig vom Abo.</div>
            <?php if ($state === 'failed' && !empty($result['error'])): ?><div class="small text-danger mt-1"><?php echo $e((string) $result['error']); ?></div><?php endif; ?>
            <form method="post" class="mt-3">
                <input type="hidden" name="action" value="start_check">
                <button type="submit" class="btn btn-primary" <?php echo $state === 'running' ? 'disabled' : ''; ?>><?php echo Icon::svg('player-play', 16, 'me-1'); ?><?php echo $result === null ? 'Jetzt prüfen' : 'Erneut prüfen'; ?></button>
            </form>
        </div></div>
    </div>
    <div class="col-md-8">
        <div class="card h-100">
            <div class="card-header"><h3 class="card-title">Befunde</h3></div>
            <div class="list-group list-group-flush">
                <?php foreach (ProductCheck::GROUPS as $group => [$groupLabel, $feature, $groupChecks]): ?>
                    <div class="list-group-item bg-light py-2 d-flex align-items-center gap-2">
                        <strong class="small text-uppercase"><?php echo $e($groupLabel); ?></strong>
                        <?php if ($groupAllowed[$group]): ?>
                            <span class="badge bg-green-lt"><?php echo $isAdmin && $feature !== null ? 'ab ' . $e(Plans::lowestPlanFor($feature)) : 'enthalten'; ?></span>
                        <?php else: ?>
                            <span class="badge bg-secondary-lt"><?php echo Icon::svg('lock', 12, 'me-1'); ?>Liste ab <?php echo $e(Plans::lowestPlanFor((string) $feature)); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php foreach ($groupChecks as $key): ?>
                        <?php [$label, , $help] = ProductCheck::CHECKS[$key]; ?>
                        <?php $count = (int) ($result['summary'][$key] ?? 0); ?>
                        <div class="list-group-item d-flex align-items-center gap-2 <?php echo $groupAllowed[$group] ? '' : 'pc-locked'; ?>">
                            <span class="badge <?php echo $result === null || !isset($result['summary'][$key]) ? 'bg-secondary-lt' : ($count > 0 ? 'bg-orange-lt' : 'bg-green-lt'); ?>" style="min-width: 3rem"><?php echo $result === null || !isset($result['summary'][$key]) ? '–' : $count; ?></span>
                            <div class="flex-fill">
                                <?php if ($groupAllowed[$group] && $count > 0): ?>
                                    <a href="<?php echo $e($base . '&check=' . $key); ?>" class="<?php echo $filterCheck === $key ? 'fw-bold' : ''; ?>"><?php echo $e($label); ?></a>
                                <?php else: ?>
                                    <?php echo $e($label); ?>
                                    <?php if (!$groupAllowed[$group]): ?><span class="text-secondary"><?php echo Icon::svg('lock', 12, 'ms-1'); ?></span><?php endif; ?>
                                <?php endif; ?>
                                <div class="small text-secondary"><?php echo $e($help); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <?php if ($result !== null && !isset($result['summary']['duplicate_description'])): ?>
                    <div class="list-group-item small text-secondary">Neue Prüfungen zur Textqualität: bitte einmal „Erneut prüfen“.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12">
        <?php if (!$anyListAllowed): ?>
            <div class="card"><div class="card-body">
                <?php echo Icon::svg('info-circle', 18, 'me-1'); ?>Die Liste der betroffenen Produkte ist in deinem Abo nicht enthalten.
                <a href="<?php echo $e($billingUrl); ?>" class="ms-1">Abo ansehen</a>
            </div></div>
        <?php elseif ($result !== null && $result['status'] === 'done'): ?>
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><?php echo $filterCheck !== '' ? $e(ProductCheck::CHECKS[$filterCheck][0]) : 'Produkte mit Befunden'; ?> (<?php echo count($issues); ?>)</h3>
                    <?php if ($filterCheck !== ''): ?><div class="card-actions"><a href="<?php echo $e($base); ?>" class="btn btn-sm">Filter aufheben</a></div><?php endif; ?>
                </div>
                <?php if ($lockedGroups !== [] && $filterCheck === ''): ?>
                    <div class="card-body py-2 small text-secondary border-bottom">
                        <?php echo Icon::svg('lock', 14, 'me-1'); ?>Befunde aus <?php echo $e(implode(' und ', array_map(static fn (string $g): string => ProductCheck::GROUPS[$g][0], $lockedGroups))); ?> sind in dieser Liste ausgeblendet.
                    </div>
                <?php endif; ?>
                <?php if ($pageIssues === []): ?>
                    <div class="card-body text-success"><?php echo Icon::svg('circle-check', 16, 'me-1'); ?>Keine Befunde.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead><tr><th>Produkt</th><th>Befunde</th><th></th></tr></thead>
                            <tbody>
                                <?php foreach ($pageIssues as $index => $issue): ?>
                                    <?php
                                    $numericId = preg_match('~(\d+)$~', $issue['id'], $m) === 1 ? $m[1] : '';
                                    $hasTextFinding = array_intersect($issue['issues'], ProductCheck::TEXT_CHECKS) !== [];
                                    ?>
                                    <tr>
                                        <td><?php echo $e($issue['title']); ?></td>
                                        <td>
                                            <?php foreach ($issue['issues'] as $key): ?>
                                                <?php $detail = (string) ($issue['details'][$key] ?? ''); ?>
                                                <span class="badge bg-orange-lt me-1 mb-1"><?php echo $e(ProductCheck::CHECKS[$key][0] ?? $key); ?><?php if ($detail !== ''): ?><span class="fw-normal"> · <?php echo $e($detail); ?></span><?php endif; ?></span>
                                            <?php endforeach; ?>
                                        </td>
                                        <td class="text-end text-nowrap">
                                            <?php if ($textAllowed && $hasTextFinding): ?>
                                                <button type="button" class="btn btn-sm" data-text-toggle="<?php echo $e($issue['id']); ?>" data-target="pc-text-<?php echo $index; ?>">Text ansehen</button>
                                            <?php endif; ?>
                                            <?php if ($numericId !== ''): ?>
                                                <a href="https://admin.shopify.com/store/<?php echo $e(rawurlencode($shop)); ?>/products/<?php echo $e($numericId); ?>" target="_top" class="btn btn-sm">In Shopify bearbeiten</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php if ($textAllowed && $hasTextFinding): ?>
                                        <tr id="pc-text-<?php echo $index; ?>" hidden>
                                            <td colspan="3"><div class="pc-text text-secondary">Lädt …</div></td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($totalPages > 1): ?>
                        <div class="card-footer d-flex justify-content-between">
                            <a class="btn btn-sm <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo $e($base . ($filterCheck !== '' ? '&check=' . $filterCheck : '') . '&page=' . ($page - 1)); ?>">Zurück</a>
                            <span class="text-secondary small">Seite <?php echo $page; ?> von <?php echo $totalPages; ?></span>
                            <a class="btn btn-sm <?php echo $page >= $totalPages ? 'disabled' : ''; ?>" href="<?php echo $e($base . ($filterCheck !== '' ? '&check=' . $filterCheck : '') . '&page=' . ($page + 1)); ?>">Weiter</a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    // Textansicht: Beschreibung live aus Shopify holen (erst beim Aufklappen), Problemstellen markiert
    document.querySelectorAll('[data-text-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var row = document.getElementById(btn.dataset.target);
            if (!row) { return; }
            row.hidden = !row.hidden;
            btn.textContent = row.hidden ? 'Text ansehen' : 'Text ausblenden';
            if (row.hidden || row.dataset.loaded) { return; }
            row.dataset.loaded = '1';
            var box = row.querySelector('.pc-text');
            var params = new URLSearchParams(window.location.search);
            params.set('text', btn.dataset.textToggle);
            fetch(window.location.pathname + '?' + params.toString(), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.error) { box.textContent = data.error; delete row.dataset.loaded; return; }
                    var info = data.length + ' Zeichen · ' + (data.paragraphs > 0 ? data.paragraphs + ' Absätze/Listenpunkte' : 'keine Absätze') + (data.language ? ' · ' + data.language : '');
                    box.classList.remove('text-secondary');
                    box.innerHTML = '<div class="small text-secondary mb-2">' + info + ' · <mark>gelb</mark> = Auffälligkeit</div>'
                        + (data.html !== '' ? data.html : '<em class="text-secondary">Keine Beschreibung.</em>');
                })
                .catch(function () { box.textContent = 'Text konnte nicht geladen werden.'; delete row.dataset.loaded; });
        });
    });
</script>

<?php if ($state === 'running'): ?>
    <script>setTimeout(function () { window.location.href = window.location.pathname + window.location.search; }, 15000);</script>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
