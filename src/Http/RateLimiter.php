<?php

declare(strict_types=1);

namespace Connect\Http;

use PDO;
use PDOException;

/**
 * Selbst auferlegte Bremse fuer ausgehende Aufrufe zu einem Zielsystem (Connector Type),
 * z.B. "hoechstens 40 Aufrufe je 60 Sekunden zu HelloCash" -- unabhaengig von echten
 * HTTP-429-Antworten des Zielsystems (dafuer siehe consumeRetryAfter()). Konfiguriert
 * pro Connector Type (connect_connector_types.rate_limit_*, Migration 0053), gilt also
 * fuer ALLE Shops/Connections dieses Typs zusammen -- genau wie die alte Welt HelloCash-
 * Aufrufe pro Worker/IP zaehlte, nicht pro Shop (siehe HANDOFF: der Grund war eine
 * IP-seitige Begrenzung bei HelloCash, kein Limit pro Kunden-Token).
 *
 * Zustand liegt in der Datenbank (connect_rate_limit_state), nicht im PHP-Prozess: mehrere
 * gleichzeitig laufende Scheduler-Prozesse -- und kuenftig mehrere Worker mit eigener IP --
 * teilen sich denselben Zaehler. Einfaches "fixed window" (kein gleitendes Fenster/Leaky-
 * Bucket): fuer die hier relevante Groessenordnung (Dutzende Aufrufe pro Minute) reicht das
 * und bleibt fuer einen Admin leicht erklaerbar ("max. X je Y Sekunden").
 *
 * Fehlt die Tabelle (Migration 0053 noch nicht eingespielt) oder schlaegt ein Zugriff
 * fehl, gilt das als "kein Limit" -- ein fehlender Baustein soll niemals echten Verkehr
 * blockieren.
 */
final class RateLimiter
{
    /**
     * Anteil des Limits, den Hintergrund-Jobs (CLI, bin/scheduler.php) hoechstens
     * belegen duerfen. Der Rest bleibt fuer Echtzeit-Aufrufe frei (Rechnung per
     * Webhook, Klick im Admin) -- sonst koennte z.B. das Detail-Nachladen von
     * HelloCash-Artikeln (bis zu 200 Aufrufe je Lauf) das Fenster ausschoepfen und
     * eine gleichzeitig eintreffende Bestellung bis zum Abbruch warten lassen.
     */
    private const BACKGROUND_SHARE = 0.75;

