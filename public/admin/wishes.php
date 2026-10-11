<?php

declare(strict_types=1);

/**
 * connect/public/admin/wishes.php
 *
 * Funktionswuensche aller Shops (Connect\Wishes\WishBoard, Migration 0093): mit Shop-Namen und
 * Unterstuetzern, freigeben, Status setzen (geplant/umgesetzt/nicht geplant), oeffentlich
 * antworten, Text vor der Freigabe anpassen (z.B. Namen entfernen), Doppelte zusammenfuehren.
 * "umgesetzt" schickt Verfasser und Unterstuetzern einen Hinweis in die App.
 *
 * Mit ?shop=…&preview=1 (Merchant-Menue in der Vorschau): die Merchant-Ansicht dieses Shops.
 */

if (isset($_GET['shop'], $_GET['preview'])) {
    $app = require dirname(__DIR__, 2) . '/admin_bootstrap.php';
    $isAdmin = false;
    require dirname(__DIR__) . '/app/_wishes_body.php';

    return;
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Connect\Auth\AdminAuth;
use Connect\Config;
use Connect\Database;
use Connect\Wishes\WishBoard;

$config = Config::load();
$db = Database::connect($config);
$pdo = $db->pdo();

$auth = new AdminAuth($pdo);
$auth->requireLogin();

$board = new WishBoard($pdo);
$available = $board->available();
$e = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES);
$flashMessage = null;
$flashType = 'info';

if ($available && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $wishId = (int) ($_POST['wish_id'] ?? 0);
    try {
        if (($_POST['action'] ?? '') === 'save') {
            $notified = $board->update(
                $wishId,
                (string) ($_POST['title'] ?? ''),
                (string) ($_POST['body'] ?? ''),
                (string) ($_POST['status'] ?? 'open'),
                (string) ($_POST['answer'] ?? ''),
                (string) $auth->currentEmail()
            );
            $flashMessage = 'Gespeichert.' . ($notified > 0 ? " {$notified} Shop(s) bekommen den Hinweis „umgesetzt“ in der App." : '');
            $flashType = 'success';
        } elseif (($_POST['action'] ?? '') === 'merge') {
            $board->merge($wishId, (int) ($_POST['into_id'] ?? 0));
            $flashMessage = 'Zusammengeführt – die Stimmen zählen jetzt beim anderen Wunsch.';
            $flashType = 'success';
        }
    } catch (\Throwable $ex) {
        $flashMessage = $ex->getMessage();
        $flashType = 'danger';
    }
}

$filter = (string) ($_GET['filter'] ?? '');
$wishes = $available ? $board->forAdmin($filter) : [];
$openTargets = $available ? array_filter($board->forAdmin(''), static fn (array $w): bool => in_array($w['status'], ['open', 'planned', 'done'], true)) : [];

$pageTitle = 'Wünsche';
$homeUrl = 'shops.php';
$adminNavItems = require __DIR__ . '/_nav.php';
$topRightHtml = '<span class="text-secondary me-3">' . htmlspecialchars((string) $auth->currentEmail()) . '</span>'
    . '<a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';
require dirname(__DIR__) . '/app/_header.php';

$filters = ['' => 'Alle', 'review' => 'Zu prüfen', 'open' => 'Neu', 'planned' => 'Geplant', 'done' => 'Umgesetzt', 'rejected' => 'Nicht geplant', 'merged' => 'Zusammengeführt'];
?>

<?php if (!$available): ?>
    <div class="alert alert-warning">Migration 0093 fehlt noch.</div>
