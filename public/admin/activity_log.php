<?php

declare(strict_types=1);

/**
 * connect/public/admin/activity_log.php
 *
 * Shop-uebergreifendes Protokoll fuer WIRKLICH ALLES: jeder einzelne
 * Schreibvorgang (Produkt-Import, Lagerbestand-Anpassung, Uebersetzung -- alle
 * ueber Connect\Sync\ActivityLog, inkl. rohem Request/Response), jeder
 * Hintergrund-Sync-Lauf (connect_sync_runs, inkl. Datensatz-Zahlen und dem
 * vollen Zeilen-Log) und jedes empfangene Webhook-Event (connect_webhook_events,
 * inkl. rohem Payload). Ergaenzt jobs.php (dort: NUR die Warteschlange/
 * Lauf-Uebersicht der Hintergrund-Jobs, ohne Einzel-Schreibvorgaenge) um genau
 * diesen fehlenden Teil -- zusammen deckt das beides ab.
 *
 * Quellen (per UNION ALL zusammengefasst, chronologisch sortiert):
 *   - connect_activity_log (Migration 0042/0043): JEDER Schreibvorgang, den
 *     Connect\Sync\ActivityLog::log() protokolliert -- aktuell Produkt-Import
 *     (Connect\Sync\ProductImportService), Lagerbestand-Schreibzugriffe
 *     (Connect\Sync\StockSyncRunner) und Uebersetzungen (Connect\Sync\
 *     SyncRunner::translateAndWrite()). Einzige Quelle mit rohem Request/
 *     Response.
 *   - connect_sync_runs: Hintergrund-Sync-Laeufe (Cache-Aktualisierung,
 *     Uebersetzungs-Batches) -- Datensatz-Zahlen + das volle Zeilen-Log
 *     (Migration 0015) als "Antwort"-Spalte.
 *   - connect_webhook_events: jedes empfangene, HMAC-gepruefte Webhook-Event
 *     inkl. rohem Payload.
 *
 * Bewusst NICHT zusaetzlich connect_stock_sync_log/connect_translation_log
 * (die urspruengliche, dediziert modellierte Version dieser Seite nutzte die)
 * -- deren Inhalte stecken jetzt vollstaendig (und mit mehr Detail) in
 * connect_activity_log, ein zweites Mal einlesen wuerde jede Aktion doppelt
 * zeigen. Diese beiden Tabellen bleiben nur fuer ihre urspruenglichen
 * Verbraucher bestehen (Merchant-Seite "Lagerbestaende", Dedup-Logik).
 *
 * Reines Admin-Feature, kein Shop-Bezug in der URL (Filter stattdessen als
 * Dropdown auf der Seite selbst) -- Muster wie jobs.php.
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

// -----------------------------------------------------------------------
// Drei Quellen (siehe Dateikommentar oben) normalisiert auf dieselbe
// Spaltenform per UNION ALL zusammenfassen, chronologisch sortiert. LIMIT
// bewusst als String direkt
// im SQL (nicht als gebundener Parameter) -- gleiche Konvention wie
// Connect\Sync\ObjectCache::hydrateDetails(), manche MySQL/PDO-Kombinationen
// akzeptieren LIMIT nicht zuverlaessig als String-Bind-Parameter.
//
// WHERE-Klauseln bewusst NUR bei gesetztem Filter eingebaut (statt z.B.
// "WHERE (:shop = '' OR shop = :shop)") -- ein einzelner benannter
// Platzhalter darf in einer Query nicht mehrfach vorkommen, sobald PDO
// echte (nicht emulierte) Prepared Statements nutzt: fuehrt sonst zu
// "SQLSTATE[HY093]: Invalid parameter number" (live auf dem Server
// beobachtet, lokal mit emulierten Prepares nicht reproduzierbar).
// -----------------------------------------------------------------------
$shopCondition1 = $shopFilter !== '' ? 'WHERE shop = :shop1' : '';
$shopCondition2 = $shopFilter !== '' ? 'WHERE m.shop = :shop2' : '';
$shopCondition3 = $shopFilter !== '' ? 'WHERE shop = :shop3' : '';

// COLLATE utf8mb4_unicode_ci auf jede echte Text-Spalte/jeden Ausdruck: die
// drei Quelltabellen stammen aus verschiedenen, ueber die Zeit gewachsenen
// Migrationen und haben nicht alle dieselbe Spalten-Collation -- ohne
// explizite Vereinheitlichung bricht UNION mit "Illegal mix of collations"
// (live auf dem Server beobachtet). Reine NULL-Literale (fehlender
// request_body/external_id in manchen Zweigen) brauchen kein COLLATE, NULL
// ist kollations-neutral.
$entriesStmt = $pdo->prepare(
    "(SELECT shop COLLATE utf8mb4_unicode_ci AS shop, created_at,
             (CASE
                 WHEN operation LIKE 'create_%' THEN 'Produkt-Import'
                 WHEN operation = 'stock_update' THEN 'Lagerbestand'
                 WHEN operation = 'translate' THEN 'Übersetzung'
                 ELSE operation
             END) COLLATE utf8mb4_unicode_ci AS category,
             operation COLLATE utf8mb4_unicode_ci AS operation,
             status COLLATE utf8mb4_unicode_ci AS status,
             message COLLATE utf8mb4_unicode_ci AS message,
             request_body COLLATE utf8mb4_unicode_ci AS request_body,
             response_body COLLATE utf8mb4_unicode_ci AS response_body,
             external_id COLLATE utf8mb4_unicode_ci AS external_id,
             sync_run_id
      FROM connect_activity_log
      {$shopCondition1})
     UNION ALL
     (SELECT m.shop COLLATE utf8mb4_unicode_ci AS shop, r.started_at AS created_at,
             'Sync-Lauf' COLLATE utf8mb4_unicode_ci AS category,
             CONCAT('sync: ', m.name) COLLATE utf8mb4_unicode_ci AS operation,
             r.status COLLATE utf8mb4_unicode_ci AS status,
             CONCAT(
                 m.name, ': ', r.records_read, ' gelesen, ', r.records_written, ' geschrieben',
                 CASE WHEN r.records_failed > 0 THEN CONCAT(', ', r.records_failed, ' fehlgeschlagen') ELSE '' END,
                 CASE WHEN r.error_summary IS NOT NULL THEN CONCAT(' -- ', r.error_summary) ELSE '' END
             ) COLLATE utf8mb4_unicode_ci AS message,
             NULL AS request_body,
             r.log COLLATE utf8mb4_unicode_ci AS response_body,
             CAST(r.job_id AS CHAR) COLLATE utf8mb4_unicode_ci AS external_id,
             r.id AS sync_run_id
      FROM connect_sync_runs r
      JOIN connect_sync_mappings m ON m.id = r.sync_mapping_id
      {$shopCondition2})
     UNION ALL
     (SELECT COALESCE(shop, '(unbekannt)') COLLATE utf8mb4_unicode_ci AS shop, received_at AS created_at,
             'Webhook' COLLATE utf8mb4_unicode_ci AS category,
             topic COLLATE utf8mb4_unicode_ci AS operation,
             status COLLATE utf8mb4_unicode_ci AS status,
             COALESCE(error_message, CONCAT('Empfangen: ', topic)) COLLATE utf8mb4_unicode_ci AS message,
             NULL AS request_body,
             payload COLLATE utf8mb4_unicode_ci AS response_body,
             NULL AS external_id,
             NULL AS sync_run_id
      FROM connect_webhook_events
      {$shopCondition3})
     ORDER BY created_at DESC
     LIMIT " . $limit
);
$entriesStmt->execute($shopFilter !== '' ? ['shop1' => $shopFilter, 'shop2' => $shopFilter, 'shop3' => $shopFilter] : []);
$entries = $entriesStmt->fetchAll();

$shops = $pdo->query('SELECT shop FROM connect_shops ORDER BY shop')->fetchAll(PDO::FETCH_COLUMN);

$statusBadge = [
    'success' => 'bg-green-lt',
    'processed' => 'bg-green-lt',
    'error' => 'bg-red-lt',
    'partial' => 'bg-yellow-lt',
    'running' => 'bg-yellow-lt',
    'received' => 'bg-yellow-lt',
];

/**
 * Fuer die aufklappbaren Details: versucht JSON huebsch einzuruecken (GraphQL-
 * Bodies/Antworten sind JSON), gibt sonst den Rohtext unveraendert zurueck
 * (z.B. XML bei MGM, oder das volle Sync-Lauf-Zeilen-Log) -- keine Annahme
 * ueber das Format erzwingen. Auf 20000 Zeichen gekuerzt -- ein Sync-Lauf-Log
 * kann bei einem grossen Katalog sehr lang werden, das wuerde sonst die Seite
 * aufblaehen (das <pre> darunter scrollt zusaetzlich).
 */
