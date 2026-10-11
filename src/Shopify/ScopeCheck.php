<?php

declare(strict_types=1);

namespace Connect\Shopify;

use Connect\Config;
use Connect\Http\HttpClient;
use Connect\Security\Crypto;
use PDO;

/**
 * Prueft, ob der Shopify-Token eines Shops alle Berechtigungen (Scopes) hat, die die App
 * braucht -- vor dem Umstellen nach Connect (admin/migrate.php, Connect\Routing\LiveSwitch)
 * und zur Anzeige. Gegenstueck zu check_scopes() der alten Welt (inc/functions.php).
 *
 * Benoetigt: CONNECT_SHOPIFY_SCOPES in connect/.env, kommagetrennt -- derselbe Wert wie
 * SHOPIFY_SCOPES der alten Welt (das ist, was die App bei der Installation anfragt). Ohne
 * Eintrag gilt DEFAULT_REQUIRED (was Connect selbst nutzt).
 * Erteilt: GET https://<shop>/admin/oauth/access_scopes.json. Eine write_-Berechtigung deckt
 * die passende read_-Berechtigung mit ab.
 *
 * Fehlt etwas, muss der Merchant die App neu autorisieren (authorizeUrl(), laeuft ueber
 * generate_token.php der alten Welt), danach im Connect-Admin "Token uebernehmen".
 */
final class ScopeCheck
{
    /** Was Connect selbst braucht: Produkte/Varianten lesen+anlegen+aendern, Lager setzen, Standorte, Bestellungen. */
    private const DEFAULT_REQUIRED = ['read_products', 'write_products', 'read_inventory', 'write_inventory', 'read_locations', 'read_orders'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly Crypto $crypto,
        private readonly Config $config,
        private readonly HttpClient $httpClient = new HttpClient()
    ) {
    }

    /** @return list<string> */
    public function required(): array
    {
        $configured = array_values(array_filter(array_map('trim', explode(',', $this->config->optional('CONNECT_SHOPIFY_SCOPES', ''))), 'strlen'));

        return $configured !== [] ? $configured : self::DEFAULT_REQUIRED;
    }

    public function requiredFromEnv(): bool
    {
        return trim($this->config->optional('CONNECT_SHOPIFY_SCOPES', '')) !== '';
    }

    /**
     * Live bei Shopify pruefen und das Ergebnis an der Shopify-Connection merken
     * (config.shopify_scopes), damit Seiten nicht bei jedem Aufruf nachfragen muessen.
     *
     * @return array{ok: ?bool, granted: list<string>, required: list<string>, missing: list<string>, error: ?string, checked_at: ?string}
     *         ok = null: Pruefung nicht moeglich (siehe error)
     */
    public function check(string $shop): array
    {
        $required = $this->required();
        $connection = $this->shopifyConnection($shop);
        if ($connection === null) {
            return self::result(null, [], $required, 'Keine Shopify-Connection für diesen Shop.');
        }

        try {
            $credentials = $this->crypto->decryptArray((string) $connection['credentials']);
            $domain = (string) parse_url((string) ($credentials['base_url'] ?? ''), PHP_URL_HOST);
            $token = (string) ($credentials['header_value'] ?? '');
            if ($domain === '' || $token === '') {
                return self::result(null, [], $required, 'Shopify-Zugangsdaten unvollständig.');
            }

            $response = $this->httpClient->request('GET', "https://{$domain}/admin/oauth/access_scopes.json", ['X-Shopify-Access-Token' => $token]);
            if (!$response->isSuccess()) {
                return self::result(null, [], $required, "Shopify antwortet mit HTTP {$response->statusCode}" . ($response->statusCode === 401 ? ' (Token ungültig – „Token übernehmen“?)' : '') . '.');
            }
            $decoded = json_decode($response->body, true);
            $granted = [];
            foreach ((array) ($decoded['access_scopes'] ?? []) as $scope) {
                if (is_array($scope) && ($scope['handle'] ?? '') !== '') {
                    $granted[] = (string) $scope['handle'];
                }
            }
        } catch (\Throwable $e) {
            return self::result(null, [], $required, $e->getMessage());
        }

        $missing = self::missing($granted, $required);
        $result = self::result($missing === [], $granted, $required, null, $missing);

        // Merken (Anzeige ohne erneute Abfrage)
        $config = json_decode((string) ($connection['config'] ?? ''), true);
        $config = is_array($config) ? $config : [];
        $config['shopify_scopes'] = ['granted' => $granted, 'checked_at' => $result['checked_at']];
        $this->pdo->prepare('UPDATE connect_connections SET config = :config WHERE id = :id')
            ->execute(['config' => json_encode($config, JSON_UNESCAPED_UNICODE), 'id' => (int) $connection['id']]);

        return $result;
    }

    /**
     * Link, ueber den der Merchant der App die fehlenden Berechtigungen erteilt (Shopify-OAuth
     * wie bei der Installation, Rueckkehr ueber generate_token.php der alten Welt, die den
     * neuen Token speichert). Danach in Connect unter Shops "Token uebernehmen".
     */
    public function authorizeUrl(string $shop): string
    {
        $redirect = $this->config->optional('CONNECT_LEGACY_TOKEN_REDIRECT', 'https://oview.at/generate_token.php');

        return 'https://' . rawurlencode($shop) . '.myshopify.com/admin/oauth/authorize?client_id=' . rawurlencode($this->config->optional('CONNECT_SHOPIFY_API_KEY', ''))
            . '&scope=' . rawurlencode(implode(',', $this->required())) . '&redirect_uri=' . rawurlencode($redirect);
    }

    /**
     * @param list<string> $granted
     * @param list<string> $required
     * @return list<string>
     */
    public static function missing(array $granted, array $required): array
    {
        $have = array_flip($granted);
        $missing = [];
        foreach ($required as $scope) {
            $covered = isset($have[$scope])
                || (str_starts_with($scope, 'read_') && isset($have['write_' . substr($scope, 5)]));
            if (!$covered) {
                $missing[] = $scope;
            }
        }

        return $missing;
    }

    /**
     * @param list<string> $granted
     * @param list<string> $required
     * @param list<string> $missing
     * @return array{ok: ?bool, granted: list<string>, required: list<string>, missing: list<string>, error: ?string, checked_at: ?string}
     */
    private static function result(?bool $ok, array $granted, array $required, ?string $error, array $missing = []): array
    {
        return ['ok' => $ok, 'granted' => $granted, 'required' => $required, 'missing' => $missing, 'error' => $error, 'checked_at' => $ok !== null ? date('Y-m-d H:i:s') : null];
    }

    /** @return array{id: int, credentials: string, config: ?string}|null */
    private function shopifyConnection(string $shop): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.id, c.credentials, c.config FROM connect_connections c JOIN connect_connector_types t ON t.id = c.connector_type_id
             WHERE c.shop = :shop AND t.type_key = 'shopify' LIMIT 1"
        );
        $stmt->execute(['shop' => $shop]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }
}
