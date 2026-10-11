<?php

declare(strict_types=1);

namespace Connect\Maintenance;

use Connect\Config;
use PDO;

/**
 * Automatische Bereinigung (Aufbewahrungsfristen) an EINER Stelle: was wie lange
 * aufbewahrt wird, wie viel jetzt faellig waere, und der eigentliche Lauf.
 *
 * Laeuft einmal taeglich aus bin/scheduler.php mit (maybeRunDaily(), geschuetzt
 * ueber eine benannte DB-Sperre, damit parallele Scheduler-Prozesse nicht doppelt
 * loeschen) und weiterhin von Hand ueber bin/purge_privacy_data.php. Jeder Lauf
 * landet in connect_maintenance_runs (Migration 0069), angezeigt unter
 * Betrieb -> Datenbank.
 *
 * Geloescht wird in Bloecken (DELETE ... LIMIT), damit ein erster Lauf auf einer
 * grossen Tabelle die Datenbank nicht lange sperrt. Was im Zeitbudget nicht
 * fertig wird, folgt beim naechsten Lauf (Status "partial").
 *
 * Fristen (Tage) ueberschreibbar in connect/.env, siehe RULES. Nutzer-Entscheidung
 * 2026-10-04: Vorschlagswerte uebernommen.
 */
final class Retention
{
    private const TASK = 'retention';
    private const LOCK_NAME = 'connect_retention';
    private const BATCH = 5000;

    /**
     * key => [Bezeichnung, Tabelle, Was passiert, .env-Schluessel, Standard-Tage]
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: ?string, 4: int}>
     */
    public const RULES = [
        'webhook_events' => ['Eingehende Webhooks', 'connect_webhook_events', 'löschen', 'CONNECT_RETENTION_WEBHOOK_DAYS', 30],
        'activity_bodies' => ['Request/Response im Protokoll', 'connect_activity_log', 'Inhalt leeren, Zeile bleibt', 'CONNECT_RETENTION_ACTIVITY_BODY_DAYS', 90],
        'activity_rows' => ['Protokoll-Zeilen (inkl. Rechnungsübersicht)', 'connect_activity_log', 'löschen', 'CONNECT_RETENTION_ACTIVITY_DAYS', 365],
        'order_cache' => ['Bestellungen im Zwischenspeicher', 'connect_object_records', 'löschen (verwaiste nach 7 Tagen)', 'CONNECT_RETENTION_ORDER_CACHE_DAYS', 90],
        'orphaned_records' => ['Verwaiste Datensätze (außer Bestellungen)', 'connect_object_records', 'löschen', 'CONNECT_RETENTION_ORPHANED_DAYS', 30],
        'sync_runs' => ['Job-Läufe mit Protokoll', 'connect_sync_runs', 'löschen', 'CONNECT_RETENTION_SYNC_RUN_DAYS', 30],
        'sync_jobs' => ['Erledigte Jobs (Warteschlange)', 'connect_sync_jobs', 'löschen, letzte Zeile je Job bleibt', 'CONNECT_RETENTION_SYNC_JOB_DAYS', 7],
        'stock_log' => ['Lagerbestand-Änderungen', 'connect_stock_sync_log', 'löschen', 'CONNECT_RETENTION_STOCK_LOG_DAYS', 180],
        // Lang genug, damit "nur von Connect ausgeblendete wieder aktivieren" auch saisonale Ware wiederfindet
        'visibility_log' => ['Ausgeblendete/eingeblendete Produkte', 'connect_product_visibility_log', 'löschen', 'CONNECT_RETENTION_VISIBILITY_LOG_DAYS', 730],
        'translation_log' => ['Doppelt-Schutz je Datensatz', 'connect_translation_log', 'löschen (nie „pending“, mind. Bestell-Frist + 30 Tage)', 'CONNECT_RETENTION_TRANSLATION_DAYS', 180],
        'api_usage' => ['API-Verbrauch je Stunde', 'connect_api_usage_hourly', 'löschen', 'CONNECT_RETENTION_API_USAGE_DAYS', 30],
        'worker_api' => ['API-Aufrufe je Worker und Minute', 'connect_worker_api_minute', 'löschen', 'CONNECT_RETENTION_WORKER_API_DAYS', 3],
        'privacy_requests' => ['Datenschutz-Anfragen', 'connect_privacy_requests', 'löschen', 'CONNECT_RETENTION_PRIVACY_DAYS', 30],
        // Nachweis der Zustimmungen gelöschter Shops: 3 Jahre (Verjährung, § 1489 ABGB), siehe Migration 0077
        'consents' => ['Zustimmungs-Nachweise gelöschter Shops', 'connect_shop_consents', 'löschen (Tage ab Löschung des Shops)', 'CONNECT_RETENTION_CONSENT_DAYS', 1095],
        'login_attempts' => ['Admin-Login-Versuche', 'connect_admin_login_attempts', 'löschen', null, 30],
        'maintenance_runs' => ['Protokoll dieser Bereinigung', 'connect_maintenance_runs', 'löschen', null, 90],
    ];

