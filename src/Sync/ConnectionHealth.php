<?php

declare(strict_types=1);

namespace Connect\Sync;

use Connect\Config;
use Connect\Connector\ConnectorFactory;
use Connect\Notify\Notifier;
use PDO;

/**
 * Heartbeat fuer Connections (Migration 0105): der Scheduler prueft jede Connection alle
 * INTERVAL_MINUTES Minuten mit einem leichten Lesezugriff (ConnectorInterface::testConnection)
 * und speichert das Ergebnis in connect_connections.health_*. Die Shopliste (admin/shops.php)
 * zeigt es fuer Shopify und Zielsystem.
 *
 * Mail an den Admin erst ab dem zweiten fehlgeschlagenen Test in Folge (kein Alarm bei einem
 * einzelnen Netzfehler), hoechstens eine Mail je Ausfall, und nur fuer Shops, die in Connect
 * laufen (live_in_connect = 1). Bei Erholung kommt eine kurze Entwarnung, wenn zuvor gemailt wurde.
 *
 * Laeuft nur in einem Scheduler gleichzeitig (GET_LOCK auf oberster Ebene, nie verschachtelt),
 * je Aufruf hoechstens MAX_PER_RUN Connections und eine Zeitgrenze.
 */
final class ConnectionHealth
{
    private const LOCK_NAME = 'connect_connection_health';
    private const INTERVAL_MINUTES = 10;
    private const MAX_PER_RUN = 6;
    private const MAX_MESSAGE = 480;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ConnectorFactory $connectorFactory,
        private readonly Config $config
    ) {
    }

    /**
     * @return array{checked: int, failed: int, mailed: int}|null null = nichts zu tun, gesperrt oder Migration 0105 fehlt
     */
    public function run(int $maxSeconds): ?array
    {
        try {
            if ((int) $this->pdo->query("SELECT GET_LOCK('" . self::LOCK_NAME . "', 0)")->fetchColumn() !== 1) {
                return null;
            }
        } catch (\PDOException) {
            return null;
        }

        try {
            try {
                $due = $this->pdo->query(
                    'SELECT c.id, c.shop, c.label, c.health_status, c.health_failures, c.health_notified_at, t.name AS type_name,
                            COALESCE(s.live_in_connect, 0) AS live
                     FROM connect_connections c
                     JOIN connect_connector_types t ON t.id = c.connector_type_id
                     LEFT JOIN connect_shops s ON s.shop = c.shop
                     WHERE c.health_checked_at IS NULL OR c.health_checked_at < (NOW() - INTERVAL ' . self::INTERVAL_MINUTES . ' MINUTE)
                     ORDER BY (c.health_checked_at IS NULL) DESC, c.health_checked_at ASC
                     LIMIT ' . self::MAX_PER_RUN
                )->fetchAll(PDO::FETCH_ASSOC);
            } catch (\PDOException) {
                return null; // Migration 0105 fehlt (oder live_in_connect, Migration 0050)
            }
            if ($due === []) {
                return null;
            }

            $deadline = microtime(true) + $maxSeconds;
            $checked = 0;
            $failed = 0;
            $mailed = 0;
            foreach ($due as $connection) {
                if ($checked > 0 && microtime(true) > $deadline) {
                    break;
                }
                $checked++;
                $result = $this->check((int) $connection['id']);
                if (!$result['ok']) {
                    $failed++;
                }
                $mailed += $this->record($connection, $result);
            }

            return ['checked' => $checked, 'failed' => $failed, 'mailed' => $mailed];
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')");
        }
    }

    /**
     * @return array{ok: bool, message: string, unknown: bool}
     */
    private function check(int $connectionId): array
    {
        try {
            $result = $this->connectorFactory->forConnection($connectionId)->testConnection();
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => mb_substr($e->getMessage(), 0, self::MAX_MESSAGE), 'unknown' => false];
        }

        // Ein Connector ohne lesbares Object laesst sich nicht pruefen: kein Fehler, nur "unbekannt"
        if (!$result->success && str_starts_with($result->message, 'Kein lesbares Object')) {
            return ['ok' => true, 'message' => $result->message, 'unknown' => true];
        }

        return ['ok' => $result->success, 'message' => mb_substr($result->message, 0, self::MAX_MESSAGE), 'unknown' => false];
    }

    /**
     * @param array<string, mixed> $connection
     * @param array{ok: bool, message: string, unknown: bool} $result
     * @return int Anzahl gesendeter Mails (0 oder 1)
     */
    private function record(array $connection, array $result): int
    {
        $id = (int) $connection['id'];

        if ($result['unknown']) {
            $this->pdo->prepare('UPDATE connect_connections SET health_checked_at = NOW() WHERE id = :id')->execute(['id' => $id]);

            return 0;
        }

        if ($result['ok']) {
            $this->pdo->prepare(
                "UPDATE connect_connections
                 SET health_status = 'ok', health_checked_at = NOW(), health_message = NULL, health_failures = 0,
                     health_notified_at = NULL,
                     health_since = CASE WHEN health_status = 'ok' AND health_since IS NOT NULL THEN health_since ELSE NOW() END
                 WHERE id = :id"
            )->execute(['id' => $id]);

            if ($connection['health_notified_at'] !== null && (int) $connection['live'] === 1) {
                return $this->mail($connection, 'Connect: Verbindung wieder erreichbar', "Die Verbindung „{$connection['label']}“ ({$connection['type_name']}) von Shop {$connection['shop']} funktioniert wieder.") ? 1 : 0;
            }

            return 0;
        }

        $failures = (int) $connection['health_failures'] + 1;
        $this->pdo->prepare(
            "UPDATE connect_connections
             SET health_status = 'error', health_checked_at = NOW(), health_message = :message, health_failures = :failures,
                 health_since = CASE WHEN health_status = 'error' AND health_since IS NOT NULL THEN health_since ELSE NOW() END
             WHERE id = :id"
        )->execute(['message' => $result['message'], 'failures' => $failures, 'id' => $id]);

        if ($failures >= 2 && $connection['health_notified_at'] === null && (int) $connection['live'] === 1) {
            $sent = $this->mail(
                $connection,
                'Connect: Verbindung gestört (' . $connection['shop'] . ')',
                "Die Verbindung „{$connection['label']}“ ({$connection['type_name']}) von Shop {$connection['shop']} antwortet seit mindestens "
                    . (($failures - 1) * self::INTERVAL_MINUTES) . " Minuten nicht mehr korrekt.\n\n"
                    . "Letzte Meldung:\n{$result['message']}\n\n"
                    . "Häufige Ursache: ein Zugangs-Token wurde ungültig (z. B. neu erzeugt) – dann unter Admin → Connections den neuen Token eintragen und „Verbindung testen“."
            );
            if ($sent) {
                $this->pdo->prepare('UPDATE connect_connections SET health_notified_at = NOW() WHERE id = :id')->execute(['id' => $id]);

                return 1;
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $connection
     */
    private function mail(array $connection, string $subject, string $text): bool
    {
        try {
            $notifier = new Notifier($this->pdo, $this->config);

            return $notifier->send($notifier->adminEmail(), $subject, $text);
        } catch (\Throwable) {
            return false;
        }
    }
}
