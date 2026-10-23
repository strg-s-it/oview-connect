<?php

declare(strict_types=1);

/**
 * connect/public/app/_wishes_body.php
 *
 * Funktionswuensche (Connect\Wishes\WishBoard, Migration 0093): einreichen, freigegebene Wuensche
 * aller Shops ohne Shop-Namen sehen, abstimmen. Merchant-Einstieg app/wishes.php, Admin-Vorschau
 * ueber admin/wishes.php?shop=…&preview=1.
 *
 * Erwartet vor dem Include: $app (Connect\AppContext), optional $isAdmin.
 */

use Connect\Ui\Icon;
use Connect\Wishes\WishBoard;

$isAdmin = $isAdmin ?? false;
$pdo = $app->pdo;
$shop = $app->shop;
$previewSuffix = isset($_GET['preview']) ? '&preview=1' : '';
$e = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES);

$board = new WishBoard($pdo);
$available = $board->available();
$flashMessage = null;
$flashType = 'info';
$draftTitle = '';
$draftBody = '';

if ($available && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'submit_wish') {
        $draftTitle = (string) ($_POST['title'] ?? '');
        $draftBody = (string) ($_POST['body'] ?? '');
        $error = $board->submit($shop, $draftTitle, $draftBody);
        if ($error === null) {
            $flashMessage = 'Danke! Dein Wunsch ist eingegangen. Wir sehen ihn uns an und geben ihn dann für alle frei.';
            $flashType = 'success';
            $draftTitle = $draftBody = '';
        } else {
            $flashMessage = $error;
            $flashType = 'danger';
        }
    } elseif ($action === 'vote') {
        $board->vote($shop, (int) ($_POST['wish_id'] ?? 0), ($_POST['on'] ?? '') === '1');
        header('Location: ' . \Connect\Http\Url::withToken('?shop=' . urlencode($shop) . $previewSuffix . '#wish-' . (int) ($_POST['wish_id'] ?? 0)));
        exit;
    }
}

$filter = (string) ($_GET['filter'] ?? '');
$sort = ($_GET['sort'] ?? '') === 'new' ? 'new' : 'votes';
$wishes = $available ? $board->forMerchant($shop, $filter, $sort) : [];

$pageTitle = 'Wünsche' . ($isAdmin ? ' — ' . $shop : '');
$navItems = require __DIR__ . '/_shop_nav.php';
if ($isAdmin) {
    $homeUrl = 'shops.php';
    $adminNavItems = require dirname(__DIR__) . '/admin/_nav.php';
    $adminEmail = (new \Connect\Auth\AdminAuth($pdo))->currentEmail();
    $topRightHtml = '<span class="text-secondary me-3">' . htmlspecialchars((string) $adminEmail) . '</span>'
        . '<a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';
}
require __DIR__ . '/_header.php';

$base = '?shop=' . urlencode($shop) . $previewSuffix;
$filters = ['' => 'Alle', 'open' => 'Neu', 'planned' => 'Geplant', 'done' => 'Umgesetzt', 'mine' => 'Meine'];
?>

<?php if (!$available): ?>
    <div class="alert alert-info">Die Wunschliste ist nach dem nächsten Update verfügbar.</div>