<?php else: ?>
    <p class="text-secondary">
        Merchants sehen fremde Wünsche erst nach der Freigabe und nie mit Shop-Namen. Vor der Freigabe kannst du Titel und Text anpassen,
        z. B. wenn jemand seinen Shop oder Kundendaten hineingeschrieben hat. „Umgesetzt“ schickt dem Verfasser und allen Unterstützern einen Hinweis in die App.
    </p>

    <div class="btn-group mb-3" role="group">
        <?php foreach ($filters as $key => $label): ?>
            <a href="wishes.php<?php echo $key !== '' ? '?filter=' . $key : ''; ?>" class="btn btn-sm <?php echo $filter === $key ? 'btn-primary' : ''; ?>"><?php echo $e($label); ?></a>
        <?php endforeach; ?>
    </div>

    <?php if ($wishes === []): ?>
        <div class="card"><div class="card-body text-secondary">Keine Wünsche in dieser Ansicht.</div></div>
    <?php endif; ?>

    <?php foreach ($wishes as $w): ?>
        <?php [$statusLabel, $statusClass] = WishBoard::STATUSES[$w['status']] ?? [$w['status'], 'bg-secondary-lt']; ?>
        <div class="card mb-3" id="wish-<?php echo (int) $w['id']; ?>">
            <div class="card-header d-flex flex-wrap align-items-center gap-2">
                <span class="badge <?php echo $e($statusClass); ?>"><?php echo $e($statusLabel); ?></span>
                <strong>#<?php echo (int) $w['id']; ?> <?php echo $e((string) $w['title']); ?></strong>
                <span class="ms-auto text-secondary small">
                    <?php echo \Connect\Ui\Icon::svg('thumb-up', 14, 'me-1'); ?><?php echo (int) $w['votes']; ?> ·
                    <a href="dashboard.php?shop=<?php echo urlencode((string) $w['shop']); ?>"><?php echo $e((string) $w['shop']); ?></a> · <?php echo $e((string) $w['created_label']); ?>
                </span>
            </div>
            <div class="card-body">
                <?php if ($w['supporters'] !== null): ?>
                    <div class="small text-secondary mb-2">Unterstützt von: <?php echo $e((string) $w['supporters']); ?></div>
                <?php endif; ?>
                <?php if ($w['status'] === 'merged'): ?>
                    <div class="small">Zusammengeführt mit <a href="#wish-<?php echo (int) $w['merged_into']; ?>">#<?php echo (int) $w['merged_into']; ?></a>.</div>
                    <?php if ($w['body'] !== null): ?><div class="text-secondary small mt-1" style="white-space: pre-line"><?php echo $e((string) $w['body']); ?></div><?php endif; ?>
                <?php else: ?>
                    <form method="post" class="row g-2">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="wish_id" value="<?php echo (int) $w['id']; ?>">
                        <div class="col-md-8">
                            <label class="form-label small mb-0">Titel</label>
                            <input type="text" name="title" maxlength="150" class="form-control form-control-sm" value="<?php echo $e((string) $w['title']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small mb-0">Status</label>
                            <select name="status" class="form-select form-select-sm">
                                <?php foreach (WishBoard::STATUSES as $key => [$label]): ?>
                                    <?php if ($key === 'merged') continue; ?>
                                    <option value="<?php echo $key; ?>" <?php echo $w['status'] === $key ? 'selected' : ''; ?>>
                                        <?php echo $e($key === 'review' ? 'wird geprüft (nicht sichtbar)' : ($key === 'open' ? 'freigegeben (neu)' : $label)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-0">Beschreibung</label>
                            <textarea name="body" rows="3" class="form-control form-control-sm"><?php echo $e((string) $w['body']); ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-0">Antwort von oView <span class="text-secondary">(für alle sichtbar, optional)</span></label>
                            <textarea name="answer" rows="2" class="form-control form-control-sm" placeholder="z. B. Ist ab dem Abo + enthalten."><?php echo $e((string) $w['answer']); ?></textarea>
                        </div>
                        <div class="col-12 d-flex flex-wrap gap-2 align-items-center">
                            <button type="submit" class="btn btn-sm btn-primary">Speichern</button>
                            <?php if ($w['status'] === 'review'): ?>
                                <button type="submit" name="status" value="open" class="btn btn-sm btn-success">Freigeben</button>
                            <?php endif; ?>
                        </div>
                    </form>
                    <?php $targets = array_filter($openTargets, static fn (array $t): bool => (int) $t['id'] !== (int) $w['id']); ?>
                    <?php if ($targets !== [] && $w['status'] !== 'done'): ?>
                        <form method="post" class="d-flex flex-wrap gap-2 align-items-center mt-2">
                            <input type="hidden" name="action" value="merge">
                            <input type="hidden" name="wish_id" value="<?php echo (int) $w['id']; ?>">
                            <span class="small text-secondary">Doppelt?</span>
                            <select name="into_id" class="form-select form-select-sm" style="max-width: 360px">
                                <?php foreach ($targets as $t): ?>
                                    <option value="<?php echo (int) $t['id']; ?>">#<?php echo (int) $t['id']; ?> <?php echo $e(mb_strimwidth((string) $t['title'], 0, 60, '…')); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-sm" onclick="return confirm('Diesen Wunsch in den gewählten überführen? Die Stimmen wandern mit.')">Zusammenführen</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require dirname(__DIR__) . '/app/_footer.php'; ?>
