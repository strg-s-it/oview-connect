<?php

declare(strict_types=1);

namespace Connect\Legacy;

use Connect\Config;
use Connect\Database;

/**
 * Liest EINMALIG Daten aus der alten oView-Datenbank fuer die
 * Migrationsbruecke (siehe Plan, Abschnitt "Datenbank" -- LEGACY_DB_* in
 * .env, optionale Faehigkeit, nur aktiv wenn konfiguriert).
 *
 * Bewusst nur fuer genau diesen Zweck -- kein allgemeiner Zugriff auf die
 * alte DB, keine Abhaengigkeit von altem PHP-Code (inc/functions.php etc.),
 * nur gezielte SQL-Abfragen auf oview_stores. Einzige Ausnahme, die schreibt:
 * updateHelloCashToken() (neuer helloCash-Token, siehe dort).
 */
final class LegacyBridge
{
    public static function isConfigured(Config $config): bool
    {
        return $config->has('LEGACY_DB_HOST') && $config->has('LEGACY_DB_NAME') && $config->has('LEGACY_DB_USER');
    }

    /**
     * @return array{store_url: string, access_token: string, scopes: string}|null
     */
    public static function findShopifyAccessToken(Config $config, string $shop): ?array
    {
        if (!self::isConfigured($config)) {
            throw new \RuntimeException('Migrationsbruecke nicht konfiguriert: LEGACY_DB_* fehlt in connect/.env.');
        }

        $storeUrl = str_contains($shop, '.') ? $shop : $shop . '.myshopify.com';

        $db = Database::connectTo(
            $config->require('LEGACY_DB_HOST'),
            $config->optional('LEGACY_DB_PORT', '3306'),
            $config->require('LEGACY_DB_NAME'),
            $config->require('LEGACY_DB_USER'),
            $config->optional('LEGACY_DB_PASS')
        );

        $stmt = $db->pdo()->prepare(
            'SELECT store_url, access_token, scopes FROM oview_stores WHERE store_url = :store_url ORDER BY install_date DESC LIMIT 1'
        );
        $stmt->execute(['store_url' => $storeUrl]);
        $row = $stmt->fetch();

        if ($row === false || empty($row['access_token'])) {
            return null;
        }

        return [
            'store_url' => (string) $row['store_url'],
            'access_token' => (string) $row['access_token'],
            'scopes' => (string) ($row['scopes'] ?? ''),
        ];
    }

    /**
     * Letzte Spuren je Shop in der alten Welt (nur lesend) -- zweite Sicherheit vor dem Entfernen
     * (admin/cleanup.php): kommen noch Bestellungen, Rechnungen, Lagerabgleiche, erfolgreiche
     * Shopify-Aufrufe oder Seitenaufrufe des Merchants, lebt der Shop.
     * Fehlende Tabellen/Spalten werden uebersprungen.
     *
     * @param list<string> $shops Shop-Namen ohne .myshopify.com
     * @return array<string, array<string, string>> shop => [Quelle => letzter Zeitpunkt (Y-m-d H:i:s)]
     */
    public static function lastActivity(Config $config, array $shops): array
    {
        if ($shops === [] || !self::isConfigured($config)) {
            return [];
        }
        $pdo = Database::connectTo(
            $config->require('LEGACY_DB_HOST'),
            $config->optional('LEGACY_DB_PORT', '3306'),
            $config->require('LEGACY_DB_NAME'),
            $config->require('LEGACY_DB_USER'),
            $config->optional('LEGACY_DB_PASS')
        )->pdo();

        // Beide Schreibweisen (mit/ohne .myshopify.com), wie sie in der alten Welt vorkommen
        $keys = [];
        foreach ($shops as $shop) {
            $keys[] = $shop;
            $keys[] = $shop . '.myshopify.com';
        }
        $placeholders = implode(',', array_fill(0, count($keys), '?'));

        $sources = [
            'Bestellung von Shopify' => "SELECT shop, MAX(created_at) AS at FROM hellocash_webhook_order_paid WHERE shop IN ({$placeholders}) GROUP BY shop",
            'Rechnung erstellt' => "SELECT shop, MAX(created_at) AS at FROM hellofy_sync_log_invoice WHERE shop IN ({$placeholders}) GROUP BY shop",
            'Lagerabgleich' => "SELECT shop, MAX(created_at) AS at FROM hellofy_sync_log_stock WHERE shop IN ({$placeholders}) GROUP BY shop",
            'Shopify-Aufruf erfolgreich' => "SELECT shop, MAX(shopify_heartbeat) AS at FROM hellocash_binding WHERE shop IN ({$placeholders}) AND shopify_heartbeat > '2001-01-01' GROUP BY shop",
            'App geöffnet' => "SELECT shop, MAX(time_written) AS at FROM oview_statistics WHERE shop IN ({$placeholders}) GROUP BY shop",
        ];

        $out = [];
        foreach ($sources as $label => $sql) {
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($keys);
                foreach ($stmt->fetchAll() as $row) {
                    $shop = (string) $row['shop'];
                    $shop = str_contains($shop, '.') ? substr($shop, 0, (int) strpos($shop, '.')) : $shop;
                    $at = (string) ($row['at'] ?? '');
                    if ($at !== '' && $at > ($out[$shop][$label] ?? '')) {
                        $out[$shop][$label] = $at;
                    }
                }
            } catch (\Throwable) {
                // Tabelle/Spalte fehlt -- diese Quelle auslassen
            }
        }

        return $out;
    }

    /**
     * EINZIGE schreibende Stelle in die alte Datenbank: neuer helloCash-Token eines Shops
     * (hellocash_binding.token, die alte Welt nimmt ihn als "Bearer" vor der Basic-Anmeldung).
     * Noetig, weil helloCash beim "Neu generieren" den bisherigen Token ungueltig macht --
     * ohne diesen Schritt stuende die alte Welt still, bis der Shop umgestellt ist.
     * Braucht fuer LEGACY_DB_USER das Recht UPDATE auf hellocash_binding.
     *
     * @return int Anzahl geaenderter Zeilen (0 = Shop in der alten Welt nicht gefunden)
     * @throws \Throwable z.B. fehlendes UPDATE-Recht
     */
    public static function updateHelloCashToken(Config $config, string $shop, string $token): int
    {
        if (!self::isConfigured($config)) {
            throw new \RuntimeException('LEGACY_DB_* fehlt in connect/.env.');
        }
        $bare = str_contains($shop, '.') ? substr($shop, 0, (int) strpos($shop, '.')) : $shop;

        $db = Database::connectTo(
            $config->require('LEGACY_DB_HOST'),
            $config->optional('LEGACY_DB_PORT', '3306'),
            $config->require('LEGACY_DB_NAME'),
            $config->require('LEGACY_DB_USER'),
            $config->optional('LEGACY_DB_PASS')
        );
        $stmt = $db->pdo()->prepare('UPDATE hellocash_binding SET token = :token, updated_at = NOW() WHERE shop IN (:a, :b)');
        $stmt->execute(['token' => $token, 'a' => $bare, 'b' => $bare . '.myshopify.com']);

        return $stmt->rowCount();
    }
}
