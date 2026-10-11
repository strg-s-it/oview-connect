<?php

declare(strict_types=1);

namespace Connect\Legacy;

use Connect\Config;
use Connect\Connector\ConnectorFactory;
use Connect\Http\HttpClient;
use Connect\Security\Crypto;
use Connect\Shopify\TokenRefresher;
use PDO;

/**
 * Legt einen Shop in Connect an bzw. frischt ihn auf -- mit dem Shopify-Access-Token
 * der alten Welt (oview_stores): Shopify-Connection (Upsert), connect_shops-Eintrag,
 * Vorbelegung Netto/Brutto + Standard-Steuersatz aus Shopify, Cache-Jobs aller
 * Job-Vorlagen mit auto_activate. Idempotent.
 *
 * Genutzt von admin/shops.php ("Token übernehmen") und public/internal/provision_shop.php
 * (Neuinstallation direkt in Connect, Schalter new_installs_to_connect).
 */
final class ShopProvisioner
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config
    ) {
    }

    /**
     * @return array{shop: string, connection_id: int, message: string}
     * @throws \RuntimeException wenn kein Token gefunden oder Shopify-Typ fehlt
     */
    public function provisionFromLegacy(string $shopInput): array
    {
        $legacyData = LegacyBridge::findShopifyAccessToken($this->config, $shopInput);
        if ($legacyData === null) {
            throw new \RuntimeException("Kein Shopify-Access-Token in der alten Welt für \"{$shopInput}\" gefunden (App dort installiert?).");
        }
        $shop = substr($legacyData['store_url'], 0, strpos($legacyData['store_url'], '.') ?: strlen($legacyData['store_url']));

        $shopifyTypeId = $this->pdo->query("SELECT id FROM connect_connector_types WHERE type_key = 'shopify'")->fetchColumn();
        if ($shopifyTypeId === false) {
            throw new \RuntimeException('Connector Type "shopify" fehlt in Connect (Migrationen ausgeführt?).');
        }

        $crypto = new Crypto($this->config->require('CONNECT_ENCRYPTION_KEY'));
        $credentials = $crypto->encryptArray([
            'header_name' => 'X-Shopify-Access-Token',
            'header_value' => $legacyData['access_token'],
            'base_url' => 'https://' . $legacyData['store_url'],
        ]);

        // Vorhandene Shopify-Connection aktualisieren statt eine zweite anzulegen
        $existingStmt = $this->pdo->prepare('SELECT id FROM connect_connections WHERE shop = :shop AND connector_type_id = :type_id');
        $existingStmt->execute(['shop' => $shop, 'type_id' => $shopifyTypeId]);
        $existingId = $existingStmt->fetchColumn();

        if ($existingId !== false) {
            $this->pdo->prepare('UPDATE connect_connections SET credentials = :credentials, status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
                ->execute(['credentials' => $credentials, 'status' => 'pending', 'id' => $existingId]);
            $connectionId = (int) $existingId;
        } else {
            $this->pdo->prepare(
                'INSERT INTO connect_connections (shop, connector_type_id, label, credentials, config, status, created_at)
                 VALUES (:shop, :type_id, :label, :credentials, :config, :status, CURRENT_TIMESTAMP)'
            )->execute([
                'shop' => $shop,
                'type_id' => $shopifyTypeId,
                'label' => 'Shopify (aus alter Welt übernommen)',
                'credentials' => $credentials,
                'config' => json_encode([]),
                'status' => 'pending',
            ]);
            // Direkt nach dem INSERT erfassen -- der connect_shops-Upsert danach setzt lastInsertId() zurueck
            $connectionId = (int) $this->pdo->lastInsertId();
        }

        $this->pdo->prepare(
            'INSERT INTO connect_shops (shop, first_seen_at, last_seen_at) VALUES (:shop, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE last_seen_at = CURRENT_TIMESTAMP'
        )->execute(['shop' => $shop]);

        $factory = new ConnectorFactory($this->pdo, $crypto, new HttpClient(), TokenRefresher::fromConfig($this->config));

        // Verbindung testen, damit der Shop gleich als bereit gilt
        try {
            $result = $factory->forConnection($connectionId)->testConnection();
            $this->pdo->prepare('UPDATE connect_connections SET status = :status, last_test_at = CURRENT_TIMESTAMP, last_test_result = :result WHERE id = :id')
                ->execute(['status' => $result->success ? 'active' : 'error', 'result' => $result->message, 'id' => $connectionId]);
        } catch (\Throwable) {
            // bleibt "pending", Test unter Connections moeglich
        }

        // Netto/Brutto und Standard-Steuersatz aus Shopify vorbelegen (nur wenn noch leer).
        // Best-effort: darf das Anlegen nicht scheitern lassen.
        try {
            $shopRows = iterator_to_array($factory->forConnection($connectionId)->read('shop'));
            $taxesIncluded = $shopRows[0]['taxes_included'] ?? null;
            $shopCountry = $shopRows[0]['country'] ?? null;

            if ($taxesIncluded !== null) {
                $this->pdo->prepare('UPDATE connect_shops SET shopify_prices_include_tax = :mode WHERE shop = :shop AND shopify_prices_include_tax IS NULL')
                    ->execute(['mode' => ((bool) $taxesIncluded) ? 1 : 0, 'shop' => $shop]);
            }
            if ($shopCountry !== null && $shopCountry !== '') {
                $rateStmt = $this->pdo->prepare('SELECT standard_rate FROM connect_country_tax_rates WHERE country_code = :code');
                $rateStmt->execute(['code' => (string) $shopCountry]);
                $rate = $rateStmt->fetchColumn();
                if ($rate !== false) {
                    $this->pdo->prepare('UPDATE connect_shops SET default_tax_rate = :rate WHERE shop = :shop AND default_tax_rate IS NULL')
                        ->execute(['rate' => $rate, 'shop' => $shop]);
                }
            }
        } catch (\Throwable) {
        }

        // Systemseitige Cache-Jobs fuer jede Job-Vorlage mit auto_activate (admin/sync_job_templates.php)
        $templates = $this->pdo->prepare(
            'SELECT id, object_id, name, default_interval_minutes FROM connect_sync_job_templates WHERE connector_type_id = :type_id AND auto_activate = 1'
        );
        $templates->execute(['type_id' => $shopifyTypeId]);
        foreach ($templates->fetchAll() as $template) {
            $existing = $this->pdo->prepare('SELECT id FROM connect_sync_mappings WHERE source_connection_id = :cid AND source_object_id = :oid');
            $existing->execute(['cid' => $connectionId, 'oid' => $template['object_id']]);
            if ($existing->fetchColumn() === false) {
                $this->pdo->prepare(
                    "INSERT INTO connect_sync_mappings
                        (shop, name, source_connection_id, source_object_id, sync_job_template_id, trigger_type, schedule_interval_minutes, enabled, managed_by)
                     VALUES (:shop, :name, :source_connection_id, :source_object_id, :sync_job_template_id, 'schedule', :interval, 1, 'system')"
                )->execute([
                    'shop' => $shop,
                    'name' => $template['name'],
                    'source_connection_id' => $connectionId,
                    'source_object_id' => $template['object_id'],
                    'sync_job_template_id' => $template['id'],
                    'interval' => $template['default_interval_minutes'],
                ]);
            }
        }

        return [
            'shop' => $shop,
            'connection_id' => $connectionId,
            'message' => "Token für \"{$shop}\" übernommen und getestet. Vorhandene Scopes: " . ($legacyData['scopes'] ?: '(keine hinterlegt)') . '.',
        ];
    }
}
