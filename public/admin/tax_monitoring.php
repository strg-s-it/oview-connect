<?php

declare(strict_types=1);

/**
 * connect/public/admin/tax_monitoring.php
 *
 * Shop-uebergreifendes Monitoring fuer die Steuer-Kontrollrechnung und die
 * Versand-Steuersatz-Aufteilung (Connect\Sync\InvoiceService, Migration 0057,
 * Nutzer-Wunsch 2026-09-27: "für das Steuerthema und die Rechnungskontrolle
 * gerne eine Übersicht ... ich brauche ein gutes Monitoring für mich").
 *
 * Beide Mechanismen protokollieren rein ueber die Nachricht in
 * connect_activity_log (keine eigenen Spalten/Tabellen -- siehe
 * InvoiceService::createForOrder()/buildShippingItems()), diese Seite filtert
 * gezielt danach:
 *   - "Steuer-Kontrolle fehlgeschlagen": Rechnung wurde NICHT angelegt, weil
 *     die selbst berechnete Steuersumme von Shopifys eigener abweicht --
 *     das WICHTIGSTE Bucket, braucht i.d.R. manuelle Pruefung der Bestellung.
 *     Enthaelt seit Migration 0064 auch "Betrags-Kontrolle fehlgeschlagen"
 *     (Summe der Positionen weicht von Shopifys Gesamtbetrag ab).
 *   - "Steuer-Kontrolle übersprungen": "total_tax" war am Bestell-Datensatz
 *     nicht verfuegbar (Migration 0057 noch nicht installiert oder Datensatz
 *     vor der Installation gecacht) -- die Rechnung wurde trotzdem angelegt,
 *     aber UNGEPRUEFT. Nach Shop gruppiert, damit sichtbar wird, wo noch ein
 *     Cache-Refresh fehlt.
 *   - "Versand auf ... Steuersätze aufgeteilt": rein informativ, zeigt wo die
 *     neue Mehrfach-Steuersatz-Aufteilung tatsaechlich gegriffen hat.
 *
 * Reines Admin-Feature, kein Shop-Bezug in der URL (Filter stattdessen als
 * Dropdown auf der Seite selbst) -- Muster wie activity_log.php/jobs.php/
 * invoices_overview.php.
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

$shopCondition = $shopFilter !== '' ? 'AND shop = :shop' : '';

function connect_tax_monitor_count(PDO $pdo, string $likePattern, string $shopCondition, string $shopFilter): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM connect_activity_log
         WHERE object_key = 'invoice' AND operation = 'create' AND message LIKE :pattern {$shopCondition}"
    );
    $params = ['pattern' => $likePattern];
    if ($shopFilter !== '') {
        $params['shop'] = $shopFilter;
    }
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

$failedCount = connect_tax_monitor_count($pdo, '%-Kontrolle fehlgeschlagen%', $shopCondition, $shopFilter);
$skippedCount = connect_tax_monitor_count($pdo, '%Steuer-Kontrolle übersprungen%', $shopCondition, $shopFilter);
$splitCount = connect_tax_monitor_count($pdo, '%Steuersätze aufgeteilt%', $shopCondition, $shopFilter);

/**
 * @return array<int, array<string, mixed>>
 */
function connect_tax_monitor_rows(PDO $pdo, string $likePattern, string $shopCondition, string $shopFilter, int $limit): array
{
    $stmt = $pdo->prepare(
        "SELECT shop, message, status, created_at, sync_run_id
         FROM connect_activity_log
         WHERE object_key = 'invoice' AND operation = 'create' AND message LIKE :pattern {$shopCondition}
         ORDER BY created_at DESC, id DESC
         LIMIT {$limit}"
    );
    $params = ['pattern' => $likePattern];
    if ($shopFilter !== '') {
        $params['shop'] = $shopFilter;
    }
    $stmt->execute($params);

    return $stmt->fetchAll();
}

$failedRows = connect_tax_monitor_rows($pdo, '%-Kontrolle fehlgeschlagen%', $shopCondition, $shopFilter, $limit);
$splitRows = connect_tax_monitor_rows($pdo, '%Steuersätze aufgeteilt%', $shopCondition, $shopFilter, $limit);

// Uebersprungene Kontrollen nach Shop gruppiert (siehe Dateikommentar) --
// zeigt WO noch ein Cache-Refresh fehlt, statt jede einzelne Bestellung.
$skippedByShopStmt = $pdo->prepare(
    "SELECT shop, COUNT(*) AS cnt, MAX(created_at) AS last_seen
     FROM connect_activity_log
     WHERE object_key = 'invoice' AND operation = 'create' AND message LIKE '%Steuer-Kontrolle übersprungen%' {$shopCondition}
     GROUP BY shop
     ORDER BY last_seen DESC"
);
$skippedByShopStmt->execute($shopFilter !== '' ? ['shop' => $shopFilter] : []);
$skippedByShop = $skippedByShopStmt->fetchAll();

$shops = $pdo->query('SELECT shop FROM connect_shops ORDER BY shop')->fetchAll(PDO::FETCH_COLUMN);

/**
 * @return array{0: ?string, 1: string}
 */
function connect_tax_monitor_split_order_number(string $message): array
{
    if (preg_match('/^Bestellung ([^:]+): (.*)$/s', $message, $matches) === 1) {
        return [$matches[1], $matches[2]];
    }

    return [null, $message];
}

