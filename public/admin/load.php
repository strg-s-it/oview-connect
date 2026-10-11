<?php

declare(strict_types=1);

/**
 * connect/public/admin/load.php
 *
 * Lastverteilung ueber Hauptserver und Worker: Warteschlange (wartend,
 * ueberfaellig, laufend, haengend), je Worker Auslastung, Laeufe, Fehler und
 * API-Aufrufe pro Minute nach System (HelloCash/Shopify/...), Laeufe pro Stunde
 * je Worker, Backlog-Verlauf, offene Jobs nach Typ und Shop und eine Pruefung
 * auf doppelte Jobs. Angelehnt an die Uebersicht der alten Welt.
 *
 * Rein lesend. Datenquellen: connect_sync_jobs (locked_by = "<worker>:<pid>"),
 * connect_scheduler_heartbeats, connect_worker_api_minute (Migration 0071).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Connect\Auth\AdminAuth;
use Connect\Config;
use Connect\Database;

$config = Config::load();
$pdo = Database::connect($config)->pdo();

$auth = new AdminAuth($pdo);
$auth->requireLogin();

$try = static function (callable $fn, mixed $fallback) {
    try {
        return $fn();
    } catch (\Throwable) {
        return $fallback;
    }
};
$scalar = static fn (string $sql): int => (int) $try(static fn () => $pdo->query($sql)->fetchColumn(), 0);
$maxParallel = max(1, (int) $config->optional('CONNECT_SCHEDULER_MAX_PARALLEL', '3'));

// ---- Kennzahlen ---------------------------------------------------------------
$kpi = [
    'pending' => $scalar("SELECT COUNT(*) FROM connect_sync_jobs WHERE status = 'pending'"),
    'overdue' => $scalar("SELECT COUNT(*) FROM connect_sync_jobs WHERE status = 'pending' AND scheduled_for < NOW() - INTERVAL 5 MINUTE"),
    'running' => $scalar("SELECT COUNT(*) FROM connect_sync_jobs WHERE status = 'running'"),
    'hanging' => $scalar("SELECT COUNT(*) FROM connect_sync_jobs WHERE status = 'running' AND started_at < NOW() - INTERVAL 30 MINUTE"),
    'done_1h' => $scalar("SELECT COUNT(*) FROM connect_sync_jobs WHERE status IN ('done', 'error') AND ended_at >= NOW() - INTERVAL 60 MINUTE"),
];

// ---- Worker -------------------------------------------------------------------
$workers = [];
foreach ($try(static fn () => $pdo->query(
    'SELECT host, TIMESTAMPDIFF(MINUTE, last_run_at, NOW()) AS minutes_ago FROM connect_scheduler_heartbeats
     WHERE last_run_at >= NOW() - INTERVAL 1 DAY ORDER BY host'
)->fetchAll(), []) as $hb) {
    $workers[(string) $hb['host']] = ['heartbeat' => (int) $hb['minutes_ago'], 'running' => 0, 'done' => 0, 'errors' => 0, 'avg' => null, 'busy' => 0, 'api' => []];
}
// Code-Stand je Worker (Connect\Version, Migration 0086) gegen den Stand dieser Seite (main)
$mainVersion = \Connect\Version::current();
$workerVersions = [];
foreach ($try(static fn () => $pdo->query('SELECT host, code_version, code_updated_at FROM connect_scheduler_heartbeats')->fetchAll(), []) as $hv) {
    $workerVersions[(string) $hv['host']] = ['hash' => $hv['code_version'] !== null ? (string) $hv['code_version'] : null, 'at' => (string) ($hv['code_updated_at'] ?? '')];
}
foreach ($try(static fn () => $pdo->query(
    "SELECT SUBSTRING_INDEX(locked_by, ':', 1) AS w,
            SUM(status = 'running') AS running,
            SUM(status IN ('done', 'error') AND ended_at >= NOW() - INTERVAL 60 MINUTE) AS done,
            SUM(status = 'error' AND ended_at >= NOW() - INTERVAL 60 MINUTE) AS errors,
            AVG(CASE WHEN status IN ('done', 'error') AND ended_at >= NOW() - INTERVAL 60 MINUTE THEN TIMESTAMPDIFF(SECOND, started_at, ended_at) END) AS avg_seconds,
            SUM(TIMESTAMPDIFF(SECOND, GREATEST(started_at, NOW() - INTERVAL 60 MINUTE), COALESCE(ended_at, NOW()))) AS busy_seconds
     FROM connect_sync_jobs
     WHERE locked_by IS NOT NULL AND started_at IS NOT NULL
       AND (status = 'running' OR ended_at >= NOW() - INTERVAL 60 MINUTE)
     GROUP BY w"
)->fetchAll(), []) as $row) {
    $w = (string) $row['w'];
    $workers[$w] ??= ['heartbeat' => null, 'running' => 0, 'done' => 0, 'errors' => 0, 'avg' => null, 'busy' => 0, 'api' => []];
    $workers[$w]['running'] = (int) $row['running'];
    $workers[$w]['done'] = (int) $row['done'];
    $workers[$w]['errors'] = (int) $row['errors'];
    $workers[$w]['avg'] = $row['avg_seconds'] !== null ? (float) $row['avg_seconds'] : null;
    $workers[$w]['busy'] = max(0, (int) $row['busy_seconds']);
}
$apiMissing = false;
$apiRows = $try(static fn () => $pdo->query(
    'SELECT worker, type_key, SUM(calls) AS calls, SUM(errors) AS errors FROM connect_worker_api_minute
     WHERE minute_start >= NOW() - INTERVAL 5 MINUTE GROUP BY worker, type_key ORDER BY worker, type_key'
)->fetchAll(), null);
if ($apiRows === null) {
    $apiMissing = true;
    $apiRows = [];
}
foreach ($apiRows as $row) {
    $w = (string) $row['worker'];
    $workers[$w] ??= ['heartbeat' => null, 'running' => 0, 'done' => 0, 'errors' => 0, 'avg' => null, 'busy' => 0, 'api' => []];
    $workers[$w]['api'][(string) $row['type_key']] = round((int) $row['calls'] / 5, 1);
}
uksort($workers, static fn ($a, $b) => [$a === 'web', $a] <=> [$b === 'web', $b]);
$typeLabels = ['hellocash' => 'HelloCash', 'shopify' => 'Shopify', 'mgm_agila' => 'MGM'];

// ---- Laeufe pro Stunde je Worker (24 h) -----------------------------------------
// Stundenachse von der DB-Uhr aus (dieselbe Uhr wie die Zeitstempel in den Tabellen)
$dbHour = (string) $try(static fn () => $pdo->query("SELECT DATE_FORMAT(NOW(), '%Y-%m-%d %H:00')")->fetchColumn(), date('Y-m-d H:00'));
$hours = [];
for ($i = 23; $i >= 0; $i--) {
    $hours[] = date('Y-m-d H:00', strtotime($dbHour . " -{$i} hour"));
}
$runsPerHour = [];
foreach ($try(static fn () => $pdo->query(
    "SELECT DATE_FORMAT(ended_at, '%Y-%m-%d %H:00') AS h, SUBSTRING_INDEX(locked_by, ':', 1) AS w, COUNT(*) AS n
     FROM connect_sync_jobs WHERE ended_at >= NOW() - INTERVAL 24 HOUR AND locked_by IS NOT NULL
     GROUP BY h, w"
)->fetchAll(), []) as $row) {
    $runsPerHour[(string) $row['h']][(string) $row['w']] = (int) $row['n'];
}

// ---- Backlog-Verlauf (24 h) -----------------------------------------------------
$backlog = [];
foreach ($try(static fn () => $pdo->query(
    "SELECT DATE_FORMAT(scheduled_for, '%Y-%m-%d %H:00') AS h, COUNT(*) AS n FROM connect_sync_jobs
     WHERE scheduled_for >= NOW() - INTERVAL 24 HOUR GROUP BY h"
)->fetchAll(), []) as $row) {
    $backlog[(string) $row['h']]['created'] = (int) $row['n'];
}
foreach ($try(static fn () => $pdo->query(
    "SELECT DATE_FORMAT(ended_at, '%Y-%m-%d %H:00') AS h, COUNT(*) AS n FROM connect_sync_jobs
     WHERE ended_at >= NOW() - INTERVAL 24 HOUR GROUP BY h"
)->fetchAll(), []) as $row) {
    $backlog[(string) $row['h']]['done'] = (int) $row['n'];
}

// ---- Offene Jobs nach Typ und Shop ------------------------------------------------
$openByType = $try(static fn () => $pdo->query(
    "SELECT m.name AS label, COUNT(*) AS n FROM connect_sync_jobs j JOIN connect_sync_mappings m ON m.id = j.sync_mapping_id
     WHERE j.status IN ('pending', 'running') GROUP BY m.name ORDER BY n DESC LIMIT 10"
)->fetchAll(), []);
$openByShop = $try(static fn () => $pdo->query(
    "SELECT m.shop AS label, COUNT(*) AS n FROM connect_sync_jobs j JOIN connect_sync_mappings m ON m.id = j.sync_mapping_id
     WHERE j.status IN ('pending', 'running') GROUP BY m.shop ORDER BY n DESC LIMIT 15"
)->fetchAll(), []);

// ---- Doppelte Jobs ----------------------------------------------------------------
$duplicatesNow = $try(static fn () => $pdo->query(
    "SELECT m.shop, m.name, COUNT(*) AS n FROM connect_sync_jobs j JOIN connect_sync_mappings m ON m.id = j.sync_mapping_id
     WHERE j.status IN ('pending', 'running') GROUP BY j.sync_mapping_id, m.shop, m.name HAVING COUNT(*) > 1 ORDER BY n DESC LIMIT 20"
)->fetchAll(), []);
$duplicates24h = $try(static fn () => $pdo->query(
    "SELECT m.shop, m.name, d.minute_key, d.n FROM (
        SELECT sync_mapping_id, DATE_FORMAT(scheduled_for, '%Y-%m-%d %H:%i') AS minute_key, COUNT(*) AS n
        FROM connect_sync_jobs WHERE scheduled_for >= NOW() - INTERVAL 24 HOUR
        GROUP BY sync_mapping_id, minute_key HAVING COUNT(*) > 1
     ) d JOIN connect_sync_mappings m ON m.id = d.sync_mapping_id ORDER BY d.minute_key DESC LIMIT 20"
)->fetchAll(), []);

// ---- Darstellung ----------------------------------------------------------------
$palette = ['var(--tblr-primary)', 'var(--tblr-green)', 'var(--tblr-orange)', 'var(--tblr-purple)', 'var(--tblr-teal)', 'var(--tblr-pink)', 'var(--tblr-cyan)', 'var(--tblr-yellow)'];
$workerColor = [];
foreach (array_keys($workers) as $i => $name) {
    $workerColor[$name] = $palette[$i % count($palette)];
}
foreach ($runsPerHour as $perWorker) {
    foreach (array_keys($perWorker) as $name) {
        $workerColor[$name] ??= $palette[count($workerColor) % count($palette)];
    }
}
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$fmt = static fn (int|float $n): string => number_format($n, 0, ',', '.');

/** Gestapelte Saeulen: Laeufe pro Stunde je Worker. */
$stackedSvg = static function () use ($hours, $runsPerHour, $workerColor, $e): string {
    $w = 720;
    $h = 220;
    $padL = 34;
    $padB = 24;
    $padT = 10;
    $max = 1;
    foreach ($hours as $hour) {
        $max = max($max, array_sum($runsPerHour[$hour] ?? []));
    }
    $step = ($w - $padL) / max(1, count($hours));
    $barW = max(4, $step * 0.7);
    $out = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" class="w-100" role="img" aria-label="Läufe pro Stunde je Worker">';
    foreach ([0, 0.5, 1] as $f) {
        $y = $padT + ($h - $padT - $padB) * (1 - $f);
        $out .= '<line x1="' . $padL . '" x2="' . $w . '" y1="' . $y . '" y2="' . $y . '" style="stroke: var(--tblr-border-color)"/>';
        $out .= '<text x="' . ($padL - 4) . '" y="' . ($y + 4) . '" text-anchor="end" style="font-size:10px; fill: var(--tblr-secondary)">' . (int) round($max * $f) . '</text>';
    }
    foreach ($hours as $i => $hour) {
        $x = $padL + $i * $step + ($step - $barW) / 2;
        $yBase = $h - $padB;
        $tooltip = [];
        foreach ($runsPerHour[$hour] ?? [] as $name => $n) {
            $bh = ($h - $padT - $padB) * $n / $max;
            $yBase -= $bh;
            $out .= '<rect x="' . round($x, 1) . '" y="' . round($yBase, 1) . '" width="' . round($barW, 1) . '" height="' . round($bh, 1) . '" style="fill: ' . $workerColor[$name] . '"><title>' . $e(substr($hour, 11, 5) . ' · ' . $name . ': ' . $n) . '</title></rect>';
            $tooltip[] = $name . ' ' . $n;
        }
        if ($i % 3 === 0) {
            $out .= '<text x="' . round($x + $barW / 2, 1) . '" y="' . ($h - 8) . '" text-anchor="middle" style="font-size:10px; fill: var(--tblr-secondary)">' . $e(substr($hour, 11, 5)) . '</text>';
        }
    }

    return $out . '</svg>';
};