    /** Maximale Wartezeit: Scheduler-Jobs duerfen ein ganzes Fenster abwarten, Web-Requests nicht. */
    private const MAX_WAIT_CLI_SECONDS = 120;
    private const MAX_WAIT_WEB_SECONDS = 20;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Wartet, bis ein Aufruf fuer diesen Connector Type erlaubt ist. Kein Limit
     * konfiguriert -> sofort zurueck. Wuerde das Warten laenger als $maxWaitSeconds
     * dauern, wird stattdessen abgebrochen (RuntimeException) -- lieber der aufrufende
     * Job sauber fehlschlagen und beim naechsten Lauf neu ansetzen, als einen PHP-Prozess
     * (moeglicherweise ein Web-Request wie "Verbindung testen") minutenlang zu blockieren.
     *
     * $maxWaitSeconds = null: Standard je nach Aufrufer -- CLI (Scheduler) 120s, damit ein
     * Job mit vielen Aufrufen ein volles Fenster abwarten kann statt nach dem ersten
     * vollen Fenster alle restlichen Aufrufe sofort scheitern zu lassen; Web/Webhook 20s.
     */
    public function waitForCapacity(RateLimitConfig $config, ?int $maxWaitSeconds = null): void
    {
        if ($config->maxRequests === null || $config->windowSeconds === null
            || $config->maxRequests < 1 || $config->windowSeconds < 1
        ) {
            return;
        }

        $isBackground = PHP_SAPI === 'cli';
        $maxWaitSeconds ??= $isBackground ? self::MAX_WAIT_CLI_SECONDS : self::MAX_WAIT_WEB_SECONDS;
        // Hintergrund-Jobs sehen ein kleineres Limit (mindestens 1), Echtzeit-Aufrufe das volle.
        $effectiveMax = $isBackground ? max(1, (int) floor($config->maxRequests * self::BACKGROUND_SHARE)) : $config->maxRequests;

        $deadline = microtime(true) + $maxWaitSeconds;

        while (true) {
            $waitSeconds = $this->tryClaim($config, $effectiveMax);
            if ($waitSeconds === 0) {
                return;
            }

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0 || $waitSeconds > $remaining) {
                throw new \RuntimeException(
                    "Anfrage-Limit erreicht (max. {$config->maxRequests} je {$config->windowSeconds}s) -- "
                    . "Job wird abgebrochen und beim naechsten Lauf fortgesetzt, statt laenger als {$maxWaitSeconds}s zu warten."
                );
            }

            usleep((int) ($waitSeconds * 1_000_000) + 50_000); // + kleiner Puffer gegen Wettlaufsituationen
        }
    }

    /**
     * Ein Versuch, einen Slot zu belegen. 0 = erlaubt (Zaehler wurde erhoeht), sonst die
     * Sekunden, die mindestens noch zu warten sind.
     */
    /**
     * Zaehler je ausgehender IP (Migration 0070, Spalte scope): HelloCash begrenzt pro IP,
     * jede Worker-IP (CONNECT_OUTBOUND_IP) bekommt ihr eigenes Kontingent. Fehlt die
     * Spalte noch (Migration nicht eingespielt), gilt fuer diesen Prozess der bisherige
     * gemeinsame Zaehler.
     */
    private static bool $scopeUnsupported = false;

    /** @return array{0: string, 1: array<string, mixed>} WHERE-Teil und Parameter fuer die Zeile dieses Prozesses */
    private static function rowFilter(int $connectorTypeId): array
    {
        if (self::$scopeUnsupported) {
            return ['connector_type_id = :id', ['id' => $connectorTypeId]];
        }

        return ['connector_type_id = :id AND scope = :scope', ['id' => $connectorTypeId, 'scope' => HttpClient::outboundIp()]];
    }

    private function tryClaim(RateLimitConfig $config, int $effectiveMax): int
    {
        [$where, $params] = self::rowFilter($config->connectorTypeId);

        try {
            // Stellt sicher, dass die Zeile existiert, OHNE einen bestehenden Zustand zu
            // veraendern -- fuer das anschliessende "SELECT ... FOR UPDATE" noetig.
            if (self::$scopeUnsupported) {
                $this->pdo->prepare(
                    'INSERT INTO connect_rate_limit_state (connector_type_id) VALUES (:id)
                     ON DUPLICATE KEY UPDATE connector_type_id = connector_type_id'
                )->execute($params);
            } else {
                $this->pdo->prepare(
                    'INSERT INTO connect_rate_limit_state (connector_type_id, scope) VALUES (:id, :scope)
                     ON DUPLICATE KEY UPDATE connector_type_id = connector_type_id'
                )->execute($params);
            }

            $this->pdo->beginTransaction();

            // NOW() aus derselben Abfrage wie die gespeicherten Zeitstempel, damit der
            // Vergleich unabhaengig von der PHP-Prozesszeitzone korrekt ist (gleiche
            // Vorsichtsmassnahme wie in Sync\ObjectCache::refresh()).
            $stmt = $this->pdo->prepare(
                "SELECT window_started_at, request_count, blocked_until, NOW() AS now_ts
                 FROM connect_rate_limit_state WHERE {$where} FOR UPDATE"
            );
            $stmt->execute($params);
            $row = $stmt->fetch();

            if ($row === false) {
                // Kann durch eine Race zwischen INSERT und SELECT eigentlich nicht vorkommen --
                // sicherheitshalber trotzdem erlauben statt einen Fehler zu werfen.
                $this->pdo->commit();

                return 0;
            }

            $now = strtotime((string) $row['now_ts']);

            if ($row['blocked_until'] !== null) {
                $blockedUntil = strtotime((string) $row['blocked_until']);
                if ($blockedUntil > $now) {
                    $this->pdo->commit();

                    return max(1, $blockedUntil - $now);
                }
            }

            $windowStarted = strtotime((string) $row['window_started_at']);
            $elapsed = $now - $windowStarted;

            if ($elapsed >= $config->windowSeconds) {
                $this->pdo->prepare(
                    "UPDATE connect_rate_limit_state SET window_started_at = NOW(), request_count = 1 WHERE {$where}"
                )->execute($params);
                $this->pdo->commit();

                return 0;
            }

            if ((int) $row['request_count'] < $effectiveMax) {
                $this->pdo->prepare(
                    "UPDATE connect_rate_limit_state SET request_count = request_count + 1 WHERE {$where}"
                )->execute($params);
                $this->pdo->commit();

                return 0;
            }

            $this->pdo->commit(); // nur gelesen, Transaktion trotzdem sauber beenden (Row-Lock freigeben)

            return max(1, $config->windowSeconds - $elapsed);
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($e->getCode() === '42S02') {
                error_log('Connect RateLimiter: connect_rate_limit_state fehlt (Migration 0053?) -- Limit wird ignoriert.');

                return 0;
            }
            if ($e->getCode() === '42S22' && !self::$scopeUnsupported) {
                // Spalte "scope" fehlt (Migration 0070 noch nicht eingespielt): gemeinsamer Zaehler wie bisher
                self::$scopeUnsupported = true;

                return $this->tryClaim($config, $effectiveMax);
            }
            throw $e;
        }
    }

    /**
     * Sperrt diesen Connector Type explizit fuer $retryAfterSeconds -- aufgerufen, wenn das
     * Zielsystem selbst mit HTTP 429 + Retry-After geantwortet hat (siehe
     * GenericHttpConnector::executeRequest()). Unabhaengig vom eigentlichen Zaehler: verhindert,
     * dass ein zweiter, fast gleichzeitig laufender Prozess (heute: ein weiterer Scheduler-Lauf,
     * kuenftig: ein anderer Worker) sofort erneut anfragt und denselben 429 provoziert.
     */
    public function consumeRetryAfter(int $connectorTypeId, int $retryAfterSeconds): void
    {
        $retryAfterSeconds = max(1, $retryAfterSeconds);

        try {
            if (self::$scopeUnsupported) {
                $this->pdo->prepare(
                    'INSERT INTO connect_rate_limit_state (connector_type_id, blocked_until)
                     VALUES (:id, NOW() + INTERVAL :seconds SECOND)
                     ON DUPLICATE KEY UPDATE blocked_until = GREATEST(COALESCE(blocked_until, NOW()), NOW() + INTERVAL :seconds2 SECOND)'
                )->execute(['id' => $connectorTypeId, 'seconds' => $retryAfterSeconds, 'seconds2' => $retryAfterSeconds]);
            } else {
                // Sperre nur fuer die IP, die den 429 bekommen hat (HelloCash sperrt pro IP)
                $this->pdo->prepare(
                    'INSERT INTO connect_rate_limit_state (connector_type_id, scope, blocked_until)
                     VALUES (:id, :scope, NOW() + INTERVAL :seconds SECOND)
                     ON DUPLICATE KEY UPDATE blocked_until = GREATEST(COALESCE(blocked_until, NOW()), NOW() + INTERVAL :seconds2 SECOND)'
                )->execute(['id' => $connectorTypeId, 'scope' => HttpClient::outboundIp(), 'seconds' => $retryAfterSeconds, 'seconds2' => $retryAfterSeconds]);
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '42S22' && !self::$scopeUnsupported) {
                self::$scopeUnsupported = true;
                $this->consumeRetryAfter($connectorTypeId, $retryAfterSeconds);

                return;
            }
            if ($e->getCode() !== '42S02') {
                throw $e;
            }
            // Tabelle fehlt -- dieselbe Fail-safe-Haltung wie in tryClaim().
        }
    }
}