$pageTitle = 'Steuer-Kontrolle';
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
        </div>
    </div>

    <div class="col-sm-6 col-lg-4">
        <div class="card">
            <div class="card-body">
                <div class="text-secondary">Rechnungen wegen Steuerabweichung blockiert</div>
                <div class="h1 mb-0 <?php echo $failedCount > 0 ? 'text-danger' : ''; ?>"><?php echo $failedCount; ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-4">
        <div class="card">
            <div class="card-body">
                <div class="text-secondary">Steuer-Kontrolle übersprungen (ungeprüft angelegt)</div>
                <div class="h1 mb-0 <?php echo $skippedCount > 0 ? 'text-warning' : ''; ?>"><?php echo $skippedCount; ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-4">
        <div class="card">
            <div class="card-body">
                <div class="text-secondary">Versand auf mehrere Steuersätze aufgeteilt</div>
                <div class="h1 mb-0"><?php echo $splitCount; ?></div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title text-danger">Blockierte Rechnungen (letzte <?php echo $limit; ?>)</h3>
            </div>
            <p class="text-secondary px-3 pt-2 mb-0">
                Die berechnete Steuersumme weicht von Shopifys eigener Steuersumme ab -- die Rechnung wurde zur
                Sicherheit NICHT angelegt. Bitte die Bestellung prüfen und die Rechnung ggf. manuell in HelloCash anlegen.
            </p>
            <?php if (count($failedRows) === 0): ?>
                <div class="card-body text-secondary">Keine blockierten Rechnungen<?php echo $shopFilter !== '' ? ' für "' . htmlspecialchars($shopFilter) . '"' : ''; ?> — gut so.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>Datum</th>
                                <th>Shop</th>
                                <th>Bestellung</th>
                                <th>Nachricht</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($failedRows as $row): ?>
                                <?php [$orderNumber, $restMessage] = connect_tax_monitor_split_order_number((string) $row['message']); ?>
                                <tr>
                                    <td class="text-secondary text-nowrap"><?php echo htmlspecialchars((string) $row['created_at']); ?></td>
                                    <td><a href="invoices.php?shop=<?php echo urlencode($row['shop']); ?>"><?php echo htmlspecialchars($row['shop']); ?></a></td>
                                    <td><?php echo $orderNumber !== null ? htmlspecialchars($orderNumber) : '<span class="text-secondary">?</span>'; ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($restMessage); ?>
                                        <?php if ($row['sync_run_id'] !== null): ?>
                                            <a href="job_log.php?run_id=<?php echo (int) $row['sync_run_id']; ?>" class="text-secondary">→ Lauf #<?php echo (int) $row['sync_run_id']; ?></a>
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

    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Übersprungene Kontrollen nach Shop</h3>
            </div>
            <p class="text-secondary px-3 pt-2 mb-0">
                Für diese Shops fehlte bei mindestens einer Rechnung das Feld "total_tax" -- die Rechnung wurde
                trotzdem angelegt, aber UNGEPRÜFT. Meist ein Zeichen, dass die Bestellung vor der Installation von
                Migration 0057 gecacht wurde. Behebt sich von selbst, sobald der Datensatz neu geladen wird.
            </p>
            <?php if (count($skippedByShop) === 0): ?>
                <div class="card-body text-secondary">Keine übersprungenen Kontrollen<?php echo $shopFilter !== '' ? ' für "' . htmlspecialchars($shopFilter) . '"' : ''; ?>.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>Shop</th>
                                <th class="text-end">Anzahl</th>
                                <th>Zuletzt</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($skippedByShop as $row): ?>
                                <tr>
                                    <td><a href="invoices.php?shop=<?php echo urlencode($row['shop']); ?>"><?php echo htmlspecialchars($row['shop']); ?></a></td>
                                    <td class="text-end"><?php echo (int) $row['cnt']; ?></td>
                                    <td class="text-secondary text-nowrap"><?php echo htmlspecialchars((string) $row['last_seen']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Versand mit mehreren Steuersätzen (letzte <?php echo $limit; ?>)</h3>
            </div>
            <p class="text-secondary px-3 pt-2 mb-0">
                Rein informativ: hier hat der Warenkorb Artikel mit unterschiedlichen Steuersätzen gemischt, der
                Versand wurde entsprechend anteilig auf mehrere Rechnungspositionen aufgeteilt.
            </p>
            <?php if (count($splitRows) === 0): ?>
                <div class="card-body text-secondary">Noch kein Fall aufgetreten<?php echo $shopFilter !== '' ? ' für "' . htmlspecialchars($shopFilter) . '"' : ''; ?>.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>Datum</th>
                                <th>Shop</th>
                                <th>Bestellung</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($splitRows as $row): ?>
                                <?php [$orderNumber, $restMessage] = connect_tax_monitor_split_order_number((string) $row['message']); ?>
                                <tr>
                                    <td class="text-secondary text-nowrap"><?php echo htmlspecialchars((string) $row['created_at']); ?></td>
                                    <td><a href="invoices.php?shop=<?php echo urlencode($row['shop']); ?>"><?php echo htmlspecialchars($row['shop']); ?></a></td>
                                    <td><?php echo $orderNumber !== null ? htmlspecialchars($orderNumber) : '<span class="text-secondary">?</span>'; ?></td>
                                    <td class="text-secondary"><?php echo htmlspecialchars($restMessage); ?></td>
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
