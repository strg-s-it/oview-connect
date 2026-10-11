<?php

declare(strict_types=1);

/**
 * connect/bin/scheduler.php
 *
 * Ausbaustufe 1 des Hintergrund-Synchronisierers (siehe Plan, Phase 4 --
 * "Sync Mappings zunaechst nur als 'Cache regelmaessig aktualisieren'").
 * Aufruf z.B. per Cron jede Minute: php connect/bin/scheduler.php
 * Beliebig oft parallel aufrufbar -- atomares Claim-Verfahren nach Vorbild
 * von jobs/scheduler.php aus der alten Welt (Zeilen ~58-93), aber komplett
 * neu implementiert (siehe Plan "Verhaeltnis zur bestehenden Codebasis":
 * keine Laufzeit-Abhaengigkeit von altem Code).
 *
 * Pro Aufruf werden faellige Jobs bearbeitet, solange das Zeitbudget reicht
 * (CONNECT_SCHEDULER_TIME_BUDGET, Standard 50s) und auf diesem Host nicht schon
 * CONNECT_SCHEDULER_MAX_PARALLEL Jobs laufen (Standard 3) -- bis 2026-10-04 war
 * es genau EIN Job pro Aufruf. Ablauf:
 *   1) faellige, aktive Sync Mappings ohne bereits wartenden/laufenden Job
 *      bekommen einen neuen pending-Eintrag in connect_sync_jobs,
 *   2) haengengebliebene "running"-Jobs (Absturz eines frueheren Worker-
 *      Prozesses) werden nach STALE_MINUTES als error zurueckgesetzt,
 *   3) genau ein pending Job wird atomar geclaimt und via SyncRunner
 *      ausgefuehrt (ObjectCache::refresh() unbegrenzt, inkl. Verwaisten-
 *      Erkennung -- das volle Gegenstueck zur manuellen, auf PREVIEW_LIMIT
 *      gedeckelten Vorschau).
 *
 * Schreibende Sync Mappings (echtes Feld-Mapping in ein Zielsystem) sind
 * bewusst noch nicht Teil dieser Ausbaustufe -- SyncRunner::run() aktualisiert
 * aktuell nur den Cache der Source-Connection (siehe Kommentar dort).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Nur per CLI ausfuehrbar.');
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Connect\Config;
use Connect\Connector\ConnectorFactory;
use Connect\Database;
use Connect\Http\HttpClient;
use Connect\Security\Crypto;
use Connect\Shopify\TokenRefresher;
use Connect\Sync\SyncRunner;

const STALE_MINUTES = 30;

function resetStaleJobs(PDO $pdo): void
{
    $stmt = $pdo->prepare(
        "UPDATE connect_sync_jobs
         SET status = 'error'
         WHERE status = 'running' AND started_at < DATE_SUB(NOW(), INTERVAL " . STALE_MINUTES . " MINUTE)"
    );
    $stmt->execute();
}

function enqueueDueMappings(PDO $pdo): void
{
    // Bewusst NICHT nach trigger_type gefiltert: eine Uebersetzungs-Mapping-
    // Regel (siehe admin/mappings.php) kann trigger_type='webhook' UND
    // gleichzeitig ein schedule_interval_minutes haben -- Webhook fuer die
    // sofortige Uebersetzung, Intervall fuer den periodischen Sicherheitsnetz-
    // Nachlauf (siehe Plan "Mapping Engine v1", Nutzer-Entscheidung "Webhook +
    // zeitgesteuerter Nachlauf"). Ein gesetztes Intervall ist damit das
    // alleinige Signal "soll periodisch laufen", unabhaengig vom Trigger-Typ.
    $mappings = $pdo->query(
        'SELECT id, schedule_interval_minutes FROM connect_sync_mappings
         WHERE enabled = 1 AND schedule_interval_minutes IS NOT NULL'
    )->fetchAll();

    foreach ($mappings as $mapping) {
        $mappingId = (int) $mapping['id'];

        $pendingStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM connect_sync_jobs WHERE sync_mapping_id = :id AND status IN ('pending', 'running')"
        );
        $pendingStmt->execute(['id' => $mappingId]);
        if ((int) $pendingStmt->fetchColumn() > 0) {
            continue;
        }

        $lastStmt = $pdo->prepare('SELECT MAX(scheduled_for) FROM connect_sync_jobs WHERE sync_mapping_id = :id');
        $lastStmt->execute(['id' => $mappingId]);
        $lastScheduledFor = $lastStmt->fetchColumn();

        $due = $lastScheduledFor === false || $lastScheduledFor === null;
        if (!$due) {
            $dueCheckStmt = $pdo->prepare('SELECT TIMESTAMPDIFF(MINUTE, :last, NOW()) >= :interval_minutes');
            $dueCheckStmt->execute([
                'last' => $lastScheduledFor,
                'interval_minutes' => (int) $mapping['schedule_interval_minutes'],
            ]);
            $due = (bool) $dueCheckStmt->fetchColumn();
        }

        if ($due) {
            // cursor_state der letzten Job-Zeile dieses Mappings uebernehmen
            // (siehe ObjectCache::hydrateDetails() / SyncRunner::run()) --
            // jeder faellige Tick legt eine NEUE Job-Zeile an, der
            // Detail-Nachlade-Fortschritt (z.B. bei welchem HelloCash-Artikel
            // der letzte Lauf aufgehoert hat) muss deshalb explizit
            // weitergereicht werden, sonst faengt jeder Lauf wieder bei vorne an.
            $lastCursorStmt = $pdo->prepare(
                'SELECT cursor_state FROM connect_sync_jobs WHERE sync_mapping_id = :id ORDER BY id DESC LIMIT 1'
            );
            $lastCursorStmt->execute(['id' => $mappingId]);
            $lastCursorState = $lastCursorStmt->fetchColumn();
            $lastCursorState = $lastCursorState !== false ? $lastCursorState : null;

            $insert = $pdo->prepare("INSERT INTO connect_sync_jobs (sync_mapping_id, status, cursor_state) VALUES (:id, 'pending', :cursor_state)");
            $insert->execute(['id' => $mappingId, 'cursor_state' => $lastCursorState]);
        }
    }
}

function claimNextJob(PDO $pdo, string $workerId): ?int
{
    $candidate = $pdo->query(
        "SELECT id FROM connect_sync_jobs
         WHERE status = 'pending' AND scheduled_for <= NOW()
         ORDER BY scheduled_for ASC, id ASC
         LIMIT 1"
    )->fetch();

    if ($candidate === false) {
        return null;
    }

    $candidateId = (int) $candidate['id'];

    $claim = $pdo->prepare(
        "UPDATE connect_sync_jobs
         SET status = 'running', started_at = NOW(), locked_by = :worker_id, attempt_count = attempt_count + 1
         WHERE id = :id AND status = 'pending'"
    );
    $claim->execute(['worker_id' => $workerId, 'id' => $candidateId]);

    // Wie im Vorbild jobs/scheduler.php: ein anderer, parallel laufender
    // Worker koennte genau diese Zeile eine Millisekunde frueher geclaimt
    // haben -- affected_rows prueft das explizit statt sich auf die
    // vorangegangene SELECT-Sicht zu verlassen (klassische TOCTOU-Luecke).
    if ($claim->rowCount() !== 1) {
        return null;
    }

    return $candidateId;
}

function finishJob(PDO $pdo, int $jobId, string $status): void
{
    $stmt = $pdo->prepare("UPDATE connect_sync_jobs SET status = :status, ended_at = NOW() WHERE id = :id");
    $stmt->execute(['status' => $status, 'id' => $jobId]);
}

/**
 * Aktualisiert den Heartbeat-Zeitstempel fuer diesen Host -- bei JEDEM
 * Aufruf, unabhaengig davon, ob danach ein Job geclaimt wird. Damit laesst
 * sich im Jobs-Dashboard (admin/jobs.php) direkt sehen, ob der Cronjob
 * ueberhaupt noch regelmaessig laeuft, statt das nur indirekt aus neuen
 * Sync-Laeufen zu erraten.
 */
