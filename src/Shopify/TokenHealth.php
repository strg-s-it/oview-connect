<?php

declare(strict_types=1);

namespace Connect\Shopify;

use Connect\Config;
use Connect\Notify\Notifier;
use Connect\Security\Crypto;
use PDO;

/**
 * Taegliche Kontrolle der Shopify-Tokens (bin/scheduler.php, ab 7 Uhr, genau ein Scheduler):
 * Mail an den Admin, wenn
 *   - eine Erneuerung fehlgeschlagen ist (config.token_refresh, siehe TokenRefresher) --
 *     dann arbeitet Connect fuer diesen Shop nicht mehr mit Shopify, oder
 *   - ein Refresh-Token in weniger als WARN_DAYS Tagen ablaeuft (90 Tage gueltig, wird bei
 *     jeder Erneuerung ersetzt -- laeuft also nur bei einem Shop ab, der lange ruht). Danach
 *     muss der Merchant die App einmal in Shopify oeffnen.
 * Unbefristete Tokens (vor der Umstellung) werden nicht gemeldet. Keine Mail, wenn alles passt.
 * Protokoll in connect_maintenance_runs (task "token_health", Migration 0069).
 */
final class TokenHealth
{
    private const TASK = 'token_health';
    private const LOCK_NAME = 'connect_token_health';
    private const WARN_DAYS = 14;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config
    ) {
    }

    /**
     * @return array{connections: int, failed: int, expiring: int, mailed: bool}|null null = nicht faellig
     */
    public function maybeRunDaily(string $host): ?array
    {
        try {
            if ((int) $this->pdo->query('SELECT HOUR(NOW())')->fetchColumn() < 7 || !$this->isDue()) {
                return null;
            }
        } catch (\PDOException) {
            return null; // Migration 0069 fehlt
        }
        if ((int) $this->pdo->query("SELECT GET_LOCK('" . self::LOCK_NAME . "', 0)")->fetchColumn() !== 1) {
            return null;
        }
        try {
            if (!$this->isDue()) {
                return null;
            }
            $this->pdo->prepare("INSERT INTO connect_maintenance_runs (task, trigger_type, host) VALUES (:task, 'scheduler', :host)")
                ->execute(['task' => self::TASK, 'host' => $host]);
            $runId = (int) $this->pdo->lastInsertId();

            $result = $this->check();
            $this->pdo->prepare("UPDATE connect_maintenance_runs SET finished_at = NOW(), status = 'success', result = :result WHERE id = :id")
                ->execute(['result' => json_encode($result), 'id' => $runId]);

            return $result;
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')");
        }
    }

    /**
     * @return array{connections: int, failed: int, expiring: int, mailed: bool}
     */
    public function check(): array
    {
        $crypto = new Crypto($this->config->require('CONNECT_ENCRYPTION_KEY'));
        $rows = $this->pdo->query(
            "SELECT c.id, c.shop, c.credentials, c.config FROM connect_connections c
             JOIN connect_connector_types t ON t.id = c.connector_type_id
             WHERE t.type_key = 'shopify' ORDER BY c.shop"
        )->fetchAll(PDO::FETCH_ASSOC);

        $failed = [];
        $expiring = [];
        foreach ($rows as $row) {
            $config = json_decode((string) ($row['config'] ?? ''), true);
            if (is_array($config) && isset($config['token_refresh']['failed_at'])) {
                $failed[] = "{$row['shop']} (Connection {$row['id']}): seit {$config['token_refresh']['failed_at']} -- "
                    . (string) ($config['token_refresh']['error'] ?? '');
            }
            try {
                $credentials = $crypto->decryptArray((string) $row['credentials']);
            } catch (\Throwable) {
                continue;
            }
            $refreshExpires = isset($credentials['refresh_token_expires_at']) ? strtotime((string) $credentials['refresh_token_expires_at']) : false;
            if ($refreshExpires !== false && $refreshExpires < time() + self::WARN_DAYS * 86400) {
                $expiring[] = "{$row['shop']} (Connection {$row['id']}): Refresh-Token " . ($refreshExpires < time() ? 'ABGELAUFEN seit ' : 'läuft ab am ') . date('d.m.Y', $refreshExpires);
            }
        }

        $mailed = false;
        if ($failed !== [] || $expiring !== []) {
            $text = "Tägliche Kontrolle der Shopify-Tokens in Connect.\n";
            if ($failed !== []) {
                $text .= "\nErneuerung FEHLGESCHLAGEN -- Connect erreicht Shopify für diese Shops nicht:\n- " . implode("\n- ", $failed) . "\n";
            }
            if ($expiring !== []) {
                $text .= "\nRefresh-Token läuft bald ab (Shop ruht?) -- danach muss der Merchant die App einmal in Shopify öffnen:\n- " . implode("\n- ", $expiring) . "\n";
            }
            $notifier = new Notifier($this->pdo, $this->config);
            $mailed = $notifier->send($notifier->adminEmail(), ($failed !== [] ? 'DRINGEND: ' : '') . 'Shopify-Tokens in Connect prüfen', $text);
        }

        return ['connections' => count($rows), 'failed' => count($failed), 'expiring' => count($expiring), 'mailed' => $mailed];
    }

    /** @phpstan-impure liest den aktuellen DB-Stand (nach der Sperre erneut) */
    private function isDue(): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM connect_maintenance_runs WHERE task = :task AND started_at > NOW() - INTERVAL 23 HOUR LIMIT 1"
        );
        $stmt->execute(['task' => self::TASK]);

        return $stmt->fetchColumn() === false;
    }
}