<?php else: ?>
<div class="row row-cards">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><h3 class="card-title"><?php echo Icon::svg('bulb', 18, 'me-1'); ?>Was fehlt dir?</h3></div>
            <div class="card-body">
                <p class="text-secondary small">
                    Schreib uns, welche Funktion dir die Arbeit erleichtern würde. Nach einer kurzen Prüfung sehen auch andere
                    Händler deinen Wunsch – <strong>ohne deinen Shop-Namen</strong> – und können dafür stimmen. So sehen wir,
                    was vielen wichtig ist.
                </p>
                <form method="post">
                    <input type="hidden" name="action" value="submit_wish">
                    <div class="mb-2">
                        <label class="form-label" for="wish-title">Dein Wunsch in einem Satz</label>
                        <input id="wish-title" type="text" name="title" maxlength="150" required class="form-control" value="<?php echo $e($draftTitle); ?>" placeholder="z. B. Lieferschein zusammen mit der Rechnung schicken">
                    </div>
                    <div class="mb-2">
                        <label class="form-label" for="wish-body">Wofür brauchst du das? <span class="text-secondary">(optional)</span></label>
                        <textarea id="wish-body" name="body" rows="5" maxlength="5000" class="form-control" placeholder="Beschreib kurz, wie du es nutzen würdest."><?php echo $e($draftBody); ?></textarea>
                        <small class="text-secondary">Bitte keine Kundendaten oder Passwörter eintragen.</small>
                    </div>
                    <button type="submit" class="btn btn-primary">Wunsch einreichen</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <div class="btn-group" role="group">
                <?php foreach ($filters as $key => $label): ?>
                    <a href="<?php echo $e($base . ($key !== '' ? '&filter=' . $key : '') . ($sort === 'new' ? '&sort=new' : '')); ?>" class="btn btn-sm <?php echo $filter === $key ? 'btn-primary' : ''; ?>"><?php echo $e($label); ?></a>
                <?php endforeach; ?>
            </div>
            <div class="ms-auto small text-secondary">
                Sortieren:
                <a href="<?php echo $e($base . ($filter !== '' ? '&filter=' . urlencode($filter) : '')); ?>" class="<?php echo $sort === 'votes' ? 'fw-bold' : ''; ?>">beliebteste</a> ·
                <a href="<?php echo $e($base . ($filter !== '' ? '&filter=' . urlencode($filter) : '') . '&sort=new'); ?>" class="<?php echo $sort === 'new' ? 'fw-bold' : ''; ?>">neueste</a>
            </div>
        </div>

        <?php if ($wishes === []): ?>
            <div class="card"><div class="card-body text-secondary">Hier ist noch nichts. Sei die/der Erste mit einem Wunsch!</div></div>
        <?php endif; ?>

        <?php foreach ($wishes as $w): ?>
            <?php [$statusLabel, $statusClass] = WishBoard::STATUSES[$w['status']] ?? [$w['status'], 'bg-secondary-lt']; ?>
            <div class="card mb-2" id="wish-<?php echo $w['id']; ?>">
                <div class="card-body d-flex gap-3">
                    <div class="text-center" style="min-width: 3.5rem">
                        <?php $canVote = !$w['mine'] && in_array($w['status'], ['open', 'planned'], true); ?>
                        <?php if ($canVote): ?>
                            <form method="post" class="m-0">
                                <input type="hidden" name="action" value="vote">
                                <input type="hidden" name="wish_id" value="<?php echo $w['id']; ?>">
                                <input type="hidden" name="on" value="<?php echo $w['voted'] ? '0' : '1'; ?>">
                                <button type="submit" class="btn btn-sm <?php echo $w['voted'] ? 'btn-primary' : 'btn-outline-primary'; ?> w-100" title="<?php echo $w['voted'] ? 'Stimme zurücknehmen' : 'Brauch ich auch'; ?>">
                                    <?php echo Icon::svg('thumb-up', 16); ?>
                                </button>
                            </form>
                        <?php else: ?>
                            <span class="text-secondary"><?php echo Icon::svg('thumb-up', 16); ?></span>
                        <?php endif; ?>
                        <div class="fw-bold mt-1"><?php echo $w['votes']; ?></div>
                        <div class="text-secondary" style="font-size: .7rem"><?php echo $w['votes'] === 1 ? 'Stimme' : 'Stimmen'; ?></div>
                    </div>
                    <div class="flex-fill" style="min-width: 0">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                            <strong><?php echo $e($w['title']); ?></strong>
                            <span class="badge <?php echo $e($statusClass); ?>"><?php echo $e($statusLabel); ?></span>
                            <?php if ($w['mine']): ?><span class="badge bg-purple-lt">dein Wunsch</span><?php endif; ?>
                        </div>
                        <?php if ($w['body'] !== null): ?>
                            <div class="text-secondary small" style="white-space: pre-line"><?php echo $e($w['body']); ?></div>
                        <?php endif; ?>
                        <?php if ($w['answer'] !== null): ?>
                            <div class="mt-2 p-2 rounded small" style="background: var(--tblr-bg-surface-secondary, #f6f8fb)">
                                <strong>Antwort von oView:</strong> <span style="white-space: pre-line"><?php echo $e($w['answer']); ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="text-secondary mt-1" style="font-size: .75rem">
                            <?php echo $w['mine'] ? 'von dir' : 'von einem Händler'; ?> · <?php echo $e($w['created_at']); ?>
                            <?php if ($w['status'] === 'review'): ?> · nur für dich sichtbar, bis wir ihn freigegeben haben<?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