function recordHeartbeat(PDO $pdo, string $host): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO connect_scheduler_heartbeats (host, last_run_at) VALUES (:host, NOW())
         ON DUPLICATE KEY UPDATE last_run_at = NOW()'
    );
    $stmt->execute(['host' => $host]);

    // Code-Stand dieses Hosts (Connect\Version, Migration 0086) -- Lastverteilung zeigt Abweichungen
    // von main (= rsync fehlt). Getrennt und still, damit der Heartbeat selbst nie daran scheitert.
    try {
        $version = \Connect\Version::current();
        $pdo->prepare('UPDATE connect_scheduler_heartbeats SET code_version = :v, code_updated_at = :u WHERE host = :host')
            ->execute(['v' => $version['hash'], 'u' => $version['updated_at'], 'host' => $host]);
    } catch (\Throwable) {
        // Migration 0086 fehlt oder Dateien nicht lesbar
    }
}

/**
 * Wie viele Jobs laufen auf diesem Host gerade (locked_by = "<host>:<pid>")?
 * Begrenzt die Zahl gleichzeitiger Scheduler-Prozesse pro Server: ein langer
 * Job (z.B. Detail-Nachladen, das auf das Anfrage-Limit wartet) laeuft ueber
 * die Minute hinaus, der naechste Cron-Aufruf startet trotzdem -- ohne Grenze
 * koennten sich Prozesse stapeln.
 */
