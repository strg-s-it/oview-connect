<?php

declare(strict_types=1);

/**
 * connect/public/admin/jobs.php
 *
 * Shop-uebergreifendes Jobs-Dashboard: aktuelle Warteschlange
 * (connect_sync_jobs, pending/running) oben, Laufhistorie (connect_sync_runs)
 * darunter -- dort je Zeile zusaetzlich "Erneut ausfuehren" (action=run_now,
 * dieselbe Logik wie zuvor nur auf der Shop-Sync-Seite), damit ein einzelner
 * Job nicht mehr zwingend ueber die Shop-Ansicht (sync_mappings.php) neu
 * angestossen werden muss. Reines Admin-Feature, kein Shop-Bezug (siehe
 * $adminNavItems statt $navItems in _header.php) -- zeigt bewusst ALLE Shops
 * auf einen Blick, damit sich haengende/fehlgeschlagene Jobs nicht erst pro
 * Shop einzeln finden lassen muessen.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Connect\Auth\AdminAuth;
use Connect\Config;
use Connect\Connector\ConnectorFactory;
use Connect\Database;
use Connect\Http\HttpClient;
use Connect\Security\Crypto;
use Connect\Shopify\TokenRefresher;
use Connect\Sync\SyncRunner;

$config = Config::load();
$db = Database::connect($config);
$pdo = $db->pdo();

$auth = new AdminAuth($pdo);
$auth->requireLogin();

$flashMessage = null;
$flashType = 'info';

$buildFactory = function () use ($pdo, $config): ConnectorFactory {
    $tokenRefresher = TokenRefresher::fromConfig($config);

    return new ConnectorFactory($pdo, new Crypto($config->require('CONNECT_ENCRYPTION_KEY')), new HttpClient(), $tokenRefresher);
};

// -----------------------------------------------------------------------
// POST: Job manuell abbrechen (auf "error" setzen) -- fuer haengengebliebene
// Jobs, ohne auf die 30-Minuten-Stale-Erkennung im Scheduler warten zu
// muessen. Setzt NICHT auf "pending" zurueck (koennte den naechsten
// Scheduler-Aufruf sofort erneut claimen lassen, bevor das eigentliche
// Problem geklaert ist) -- bei Bedarf stattdessen "Jetzt ausfuehren" weiter
// unten nutzen, um es gezielt neu anzustossen (jetzt direkt hier moeglich,
// keine Notwendigkeit mehr, dafuer in die Shop-Ansicht zu wechseln).
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_job') {
    $jobId = (int) ($_POST['job_id'] ?? 0);

    $pdo->prepare("UPDATE connect_sync_jobs SET status = 'error', ended_at = NOW() WHERE id = :id AND status IN ('pending', 'running')")
        ->execute(['id' => $jobId]);

    $flashMessage = "Job #{$jobId} abgebrochen.";
    $flashType = 'success';
}

// -----------------------------------------------------------------------
// POST: Heartbeat-Eintrag entfernen -- z.B. nach Umbenennung eines Servers
// (CONNECT_WORKER_NAME) bleibt der alte Name sonst fuer immer als "steht still"
// stehen. Nur Eintraege, die laenger als einen Tag still sind.
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_heartbeat') {
    $removeHost = (string) ($_POST['host'] ?? '');
    $removeStmt = $pdo->prepare('DELETE FROM connect_scheduler_heartbeats WHERE host = :host AND last_run_at < NOW() - INTERVAL 1 DAY');
    $removeStmt->execute(['host' => $removeHost]);
    $flashMessage = $removeStmt->rowCount() > 0 ? "Eintrag \"{$removeHost}\" entfernt." : 'Nur Einträge, die länger als einen Tag still sind, lassen sich entfernen.';
    $flashType = $removeStmt->rowCount() > 0 ? 'success' : 'info';
}

// -----------------------------------------------------------------------
// POST: Aktivierung jetzt manuell ausfuehren -- gleiche Logik wie zuvor nur
// auf der Shop-Sync-Seite (_sync_mappings_body.php, action=run_now), jetzt
// zusaetzlich hier verfuegbar, damit ein Admin nicht extra in die Shop-
// Ansicht wechseln muss, nur um einen haengenden/fehlgeschlagenen Job gezielt
// neu anzustossen. Laeuft synchron im Request, wie der Scheduler es taete.
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_now') {
    $mappingId = (int) ($_POST['mapping_id'] ?? 0);

    $mappingStmt = $pdo->prepare('SELECT * FROM connect_sync_mappings WHERE id = :id');
    $mappingStmt->execute(['id' => $mappingId]);
    $mapping = $mappingStmt->fetch();

    if ($mapping === false) {
        $flashMessage = 'Aktivierung nicht gefunden.';
        $flashType = 'danger';
    } else {
        try {
            (new SyncRunner($pdo, $buildFactory()))->run($mapping);
            $flashMessage = "\"{$mapping['name']}\" ({$mapping['shop']}) wurde synchronisiert.";
            $flashType = 'success';
        } catch (\Throwable $e) {
            $flashMessage = "Synchronisierung von \"{$mapping['name']}\" ({$mapping['shop']}) fehlgeschlagen: " . $e->getMessage();
            $flashType = 'danger';
        }
    }
}

// -----------------------------------------------------------------------
// Scheduler-Heartbeat -- pro Host der Zeitpunkt des letzten Aufrufs von
// bin/scheduler.php (siehe recordHeartbeat() dort), unabhaengig davon, ob
// dabei ein Job geclaimt wurde. Bei einem Ein-Minuten-Cron sollte das immer
// nur wenige Minuten her sein -- alles darueber deutet auf einen
// fehlenden/kaputten Cronjob hin.
// -----------------------------------------------------------------------
$heartbeats = $pdo->query(
    'SELECT host, last_run_at, TIMESTAMPDIFF(MINUTE, last_run_at, NOW()) AS minutes_ago
     FROM connect_scheduler_heartbeats ORDER BY host'
)->fetchAll();

// -----------------------------------------------------------------------
// Anfrage-Limits (Migration 0053, Connect\Http\RateLimiter): pro Connector
// Type mit Limit der Stand des aktuellen Fensters, eine evtl. Sperre nach
// HTTP 429 und wie oft Jobs in den letzten 24h am Limit abgebrochen sind.
// Fehlt Tabelle/Spalte, bleibt die Karte einfach leer.
// -----------------------------------------------------------------------
$rateLimits = [];
$rateLimitAborts = null;
try {
    $rateLimitSql = static fn (string $scopeColumn): string =>
        "SELECT t.id, t.name, t.rate_limit_max_requests, t.rate_limit_window_seconds, {$scopeColumn} AS scope,
                s.request_count, s.blocked_until,
                TIMESTAMPDIFF(SECOND, s.window_started_at, NOW()) AS window_elapsed,
                TIMESTAMPDIFF(SECOND, NOW(), s.blocked_until) AS blocked_seconds
         FROM connect_connector_types t
         LEFT JOIN connect_rate_limit_state s ON s.connector_type_id = t.id
         WHERE t.rate_limit_max_requests IS NOT NULL
         ORDER BY t.name, scope";
    try {
        // je ausgehender IP (Migration 0070)
        $rateLimits = $pdo->query($rateLimitSql('s.scope'))->fetchAll();
    } catch (\Throwable) {
        $rateLimits = $pdo->query($rateLimitSql("''"))->fetchAll();
    }
    $rateLimitAborts = (int) $pdo->query(
        "SELECT COUNT(*) FROM connect_sync_runs
         WHERE started_at > NOW() - INTERVAL 1 DAY AND error_summary LIKE '%Anfrage-Limit erreicht%'"
    )->fetchColumn();
} catch (\Throwable) {
    $rateLimits = [];
}
// -----------------------------------------------------------------------
// Shopify je Shop (Migration 0068, Connect\Http\ApiUsageRecorder): Kosten-Eimer,
// Aufrufe/Kosten/Drosselungen/Fehler, Webhooks der letzten 24 h. Details je Shop
// in shopify_activity.php.
// -----------------------------------------------------------------------
$shopifyRows = [];
$shopifyUsageMissing = false;
try {
    $shopifyRows = $pdo->query(
        "SELECT c.id, c.shop, c.status, s.live_in_connect,
                b.currently_available, b.maximum_available, b.restore_rate, b.last_throttled_at,
                TIMESTAMPDIFF(MINUTE, b.updated_at, NOW()) AS bucket_minutes_ago,
                (SELECT COALESCE(SUM(u.calls), 0) FROM connect_api_usage_hourly u WHERE u.connection_id = c.id AND u.hour_start >= NOW() - INTERVAL 1 HOUR) AS calls_1h,
                (SELECT COALESCE(SUM(u.calls), 0) FROM connect_api_usage_hourly u WHERE u.connection_id = c.id AND u.hour_start >= NOW() - INTERVAL 1 DAY) AS calls_24h,
                (SELECT COALESCE(SUM(u.cost), 0) FROM connect_api_usage_hourly u WHERE u.connection_id = c.id AND u.hour_start >= NOW() - INTERVAL 1 DAY) AS cost_24h,
                (SELECT COALESCE(SUM(u.throttled), 0) FROM connect_api_usage_hourly u WHERE u.connection_id = c.id AND u.hour_start >= NOW() - INTERVAL 1 DAY) AS throttled_24h,
                (SELECT COALESCE(SUM(u.errors), 0) FROM connect_api_usage_hourly u WHERE u.connection_id = c.id AND u.hour_start >= NOW() - INTERVAL 1 DAY) AS errors_24h
         FROM connect_connections c
         JOIN connect_connector_types t ON t.id = c.connector_type_id AND t.type_key = 'shopify'
         LEFT JOIN connect_shops s ON s.shop = c.shop
         LEFT JOIN connect_api_bucket b ON b.connection_id = c.id
         ORDER BY c.shop"
    )->fetchAll();
} catch (\Throwable) {
    $shopifyUsageMissing = true;
}
$webhookCounts = [];
foreach ($pdo->query(
    "SELECT shop, status, COUNT(*) AS n FROM connect_webhook_events
     WHERE received_at >= NOW() - INTERVAL 1 DAY GROUP BY shop, status"
)->fetchAll() as $row) {
    $webhookCounts[$row['shop']][$row['status']] = (int) $row['n'];
}

$schedulerBudget = max(10, (int) $config->optional('CONNECT_SCHEDULER_TIME_BUDGET', '50'));
$schedulerParallel = max(1, (int) $config->optional('CONNECT_SCHEDULER_MAX_PARALLEL', '3'));

// -----------------------------------------------------------------------
// Aktuelle Warteschlange -- nur pending/running, das ist der "was passiert
// gerade"-Teil. Laenger als 30 Minuten laufende Jobs sind vermutlich
// haengengeblieben (siehe STALE_MINUTES in bin/scheduler.php) und werden
// separat markiert.
// -----------------------------------------------------------------------
$queueStmt = $pdo->query(
    "SELECT j.id, j.status, j.scheduled_for, j.started_at, j.locked_by, j.attempt_count,
            m.shop, m.name AS mapping_name, m.schedule_interval_minutes,
            TIMESTAMPDIFF(MINUTE, j.started_at, NOW()) AS running_minutes
     FROM connect_sync_jobs j
     JOIN connect_sync_mappings m ON m.id = j.sync_mapping_id
     WHERE j.status IN ('pending', 'running')
     ORDER BY j.scheduled_for ASC
     LIMIT 50"
);
$queue = $queueStmt->fetchAll();

// -----------------------------------------------------------------------
// Laufhistorie -- letzte 100 abgeschlossenen Laeufe ueber alle Shops.
// -----------------------------------------------------------------------
$historyStmt = $pdo->query(
    "SELECT r.id, r.status, r.started_at, r.ended_at, r.records_read, r.records_written, r.records_failed, r.error_summary,
            m.id AS mapping_id, m.shop, m.name AS mapping_name, m.schedule_interval_minutes, j.locked_by
     FROM connect_sync_runs r
     JOIN connect_sync_mappings m ON m.id = r.sync_mapping_id
     LEFT JOIN connect_sync_jobs j ON j.id = r.job_id
     ORDER BY r.id DESC
     LIMIT 100"
);
$history = $historyStmt->fetchAll();

$statusBadge = [
    'success' => 'bg-green-lt',
    'done' => 'bg-green-lt',
    'error' => 'bg-red-lt',
    'running' => 'bg-yellow-lt',
    'pending' => 'bg-secondary-lt',
    'partial' => 'bg-yellow-lt',
];

$pageTitle = 'Jobs';
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
                <h3 class="card-title">Scheduler-Heartbeat</h3>
            </div>
            <?php if (count($heartbeats) === 0): ?>
                <div class="card-body">
                    <div class="alert alert-danger mb-0">
                        Noch kein Heartbeat empfangen -- der Cronjob (bin/scheduler.php) lief seit Einführung dieser Anzeige noch nicht, oder läuft gar nicht.
                    </div>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>Host</th>
                                <th>Zuletzt gelaufen</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($heartbeats as $heartbeat): ?>
                                <?php
                                $minutesAgo = (int) $heartbeat['minutes_ago'];
                                if ($minutesAgo <= 2) {
                                    $heartbeatBadge = 'bg-green-lt';
                                    $heartbeatLabel = 'läuft';
                                } elseif ($minutesAgo <= 5) {
                                    $heartbeatBadge = 'bg-yellow-lt';
                                    $heartbeatLabel = 'verzögert';
                                } elseif ($minutesAgo <= 24 * 60) {
                                    $heartbeatBadge = 'bg-red-lt';
                                    $heartbeatLabel = 'steht vermutlich still';
                                } else {
                                    // Laenger als einen Tag still: meist ein umbenannter oder abgebauter Server
                                    $heartbeatBadge = 'bg-secondary-lt';
                                    $heartbeatLabel = 'nicht mehr aktiv';
                                }
                                ?>
                                <tr class="<?php echo $minutesAgo > 24 * 60 ? 'text-secondary' : ''; ?>">
                                    <td><?php echo htmlspecialchars($heartbeat['host']); ?></td>
                                    <td class="text-secondary"><?php echo htmlspecialchars((string) $heartbeat['last_run_at']); ?> (vor <?php echo $minutesAgo >= 120 ? intdiv($minutesAgo, 60) . ' Std.' : $minutesAgo . ' Min.'; ?>)</td>
                                    <td>
                                        <span class="badge <?php echo $heartbeatBadge; ?>"><?php echo $heartbeatLabel; ?></span>
                                        <?php if ($minutesAgo > 24 * 60): ?>
                                            <form method="post" class="d-inline ms-2">
                                                <input type="hidden" name="action" value="remove_heartbeat">
                                                <input type="hidden" name="host" value="<?php echo htmlspecialchars($heartbeat['host']); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-secondary">Entfernen</button>
                                            </form>
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
                <h3 class="card-title">Shopify</h3>
            </div>
            <div class="card-body pb-2 text-secondary small">
                Shopify begrenzt pro Shop über einen Kosten-Eimer (Punkte). Gedrosselte Abfragen wartet Connect ab und wiederholt sie einmal.
                Zahlen der letzten 24 h, „Eimer“ = zuletzt von Shopify gemeldeter Stand.
            </div>
            <?php if ($shopifyUsageMissing): ?>
                <div class="card-body pt-0"><div class="alert alert-warning mb-0">Die Verbrauchsstatistik braucht Migration 0068 (<code>php connect/bin/install.php</code>).</div></div>
            <?php elseif (count($shopifyRows) === 0): ?>
                <div class="card-body pt-0 text-secondary">Keine Shopify-Connections.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>Shop</th>
                                <th>Eimer</th>
                                <th class="text-end">Aufrufe 1 h / 24 h</th>
                                <th class="text-end">Kosten 24 h</th>
                                <th class="text-end">Gedrosselt</th>
                                <th class="text-end">Fehler</th>
                                <th>Webhooks 24 h</th>
                                <th class="w-1"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($shopifyRows as $row): ?>
                                <?php
                                $max = $row['maximum_available'] !== null ? (float) $row['maximum_available'] : null;
                                $available = $row['currently_available'] !== null ? (float) $row['currently_available'] : null;
                                $fill = $max !== null && $max > 0 && $available !== null ? $available / $max : null;
                                $bucketBadge = $fill === null ? 'bg-secondary-lt' : ($fill < 0.2 ? 'bg-red-lt' : ($fill < 0.5 ? 'bg-yellow-lt' : 'bg-green-lt'));
                                $hooks = $webhookCounts[$row['shop']] ?? [];
                                ?>
                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars($row['shop']); ?>
                                        <div class="small text-secondary"><?php echo (int) ($row['live_in_connect'] ?? 0) === 1 ? 'läuft in Connect' : 'alte Welt'; ?> · Connection <?php echo htmlspecialchars((string) $row['status']); ?></div>
                                    </td>
                                    <td>
                                        <?php if ($fill === null): ?>
                                            <span class="text-secondary">noch keine Meldung</span>
                                        <?php else: ?>
                                            <span class="badge <?php echo $bucketBadge; ?>"><?php echo number_format($available, 0, ',', '.'); ?> / <?php echo number_format($max, 0, ',', '.'); ?></span>
                                            <div class="small text-secondary">+<?php echo number_format((float) $row['restore_rate'], 0, ',', '.'); ?>/s · vor <?php echo (int) $row['bucket_minutes_ago']; ?> Min.</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end" style="font-variant-numeric: tabular-nums"><?php echo (int) $row['calls_1h']; ?> / <?php echo (int) $row['calls_24h']; ?></td>
                                    <td class="text-end" style="font-variant-numeric: tabular-nums"><?php echo number_format((int) $row['cost_24h'], 0, ',', '.'); ?></td>
                                    <td class="text-end"><span class="<?php echo (int) $row['throttled_24h'] > 0 ? 'text-warning fw-bold' : 'text-secondary'; ?>"><?php echo (int) $row['throttled_24h']; ?></span></td>
                                    <td class="text-end"><span class="<?php echo (int) $row['errors_24h'] > 0 ? 'text-danger fw-bold' : 'text-secondary'; ?>"><?php echo (int) $row['errors_24h']; ?></span></td>
                                    <td class="small">
                                        <?php if ($hooks === []): ?>
                                            <span class="text-secondary">keine</span>
                                        <?php else: ?>
                                            <?php foreach (['processed' => 'bg-green-lt', 'received' => 'bg-secondary-lt', 'ignored' => 'bg-yellow-lt', 'error' => 'bg-red-lt'] as $hookStatus => $hookBadge): ?>
                                                <?php if (($hooks[$hookStatus] ?? 0) > 0): ?>
                                                    <span class="badge <?php echo $hookBadge; ?>"><?php echo $hooks[$hookStatus]; ?> <?php echo $hookStatus; ?></span>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><a class="btn btn-sm btn-outline-primary" href="shopify_activity.php?shop=<?php echo urlencode($row['shop']); ?>">Details</a></td>
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
                <h3 class="card-title">Anfrage-Limits</h3>
            </div>
            <div class="card-body pb-2 text-secondary small">
                Scheduler: bis zu <?php echo $schedulerBudget; ?> s Jobs pro Aufruf, höchstens <?php echo $schedulerParallel; ?> gleichzeitig pro Server
                (änderbar in <code>connect/.env</code>: <code>CONNECT_SCHEDULER_TIME_BUDGET</code>, <code>CONNECT_SCHEDULER_MAX_PARALLEL</code>).
                Hintergrund-Jobs nutzen höchstens 75 % eines Limits, der Rest bleibt für Rechnungen per Webhook und Klicks im Admin frei.
                <?php if ($rateLimitAborts !== null): ?>
                    Am Limit abgebrochene Läufe (24 h): <strong class="<?php echo $rateLimitAborts > 0 ? 'text-warning' : ''; ?>"><?php echo $rateLimitAborts; ?></strong>.
                <?php endif; ?>
            </div>
            <?php if (count($rateLimits) === 0): ?>
                <div class="card-body pt-0 text-secondary">Für keinen Connector Type ist ein Limit hinterlegt (Connector Types → Bearbeiten → Anfrage-Limit).</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>System</th>
                                <th>Ausgehende IP</th>
                                <th>Limit</th>
                                <th>Aktuelles Fenster</th>
                                <th>Sperre nach HTTP 429</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rateLimits as $limit): ?>
                                <?php
                                $max = (int) $limit['rate_limit_max_requests'];
                                $window = (int) $limit['rate_limit_window_seconds'];
                                $elapsed = $limit['window_elapsed'] !== null ? (int) $limit['window_elapsed'] : null;
                                $count = ($elapsed !== null && $elapsed < $window) ? (int) $limit['request_count'] : 0;
                                $share = $max > 0 ? $count / $max : 0;
                                $badge = $share >= 1 ? 'bg-red-lt' : ($share >= 0.75 ? 'bg-yellow-lt' : 'bg-green-lt');
                                $blockedSeconds = $limit['blocked_seconds'] !== null ? (int) $limit['blocked_seconds'] : 0;
                                ?>
                                <tr>
                                    <td><a href="connector_type_edit.php?id=<?php echo (int) $limit['id']; ?>"><?php echo htmlspecialchars($limit['name']); ?></a></td>
                                    <td class="small"><?php echo (string) ($limit['scope'] ?? '') !== '' ? '<code>' . htmlspecialchars((string) $limit['scope']) . '</code>' : '<span class="text-secondary">Standard-IP</span>'; ?></td>
                                    <td><?php echo $max; ?> je <?php echo $window; ?> s</td>
                                    <td>
                                        <span class="badge <?php echo $badge; ?>"><?php echo $count; ?> / <?php echo $max; ?></span>
                                        <span class="text-secondary small"><?php echo $elapsed !== null && $elapsed < $window ? 'seit ' . $elapsed . ' s' : 'kein Aufruf im laufenden Fenster'; ?></span>
                                    </td>
                                    <td><?php echo $blockedSeconds > 0 ? '<span class="badge bg-red-lt">noch ' . $blockedSeconds . ' s</span>' : '<span class="text-secondary">keine</span>'; ?></td>
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
                <h3 class="card-title">Aktuelle Warteschlange</h3>
            </div>
            <?php if (count($queue) === 0): ?>
                <div class="card-body text-secondary">Nichts wartet oder läuft gerade.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>Shop</th>
                                <th>Mapping</th>
                                <th>Intervall</th>
                                <th>Status</th>
                                <th>Geplant für</th>
                                <th>Läuft seit</th>
                                <th>Versuch</th>
                                <th>Worker</th>
                                <th class="w-1"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($queue as $job): ?>
                                <?php $isStale = $job['status'] === 'running' && (int) ($job['running_minutes'] ?? 0) >= 30; ?>
                                <tr>
                                    <td><a href="connections.php?shop=<?php echo urlencode($job['shop']); ?>"><?php echo htmlspecialchars($job['shop']); ?></a></td>
                                    <td><?php echo htmlspecialchars($job['mapping_name']); ?></td>
                                    <td class="text-secondary"><?php echo (int) $job['schedule_interval_minutes']; ?> Min.</td>
                                    <td>
                                        <span class="badge <?php echo htmlspecialchars($statusBadge[$job['status']] ?? 'bg-secondary-lt'); ?>">
                                            <?php echo htmlspecialchars($job['status']); ?>
                                        </span>
                                        <?php if ($isStale): ?>
                                            <span class="badge bg-red-lt" title="Laeuft laenger als 30 Minuten, vermutlich haengen geblieben (z.B. abgebrochener Scheduler-Aufruf) -- wird beim naechsten Scheduler-Lauf automatisch zurueckgesetzt.">hängt?</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-secondary"><?php echo htmlspecialchars((string) $job['scheduled_for']); ?></td>
                                    <td class="text-secondary"><?php echo $job['started_at'] !== null ? htmlspecialchars((string) $job['started_at']) : '–'; ?></td>
                                    <td class="text-secondary"><?php echo (int) $job['attempt_count']; ?></td>
                                    <td class="text-secondary"><?php echo htmlspecialchars((string) ($job['locked_by'] ?? '')); ?></td>
                                    <td class="text-end">
                                        <form method="post" onsubmit="return confirm('Job #<?php echo (int) $job['id']; ?> (<?php echo htmlspecialchars(addslashes($job['mapping_name'])); ?>) abbrechen?');">
                                            <input type="hidden" name="action" value="cancel_job">
                                            <input type="hidden" name="job_id" value="<?php echo (int) $job['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Abbrechen</button>
                                        </form>
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
                <h3 class="card-title">Laufhistorie (letzte 100)</h3>
            </div>
            <?php if (count($history) === 0): ?>
                <div class="card-body text-secondary">Noch kein Lauf protokolliert.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>Shop</th>
                                <th>Mapping</th>
                                <th>Intervall</th>
                                <th>Status</th>
                                <th>Gestartet</th>
                                <th>Beendet</th>
                                <th>Worker</th>
                                <th>Datensätze</th>
                                <th>Fehler</th>
                                <th class="w-1"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $run): ?>
                                <tr>
                                    <td><a href="connections.php?shop=<?php echo urlencode($run['shop']); ?>"><?php echo htmlspecialchars($run['shop']); ?></a></td>
                                    <td><?php echo htmlspecialchars($run['mapping_name']); ?></td>
                                    <td class="text-secondary"><?php echo (int) $run['schedule_interval_minutes']; ?> Min.</td>
                                    <td>
                                        <span class="badge <?php echo htmlspecialchars($statusBadge[$run['status']] ?? 'bg-secondary-lt'); ?>">
                                            <?php echo htmlspecialchars($run['status']); ?>
                                        </span>
                                    </td>
                                    <td class="text-secondary"><?php echo htmlspecialchars((string) $run['started_at']); ?></td>
                                    <td class="text-secondary"><?php echo $run['ended_at'] !== null ? htmlspecialchars((string) $run['ended_at']) : '–'; ?></td>
                                    <td class="text-secondary small">
                                        <?php if ($run['locked_by'] !== null && $run['locked_by'] !== ''): ?>
                                            <?php echo htmlspecialchars(explode(':', (string) $run['locked_by'])[0]); ?>
                                        <?php else: ?>
                                            <span title="Kein Scheduler-Job: Webhook, Klick im Admin oder Job-Zeile bereits bereinigt">Webhook/Admin</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-secondary">
                                        <?php echo (int) $run['records_read']; ?> gelesen
                                        <?php if ((int) $run['records_failed'] > 0): ?>
                                            / <?php echo (int) $run['records_failed']; ?> fehlgeschlagen
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-danger">
                                        <?php if ($run['error_summary'] !== null): ?>
                                            <small><?php echo htmlspecialchars(mb_substr((string) $run['error_summary'], 0, 200)); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <a href="job_log.php?run_id=<?php echo (int) $run['id']; ?>" class="btn btn-sm btn-outline-secondary">Details</a>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="action" value="run_now">
                                            <input type="hidden" name="mapping_id" value="<?php echo (int) $run['mapping_id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-primary">Erneut ausführen</button>
                                        </form>
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
