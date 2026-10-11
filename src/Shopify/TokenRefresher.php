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
 * Der erste Tausch (alter, unbefristeter Token -> neuer, ablaufender Token)
 * braucht einen App-Bridge-Session-Token vom Merchant-Browser (siehe
 * Migrationsbruecke) und passiert dort, nicht hier. DIESE Klasse kuemmert
 * sich nur um das laufende Erneuern via Refresh-Token -- ein reiner
 * Server-zu-Server-Aufruf, ohne Merchant-Interaktion, funktioniert also
 * genauso aus einem Cronjob wie aus einem Live-Seitenaufruf.
 *
 * Request-Format (form-urlencoded, kein JSON) 1:1 uebernommen aus dem
 * bewaehrten Muster in generate_token.php der alten Welt -- nur mit
 * grant_type=refresh_token statt code.
 */
final class TokenRefresher
{
    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly string $clientId,
        private readonly string $clientSecret
    ) {
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
        if (!isset($credentials['refresh_token'], $credentials['expires_at'])) {
            return $credentials;
        }

        $expiresAt = strtotime((string) $credentials['expires_at']);
        // 60 Sekunden Sicherheitspuffer, damit ein Token nicht mitten in
        // einem laufenden API-Aufruf ablaeuft.
        if ($expiresAt !== false && $expiresAt > time() + 60) {
            return $credentials;
        }

        $shopDomain = self::extractShopDomain((string) ($credentials['base_url'] ?? ''));

        // Zweite Absicherung (zusaetzlich zur Pruefung in ConnectorFactory): das App-Secret geht nur an
        // eine echte <shop>.myshopify.com-Adresse, nie an einen frei gewaehlten Host.
        if ($shopDomain !== null && preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/i', $shopDomain) !== 1) {
            throw new \RuntimeException("Connection {$connectionId}: Token-Refresh nur fuer *.myshopify.com erlaubt.");
        }

        if ($shopDomain === null) {
            throw new \RuntimeException("Connection {$connectionId}: keine gueltige Shopify-Basis-URL fuer Token-Refresh ermittelbar.");
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
            throw new \RuntimeException("Token-Refresh fuer Connection {$connectionId} fehlgeschlagen: HTTP {$response->statusCode} - {$response->body}");
        }

        $decoded = json_decode($response->body, true);
        if (empty($decoded['access_token']) || empty($decoded['refresh_token'])) {
            throw new \RuntimeException("Token-Refresh fuer Connection {$connectionId}: Antwort enthielt keinen access_token/refresh_token.");
        }

        // Jede Erneuerung liefert auch ein NEUES refresh_token zurueck --
        // das alte ist danach ungueltig. Muss ebenfalls gespeichert werden,
        // sonst schlaegt der naechste Refresh fehl.
        $credentials['header_value'] = $decoded['access_token'];
        $credentials['refresh_token'] = $decoded['refresh_token'];
        $credentials['expires_at'] = date('c', time() + (int) ($decoded['expires_in'] ?? 3600));

        $update = $pdo->prepare('UPDATE connect_connections SET credentials = :credentials, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $update->execute([
            'credentials' => $crypto->encryptArray($credentials),
            'id' => $connectionId,
        ]);

        return $credentials;
    }

    private static function extractShopDomain(string $baseUrl): ?string
    {
        $host = parse_url($baseUrl, PHP_URL_HOST);

        return is_string($host) ? $host : null;
    }
}
