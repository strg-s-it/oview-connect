<?php

declare(strict_types=1);

namespace Connect\Http;

use PDO;

/**
 * Zaehlt ausgehende API-Aufrufe je Connection und Stunde (connect_api_usage_hourly)
 * und merkt sich den zuletzt gemeldeten Stand des Shopify-Kosten-Eimers
 * (connect_api_bucket), siehe Migration 0068. Grundlage fuer die Karte "Shopify"
 * in admin/jobs.php und admin/shopify_activity.php.
 *
 * Wertet die Antwort nur oberflaechlich aus (Textsuche statt vollem JSON-Decode),
 * weil Listen-Antworten gross sein koennen und der eigentliche Aufrufer sie ohnehin
 * selbst dekodiert. Fail-safe: fehlt die Tabelle oder schlaegt das Schreiben fehl,
 * wird das ignoriert -- die Statistik darf nie einen echten Aufruf stoeren.
 */
final class ApiUsageRecorder
{
    private bool $disabled = false;
    private bool $workerStatsDisabled = false;

    /**
     * @param string $typeKey connector_types.type_key dieser Connection (fuer die Statistik je
     *                        Worker und System, Migration 0071), leer = nicht erfassen
     */
    public function __construct(private readonly PDO $pdo, private readonly string $typeKey = '')
    {
    }

    /**
     * Shopify-GraphQL-Kosteninfo aus einer Antwort (extensions.cost), oder null.
     *
     * @return array{requested: ?float, actual: ?float, available: ?float, maximum: ?float, restoreRate: ?float}|null
     */
    public static function parseShopifyCost(string $body): ?array
    {
        if (!str_contains($body, '"throttleStatus"')) {
            return null;
        }
        $number = static function (string $key) use ($body): ?float {
            return preg_match('/"' . $key . '"\s*:\s*(-?[0-9]+(?:\.[0-9]+)?)/', $body, $m) === 1 ? (float) $m[1] : null;
        };

        return [
            'requested' => $number('requestedQueryCost'),
            'actual' => $number('actualQueryCost'),
            'available' => $number('currentlyAvailable'),
            'maximum' => $number('maximumAvailable'),
            'restoreRate' => $number('restoreRate'),
        ];
    }

    /** Shopify GraphQL meldet Drosselung mit HTTP 200 und errors[].extensions.code = THROTTLED. */
    public static function isThrottled(HttpResponse $response): bool
    {
        return $response->statusCode === 429 || ($response->statusCode === 200 && str_contains($response->body, '"THROTTLED"'));
    }

    public static function isError(HttpResponse $response): bool
    {
        if ($response->statusCode >= 400) {
            return true;
        }
        // GraphQL-Fehler auf oberster Ebene: {"errors":[...]} (ohne oder neben "data")
        return preg_match('/^\s*\{\s*"errors"\s*:\s*\[/', $response->body) === 1;
    }

    public function record(int $connectionId, HttpResponse $response): void
    {
        $throttled = self::isThrottled($response);
        $error = !$throttled && self::isError($response);

        $this->recordWorkerMinute($throttled || $error);

        if ($this->disabled) {
            return;
        }
        $cost = self::parseShopifyCost($response->body);
        $actualCost = $cost !== null ? (int) round((float) ($cost['actual'] ?? $cost['requested'] ?? 0)) : 0;

        try {
            $this->pdo->prepare(
                "INSERT INTO connect_api_usage_hourly (connection_id, hour_start, calls, cost, throttled, errors)
                 VALUES (:id, NOW() - INTERVAL (MINUTE(NOW()) * 60 + SECOND(NOW())) SECOND, 1, :cost, :throttled, :errors)
                 ON DUPLICATE KEY UPDATE calls = calls + 1, cost = cost + :cost2, throttled = throttled + :throttled2, errors = errors + :errors2"
            )->execute([
                'id' => $connectionId,
                'cost' => $actualCost, 'cost2' => $actualCost,
                'throttled' => $throttled ? 1 : 0, 'throttled2' => $throttled ? 1 : 0,
                'errors' => $error ? 1 : 0, 'errors2' => $error ? 1 : 0,
            ]);

            if ($cost !== null && $cost['available'] !== null) {
                $this->pdo->prepare(
                    'INSERT INTO connect_api_bucket (connection_id, currently_available, maximum_available, restore_rate, last_throttled_at, updated_at)
                     VALUES (:id, :available, :maximum, :rate, ' . ($throttled ? 'NOW()' : 'NULL') . ', NOW())
                     ON DUPLICATE KEY UPDATE currently_available = :available2, maximum_available = :maximum2, restore_rate = :rate2,
                         last_throttled_at = ' . ($throttled ? 'NOW()' : 'last_throttled_at') . ', updated_at = NOW()'
                )->execute([
                    'id' => $connectionId,
                    'available' => $cost['available'], 'available2' => $cost['available'],
                    'maximum' => $cost['maximum'], 'maximum2' => $cost['maximum'],
                    'rate' => $cost['restoreRate'], 'rate2' => $cost['restoreRate'],
                ]);
            }
        } catch (\Throwable $e) {
            // Tabelle fehlt (Migration 0068 noch nicht eingespielt) o.ae. -- fuer den
            // Rest dieses Prozesses abschalten statt bei jedem Aufruf erneut zu scheitern.
            $this->disabled = true;
            error_log('Connect ApiUsageRecorder deaktiviert: ' . $e->getMessage());
        }
    }

    /**
     * Aufrufe je Worker, System und Minute (Migration 0071) -- Grundlage fuer die
     * Lastverteilung (admin/load.php). Unabhaengig von der Connection-Statistik oben.
     */
    private function recordWorkerMinute(bool $failed): void
    {
        if ($this->workerStatsDisabled || $this->typeKey === '') {
            return;
        }
        try {
            $this->pdo->prepare(
                'INSERT INTO connect_worker_api_minute (worker, type_key, minute_start, calls, errors)
                 VALUES (:worker, :type_key, NOW() - INTERVAL SECOND(NOW()) SECOND, 1, :errors)
                 ON DUPLICATE KEY UPDATE calls = calls + 1, errors = errors + :errors2'
            )->execute([
                'worker' => mb_substr(\Connect\Runtime::workerName(), 0, 64),
                'type_key' => $this->typeKey,
                'errors' => $failed ? 1 : 0,
                'errors2' => $failed ? 1 : 0,
            ]);
        } catch (\Throwable $e) {
            $this->workerStatsDisabled = true; // Migration 0071 fehlt -- fuer diesen Prozess abschalten
        }
    }
}
