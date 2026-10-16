<?php

declare(strict_types=1);

/**
 * connect/public/app/_faq_body.php
 *
 * FAQ fuer Merchants (Connect\Faq\FaqBoard, Migration 0102): nur veroeffentlichte Eintraege,
 * nach Kategorie gruppiert, aufklappbar, mit Suchfeld (reines Filtern im Browser).
 * Merchant-Einstieg app/faq.php, Admin-Vorschau ueber admin/faq.php?shop=…&preview=1.
 *
 * Erwartet vor dem Include: $app (Connect\AppContext), optional $isAdmin.
 */

use Connect\Faq\FaqBoard;
use Connect\Setup\LegalTexts;
use Connect\Ui\Icon;

$isAdmin = $isAdmin ?? false;
$pdo = $app->pdo;
$shop = $app->shop;
$e = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES);

$board = new FaqBoard($pdo);
$available = $board->available();
$groups = $available ? FaqBoard::grouped($board->all(true)) : [];

$pageTitle = 'FAQ' . ($isAdmin ? ' — ' . $shop : '');
$navItems = require __DIR__ . '/_shop_nav.php';
if ($isAdmin) {
    $homeUrl = 'shops.php';
    $adminNavItems = require dirname(__DIR__) . '/admin/_nav.php';
    $adminEmail = (new \Connect\Auth\AdminAuth($pdo))->currentEmail();
    $topRightHtml = '<span class="text-secondary me-3">' . htmlspecialchars((string) $adminEmail) . '</span>'
        . '<a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';
}
require __DIR__ . '/_header.php';

$previewSuffix = isset($_GET['preview']) ? '&preview=1' : '';
?>

<?php if (!$available): ?>
    <div class="alert alert-info">Die FAQ ist nach dem nächsten Update verfügbar.</div>
<?php elseif ($groups === []): ?>
    <div class="card"><div class="card-body text-secondary">Hier gibt es noch keine Einträge. Fehlt dir etwas? Schreib uns unter <a href="wishes.php?shop=<?php echo $e(urlencode($shop) . $previewSuffix); ?>">Wünsche</a>.</div></div>
<?php else: ?>
    <div class="mb-3">
        <input type="search" id="faq-search" class="form-control" placeholder="In den Antworten suchen …" autocomplete="off" aria-label="FAQ durchsuchen">
    </div>

    <?php foreach ($groups as $category => $items): ?>
        <div class="card mb-3 faq-group">
            <div class="card-header"><h3 class="card-title"><?php echo Icon::svg('help-circle', 18, 'me-1'); ?><?php echo $e($category); ?></h3></div>
            <div class="list-group list-group-flush">
                <?php foreach ($items as $item): ?>
                    <details class="list-group-item faq-item" id="faq-<?php echo (int) $item['id']; ?>">
                        <summary class="fw-bold" style="cursor:pointer;"><?php echo $e($item['question']); ?></summary>
                        <div class="mt-2"><?php echo LegalTexts::render($item['answer']); ?></div>
                    </details>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <p id="faq-empty" class="text-secondary" hidden>Nichts gefunden. Fehlt dir etwas? Schreib uns unter <a href="wishes.php?shop=<?php echo $e(urlencode($shop) . $previewSuffix); ?>">Wünsche</a>.</p>

    <script>
        (function () {
            var input = document.getElementById('faq-search');
            var items = document.querySelectorAll('.faq-item');
            var groups = document.querySelectorAll('.faq-group');
            var empty = document.getElementById('faq-empty');
            input.addEventListener('input', function () {
                var q = input.value.trim().toLowerCase();
                items.forEach(function (item) {
                    var hit = q === '' || item.textContent.toLowerCase().indexOf(q) !== -1;
                    item.hidden = !hit;
                    if (q !== '' && hit) { item.open = true; }
                    if (q === '') { item.open = false; }
                });
                var visible = 0;
                groups.forEach(function (group) {
                    var any = group.querySelector('.faq-item:not([hidden])') !== null;
                    group.hidden = !any;
                    if (any) { visible++; }
                });
                empty.hidden = visible > 0;
            });
        })();
    </script>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
