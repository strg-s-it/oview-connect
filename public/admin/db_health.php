<?php

declare(strict_types=1);

/**
 * connect/public/admin/db_health.php
 *
 * "Wie geht's der Datenbank": Groesse und Wachstum je Tabelle, Stand der
 * Migrationen, Server-Kennzahlen und eine Liste "Zu pruefen" mit typischen
 * Betriebsproblemen (fehlende Migration, Scheduler steht, haengende Jobs/
 * Sperren, nicht verarbeitete Webhooks, Bereinigung laeuft nicht,
 * Fragmentierung, Verbindungsauslastung).
 *
 * Rein lesend. Jede Abfrage einzeln abgesichert -- fehlt einem DB-User ein
 * Recht (z.B. SHOW GLOBAL STATUS) oder einer Installation eine Tabelle, faellt
 * nur der betreffende Teil weg.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Connect\Auth\AdminAuth;
use Connect\Config;
use Connect\Database;

$config = Config::load();
$pdo = Database::connect($config)->pdo();

$auth = new AdminAuth($pdo);
$auth->requireLogin();

$try = static function (callable $fn, mixed $fallback = null): mixed {
    try {
        return $fn();
    } catch (\Throwable) {
        return $fallback;
    }
};
$value = static fn (string $sql): mixed => $try(static fn () => $pdo->query($sql)->fetchColumn(), null);

$schema = (string) $value('SELECT DATABASE()');

// ---- Zweck und Zeitspalte je Tabelle -----------------------------------------
$purpose = [
    'connect_object_records' => ['Zwischenspeicher (Bestellungen, Produkte, Artikel …)', 'fetched_at'],
    'connect_webhook_events' => ['Eingehende Webhooks (Rohdaten)', 'received_at'],
    'connect_activity_log' => ['Protokoll inkl. Request/Response', 'created_at'],
    'connect_sync_runs' => ['Laufhistorie der Jobs', 'started_at'],
    'connect_sync_jobs' => ['Job-Warteschlange', 'scheduled_for'],
    'connect_translation_log' => ['Übersetzungen / Doppelt-Schutz je Datensatz', 'created_at'],
    'connect_stock_sync_log' => ['Lagerbestand-Änderungen', 'created_at'],
    'connect_stock_sync_runs' => ['Lagerbestand-Läufe (alt)', null],
    'connect_api_usage_hourly' => ['API-Verbrauch je Stunde', 'hour_start'],
    'connect_api_bucket' => ['Shopify-Kosten-Eimer', null],
    'connect_worker_api_minute' => ['API-Aufrufe je Worker und Minute', 'minute_start'],
    'connect_maintenance_runs' => ['Protokoll der Bereinigung', 'started_at'],
    'connect_privacy_requests' => ['Datenschutz-Anfragen', 'received_at'],
    'connect_admin_login_attempts' => ['Login-Versuche (Sperre)', 'attempted_at'],
    'connect_shops' => ['Shops und Shop-Einstellungen', null],
    'connect_connections' => ['Connections (verschlüsselte Zugangsdaten)', null],
    'connect_sync_mappings' => ['Aktivierungen und Jobs je Shop', null],
    'connect_webhook_subscriptions' => ['Webhook-Abos von Connect', null],
    'connect_connector_types' => ['Systeme (Connector Types)', null],
    'connect_objects' => ['Objects', null],
    'connect_object_fields' => ['Felder', null],
    'connect_object_operations' => ['Operationen', null],
    'connect_mapping_templates' => ['Mapping-Vorlagen', null],
    'connect_field_mappings' => ['Feldzuordnungen', null],
    'connect_functions' => ['Funktionen', null],
    'connect_lookup_tables' => ['Lookup-Tabellen', null],
    'connect_lookup_table_entries' => ['Lookup-Einträge', null],
    'connect_sync_job_templates' => ['Job-Vorlagen', null],
    'connect_rate_limit_state' => ['Anfrage-Limit-Zähler', null],
    'connect_scheduler_heartbeats' => ['Scheduler-Heartbeat', null],
    'connect_schema_migrations' => ['Eingespielte Migrationen', 'applied_at'],
    'connect_admin_users' => ['Admin-Benutzer', null],
    'connect_country_tax_rates' => ['Steuersätze je Land', null],
];

// ---- Tabellen aus information_schema -----------------------------------------
$tables = $try(static function () use ($pdo, $schema): array {
    $stmt = $pdo->prepare(
        'SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_ROWS AS approx_rows, DATA_LENGTH AS data_bytes,
                INDEX_LENGTH AS index_bytes, DATA_FREE AS free_bytes
         FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC'
    );
    $stmt->execute(['schema' => $schema]);

    return $stmt->fetchAll();
}, []);

$totalBytes = 0;
foreach ($tables as $i => $table) {
    $totalBytes += (int) $table['data_bytes'] + (int) $table['index_bytes'];
    $timeColumn = $purpose[$table['name']][1] ?? null;
    $tables[$i]['purpose'] = $purpose[$table['name']][0] ?? '';
    $tables[$i]['growth_24h'] = null;
    $tables[$i]['growth_7d'] = null;
    $tables[$i]['oldest'] = null;
    $tables[$i]['newest'] = null;
    if ($timeColumn !== null && preg_match('/^[a-z_]+$/', $table['name']) && preg_match('/^[a-z_]+$/', $timeColumn)) {
        $row = $try(static fn () => $pdo->query(
            "SELECT SUM({$timeColumn} >= NOW() - INTERVAL 1 DAY) AS d1, SUM({$timeColumn} >= NOW() - INTERVAL 7 DAY) AS d7,
                    MIN({$timeColumn}) AS oldest, MAX({$timeColumn}) AS newest
             FROM `{$table['name']}`"
        )->fetch(), null);
        if (is_array($row)) {
            $tables[$i]['growth_24h'] = $row['d1'] !== null ? (int) $row['d1'] : 0;
            $tables[$i]['growth_7d'] = $row['d7'] !== null ? (int) $row['d7'] : 0;
            $tables[$i]['oldest'] = $row['oldest'];
            $tables[$i]['newest'] = $row['newest'];
        }
    }
}

$mb = static fn (int|float|string|null $bytes): string => number_format(((float) $bytes) / 1048576, 1, ',', '.') . ' MB';

// ---- Migrationen --------------------------------------------------------------
$migrationFiles = array_map('basename', glob(dirname(__DIR__, 2) . '/migrations/*.sql') ?: []);
sort($migrationFiles);
$applied = $try(static fn () => $pdo->query('SELECT migration, applied_at FROM connect_schema_migrations ORDER BY id')->fetchAll(), []);
$appliedNames = array_flip(array_map(static fn ($r) => (string) $r['migration'], $applied));
$pendingMigrations = array_values(array_filter($migrationFiles, static fn ($f) => !isset($appliedNames[$f]) && !isset($appliedNames[pathinfo($f, PATHINFO_FILENAME)])));
$lastApplied = $applied !== [] ? $applied[count($applied) - 1] : null;

// ---- Server -------------------------------------------------------------------
$globalValue = static function (string $type, string $name) use ($pdo, $try): ?string {
    return $try(static function () use ($pdo, $type, $name): ?string {
        $row = $pdo->query("SHOW GLOBAL {$type} LIKE " . $pdo->quote($name))->fetch();

        return $row !== false ? (string) array_values($row)[1] : null;
    }, null);
};
$server = [
    'version' => (string) $value('SELECT VERSION()'),
    'uptime' => $globalValue('STATUS', 'Uptime'),
    'threads' => $globalValue('STATUS', 'Threads_connected'),
    'max_used' => $globalValue('STATUS', 'Max_used_connections'),
    'max_connections' => $globalValue('VARIABLES', 'max_connections'),
    'slow_queries' => $globalValue('STATUS', 'Slow_queries'),
    'buffer_pool' => $globalValue('VARIABLES', 'innodb_buffer_pool_size'),
];

// ---- Pruefungen ---------------------------------------------------------------
$issues = []; // [severity err|warn|info, Text, Link]
$add = static function (string $severity, string $text, ?string $link = null) use (&$issues): void {
    $issues[] = [$severity, $text, $link];
};

if ($pendingMigrations !== []) {
    $add('err', count($pendingMigrations) . ' Migration(en) nicht eingespielt: ' . implode(', ', $pendingMigrations) . ' – php connect/bin/install.php ausführen.');
}

$heartbeatMinutes = $value('SELECT MIN(TIMESTAMPDIFF(MINUTE, last_run_at, NOW())) FROM connect_scheduler_heartbeats');
if ($heartbeatMinutes === null) {
    $add('err', 'Kein Scheduler-Heartbeat – bin/scheduler.php läuft nicht.', 'jobs.php');
} elseif ((int) $heartbeatMinutes > 5) {
    $add('err', "Scheduler zuletzt vor {$heartbeatMinutes} Min. – der Cronjob steht vermutlich.", 'jobs.php');
}

$stuckJobs = (int) $value("SELECT COUNT(*) FROM connect_sync_jobs WHERE status = 'running' AND started_at < NOW() - INTERVAL 30 MINUTE");
if ($stuckJobs > 0) {
    $add('warn', "{$stuckJobs} Job(s) laufen seit über 30 Min. (werden beim nächsten Scheduler-Lauf als Fehler zurückgesetzt).", 'jobs.php');
}
$oldestPending = $value("SELECT TIMESTAMPDIFF(MINUTE, MIN(scheduled_for), NOW()) FROM connect_sync_jobs WHERE status = 'pending'");
$pendingCount = (int) $value("SELECT COUNT(*) FROM connect_sync_jobs WHERE status = 'pending'");
if ($oldestPending !== null && (int) $oldestPending > 15) {
    $add('warn', "{$pendingCount} Job(s) warten, der älteste seit {$oldestPending} Min. – der Scheduler kommt nicht hinterher.", 'jobs.php');
}

$stuckClaims = (int) $value("SELECT COUNT(*) FROM connect_translation_log WHERE status = 'pending' AND created_at < NOW() - INTERVAL 30 MINUTE");
if ($stuckClaims > 0) {
    $add('warn', "{$stuckClaims} Übersetzung(en) hängen im Status „pending“ (älter als 30 Min.) – diese Datensätze werden nicht erneut versucht.");
}

$unprocessedHooks = (int) $value("SELECT COUNT(*) FROM connect_webhook_events WHERE status = 'received' AND received_at < NOW() - INTERVAL 10 MINUTE AND received_at > NOW() - INTERVAL 7 DAY");
if ($unprocessedHooks > 0) {
    $add('warn', "{$unprocessedHooks} Webhook(s) der letzten 7 Tage wurden nie fertig verarbeitet (Status „received“).", 'webhooks.php');
}
$hookErrors = (int) $value("SELECT COUNT(*) FROM connect_webhook_events WHERE status = 'error' AND received_at > NOW() - INTERVAL 1 DAY");
if ($hookErrors > 0) {
    $add('warn', "{$hookErrors} Webhook(s) mit Fehler in den letzten 24 h.", 'webhooks.php');
}
$runErrors = (int) $value("SELECT COUNT(*) FROM connect_sync_runs WHERE status = 'error' AND started_at > NOW() - INTERVAL 1 DAY");
if ($runErrors > 0) {
    $add('info', "{$runErrors} fehlgeschlagene Job-Läufe in den letzten 24 h.", 'jobs.php');
}

// ---- Automatische Bereinigung (Connect\Maintenance\Retention, Migration 0069) ----
$retention = new \Connect\Maintenance\Retention($pdo, $config);
$retentionDays = $retention->days();
$retentionDue = $retention->countDue();
$maintenanceRuns = $try(static fn () => $pdo->query(
    "SELECT id, trigger_type, host, started_at, finished_at, status, result, error_message,
            TIMESTAMPDIFF(MINUTE, started_at, NOW()) AS minutes_ago, TIMESTAMPDIFF(SECOND, started_at, finished_at) AS duration
     FROM connect_maintenance_runs WHERE task = 'retention' ORDER BY id DESC LIMIT 5"
)->fetchAll(), null);
$lastRun = is_array($maintenanceRuns) && $maintenanceRuns !== [] ? $maintenanceRuns[0] : null;
$lastCounts = $lastRun !== null && $lastRun['result'] !== null ? (json_decode((string) $lastRun['result'], true) ?: []) : [];
$tableRules = [];
foreach (\Connect\Maintenance\Retention::RULES as $ruleKey => $rule) {
    $tableRules[$rule[1]][] = $retentionDays[$ruleKey] . ' T';
}

if ($maintenanceRuns === null) {
    $add('warn', 'Die automatische Bereinigung braucht Migration 0069 (php connect/bin/install.php).');
} elseif ($lastRun === null) {
    $add('warn', 'Die automatische Bereinigung ist noch nie gelaufen. Der Scheduler startet sie beim nächsten Aufruf.');
} elseif ((int) $lastRun['minutes_ago'] > 26 * 60) {
    $add('warn', 'Die automatische Bereinigung lief zuletzt vor ' . intdiv((int) $lastRun['minutes_ago'], 60) . ' Stunden (erwartet: täglich).');
} elseif ($lastRun['status'] === 'error') {
    $add('warn', 'Die letzte Bereinigung ist fehlgeschlagen: ' . (string) $lastRun['error_message']);
}

// Gemeinsamer InnoDB-Tablespace (innodb_file_per_table=OFF, alles in ibdata1):
// dann meldet DATA_FREE fuer JEDE InnoDB-Tabelle denselben Wert -- den freien
// Platz der gemeinsamen Datei. Der gehoert keiner Tabelle, OPTIMIZE TABLE gibt
// dort nichts frei. Erkennung ueber die Einstellung und, falls die nicht lesbar
// ist, ueber denselben DATA_FREE-Wert bei mehreren Tabellen.
$filePerTable = $globalValue('VARIABLES', 'innodb_file_per_table');
$freeValueCounts = [];
foreach ($tables as $table) {
    if (strtolower((string) $table['engine']) === 'innodb' && (int) $table['free_bytes'] > 0) {
        $freeValueCounts[(string) $table['free_bytes']] = ($freeValueCounts[(string) $table['free_bytes']] ?? 0) + 1;
    }
}
$sharedFree = null;
foreach ($freeValueCounts as $freeValue => $count) {
    if ($count >= 3 || (strtoupper((string) $filePerTable) === 'OFF' && $count >= 1)) {
        $sharedFree = (int) $freeValue;
        break;
    }
}
if ($sharedFree !== null) {
    $add('info', 'Die InnoDB-Tabellen liegen in einem gemeinsamen Tablespace (ibdata1, innodb_file_per_table=' . ($filePerTable ?? 'OFF') . '). '
        . 'Dieser hat ' . $mb($sharedFree) . ' frei, die Datei wird dadurch nicht kleiner, der Platz wird aber für neue Daten wiederverwendet. '
        . 'Kein Handlungsbedarf, OPTIMIZE TABLE hilft hier nicht.');
}

foreach ($tables as $table) {
    $used = (int) $table['data_bytes'] + (int) $table['index_bytes'];
    $free = (int) $table['free_bytes'];
    if ($sharedFree !== null && $free === $sharedFree) {
        continue; // gemeinsamer Tablespace, siehe oben
    }
    if ($free > 50 * 1048576 && $used > 0 && $free > 0.2 * $used) {
        $add('info', 'Tabelle ' . $table['name'] . ': ' . $mb($free) . ' ungenutzter Platz – bei Gelegenheit OPTIMIZE TABLE ' . $table['name'] . ' (nachts, sperrt kurz).');
    }
}

if ($server['threads'] !== null && $server['max_connections'] !== null && (int) $server['max_connections'] > 0) {
    $ratio = (int) $server['threads'] / (int) $server['max_connections'];
    if ($ratio > 0.8) {
        $add('warn', "Datenbank-Verbindungen zu {$server['threads']} von {$server['max_connections']} belegt.");
    }
}

$severityOrder = ['err' => 0, 'warn' => 1, 'info' => 2];
usort($issues, static fn ($a, $b) => $severityOrder[$a[0]] <=> $severityOrder[$b[0]]);
$issueCounts = ['err' => 0, 'warn' => 0, 'info' => 0];
foreach ($issues as $issue) {
    $issueCounts[$issue[0]]++;
}

$pageTitle = 'Datenbank';
$homeUrl = 'shops.php';
$adminNavItems = require __DIR__ . '/_nav.php';
$navItems = [];
$topRightHtml = '<span class="text-secondary me-3">' . htmlspecialchars((string) $auth->currentEmail()) . '</span>'
    . '<a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';

require dirname(__DIR__) . '/app/_header.php';

$uptimeText = $server['uptime'] !== null ? (function (int $s): string {
    $d = intdiv($s, 86400);
    $h = intdiv($s % 86400, 3600);

    return ($d > 0 ? $d . ' Tg. ' : '') . $h . ' Std.';
})((int) $server['uptime']) : '–';
?>

<div class="row row-cards">
    <div class="col-sm-6 col-lg-3">
        <div class="card"><div class="card-body">
            <div class="subheader">Größe gesamt</div>
            <div class="h1 mb-0"><?php echo $mb($totalBytes); ?></div>
            <div class="text-secondary small"><?php echo count($tables); ?> Tabellen in <code><?php echo htmlspecialchars($schema); ?></code></div>
        </div></div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card"><div class="card-body">
            <div class="subheader">Zu prüfen</div>
            <div class="h1 mb-0">
                <?php if ($issues === []): ?><span class="text-success">alles gut</span><?php else: ?>
                    <?php if ($issueCounts['err'] > 0): ?><span class="text-danger"><?php echo $issueCounts['err']; ?> Fehler</span><?php endif; ?>
                    <?php if ($issueCounts['warn'] > 0): ?><span class="text-warning"><?php echo $issueCounts['warn']; ?> Warn.</span><?php endif; ?>
                    <?php if ($issueCounts['err'] + $issueCounts['warn'] === 0): ?><span class="text-secondary"><?php echo $issueCounts['info']; ?> Hinweis<?php echo $issueCounts['info'] === 1 ? '' : 'e'; ?></span><?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="text-secondary small">Migrationen: <?php echo count($applied); ?> eingespielt<?php echo $pendingMigrations !== [] ? ', ' . count($pendingMigrations) . ' offen' : ''; ?></div>
        </div></div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card"><div class="card-body">
            <div class="subheader">Verbindungen</div>
            <div class="h1 mb-0"><?php echo htmlspecialchars($server['threads'] ?? '–'); ?> <span class="text-secondary fs-3">/ <?php echo htmlspecialchars($server['max_connections'] ?? '–'); ?></span></div>
            <div class="text-secondary small">Spitze seit Start: <?php echo htmlspecialchars($server['max_used'] ?? '–'); ?></div>
        </div></div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card"><div class="card-body">
            <div class="subheader">Server</div>
            <div class="h3 mb-0 text-truncate" title="<?php echo htmlspecialchars($server['version']); ?>"><?php echo htmlspecialchars($server['version'] !== '' ? $server['version'] : '–'); ?></div>
            <div class="text-secondary small">läuft seit <?php echo $uptimeText; ?> · langsame Abfragen: <?php echo htmlspecialchars($server['slow_queries'] ?? '–'); ?><?php echo $server['buffer_pool'] !== null ? ' · Buffer Pool ' . $mb($server['buffer_pool']) : ''; ?></div>
        </div></div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Zu prüfen</h3></div>
            <?php if ($issues === []): ?>
                <div class="card-body text-success">Keine Auffälligkeiten gefunden.</div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($issues as [$severity, $text, $link]): ?>
                        <div class="list-group-item d-flex gap-2 align-items-start">
                            <span class="badge <?php echo ['err' => 'bg-red-lt', 'warn' => 'bg-yellow-lt', 'info' => 'bg-secondary-lt'][$severity]; ?>"><?php echo ['err' => 'Fehler', 'warn' => 'Warnung', 'info' => 'Hinweis'][$severity]; ?></span>
                            <span class="flex-fill"><?php echo htmlspecialchars($text); ?></span>
                            <?php if ($link !== null): ?><a href="<?php echo htmlspecialchars($link); ?>" class="btn btn-sm btn-outline-secondary">Ansehen</a><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Automatische Bereinigung</h3></div>
            <div class="card-body pb-2">
                <?php if ($maintenanceRuns === null): ?>
                    <div class="alert alert-warning mb-0">Braucht Migration 0069 (<code>php connect/bin/install.php</code>).</div>
                <?php elseif ($lastRun === null): ?>
                    <span class="text-secondary">Noch kein Lauf. Der Scheduler bereinigt einmal täglich automatisch.</span>
                <?php else: ?>
                    Letzter Lauf: <strong><?php echo htmlspecialchars((string) $lastRun['started_at']); ?></strong>
                    (vor <?php echo (int) $lastRun['minutes_ago'] >= 120 ? intdiv((int) $lastRun['minutes_ago'], 60) . ' Std.' : (int) $lastRun['minutes_ago'] . ' Min.'; ?>,
                    <?php echo $lastRun['trigger_type'] === 'manual' ? 'von Hand' : 'Scheduler'; ?>,
                    <?php echo $lastRun['duration'] !== null ? (int) $lastRun['duration'] . ' s' : 'läuft'; ?>)
                    <span class="badge <?php echo ['success' => 'bg-green-lt', 'partial' => 'bg-yellow-lt', 'error' => 'bg-red-lt', 'running' => 'bg-secondary-lt'][$lastRun['status']] ?? 'bg-secondary-lt'; ?>"><?php echo htmlspecialchars((string) $lastRun['status']); ?></span>
                    <?php if ($lastRun['status'] === 'partial'): ?><span class="text-secondary small">– Rest folgt im nächsten Lauf (stündlich, bis alles erledigt ist)</span><?php endif; ?>
                    <?php if ($lastRun['error_message'] !== null): ?><div class="text-danger small mt-1"><?php echo htmlspecialchars((string) $lastRun['error_message']); ?></div><?php endif; ?>
                <?php endif; ?>
                <div class="text-secondary small mt-1">Läuft einmal täglich aus dem Scheduler. Fristen änderbar in <code>connect/.env</code>; von Hand: <code>php connect/bin/purge_privacy_data.php</code> (mit <code>--dry-run</code> nur zählen).</div>
            </div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th>Bereich</th>
                            <th>Tabelle</th>
                            <th class="text-end">Frist</th>
                            <th>Was passiert</th>
                            <th class="text-end">Letzter Lauf</th>
                            <th class="text-end">Jetzt fällig</th>
                            <th><code>.env</code></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (\Connect\Maintenance\Retention::RULES as $ruleKey => [$label, $ruleTable, $action, $envKey]): ?>
                            <?php $due = $retentionDue[$ruleKey] ?? null; ?>
                            <tr>
                                <td><?php echo htmlspecialchars($label); ?></td>
                                <td><code class="small"><?php echo htmlspecialchars($ruleTable); ?></code></td>
                                <td class="text-end" style="font-variant-numeric: tabular-nums"><?php echo (int) $retentionDays[$ruleKey]; ?> Tage</td>
                                <td class="small text-secondary"><?php echo htmlspecialchars($action); ?></td>
                                <td class="text-end" style="font-variant-numeric: tabular-nums"><?php echo isset($lastCounts[$ruleKey]) ? number_format((int) $lastCounts[$ruleKey], 0, ',', '.') : '–'; ?></td>
                                <td class="text-end" style="font-variant-numeric: tabular-nums"><?php echo $due === null ? '<span class="text-secondary">Tabelle fehlt</span>' : ($due > 0 ? '<strong>' . number_format($due, 0, ',', '.') . '</strong>' : '0'); ?></td>
                                <td class="small text-secondary"><?php echo $envKey !== null ? htmlspecialchars($envKey) : 'fest'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Tabellen</h3></div>
            <div class="card-body pb-0 text-secondary small">
                Zeilenzahl laut Datenbank-Statistik (bei InnoDB nur ungefähr). „Neu 24 h / 7 Tage“ zählt genau, über die Zeitspalte der Tabelle.
            </div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th>Tabelle</th>
                            <th class="text-end">Zeilen (ca.)</th>
                            <th class="text-end">Daten</th>
                            <th class="text-end">Index</th>
                            <th class="text-end">Frei</th>
                            <th class="text-end">Neu 24 h / 7 Tage</th>
                            <th>Ältester / neuester Eintrag</th>
                            <th>Bereinigung</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tables as $table): ?>
                            <?php $used = (int) $table['data_bytes'] + (int) $table['index_bytes']; ?>
                            <tr>
                                <td>
                                    <code><?php echo htmlspecialchars($table['name']); ?></code>
                                    <?php if ($table['purpose'] !== ''): ?><div class="small text-secondary"><?php echo htmlspecialchars($table['purpose']); ?></div><?php endif; ?>
                                </td>
                                <td class="text-end" style="font-variant-numeric: tabular-nums"><?php echo number_format((int) $table['approx_rows'], 0, ',', '.'); ?></td>
                                <td class="text-end" style="font-variant-numeric: tabular-nums"><?php echo $mb($table['data_bytes']); ?></td>
                                <td class="text-end text-secondary" style="font-variant-numeric: tabular-nums"><?php echo $mb($table['index_bytes']); ?></td>
                                <td class="text-end text-secondary" style="font-variant-numeric: tabular-nums"><?php echo (int) $table['free_bytes'] > 0 && (int) $table['free_bytes'] !== $sharedFree ? $mb($table['free_bytes']) : ((int) $table['free_bytes'] === $sharedFree && $sharedFree !== null ? '<span title="Freier Platz des gemeinsamen Tablespace, nicht dieser Tabelle">gemeinsam</span>' : '–'); ?></td>
                                <td class="text-end" style="font-variant-numeric: tabular-nums">
                                    <?php echo $table['growth_24h'] !== null ? number_format($table['growth_24h'], 0, ',', '.') . ' / ' . number_format($table['growth_7d'], 0, ',', '.') : '<span class="text-secondary">–</span>'; ?>
                                </td>
                                <td class="small text-secondary">
                                    <?php echo $table['oldest'] !== null ? htmlspecialchars(substr((string) $table['oldest'], 0, 16)) . '<br>' . htmlspecialchars(substr((string) $table['newest'], 0, 16)) : '–'; ?>
                                </td>
                                <td class="small">
                                    <?php if (isset($tableRules[$table['name']])): ?>
                                        <span class="text-secondary"><?php echo htmlspecialchars(implode(' / ', $tableRules[$table['name']])); ?></span>
                                    <?php elseif ($table['growth_7d'] !== null && $table['growth_7d'] > 0): ?>
                                        <span class="badge bg-yellow-lt" title="Wächst und hat keine Bereinigungsregel">wächst unbegrenzt</span>
                                    <?php else: ?>
                                        <span class="text-secondary">–</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Migrationen</h3></div>
            <div class="card-body">
                <p class="mb-2">
                    <?php echo count($applied); ?> von <?php echo count($migrationFiles); ?> Migrationsdateien eingespielt.
                    <?php if ($lastApplied !== null): ?>
                        Zuletzt: <code><?php echo htmlspecialchars((string) $lastApplied['migration']); ?></code> am <?php echo htmlspecialchars((string) $lastApplied['applied_at']); ?>.
                    <?php endif; ?>
                </p>
                <?php if ($pendingMigrations !== []): ?>
                    <div class="alert alert-danger mb-0">
                        Noch nicht eingespielt: <?php foreach ($pendingMigrations as $m): ?><code><?php echo htmlspecialchars($m); ?></code> <?php endforeach; ?><br>
                        Auf dem Server ausführen: <code>php /var/www/vhosts/oview.at/connect/bin/install.php</code>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require dirname(__DIR__) . '/app/_footer.php'; ?>