/** Linien: neu eingereihte vs. abgearbeitete Jobs pro Stunde. */
$backlogSvg = static function () use ($hours, $backlog, $e): string {
    $w = 720;
    $h = 200;
    $padL = 34;
    $padB = 24;
    $padT = 10;
    $max = 1;
    foreach ($hours as $hour) {
        $max = max($max, $backlog[$hour]['created'] ?? 0, $backlog[$hour]['done'] ?? 0);
    }
    $step = ($w - $padL) / max(1, count($hours) - 1);
    $out = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" class="w-100" role="img" aria-label="Backlog-Verlauf">';
    foreach ([0, 0.5, 1] as $f) {
        $y = $padT + ($h - $padT - $padB) * (1 - $f);
        $out .= '<line x1="' . $padL . '" x2="' . $w . '" y1="' . $y . '" y2="' . $y . '" style="stroke: var(--tblr-border-color)"/>';
        $out .= '<text x="' . ($padL - 4) . '" y="' . ($y + 4) . '" text-anchor="end" style="font-size:10px; fill: var(--tblr-secondary)">' . (int) round($max * $f) . '</text>';
    }
    foreach (['created' => 'var(--tblr-orange)', 'done' => 'var(--tblr-green)'] as $series => $color) {
        $points = [];
        foreach ($hours as $i => $hour) {
            $points[] = round($padL + $i * $step, 1) . ',' . round($padT + ($h - $padT - $padB) * (1 - ($backlog[$hour][$series] ?? 0) / $max), 1);
        }
        $out .= '<polyline fill="none" style="stroke: ' . $color . '; stroke-width: 2" points="' . implode(' ', $points) . '"/>';
    }
    foreach ($hours as $i => $hour) {
        if ($i % 3 === 0) {
            $out .= '<text x="' . round($padL + $i * $step, 1) . '" y="' . ($h - 8) . '" text-anchor="middle" style="font-size:10px; fill: var(--tblr-secondary)">' . $e(substr($hour, 11, 5)) . '</text>';
        }
    }

    return $out . '</svg>';
};