    /** @var array<string, int> */
    private array $days = [];

    public function __construct(private readonly PDO $pdo, Config $config)
    {
        foreach (self::RULES as $key => $rule) {
            $default = $rule[4];
            $value = $rule[3] !== null ? $config->optional($rule[3], (string) $default) : (string) $default;
            $this->days[$key] = ctype_digit($value) && (int) $value >= 1 ? (int) $value : $default;
        }
        // Der Doppelt-Schutz muss laenger leben als Bestellungen im Zwischenspeicher, sonst
        // koennte der Zeitplan-Nachlauf eine schon verrechnete Bestellung erneut verarbeiten.
        $this->days['translation_log'] = max($this->days['translation_log'], $this->days['order_cache'] + 30);
    }

    /** @return array<string, int> Frist in Tagen je Regel */
    public function days(): array
    {
        return $this->days;
    }

    /**
     * Wie viele Zeilen jetzt faellig waeren (ohne etwas zu aendern). Fehlt eine
     * Tabelle, steht dort null.
     *
     * @return array<string, ?int>
     */
    public function countDue(): array
    {
        $counts = [];
        foreach (array_keys(self::RULES) as $key) {
            [$where] = $this->condition($key);
            try {
                $counts[$key] = (int) $this->pdo->query('SELECT COUNT(*) FROM ' . self::RULES[$key][1] . ' WHERE ' . $where)->fetchColumn();
            } catch (\Throwable) {
                $counts[$key] = null;
            }
        }

        return $counts;
    }

