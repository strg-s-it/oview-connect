<?php

declare(strict_types=1);

namespace Connect\Notify;

use Connect\Config;
use Connect\Http\HttpClient;
use Connect\Security\Crypto;
use Connect\Shopify\TokenRefresher;
use PDO;

/**
 * Kontaktdaten der Shops laut Shopify (Name, echte URL, Telefon, Mail) -- Grundlage fuer den
 * CSV-Export (admin/export.php, Akquise und Umstieg). Nur lesend gegen Shopify, Ergebnis je Shop
 * in connect_shop_contacts (Migration 0108).
 *
 * Token: Connect-Shops ueber TokenRefresher::freshCredentials(), Shops nur der alten Welt mit dem
 * Token aus oview_stores (MerchantDirectory::legacyToken()).
 */
final class ShopContacts
{
    private const QUERY = '{ shop { name email contactEmail myshopifyDomain primaryDomain { url }'
        . ' billingAddress { company phone address1 zip city countryCodeV2 } } }';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Crypto $crypto,
        private readonly Config $config,
        private readonly HttpClient $http = new HttpClient()
    ) {
    }

    /**
     * Gemerkte Kontaktdaten je Shop.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        try {
            $out = [];
            foreach ($this->pdo->query('SELECT * FROM connect_shop_contacts')->fetchAll() as $row) {
                $out[(string) $row['shop']] = $row;
            }

            return $out;
        } catch (\PDOException) {
            return []; // Migration 0108 fehlt
        }
    }

    /**
     * Live bei Shopify abfragen und merken.
     *
     * @param string|null $legacyToken Token der alten Welt (null = Connect-Shop mit eigener Connection)
     * @return string|null Fehler, null = gespeichert
     */
    public function fetch(string $shop, bool $isLegacy, ?string $legacyToken): ?string
    {
        $error = null;
        $data = null;
        try {
            if (preg_match('/^[a-z0-9][a-z0-9\-]*$/i', $shop) !== 1) {
                throw new \RuntimeException('ungültiger Shop-Name');
            }
            $domain = $shop . '.myshopify.com';
            if ($isLegacy) {
                $token = (string) $legacyToken;
                if ($token === '') {
                    throw new \RuntimeException('kein Shopify-Token in der alten Welt');
                }
            } else {
                $stmt = $this->pdo->prepare(
                    "SELECT c.id FROM connect_connections c JOIN connect_connector_types t ON t.id = c.connector_type_id
                     WHERE c.shop = :shop AND t.type_key = 'shopify' LIMIT 1"
                );
                $stmt->execute(['shop' => $shop]);
                $connectionId = $stmt->fetchColumn();
                if ($connectionId === false) {
                    throw new \RuntimeException('keine Shopify-Connection');
                }
                $credentials = TokenRefresher::fromConfig($this->config)->freshCredentials($this->pdo, $this->crypto, (int) $connectionId);
                $token = (string) ($credentials['header_value'] ?? '');
                $domain = (string) parse_url((string) ($credentials['base_url'] ?? ''), PHP_URL_HOST) ?: $domain;
                if ($token === '') {
                    throw new \RuntimeException('Shopify-Zugangsdaten unvollständig');
                }
            }

            $response = $this->http->request(
                'POST',
                'https://' . $domain . '/admin/api/' . $this->config->optional('CONNECT_SHOPIFY_API_VERSION', '2026-07') . '/graphql.json',
                ['X-Shopify-Access-Token' => $token, 'Content-Type' => 'application/json'],
                (string) json_encode(['query' => self::QUERY])
            );
            if (!$response->isSuccess()) {
                throw new \RuntimeException('Shopify HTTP ' . $response->statusCode . ($response->statusCode === 401 ? ' (Token ungültig – App deinstalliert?)' : ''));
            }
            $decoded = json_decode($response->body, true);
            $data = is_array($decoded) ? ($decoded['data']['shop'] ?? null) : null;
            if (!is_array($data)) {
                throw new \RuntimeException('Antwort ohne Shop-Daten');
            }
        } catch (\Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 250);
        }

        $billing = is_array($data['billingAddress'] ?? null) ? $data['billingAddress'] : [];
        $address = trim(implode(', ', array_filter([
            trim((string) ($billing['address1'] ?? '')),
            trim(((string) ($billing['zip'] ?? '')) . ' ' . ((string) ($billing['city'] ?? ''))),
        ], static fn (string $part): bool => $part !== '')));
        $clean = static fn (mixed $v, int $max): ?string => trim((string) $v) !== '' ? mb_substr(trim((string) $v), 0, $max) : null;

        try {
            $this->pdo->prepare(
                'INSERT INTO connect_shop_contacts (shop, shop_name, company, url, email, contact_email, phone, address, country, error, fetched_at)
                 VALUES (:shop, :name, :company, :url, :email, :contact, :phone, :address, :country, :error, NOW())
                 ON DUPLICATE KEY UPDATE shop_name = VALUES(shop_name), company = VALUES(company), url = VALUES(url), email = VALUES(email),
                     contact_email = VALUES(contact_email), phone = VALUES(phone), address = VALUES(address), country = VALUES(country),
                     error = VALUES(error), fetched_at = NOW()'
            )->execute([
                'shop' => $shop,
                'name' => $clean($data['name'] ?? null, 255),
                'company' => $clean($billing['company'] ?? null, 255),
                'url' => $clean($data['primaryDomain']['url'] ?? null, 255),
                'email' => $clean($data['email'] ?? null, 190),
                'contact' => $clean($data['contactEmail'] ?? null, 190),
                'phone' => $clean($billing['phone'] ?? null, 64),
                'address' => $clean($address, 255),
                'country' => $clean($billing['countryCodeV2'] ?? null, 8),
                'error' => $error,
            ]);
        } catch (\PDOException $e) {
            return 'Speichern fehlgeschlagen (Migration 0108 eingespielt?): ' . $e->getMessage();
        }

        return $error;
    }
}