function connect_pretty_body(string $body): string
{
    $decoded = json_decode($body, true);
    $pretty = json_last_error() === JSON_ERROR_NONE
        ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        : $body;

    return mb_strlen($pretty) > 20000 ? mb_substr($pretty, 0, 20000) . "\n… (gekürzt)" : $pretty;
}

$pageTitle = 'Protokoll';
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
                <h3 class="card-title">Protokoll (letzte <?php echo $limit; ?>)</h3>
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
            <?php if (count($entries) === 0): ?>
                <div class="card-body text-secondary">Noch nichts protokolliert<?php echo $shopFilter !== '' ? ' für "' . htmlspecialchars($shopFilter) . '"' : ''; ?>.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>Zeitpunkt</th>
                                <th>Shop</th>
                                <th>Art</th>
                                <th>Status</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($entries as $entry): ?>
                                <tr>
                                    <td class="text-secondary text-nowrap"><?php echo htmlspecialchars((string) $entry['created_at']); ?></td>
                                    <td><a href="connections.php?shop=<?php echo urlencode($entry['shop']); ?>"><?php echo htmlspecialchars($entry['shop']); ?></a></td>
                                    <td><?php echo htmlspecialchars($entry['category']); ?></td>
                                    <td><span class="badge <?php echo htmlspecialchars($statusBadge[$entry['status']] ?? 'bg-secondary-lt'); ?>"><?php echo htmlspecialchars($entry['status']); ?></span></td>
                                    <td>
                                        <?php echo htmlspecialchars(mb_substr((string) $entry['message'], 0, 300)); ?>
                                        <?php if ($entry['external_id'] !== null && $entry['external_id'] !== ''): ?>
                                            <span class="text-secondary">(<?php echo htmlspecialchars((string) $entry['external_id']); ?>)</span>
                                        <?php endif; ?>
                                        <?php if ($entry['sync_run_id'] !== null): ?>
                                            <a href="job_log.php?run_id=<?php echo (int) $entry['sync_run_id']; ?>" class="text-secondary">→ Lauf #<?php echo (int) $entry['sync_run_id']; ?></a>
                                        <?php endif; ?>
                                        <?php if ($entry['request_body'] !== null || $entry['response_body'] !== null): ?>
                                            <details class="mt-1">
                                                <summary class="text-secondary" style="cursor:pointer">Gesendet/Empfangen anzeigen</summary>
                                                <?php if ($entry['request_body'] !== null): ?>
                                                    <div class="text-secondary mt-1">Gesendet:</div>
                                                    <pre class="bg-dark-lt p-2 mb-1" style="white-space:pre-wrap; max-height:300px; overflow:auto;"><?php echo htmlspecialchars(connect_pretty_body((string) $entry['request_body'])); ?></pre>
                                                <?php endif; ?>
                                                <?php if ($entry['response_body'] !== null): ?>
                                                    <div class="text-secondary mt-1">Empfangen:</div>
                                                    <pre class="bg-dark-lt p-2 mb-0" style="white-space:pre-wrap; max-height:300px; overflow:auto;"><?php echo htmlspecialchars(connect_pretty_body((string) $entry['response_body'])); ?></pre>
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