function runningJobsOnHost(PDO $pdo, string $host): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM connect_sync_jobs WHERE status = 'running' AND locked_by LIKE :prefix");
    $stmt->execute(['prefix' => str_replace(['%', '_'], ['\\%', '\\_'], $host) . ':%']);

    return (int) $stmt->fetchColumn();
}

$config = Config::load();
$pdo = Database::connect($config)->pdo();
// Name dieser Installation fuer Heartbeat, Job-Sperren (locked_by) und die Grenze
// paralleler Jobs: CONNECT_WORKER_NAME aus connect/.env -- noetig, wenn mehrere
// Worker-Abos auf DEMSELBEN Server laufen (gleicher Rechnername).
$host = trim($config->optional('CONNECT_WORKER_NAME', '')) !== '' ? trim($config->optional('CONNECT_WORKER_NAME', '')) : (string) gethostname();
$workerId = $host . ':' . getmypid();

// Ausbaustufe 2 (2026-10-04): mehrere Jobs pro Aufruf statt genau einem --
// solange Zeit im Budget ist und die Grenze paralleler Jobs auf diesem Host
// nicht erreicht ist. Beide Werte optional in connect/.env.
$timeBudgetSeconds = max(10, (int) $config->optional('CONNECT_SCHEDULER_TIME_BUDGET', '50'));
$maxParallelPerHost = max(1, (int) $config->optional('CONNECT_SCHEDULER_MAX_PARALLEL', '3'));
$startedAt = microtime(true);

recordHeartbeat($pdo, $host);

resetStaleJobs($pdo);

// Einreihen nur unter einer benannten DB-Sperre: laufen mehrere Scheduler (Hauptserver +
// Worker) zur selben Sekunde, saehen sonst zwei gleichzeitig "noch kein Job wartend" und
// legten fuer dasselbe Mapping je einen Job an -- derselbe Job liefe dann zweimal. Bis zu
// 10 s warten; bekommt ein Prozess die Sperre nicht, reiht er in diesem Aufruf nichts ein
// (der andere hat es gerade getan) und arbeitet nur die Warteschlange ab.
if ((int) $pdo->query("SELECT GET_LOCK('connect_enqueue', 10)")->fetchColumn() === 1) {
    try {
        enqueueDueMappings($pdo);
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('connect_enqueue')");
    }
}

// Automatische Bereinigung (Connect\Maintenance\Retention): einmal taeglich, hoechstens
// 30 s dieses Aufrufs -- ein grosser erster Lauf wird stuendlich fortgesetzt. Ein Fehler
// hier darf die Jobs nicht aufhalten.
try {
    $retentionResult = (new \Connect\Maintenance\Retention($pdo, $config))->maybeRunDaily($host, 30);
    if ($retentionResult !== null) {
        fwrite(STDOUT, "Bereinigung: {$retentionResult['status']} " . json_encode($retentionResult['counts']) . "\n");
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'Bereinigung fehlgeschlagen: ' . $e->getMessage() . "\n");
}

// Nachrichten an Merchants (Connect\Notify\MessageService, admin/messages.php): je Aufruf
// hoechstens 20 Mails, genau ein Scheduler sendet (GET_LOCK).
try {
    $messageResult = (new \Connect\Notify\MessageService($pdo, $config))->sendPending(20);
    if ($messageResult !== null) {
        fwrite(STDOUT, 'Nachrichten: ' . json_encode($messageResult) . "\n");
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'Nachrichten-Versand fehlgeschlagen: ' . $e->getMessage() . "\n");
}

