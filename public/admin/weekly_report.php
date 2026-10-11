<?php

declare(strict_types=1);

/**
 * connect/public/admin/weekly_report.php
 *
 * Vorschau des Wochenberichts (Connect\Notify\WeeklyReport) fuer den gewaehlten Shop und
 * Woche, plus "Testmail an mich" (geht nur an CONNECT_ERROR_NOTIFICATION_EMAIL, nie an den
 * Merchant, und zaehlt nicht als gesendet). Darunter die bisher gesendeten Berichte.
 */

use Connect\Notify\Notifier;
use Connect\Notify\WeeklyReport;

/** @var \Connect\AppContext $app */
$app = require dirname(__DIR__, 2) . '/admin_bootstrap.php';

$isAdmin = true;
$pdo = $app->pdo;
$shop = $app->shop;

$report = new WeeklyReport($pdo, $app->config);
$notifier = new Notifier($pdo, $app->config);

$lastMonday = (string) $pdo->query('SELECT DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) + 7 DAY)')->fetchColumn();
$periodStart = (string) ($_GET['week'] ?? $lastMonday);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodStart) || (int) (new \DateTimeImmutable($periodStart))->format('N') !== 1) {
    $periodStart = $lastMonday;
}

$flashMessage = null;
$flashType = 'info';
$stats = null;
$rendered = null;
$error = null;
try {
    $stats = $report->stats($shop, $periodStart);
    $rendered = $report->render($shop, $stats, $notifier->appUrl($shop));
} catch (\Throwable $e) {
    $error = $e->getMessage();
}

if ($rendered !== null && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_test') {
    [$subject, $text, $html] = $rendered;
    $sent = $notifier->send($notifier->adminEmail(), '[Test] ' . $subject, $text, $html);
    $flashMessage = $sent ? 'Testmail an ' . $notifier->adminEmail() . ' verschickt.' : 'Testmail konnte nicht verschickt werden (mail()).';
    $flashType = $sent ? 'success' : 'danger';
}

$history = [];
try {
    $historyStmt = $pdo->prepare("SELECT period_start, recipient, status, note, created_at FROM connect_report_mails WHERE shop = :shop ORDER BY period_start DESC LIMIT 12");
    $historyStmt->execute(['shop' => $shop]);
    $history = $historyStmt->fetchAll();
} catch (\PDOException) {
    $error ??= 'Migration 0079 fehlt noch.';
}
$settings = $notifier->settings($shop);

$pageTitle = 'Wochenbericht — ' . $shop;
$navItems = require dirname(__DIR__) . '/app/_shop_nav.php';
$homeUrl = 'shops.php';
$adminNavItems = require __DIR__ . '/_nav.php';
$adminEmail = (new \Connect\Auth\AdminAuth($pdo))->currentEmail();
$topRightHtml = '<span class="text-secondary me-3">' . htmlspecialchars((string) $adminEmail) . '</span>'
    . '<a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';

require dirname(__DIR__) . '/app/_header.php';
?>

<div class="row row-cards">
    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-body">
                <form method="get" class="d-flex gap-2 align-items-end mb-3">
                    <input type="hidden" name="shop" value="<?php echo htmlspecialchars($shop); ?>">
                    <div class="flex-fill">
                        <label class="form-label">Woche ab Montag</label>
                        <input type="date" name="week" class="form-control" value="<?php echo htmlspecialchars($periodStart); ?>">
                    </div>
                    <button class="btn">Anzeigen</button>
                </form>
                <div class="small text-secondary mb-3">
                    Empfänger: <strong><?php echo htmlspecialchars($settings['effective'] ?? '– keine Adresse –'); ?></strong>
                    <?php echo $settings['custom'] === null && $settings['shopify'] !== null ? '(aus Shopify)' : ''; ?><br>
                    Wochenbericht: <?php echo $settings['weekly'] ? 'an' : 'aus'; ?> ·
                    automatischer Versand: <?php echo strtolower($app->config->optional('CONNECT_WEEKLY_REPORT', 'on')) === 'off' ? 'aus (CONNECT_WEEKLY_REPORT=off)' : 'an, montags ab 7 Uhr'; ?>
                </div>
                <?php if ($stats !== null && $stats['invoices_ok'] + $stats['invoices_failed'] + $stats['stock_runs'] === 0): ?>
                    <div class="alert alert-info">Keine Aktivität in dieser Woche – der Shop bekäme keinen Bericht.</div>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="action" value="send_test">
                    <button class="btn btn-primary" <?php echo $rendered === null ? 'disabled' : ''; ?>>Testmail an <?php echo htmlspecialchars($notifier->adminEmail()); ?></button>
                </form>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h3 class="card-title">Gesendet</h3></div>
            <div class="list-group list-group-flush">
                <?php foreach ($history as $h): ?>
                    <div class="list-group-item small">
                        <strong><?php echo htmlspecialchars((new \DateTimeImmutable((string) $h['period_start']))->format('d.m.Y')); ?></strong>
                        · <?php echo htmlspecialchars((string) $h['status']); ?>
                        <?php echo $h['recipient'] !== null ? ' an ' . htmlspecialchars((string) $h['recipient']) : ''; ?>
                        <?php echo $h['note'] !== null ? '<span class="text-secondary"> · ' . htmlspecialchars((string) $h['note']) . '</span>' : ''; ?>
                    </div>
                <?php endforeach; ?>
                <?php if ($history === []): ?>
                    <div class="list-group-item text-secondary small">Noch kein Bericht.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><h3 class="card-title"><?php echo $rendered !== null ? htmlspecialchars($rendered[0]) : 'Vorschau'; ?></h3></div>
            <?php if ($error !== null): ?>
                <div class="card-body text-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php elseif ($rendered !== null): ?>
                <iframe title="Vorschau Wochenbericht" sandbox="" style="width:100%;height:640px;border:0" srcdoc="<?php echo htmlspecialchars($rendered[2]); ?>"></iframe>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require dirname(__DIR__) . '/app/_footer.php'; ?>
