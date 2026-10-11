<?php

declare(strict_types=1);

namespace Connect\Shopify;

use Connect\Http\HttpClient;
use Connect\Security\Crypto;
use PDO;

/**
 * Haelt Shopify-Access-Tokens frisch (siehe Shopify-Ankuendigung: ab
 * 1.1.2027 muessen alle Public Apps ablaufende Offline-Tokens nutzen,
 * https://shopify.dev/changelog/expiring-offline-access-tokens-required-for-all-public-apps-as-of-january-1-2027).
 *
 * Ablaufende Tokens gelten 1 Stunde, das Refresh-Token 90 Tage; jede Erneuerung
 * liefert ein NEUES Refresh-Token. Shopify haelt je App und Shop nur EIN gueltiges
 * ablaufendes Token -- erneuern zwei Prozesse (Scheduler, Webhook, Worker)
 * gleichzeitig, kann ein schon ersetztes Token in der DB landen. Deshalb wird je
 * Connection mit einer Zeilensperre (SELECT ... FOR UPDATE) erneuert und nach der
 * Sperre neu gelesen. Bewusst KEIN GET_LOCK: unter MariaDB 5.5 gibt ein zweites
 * GET_LOCK die bisherige Sperre derselben Verbindung frei (Scheduler-Sperren).
 *
 * Der erste Tausch (alter, unbefristeter Token -> ablaufender Token) ist ein
 * eigener Schritt und passiert hier nicht. DIESE Klasse erneuert nur laufend via
 * Refresh-Token -- ein reiner Server-zu-Server-Aufruf, funktioniert also aus einem
 * Cronjob wie aus einem Seitenaufruf. Credentials ohne refresh_token/expires_at
 * (unbefristete Tokens, MGM, helloCash) bleiben unveraendert.
 *
 * Fehlgeschlagene Erneuerungen stehen in connect_connections.config.token_refresh
 * ({failed_at, error}), Connect\Shopify\TokenHealth meldet sie taeglich.
 *
 * Request-Format (form-urlencoded, kein JSON) 1:1 uebernommen aus dem
 * bewaehrten Muster in generate_token.php der alten Welt -- nur mit
 * grant_type=refresh_token statt code.
 */