// Wochenbericht an die Merchants (Connect\Notify\WeeklyReport): montags ab 7 Uhr, hoechstens
// 20 s dieses Aufrufs, genau ein Scheduler sendet (GET_LOCK). Abschaltbar mit
// CONNECT_WEEKLY_REPORT=off in der .env.
if (strtolower($config->optional('CONNECT_WEEKLY_REPORT', 'on')) !== 'off') {
    try {
        $reportResult = (new \Connect\Notify\WeeklyReport($pdo, $config))->maybeRun(20);
        if ($reportResult !== null) {
            fwrite(STDOUT, 'Wochenbericht: ' . json_encode($reportResult) . "\n");
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, 'Wochenbericht fehlgeschlagen: ' . $e->getMessage() . "\n");
    }
}

$crypto = new Crypto($config->require('CONNECT_ENCRYPTION_KEY'));
$tokenRefresher = TokenRefresher::fromConfig($config);
$factory = new ConnectorFactory($pdo, $crypto, new HttpClient(), $tokenRefresher);
$runner = new SyncRunner($pdo, $factory);

// Preise (Connect\Sync\PriceTasks, Migration 0087/0088): faellige Preissynchronisation einreihen,
// offene Sammelaenderungen abarbeiten -- hoechstens 15 s je Aufruf, genau ein Scheduler (GET_LOCK).
try {
    $priceResult = \Connect\Sync\PriceTasks::run($pdo, $factory, 15);
    if ($priceResult !== null) {
        fwrite(STDOUT, 'Preise: ' . json_encode($priceResult) . "\n");
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'Preis-Aufgaben fehlgeschlagen: ' . $e->getMessage() . "\n");
}

// Neue Funktionswuensche als Wochen-Zusammenfassung an den Admin (Connect\Wishes\WishBoard,
// Migration 0093): montags ab 7 Uhr, hoechstens eine Mail, genau ein Scheduler (GET_LOCK).
try {
    $wishResult = (new \Connect\Wishes\WishBoard($pdo))->maybeSendDigest($config);
    if ($wishResult !== null) {
        fwrite(STDOUT, 'Wünsche: ' . json_encode($wishResult) . "\n");
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'Wünsche-Zusammenfassung fehlgeschlagen: ' . $e->getMessage() . "\n");
}

// Mail bei niedrigem Lagerstand (Connect\Notify\LowStockAlert, Migration 0092): einmal am Tag
// ab 7 Uhr je Shop mit Schwellwert, hoechstens 15 s je Aufruf, genau ein Scheduler (GET_LOCK).
try {
    $lowStockResult = (new \Connect\Notify\LowStockAlert($pdo, $config, $factory))->maybeRun(15);
    if ($lowStockResult !== null) {
        fwrite(STDOUT, 'Lagerstand-Mails: ' . json_encode($lowStockResult) . "\n");
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'Lagerstand-Mails fehlgeschlagen: ' . $e->getMessage() . "\n");
}

$exitCode = 0;
$jobsRun = 0;

while (microtime(true) - $startedAt < $timeBudgetSeconds) {
    if (runningJobsOnHost($pdo, $host) >= $maxParallelPerHost) {
        break;
    }

    $jobId = claimNextJob($pdo, $workerId);
    if ($jobId === null) {
        break; // Warteschlange leer (oder ein anderer Prozess war schneller)
    }

    $job = $pdo->prepare('SELECT * FROM connect_sync_jobs WHERE id = :id');
    $job->execute(['id' => $jobId]);
    $job = $job->fetch();

    $mappingStmt = $pdo->prepare('SELECT * FROM connect_sync_mappings WHERE id = :id');
    $mappingStmt->execute(['id' => $job['sync_mapping_id']]);
    $mapping = $mappingStmt->fetch();

    if ($mapping === false || (int) $mapping['enabled'] === 0) {
        finishJob($pdo, $jobId, 'error');
        continue;
    }

    $jobsRun++;
    try {
        $runner->run($mapping, $jobId);
        finishJob($pdo, $jobId, 'done');
        fwrite(STDOUT, "Job {$jobId} (Mapping \"{$mapping['name']}\") erfolgreich.\n");
    } catch (\Throwable $e) {
        finishJob($pdo, $jobId, 'error');
        fwrite(STDERR, "Job {$jobId} (Mapping \"{$mapping['name']}\") fehlgeschlagen: {$e->getMessage()}\n");
        $exitCode = 1;
    }
}

exit($exitCode);
