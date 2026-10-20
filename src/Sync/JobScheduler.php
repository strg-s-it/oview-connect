<?php

declare(strict_types=1);

namespace Connect\Sync;

use PDO;

/**
 * Jobs eines Shops "jetzt" einplanen, statt auf das naechste Intervall zu warten.
 *
 * Anlass: Stammdaten-Jobs (z.B. helloCash "Mitarbeiter", Zahlungsarten, Shopify-Standort) laufen
 * nur selten. Nach einer neuen/geaenderten Verbindung, einem neuen Token, dem Uebernehmen aus der
 * alten Welt oder dem Umstellen fehlten ihre Daten (Kassen-Einstellungen!) sonst bis zu einen Tag.
 *
 * Ein offener Job (pending) wird auf jetzt vorgezogen, ein laufender bleibt, sonst wird ein neuer
 * angelegt (mit dem letzten Cursor, damit inkrementelle Jobs nicht von vorne anfangen).
 */
final class JobScheduler
{
    /** Schreibende Arten -- muessen zum Umstellen passen (SyncRunner, live_in_connect) */
    public const WRITING_KINDS = ['stock_sync', 'stock_back', 'product_visibility', 'auto_import_products', 'invoice'];

    /**
     * Lese-/Daten-Jobs (fuellen den Zwischenspeicher, schreiben nichts) jetzt einplanen.
     *
     * @param int|null $connectionId nur die Jobs dieser Verbindung (z.B. nach neuem Token)
     * @return int Anzahl eingeplanter Jobs
     */
    public static function dataJobsNow(PDO $pdo, string $shop, ?int $connectionId = null): int
    {
        $sql = "SELECT id FROM connect_sync_mappings
                WHERE shop = :shop AND enabled = 1 AND schedule_interval_minutes IS NOT NULL
                  AND target_connection_id IS NULL
                  AND COALESCE(mapping_kind, 'default') NOT IN ('" . implode("','", self::WRITING_KINDS) . "')";
        $params = ['shop' => $shop];
        if ($connectionId !== null) {
            $sql .= ' AND source_connection_id = :cid';
            $params['cid'] = $connectionId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return self::now($pdo, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /**
     * @param list<int> $mappingIds
     * @return int Anzahl eingeplanter Jobs
     */
    public static function now(PDO $pdo, array $mappingIds): int
    {
        $count = 0;
        foreach ($mappingIds as $mappingId) {
            $open = $pdo->prepare("SELECT id, status FROM connect_sync_jobs WHERE sync_mapping_id = :id AND status IN ('pending', 'running') ORDER BY id DESC LIMIT 1");
            $open->execute(['id' => $mappingId]);
            $job = $open->fetch(PDO::FETCH_ASSOC);

            if ($job !== false && $job['status'] === 'running') {
                continue;
            }
            if ($job !== false) {
                $pdo->prepare('UPDATE connect_sync_jobs SET scheduled_for = NOW() WHERE id = :id')->execute(['id' => (int) $job['id']]);
            } else {
                $cursor = $pdo->prepare('SELECT cursor_state FROM connect_sync_jobs WHERE sync_mapping_id = :id ORDER BY id DESC LIMIT 1');
                $cursor->execute(['id' => $mappingId]);
                $cursorState = $cursor->fetchColumn();
                $pdo->prepare("INSERT INTO connect_sync_jobs (sync_mapping_id, status, cursor_state) VALUES (:id, 'pending', :cursor)")
                    ->execute(['id' => $mappingId, 'cursor' => $cursorState !== false ? $cursorState : null]);
            }
            $count++;
        }

        return $count;
    }

    /**
     * Nach einem ERFOLGREICHEN Verbindungstest aufrufen, mit dem Status VOR dem Test:
     * war die Verbindung nicht aktiv (neu, neue Zugangsdaten, Fehler), alle Daten-Jobs der
     * Verbindung sofort -- sonst nur die zuletzt gescheiterten. Scheitert nie laut.
     */
    public static function afterSuccessfulTest(PDO $pdo, int $connectionId, ?string $previousStatus): int
    {
        if ($previousStatus !== 'active') {
            try {
                $shop = $pdo->prepare('SELECT shop FROM connect_connections WHERE id = :id');
                $shop->execute(['id' => $connectionId]);

                return self::dataJobsNow($pdo, (string) $shop->fetchColumn(), $connectionId);
            } catch (\Throwable $e) {
                error_log('[JobScheduler] Connection ' . $connectionId . ': ' . $e->getMessage());

                return 0;
            }
        }

        return self::retryFailedDataJobs($pdo, $connectionId);
    }

    /** Status einer Connection (fuer afterSuccessfulTest, VOR dem Test lesen). */
    public static function connectionStatus(PDO $pdo, int $connectionId): ?string
    {
        $stmt = $pdo->prepare('SELECT status FROM connect_connections WHERE id = :id');
        $stmt->execute(['id' => $connectionId]);
        $status = $stmt->fetchColumn();

        return $status !== false ? (string) $status : null;
    }

    /**
     * Nach einem ERFOLGREICHEN Verbindungstest: Daten-Jobs dieser Verbindung, deren letzter Lauf
     * fehlgeschlagen ist (z.B. Mitarbeiter mit altem Token), sofort wiederholen -- statt erst im
     * naechsten Intervall. Gesunde Jobs bleiben, wie sie sind (kein Produkt-Volllauf je Testklick).
     * Scheitert nie laut.
     */
    public static function retryFailedDataJobs(PDO $pdo, int $connectionId): int
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT m.id FROM connect_sync_mappings m
                 WHERE m.source_connection_id = :cid AND m.enabled = 1 AND m.schedule_interval_minutes IS NOT NULL
                   AND m.target_connection_id IS NULL
                   AND COALESCE(m.mapping_kind, 'default') NOT IN ('" . implode("','", self::WRITING_KINDS) . "')
                   AND (SELECT j.status FROM connect_sync_jobs j WHERE j.sync_mapping_id = m.id AND j.status NOT IN ('pending', 'running')
                        ORDER BY j.id DESC LIMIT 1) = 'error'"
            );
            $stmt->execute(['cid' => $connectionId]);

            return self::now($pdo, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        } catch (\Throwable $e) {
            error_log('[JobScheduler] Connection ' . $connectionId . ': ' . $e->getMessage());

            return 0;
        }
    }

    /**
     * Erstinitialisierung: ist jeder Daten-Job des Shops mindestens einmal erfolgreich gelaufen?
     * Fuer den Hinweis auf der Merchant-Uebersicht (_dashboard_body.php).
     *
     * @return array{total: int, done: int, open: list<array{name: string, failed: bool}>}
     */
    public static function initialLoad(PDO $pdo, string $shop): array
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT m.name,
                        EXISTS (SELECT 1 FROM connect_sync_jobs j WHERE j.sync_mapping_id = m.id AND j.status = 'done') AS ever_done,
                        (SELECT j.status FROM connect_sync_jobs j WHERE j.sync_mapping_id = m.id AND j.status NOT IN ('pending', 'running')
                         ORDER BY j.id DESC LIMIT 1) AS last_status
                 FROM connect_sync_mappings m
                 WHERE m.shop = :shop AND m.enabled = 1 AND m.schedule_interval_minutes IS NOT NULL
                   AND m.target_connection_id IS NULL
                   AND COALESCE(m.mapping_kind, 'default') NOT IN ('" . implode("','", self::WRITING_KINDS) . "')
                 ORDER BY m.name"
            );
            $stmt->execute(['shop' => $shop]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return ['total' => 0, 'done' => 0, 'open' => []];
        }

        $open = [];
        foreach ($rows as $row) {
            if ((int) $row['ever_done'] !== 1) {
                $open[] = ['name' => (string) $row['name'], 'failed' => $row['last_status'] === 'error'];
            }
        }

        return ['total' => count($rows), 'done' => count($rows) - count($open), 'open' => $open];
    }

    /** Wie dataJobsNow(), scheitert aber nie laut (Aufrufer: Speichern/Testen soll nicht daran haengen). */
    public static function dataJobsNowQuietly(PDO $pdo, string $shop, ?int $connectionId = null): int
    {
        try {
            return self::dataJobsNow($pdo, $shop, $connectionId);
        } catch (\Throwable $e) {
            error_log('[JobScheduler] ' . $shop . ': ' . $e->getMessage());

            return 0;
        }
    }
}