$barList = static function (array $rows) use ($e, $fmt): string {
    if ($rows === []) {
        return '<div class="text-secondary">Nichts offen.</div>';
    }
    $max = max(array_map(static fn ($r) => (int) $r['n'], $rows));
    $out = '<div class="d-grid gap-2">';
    foreach ($rows as $row) {
        $pct = $max > 0 ? round((int) $row['n'] / $max * 100) : 0;
        $out .= '<div><div class="d-flex justify-content-between small"><span class="text-truncate">' . $e((string) $row['label']) . '</span><strong>' . $fmt((int) $row['n']) . '</strong></div>'
            . '<div class="progress progress-sm"><div class="progress-bar bg-primary" style="width: ' . $pct . '%"></div></div></div>';
    }

    return $out . '</div>';
};

$pageTitle = 'Lastverteilung';
$homeUrl = 'shops.php';
$adminNavItems = require __DIR__ . '/_nav.php';
$navItems = [];
$topRightHtml = '<label class="form-check form-switch d-inline-flex align-items-center gap-2 me-3 mb-0"><input type="checkbox" class="form-check-input" id="load-autorefresh"><span class="text-secondary small">alle 60 s aktualisieren</span></label>'
    . '<span class="text-secondary me-3">' . htmlspecialchars((string) $auth->currentEmail()) . '</span>'
    . '<a href="logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>';