final class TokenRefresher
{
    /** Sicherheitspuffer, damit ein Token nicht mitten in einem laufenden Aufruf ablaeuft */
    private const EXPIRY_BUFFER_SECONDS = 60;

    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly string $clientId,
        private readonly string $clientSecret
    ) {
    }

    /** Standard-Aufbau mit den Shopify-App-Zugangsdaten aus connect/.env. */
    public static function fromConfig(\Connect\Config $config): self
    {
        return new self(
            new HttpClient(),
            $config->optional('CONNECT_SHOPIFY_API_KEY'),
            $config->optional('CONNECT_SHOPIFY_API_SECRET')
        );
    }

    /**
     * Zugangsdaten einer Connection lesen und dabei bei Bedarf erneuern -- fuer alle Stellen,
     * die den Shopify-Token direkt brauchen (header_value), statt ueber ConnectorFactory.
     *
     * @return array<string, mixed>
     */
    public function freshCredentials(PDO $pdo, Crypto $crypto, int $connectionId): array
    {
        $stmt = $pdo->prepare('SELECT credentials FROM connect_connections WHERE id = :id');
        $stmt->execute(['id' => $connectionId]);
        $encrypted = $stmt->fetchColumn();
        if ($encrypted === false) {
            throw new \RuntimeException("Connection {$connectionId} nicht gefunden.");
        }

        return $this->ensureFresh($pdo, $crypto, $connectionId, $crypto->decryptArray((string) $encrypted));
    }

    /**
     * Prueft Ablauf und erneuert bei Bedarf; persistiert eine Erneuerung
     * sofort in der DB. Bei Credentials ohne refresh_token/expires_at
     * (z.B. MGM, oder Shopify-Connections vor der Umstellung) passiert
     * nichts -- kein Fehler, einfach unveraendert zurueckgeben.
     *
     * @param array<string, mixed> $credentials
     * @return array<string, mixed>
     */
    public function ensureFresh(PDO $pdo, Crypto $crypto, int $connectionId, array $credentials): array
    {
        if (!self::needsRefresh($credentials)) {
            return $credentials;
        }

        // Eigene Transaktion nur, wenn der Aufrufer keine offen hat -- sonst haelt seine
        // Transaktion die Zeilensperre bis zu seinem Commit.
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }
        try {
            // Sperre und neu lesen: wartet, bis ein parallel erneuernder Prozess fertig ist,
            // und sieht dann dessen Ergebnis.
            $stmt = $pdo->prepare('SELECT credentials, config FROM connect_connections WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $connectionId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $current = $row !== false ? $crypto->decryptArray((string) $row['credentials']) : $credentials;

            if (!self::needsRefresh($current)) {
                if ($ownTransaction) {
                    $pdo->commit();
                }

                return $current;
            }

            try {
                $current = $this->refresh($connectionId, $current);
            } catch (\Throwable $e) {
                if ($ownTransaction) {
                    $pdo->rollBack();
                }
                $this->recordFailure($pdo, $connectionId, $e->getMessage());

                throw $e;
            }

            $config = json_decode((string) ($row['config'] ?? ''), true);
            $config = is_array($config) ? $config : [];
            unset($config['token_refresh']);
            $pdo->prepare('UPDATE connect_connections SET credentials = :credentials, config = :config, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
                ->execute([
                    'credentials' => $crypto->encryptArray($current),
                    'config' => json_encode($config, JSON_UNESCAPED_UNICODE),
                    'id' => $connectionId,
                ]);
            if ($ownTransaction) {
                $pdo->commit();
            }

            return $current;
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Ablaufendes Token, das (fast) abgelaufen ist? Unbefristete Tokens: nie.
     *
     * @param array<string, mixed> $credentials
     */
    public static function needsRefresh(array $credentials): bool
    {
        if (!isset($credentials['refresh_token'], $credentials['expires_at'])) {
            return false;
        }
        $expiresAt = strtotime((string) $credentials['expires_at']);

        return $expiresAt === false || $expiresAt <= time() + self::EXPIRY_BUFFER_SECONDS;
    }

    /**
     * @param array<string, mixed> $credentials
     * @return array<string, mixed> erneuerte Credentials (noch nicht gespeichert)
     */
    private function refresh(int $connectionId, array $credentials): array
    {
        $shopDomain = self::extractShopDomain((string) ($credentials['base_url'] ?? ''));

        // Zweite Absicherung (zusaetzlich zur Pruefung in ConnectorFactory): das App-Secret geht nur an
        // eine echte <shop>.myshopify.com-Adresse, nie an einen frei gewaehlten Host.
        if ($shopDomain === null) {
            throw new \RuntimeException("Connection {$connectionId}: keine gueltige Shopify-Basis-URL fuer Token-Refresh ermittelbar.");
        }
        if (preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/i', $shopDomain) !== 1) {
            throw new \RuntimeException("Connection {$connectionId}: Token-Refresh nur fuer *.myshopify.com erlaubt.");
        }

        $body = http_build_query([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $credentials['refresh_token'],
        ]);

        $response = $this->httpClient->request(
            'POST',
            "https://{$shopDomain}/admin/oauth/access_token",
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            $body
        );

        if (!$response->isSuccess()) {
            throw new \RuntimeException("Token-Refresh fuer Connection {$connectionId} fehlgeschlagen: HTTP {$response->statusCode} - " . mb_substr($response->body, 0, 300));
        }

        $decoded = json_decode($response->body, true);
        if (!is_array($decoded) || empty($decoded['access_token']) || empty($decoded['refresh_token'])) {
            throw new \RuntimeException("Token-Refresh fuer Connection {$connectionId}: Antwort enthielt keinen access_token/refresh_token.");
        }

        // Jede Erneuerung liefert auch ein NEUES refresh_token zurueck --
        // das alte ist danach ungueltig. Muss ebenfalls gespeichert werden,
        // sonst schlaegt der naechste Refresh fehl.
        $credentials['header_value'] = $decoded['access_token'];
        $credentials['refresh_token'] = $decoded['refresh_token'];
        $credentials['expires_at'] = date('c', time() + (int) ($decoded['expires_in'] ?? 3600));
        if (isset($decoded['refresh_token_expires_in'])) {
            $credentials['refresh_token_expires_at'] = date('c', time() + (int) $decoded['refresh_token_expires_in']);
        }

        return $credentials;
    }

    /** Fehlschlag an der Connection merken (TokenHealth meldet ihn), ohne die Zugangsdaten anzufassen. */
    private function recordFailure(PDO $pdo, int $connectionId, string $error): void
    {
        try {
            $stmt = $pdo->prepare('SELECT config FROM connect_connections WHERE id = :id');
            $stmt->execute(['id' => $connectionId]);
            $config = json_decode((string) $stmt->fetchColumn(), true);
            $config = is_array($config) ? $config : [];
            $config['token_refresh'] = ['failed_at' => date('Y-m-d H:i:s'), 'error' => mb_substr($error, 0, 300)];
            $pdo->prepare('UPDATE connect_connections SET config = :config WHERE id = :id')
                ->execute(['config' => json_encode($config, JSON_UNESCAPED_UNICODE), 'id' => $connectionId]);
        } catch (\Throwable) {
            // Protokoll ist Zusatz -- der eigentliche Fehler wird ohnehin weitergeworfen
        }
    }

    private static function extractShopDomain(string $baseUrl): ?string
    {
        $host = parse_url($baseUrl, PHP_URL_HOST);

        return is_string($host) ? $host : null;
    }
}
