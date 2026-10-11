<?php

declare(strict_types=1);

/**
 * connect/public/admin/invoices_overview.php
 *
 * Shop-uebergreifende Uebersicht "Rechnungen" -- Gegenstueck zur shop-
 * bezogenen Seite app/invoices.php (dort: EIN Shop, Status-Karte +
 * Pagination), hier: ALLE Shops auf einen Blick, dafuer mit fester
 * Obergrenze statt Pagination (gleiches Prinzip wie admin/jobs.php und
 * admin/activity_log.php: "letzte N", kein Deep-Dive -- fuer den vollen
 * Verlauf eines einzelnen Shops gibt es die shop-bezogene Seite).
 *
 * Quelle: connect_activity_log (object_key='invoice', operation='create'),
 * gleiche Quelle wie app/_invoices_body.php. Verlinkt (sync_run_id) zum
 * ausloesenden Sync-Lauf (admin/job_log.php) -- zeigt, ob eine Rechnung ueber
 * den Webhook direkt oder ueber den periodischen "Uebersetzungs-Nachlauf"
 * (Sicherheitsnetz, siehe SyncRunner::runScheduledTranslationBatch())
 * entstanden ist.
 *
 * Bewusst eine EIGENE Seite statt gemeinsam mit "Lagerbestand" (fruehere
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

$invoiceCondition = $shopFilter !== '' ? 'AND shop = :shop1' : '';
$invoicesStmt = $pdo->prepare(
    "SELECT shop, status, message, external_id, created_at, request_body, response_body, sync_run_id
     FROM connect_activity_log
     WHERE object_key = 'invoice' AND operation = 'create' {$invoiceCondition}
     ORDER BY created_at DESC, id DESC
     LIMIT " . $limit
);
$invoicesStmt->execute($shopFilter !== '' ? ['shop1' => $shopFilter] : []);
$invoiceRows = $invoicesStmt->fetchAll();

$shops = $pdo->query('SELECT shop FROM connect_shops ORDER BY shop')->fetchAll(PDO::FETCH_COLUMN);

$statusBadge = ['success' => 'bg-green-lt', 'error' => 'bg-red-lt'];

/**
 * Siehe app/_invoices_body.php::connect_invoice_split_order_number() --
 * identische Logik, hier dupliziert statt geteilt (kein gemeinsamer Include
 * dieser beiden ansonsten unabhaengigen Seiten), gleiches Prinzip wie
 * connect_pretty_body()/connect_invoice_pretty_body() an mehreren Stellen.
 *
 * @return array{0: ?string, 1: string}
 */
function connect_invoices_overview_split_order_number(string $message): array
{
    if (preg_match('/^Bestellung ([^:]+): (.*)$/s', $message, $matches) === 1) {
        return [$matches[1], $matches[2]];
    }

    return [null, $message];
}

function connect_invoices_overview_pretty_body(string $body): string
{
    $decoded = json_decode($body, true);
    $pretty = json_last_error() === JSON_ERROR_NONE
        ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        : $body;

    return mb_strlen($pretty) > 20000 ? mb_substr($pretty, 0, 20000) . "\n… (gekürzt)" : $pretty;
}

$pageTitle = 'Rechnungen';
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
                <h3 class="card-title">Rechnungen (letzte <?php echo $limit; ?>)</h3>
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
            <?php if (count($invoiceRows) === 0): ?>
                <div class="card-body text-secondary">Noch keine Rechnung angelegt<?php echo $shopFilter !== '' ? ' für "' . htmlspecialchars($shopFilter) . '"' : ''; ?>.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>Datum</th>
                                <th>Shop</th>
                                <th>Bestellung</th>
                                <th>Rechnungs-Nr.</th>
                                <th>Status</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invoiceRows as $row): ?>
                                <?php [$orderNumber, $restMessage] = connect_invoices_overview_split_order_number((string) $row['message']); ?>
                                <tr>
                                    <td class="text-secondary text-nowrap"><?php echo htmlspecialchars((string) $row['created_at']); ?></td>
                                    <td><a href="invoices.php?shop=<?php echo urlencode($row['shop']); ?>"><?php echo htmlspecialchars($row['shop']); ?></a></td>
                                    <td><?php echo $orderNumber !== null ? htmlspecialchars($orderNumber) : '<span class="text-secondary">?</span>'; ?></td>
                                    <td><?php echo $row['external_id'] !== null && $row['external_id'] !== '' ? htmlspecialchars((string) $row['external_id']) : '<span class="text-secondary">—</span>'; ?></td>
                                    <td><span class="badge <?php echo htmlspecialchars($statusBadge[$row['status']] ?? 'bg-secondary-lt'); ?>"><?php echo htmlspecialchars((string) $row['status']); ?></span></td>
                                    <td>
                                        <?php echo htmlspecialchars(mb_substr($restMessage, 0, 200)); ?>
                                        <?php if ($row['sync_run_id'] !== null): ?>
                                            <a href="job_log.php?run_id=<?php echo (int) $row['sync_run_id']; ?>" class="text-secondary">→ Lauf #<?php echo (int) $row['sync_run_id']; ?></a>
                                        <?php endif; ?>
                                        <?php if ($row['request_body'] !== null || $row['response_body'] !== null): ?>
                                            <details class="mt-1">
                                                <summary class="text-secondary" style="cursor:pointer">Gesendet/Empfangen anzeigen</summary>
                                                <?php if ($row['request_body'] !== null): ?>
                                                    <div class="text-secondary mt-1">Gesendet:</div>
                                                    <pre class="bg-dark-lt p-2 mb-1" style="white-space:pre-wrap; max-height:300px; overflow:auto;"><?php echo htmlspecialchars(connect_invoices_overview_pretty_body((string) $row['request_body'])); ?></pre>
                                                <?php endif; ?>
                                                <?php if ($row['response_body'] !== null): ?>
                                                    <div class="text-secondary mt-1">Empfangen:</div>
                                                    <pre class="bg-dark-lt p-2 mb-0" style="white-space:pre-wrap; max-height:300px; overflow:auto;"><?php echo htmlspecialchars(connect_invoices_overview_pretty_body((string) $row['response_body'])); ?></pre>
                                                <?php endif; ?>
                                            </details>
                                        <?php endif; ?>
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