    /**
     * Ein Bereinigungslauf. Bricht nach $timeBudgetSeconds ab (Rest beim naechsten Lauf).
     *
     * @return array{status: string, counts: array<string, int>, errors: array<string, string>}
     */
    public function run(int $timeBudgetSeconds = 120): array
    {
        $started = microtime(true);
        $counts = [];
        $errors = [];
        $incomplete = false;

        foreach (array_keys(self::RULES) as $key) {
            [$where, $isUpdate] = $this->condition($key);
            $table = self::RULES[$key][1];
            $sql = $isUpdate
                ? "UPDATE {$table} SET request_body = NULL, response_body = NULL WHERE {$where} LIMIT " . self::BATCH
                : "DELETE FROM {$table} WHERE {$where} LIMIT " . self::BATCH;
            $counts[$key] = 0;

            try {
                do {
                    $affected = (int) $this->pdo->exec($sql);
                    $counts[$key] += $affected;
                    if (microtime(true) - $started > $timeBudgetSeconds) {
                        // Zeitbudget aufgebraucht: Rest (auch noch nicht erreichte Regeln) beim naechsten Lauf
                        $incomplete = true;
                        break 2;
                    }
                } while ($affected === self::BATCH);
            } catch (\PDOException $e) {
                if ($e->getCode() !== '42S02') { // fehlende Tabelle (Migration noch nicht eingespielt) ist kein Fehler
                    $errors[$key] = $e->getMessage();
                }
            } catch (\Throwable $e) {
                $errors[$key] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            $status = count($errors) < count(self::RULES) ? 'partial' : 'error';
        } else {
            $status = $incomplete ? 'partial' : 'success';
        }

        return ['status' => $status, 'counts' => $counts, 'errors' => $errors];
    }

    /**
     * Einmal taeglich aus dem Scheduler: nur wenn seit dem letzten erfolgreichen
     * Lauf 24 h vergangen sind und kein anderer Prozess gerade bereinigt.
     */
    public function maybeRunDaily(string $host, int $timeBudgetSeconds = 30): ?array
    {
        try {
            if (!$this->isDue()) {
                return null;
            }
        } catch (\Throwable) {
            return null; // Migration 0069 fehlt -- dann nicht automatisch bereinigen
        }

        // Benannte Sperre auf dem MariaDB-Server: gilt fuer alle Verbindungen, also auch fuer
        // Scheduler auf anderen Rechnern (Worker) mit derselben Datenbank. Wartezeit 0 -- wer
        // sie nicht bekommt, laesst die Bereinigung in diesem Aufruf einfach aus.
        if ((int) $this->pdo->query("SELECT GET_LOCK('" . self::LOCK_NAME . "', 0)")->fetchColumn() !== 1) {
            return null;
        }
        try {
            // Erneut pruefen MIT Sperre: ein anderer Prozess kann zwischen der ersten Pruefung
            // und dem Erhalt der Sperre gelaufen und schon fertig sein.
            if (!$this->isDue()) {
                return null;
            }

            return $this->runAndRecord('scheduler', $host, $timeBudgetSeconds);
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')");
        }
    }

    /**
     * Faellig, wenn noch nie gelaufen, der letzte Lauf "partial" war und 1 h her ist
     * (grosser erster Lauf wird zuegig fortgesetzt), sonst nach 24 h.
     */
    private function isDue(): bool
    {
        $last = $this->pdo->query(
            "SELECT status, TIMESTAMPDIFF(MINUTE, started_at, NOW()) AS minutes_ago FROM connect_maintenance_runs
             WHERE task = '" . self::TASK . "' AND status IN ('success', 'partial', 'running', 'error')
             ORDER BY id DESC LIMIT 1"
        )->fetch();
        if ($last === false) {
            return true;
        }
        $minMinutes = $last['status'] === 'partial' ? 60 : 24 * 60;

        return (int) $last['minutes_ago'] >= $minMinutes;
    }

    /**
     * Lauf mit Eintrag in connect_maintenance_runs (falls die Tabelle existiert).
     *
     * @return array{status: string, counts: array<string, int>, errors: array<string, string>}
     */
    public function runAndRecord(string $trigger, string $host, int $timeBudgetSeconds = 120): array
    {
        $runId = null;
        try {
            $this->pdo->prepare('INSERT INTO connect_maintenance_runs (task, trigger_type, host) VALUES (:task, :trigger, :host)')
                ->execute(['task' => self::TASK, 'trigger' => $trigger, 'host' => $host]);
            $runId = (int) $this->pdo->lastInsertId();
        } catch (\Throwable) {
            // ohne Protokolltabelle trotzdem bereinigen
        }

        $result = $this->run($timeBudgetSeconds);

        if ($runId !== null) {
            $this->pdo->prepare(
                'UPDATE connect_maintenance_runs SET finished_at = NOW(), status = :status, result = :result, error_message = :error WHERE id = :id'
            )->execute([
                'status' => $result['status'],
                'result' => json_encode($result['counts']),
                'error' => $result['errors'] !== [] ? implode(' | ', array_map(static fn ($k, $v) => "{$k}: {$v}", array_keys($result['errors']), $result['errors'])) : null,
                'id' => $runId,
            ]);
        }

        return $result;
    }

    /**
     * WHERE-Bedingung je Regel und ob es ein UPDATE (Inhalt leeren) statt DELETE ist.
     * Alle Werte stammen aus Konstanten bzw. ganzzahligen Fristen -- keine Eingaben.
     *
     * @return array{0: string, 1: bool}
     */
    private function condition(string $key): array
    {
        $d = $this->days[$key];

        return match ($key) {
            'webhook_events' => ["received_at < NOW() - INTERVAL {$d} DAY", false],
            'activity_bodies' => ["created_at < NOW() - INTERVAL {$d} DAY AND (request_body IS NOT NULL OR response_body IS NOT NULL)", true],
            'activity_rows' => ["created_at < NOW() - INTERVAL {$d} DAY", false],
            'order_cache' => ["object_key = 'order' AND ((status = 'orphaned' AND fetched_at < NOW() - INTERVAL 7 DAY) OR fetched_at < NOW() - INTERVAL {$d} DAY)", false],
            'orphaned_records' => ["object_key <> 'order' AND status = 'orphaned' AND fetched_at < NOW() - INTERVAL {$d} DAY", false],
            'sync_runs' => ["started_at < NOW() - INTERVAL {$d} DAY", false],
            // Die juengste Zeile je Job bleibt immer: daraus liest der Scheduler Faelligkeit und Fortschritt (cursor_state).
            'sync_jobs' => ["status IN ('done', 'error') AND scheduled_for < NOW() - INTERVAL {$d} DAY
                             AND id NOT IN (SELECT keep_id FROM (SELECT MAX(id) AS keep_id FROM connect_sync_jobs GROUP BY sync_mapping_id) AS keep_rows)", false],
            'stock_log' => ["created_at < NOW() - INTERVAL {$d} DAY", false],
            'visibility_log' => ["created_at < NOW() - INTERVAL {$d} DAY", false],
            'translation_log' => ["status <> 'pending' AND created_at < NOW() - INTERVAL {$d} DAY", false],
            'api_usage' => ["hour_start < NOW() - INTERVAL {$d} DAY", false],
            'worker_api' => ["minute_start < NOW() - INTERVAL {$d} DAY", false],
            'privacy_requests' => ["received_at < NOW() - INTERVAL {$d} DAY", false],
            'consents' => ["shop_deleted_at IS NOT NULL AND shop_deleted_at < NOW() - INTERVAL {$d} DAY", false],
            'login_attempts' => ["attempted_at < NOW() - INTERVAL {$d} DAY", false],
            'maintenance_runs' => ["started_at < NOW() - INTERVAL {$d} DAY AND status <> 'running'", false],
        };
    }
}
