<?php

declare(strict_types=1);

/**
 * connect/public/admin/job_log.php
 *
 * Detailansicht EINES Sync-Laufs (connect_sync_runs) -- verlinkt von der
 * Laufhistorie in admin/jobs.php. Zeigt das volle, ungekuerzte Meilenstein-Log
 * (connect_sync_runs.log, siehe Connect\Sync\SyncRunner), im Unterschied zur
 * kurzen error_summary-Vorschau in der Listenansicht -- UND, darunter, jeden
 * einzelnen Schreibvorgang dieses Laufs (connect_activity_log.sync_run_id,
 * Migration 0044) mit vollem Request/Response, genau wie admin/activity_log.php
 * ("Protokoll") das fuer die shop-uebergreifende Sicht zeigt. Bisher zwei
 * unverbundene Ansichten (Nutzer-Feedback) -- ab jetzt an einer Stelle.
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

$runId = (int) ($_GET['run_id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT r.*, m.shop, m.name AS mapping_name
     FROM connect_sync_runs r
     JOIN connect_sync_mappings m ON m.id = r.sync_mapping_id
     WHERE r.id = :id'
);
$stmt->execute(['id' => $runId]);
$run = $stmt->fetch();

if ($run === false) {
    \Connect\Http\ErrorPage::show(
        404,
        'Lauf nicht gefunden',
        'Dieser Eintrag existiert nicht (mehr) oder wurde gelöscht.',
        ['label' => 'Zu den Jobs', 'url' => 'jobs.php']
    );
}

// Einzelne Schreibvorgaenge dieses Laufs (Migration 0044) -- siehe
// Dateikommentar. Aeltere Laeufe (vor dieser Migration) haben hier keine
// Zeilen, das ist kein Fehler, nur schlicht vor der Funktion gelaufen.
$writesStmt = $pdo->prepare(
    "SELECT operation, status, message, request_body, response_body, external_id, created_at
     FROM connect_activity_log
     WHERE sync_run_id = :run_id
     ORDER BY id ASC"
);
$writesStmt->execute(['run_id' => $runId]);
$writes = $writesStmt->fetchAll();

$statusBadge = [
    'success' => 'bg-green-lt',
    'error' => 'bg-red-lt',
    'running' => 'bg-yellow-lt',
    'partial' => 'bg-yellow-lt',
];

/**
 * Siehe admin/activity_log.php (identische kleine Hilfsfunktion, bewusst
 * dupliziert statt in eine gemeinsame Include-Datei ausgelagert -- fuer eine
 * so kleine, reine Anzeige-Hilfsfunktion waere das Overengineering).
 */
function connect_pretty_body(string $body): string
{
    $decoded = json_decode($body, true);
    $pretty = json_last_error() === JSON_ERROR_NONE
        ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        : $body;

    return mb_strlen($pretty) > 20000 ? mb_substr($pretty, 0, 20000) . "\n… (gekürzt)" : $pretty;
}

$pageTitle = 'Lauf-Details';
$homeUrl = 'shops.php';
$adminNavItems = require __DIR__ . '/_nav.php';
$navItems = [];
$breadcrumbs = [['jobs.php', 'Jobs'], [null, 'Lauf #' . $runId]];
$topRightHtml = '<span class="text-secondary me-3">' . htmlspecialchars((string) $auth->currentEmail()) . '</span>'
    . '<a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';

require dirname(__DIR__) . '/app/_header.php';
?>

<div class="row row-cards">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <?php echo htmlspecialchars($run['mapping_name']); ?>
                    <span class="badge <?php echo htmlspecialchars($statusBadge[$run['status']] ?? 'bg-secondary-lt'); ?> ms-2">
                        <?php echo htmlspecialchars($run['status']); ?>
                    </span>
                </h3>
            </div>
            <div class="card-body">
                <dl class="row">
                    <dt class="col-3">Shop</dt>
                    <dd class="col-9"><a href="connections.php?shop=<?php echo urlencode($run['shop']); ?>"><?php echo htmlspecialchars($run['shop']); ?></a></dd>

                    <dt class="col-3">Gestartet</dt>
                    <dd class="col-9"><?php echo htmlspecialchars((string) $run['started_at']); ?></dd>

                    <dt class="col-3">Beendet</dt>
                    <dd class="col-9"><?php echo $run['ended_at'] !== null ? htmlspecialchars((string) $run['ended_at']) : '–'; ?></dd>

                    <dt class="col-3">Datensätze</dt>
                    <dd class="col-9">
                        <?php echo (int) $run['records_read']; ?> gelesen,
                        <?php echo (int) $run['records_written']; ?> geschrieben,
                        <?php echo (int) $run['records_failed']; ?> fehlgeschlagen
                    </dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Log</h3>
            </div>
            <div class="card-body">
                <?php if ($run['log'] !== null && $run['log'] !== ''): ?>
                    <pre class="mb-0" style="white-space: pre-wrap; word-break: break-word;"><?php echo htmlspecialchars($run['log']); ?></pre>
                <?php else: ?>
                    <p class="text-secondary mb-0">Kein Log vorhanden (Lauf ist älter als die Log-Funktion, oder es lief nichts Protokollierbares).</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Einzelne Schreibvorgänge (<?php echo count($writes); ?>)</h3>
            </div>
            <?php if (count($writes) === 0): ?>
                <div class="card-body text-secondary">
                    Keine einzelnen Schreibvorgänge protokolliert -- entweder lief in diesem Lauf nur ein Cache-Refresh
                    (kein Schreiben), oder der Lauf ist älter als diese Funktion (Migration 0044). Für alle Schreib-
                    vorgänge shop-übergreifend siehe <a href="activity_log.php">Protokoll</a>.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>Zeitpunkt</th>
                                <th>Art</th>
                                <th>Status</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($writes as $write): ?>
                                <tr>
                                    <td class="text-secondary text-nowrap"><?php echo htmlspecialchars((string) $write['created_at']); ?></td>
                                    <td><?php echo htmlspecialchars($write['operation']); ?></td>
                                    <td><span class="badge <?php echo htmlspecialchars($statusBadge[$write['status']] ?? 'bg-secondary-lt'); ?>"><?php echo htmlspecialchars($write['status']); ?></span></td>
                                    <td>
                                        <?php echo htmlspecialchars(mb_substr((string) $write['message'], 0, 300)); ?>
                                        <?php if ($write['external_id'] !== null && $write['external_id'] !== ''): ?>
                                            <span class="text-secondary">(<?php echo htmlspecialchars((string) $write['external_id']); ?>)</span>
                                        <?php endif; ?>
                                        <?php if ($write['request_body'] !== null || $write['response_body'] !== null): ?>
                                            <details class="mt-1">
                                                <summary class="text-secondary" style="cursor:pointer">Gesendet/Empfangen anzeigen</summary>
                                                <?php if ($write['request_body'] !== null): ?>
                                                    <div class="text-secondary mt-1">Gesendet:</div>
                                                    <pre class="bg-dark-lt p-2 mb-1" style="white-space:pre-wrap; max-height:300px; overflow:auto;"><?php echo htmlspecialchars(connect_pretty_body((string) $write['request_body'])); ?></pre>
                                                <?php endif; ?>
                                                <?php if ($write['response_body'] !== null): ?>
                                                    <div class="text-secondary mt-1">Empfangen:</div>
                                                    <pre class="bg-dark-lt p-2 mb-0" style="white-space:pre-wrap; max-height:300px; overflow:auto;"><?php echo htmlspecialchars(connect_pretty_body((string) $write['response_body'])); ?></pre>
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