require dirname(__DIR__) . '/app/_header.php';
?>

<div class="row row-cards">
    <?php foreach ([
        ['Wartend', $kpi['pending'], 'bg-primary-lt', 'Jobs in der Warteschlange'],
        ['Überfällig', $kpi['overdue'], $kpi['overdue'] > 0 ? 'bg-yellow-lt' : 'bg-green-lt', 'warten länger als 5 Min.'],
        ['Laufend', $kpi['running'], 'bg-primary-lt', 'gerade in Arbeit'],
        ['Hängend', $kpi['hanging'], $kpi['hanging'] > 0 ? 'bg-red-lt' : 'bg-green-lt', 'laufen länger als 30 Min.'],
        ['Erledigt (1 h)', $kpi['done_1h'], 'bg-green-lt', 'abgeschlossen in der letzten Stunde'],
    ] as [$label, $value, $badge, $hint]): ?>
        <div class="col-6 col-md">
            <div class="card"><div class="card-body text-center">
                <div class="subheader"><?php echo $e($label); ?></div>
                <div class="h1 my-1"><span class="badge <?php echo $badge; ?> fs-2 px-3"><?php echo $fmt($value); ?></span></div>
                <div class="text-secondary small"><?php echo $e($hint); ?></div>
            </div></div>
        </div>
    <?php endforeach; ?>

    <div class="col-12">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Worker</h3></div>
            <div class="card-body pb-0 text-secondary small">
                Auslastung = belegte Job-Zeit der letzten Stunde im Verhältnis zu <?php echo $maxParallel; ?> parallelen Plätzen je Worker. API-Aufrufe = Durchschnitt der letzten 5 Minuten. „web“ sind Webhooks und Klicks im Admin.
                <?php if ($apiMissing): ?><span class="text-warning">API-Aufrufe je Worker brauchen Migration 0071.</span><?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th>Worker</th>
                            <th>Heartbeat</th>
                            <th title="Fingerabdruck des Codes (Connect\Version). Weicht er von main ab, fehlt auf dem Worker der rsync.">Code</th>
                            <th>Laufend</th>
                            <th style="width: 18%">Auslastung (1 h)</th>
                            <th class="text-end">Läufe 1 h</th>
                            <th class="text-end">Ø Dauer</th>
                            <th class="text-end">Fehler 1 h</th>
                            <th>API-Aufrufe / Min.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($workers === []): ?>
                            <tr><td colspan="9" class="text-secondary">Noch keine Daten.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($workers as $name => $wk): ?>
                            <?php
                            $utilPct = $name === 'web' ? null : min(100, (int) round($wk['busy'] / (3600 * $maxParallel) * 100));
                            $utilClass = $utilPct === null ? '' : ($utilPct >= 80 ? 'bg-red' : ($utilPct >= 50 ? 'bg-yellow' : 'bg-green'));
                            ?>
                            <tr>
                                <td><span class="d-inline-block rounded-circle me-2" style="width:10px;height:10px;background: <?php echo $workerColor[$name] ?? 'var(--tblr-secondary)'; ?>"></span><strong><?php echo $e($name); ?></strong></td>
                                <td class="small">
                                    <?php if ($name === 'web'): ?>
                                        <span class="text-secondary">–</span>
                                    <?php elseif ($wk['heartbeat'] === null): ?>
                                        <span class="badge bg-secondary-lt">unbekannt</span>
                                    <?php else: ?>
                                        <span class="badge <?php echo $wk['heartbeat'] <= 2 ? 'bg-green-lt' : ($wk['heartbeat'] <= 5 ? 'bg-yellow-lt' : 'bg-red-lt'); ?>">vor <?php echo (int) $wk['heartbeat']; ?> Min.</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small">
                                    <?php $wv = $name === 'web' ? ['hash' => $mainVersion['hash'], 'at' => $mainVersion['updated_at']] : ($workerVersions[$name] ?? ['hash' => null, 'at' => '']); ?>
                                    <?php if ($wv['hash'] === null): ?>
                                        <span class="text-secondary" title="Noch nicht gemeldet (Migration 0086 bzw. erster Lauf mit neuem Code ausstehend)">–</span>
                                    <?php elseif ($wv['hash'] === $mainVersion['hash']): ?>
                                        <span class="badge bg-green-lt font-monospace" title="Stand <?php echo $e($wv['at']); ?>"><?php echo $e($wv['hash']); ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-red-lt font-monospace" title="Abweichend von main (<?php echo $e($mainVersion['hash']); ?>) – rsync fehlt? Stand dort <?php echo $e($wv['at']); ?>"><?php echo $e($wv['hash']); ?> · veraltet</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $name === 'web' ? '<span class="text-secondary">–</span>' : (int) $wk['running'] . ' / ' . $maxParallel; ?></td>
                                <td>
                                    <?php if ($utilPct === null): ?>
                                        <span class="text-secondary">–</span>
                                    <?php else: ?>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="progress progress-sm flex-fill"><div class="progress-bar <?php echo $utilClass; ?>" style="width: <?php echo $utilPct; ?>%"></div></div>
                                            <span class="small" style="min-width:3em; text-align:right"><?php echo $utilPct; ?> %</span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end" style="font-variant-numeric: tabular-nums"><?php echo $name === 'web' ? '–' : $fmt($wk['done']); ?></td>
                                <td class="text-end" style="font-variant-numeric: tabular-nums"><?php echo $wk['avg'] !== null ? $fmt($wk['avg']) . ' s' : '–'; ?></td>
                                <td class="text-end"><span class="<?php echo $wk['errors'] > 0 ? 'text-danger fw-bold' : 'text-secondary'; ?>"><?php echo $name === 'web' ? '–' : (int) $wk['errors']; ?></span></td>
                                <td class="small">
                                    <?php if ($wk['api'] === []): ?>
                                        <span class="text-secondary">–</span>
                                    <?php else: ?>
                                        <?php foreach ($wk['api'] as $type => $perMinute): ?>
                                            <span class="badge bg-secondary-lt me-1"><?php echo $e($typeLabels[$type] ?? $type); ?> <?php echo str_replace('.', ',', (string) $perMinute); ?>/min</span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h3 class="card-title">Läufe pro Stunde je Worker (24 h)</h3></div>
            <div class="card-body">
                <?php echo $stackedSvg(); ?>
                <div class="d-flex flex-wrap gap-3 small text-secondary mt-2">
                    <?php foreach ($workerColor as $name => $color): ?>
                        <?php if ($name === 'web') { continue; } ?>
                        <span><span class="d-inline-block rounded-circle me-1" style="width:9px;height:9px;background: <?php echo $color; ?>"></span><?php echo $e($name); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h3 class="card-title">Backlog-Verlauf (24 h)</h3></div>
            <div class="card-body">
                <?php echo $backlogSvg(); ?>
                <div class="d-flex flex-wrap gap-3 small text-secondary mt-2">
                    <span><span class="d-inline-block me-1" style="width:14px;height:2px;background: var(--tblr-orange);vertical-align:middle"></span>neu eingereiht</span>
                    <span><span class="d-inline-block me-1" style="width:14px;height:2px;background: var(--tblr-green);vertical-align:middle"></span>abgearbeitet</span>
                    <span>Liegt Orange dauerhaft über Grün, wächst die Warteschlange – dann fehlen Worker oder Plätze.</span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h3 class="card-title">Offene Jobs nach Typ (Top 10)</h3></div>
            <div class="card-body"><?php echo $barList($openByType); ?></div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h3 class="card-title">Offene Jobs nach Shop (Top 15)</h3></div>
            <div class="card-body"><?php echo $barList($openByShop); ?></div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Doppelte Jobs</h3></div>
            <div class="card-body">
                <p class="text-secondary small mb-3">
                    Ein Job (Aktivierung) darf nur einmal gleichzeitig warten oder laufen. Mehrere Einträge in derselben Minute deuten auf gleichzeitiges Einreihen durch mehrere Scheduler hin – seit 2026-10-04 durch eine Datenbank-Sperre verhindert.
                </p>
                <?php if ($duplicatesNow === [] && $duplicates24h === []): ?>
                    <div class="text-success">Keine doppelten Jobs – weder aktuell noch in den letzten 24 h.</div>
                <?php endif; ?>
                <?php if ($duplicatesNow !== []): ?>
                    <div class="mb-3">
                        <div class="fw-bold mb-1 text-danger">Aktuell mehrfach wartend oder laufend</div>
                        <ul class="mb-0">
                            <?php foreach ($duplicatesNow as $d): ?>
                                <li><?php echo $e((string) $d['shop']); ?> · <?php echo $e((string) $d['name']); ?>: <strong><?php echo (int) $d['n']; ?>×</strong></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <?php if ($duplicates24h !== []): ?>
                    <div>
                        <div class="fw-bold mb-1 text-warning">In derselben Minute mehrfach eingereiht (24 h)</div>
                        <ul class="mb-0">
                            <?php foreach ($duplicates24h as $d): ?>
                                <li><?php echo $e((string) $d['minute_key']); ?> · <?php echo $e((string) $d['shop']); ?> · <?php echo $e((string) $d['name']); ?>: <strong><?php echo (int) $d['n']; ?>×</strong></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    // Optional jede Minute neu laden -- Wahl merkt sich der Browser.
    (function () {
        var box = document.getElementById('load-autorefresh');
        if (!box) { return; }
        var key = 'connectLoadAutoRefresh';
        try { box.checked = localStorage.getItem(key) === '1'; } catch (e) {}
        var timer = null;
        function apply() {
            clearTimeout(timer);
            if (box.checked) { timer = setTimeout(function () { location.reload(); }, 60000); }
        }
        box.addEventListener('change', function () {
            try { localStorage.setItem(key, box.checked ? '1' : '0'); } catch (e) {}
            apply();
        });
        apply();
    })();
</script>

<?php require dirname(__DIR__) . '/app/_footer.php'; ?>
