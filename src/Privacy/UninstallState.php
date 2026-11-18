<?php

declare(strict_types=1);

namespace Connect\Privacy;

use PDO;

/**
 * Ist die App in einem Shop deinstalliert? Connect stoppt bei app/uninstalled nur die Automatisierung
 * (PrivacyService::handleUninstall), der Shop bleibt bis shop/redact (48 Std. spaeter) in der Liste.
 * Bis dahin schlagen die Verbindungspruefungen zwangslaeufig fehl (Token ungueltig) -- das ist kein
 * Fehler, den Admin oder Merchant per Mail melden muessen.
 *
 * Kein eigenes Merkmal in der DB: "deinstalliert" = es gibt einen verarbeiteten app/uninstalled-Eintrag
 * (connect_privacy_requests) UND die Shopify-Connection war seither nicht wieder in Ordnung
 * (health_status 'ok' mit health_since nach der Deinstallation = Neuinstallation, Token erneuert).
 * Die Pruefungen laufen weiter, damit eine Neuinstallation von selbst erkannt wird.
 */
final class UninstallState
{
    /**
     * Zeitpunkt der letzten Deinstallation je Shop.
     *
     * @return array<string, string> shop => received_at
     */
    public static function all(PDO $pdo): array
    {
        try {
            $rows = $pdo->query(
                "SELECT shop, MAX(received_at) AS at FROM connect_privacy_requests
                 WHERE topic = 'app/uninstalled' AND status = 'processed' GROUP BY shop"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return []; // Migration 0051 fehlt
        }

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['shop']] = (string) $row['at'];
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|null $shopifyHealth Zeile der Shopify-Connection (health_status, health_since) oder null
     */
    public static function isUninstalled(?string $uninstalledAt, ?array $shopifyHealth): bool
    {
        if ($uninstalledAt === null) {
            return false;
        }
        if ($shopifyHealth !== null && ($shopifyHealth['health_status'] ?? null) === 'ok' && ($shopifyHealth['health_since'] ?? null) !== null
            && strtotime((string) $shopifyHealth['health_since']) > strtotime($uninstalledAt)) {
            return false; // Neuinstallation: Shopify-Zugang laeuft wieder
        }

        return true;
    }

    /** Einzelabfrage fuer einen Shop (Heartbeat). */
    public static function forShop(PDO $pdo, string $shop): bool
    {
        try {
            $stmt = $pdo->prepare("SELECT MAX(received_at) FROM connect_privacy_requests WHERE shop = :shop AND topic = 'app/uninstalled' AND status = 'processed'");
            $stmt->execute(['shop' => $shop]);
            $at = $stmt->fetchColumn();
            if ($at === false || $at === null) {
                return false;
            }

            $health = $pdo->prepare(
                "SELECT c.health_status, c.health_since FROM connect_connections c
                 JOIN connect_connector_types t ON t.id = c.connector_type_id
                 WHERE c.shop = :shop AND t.type_key = 'shopify' LIMIT 1"
            );
            $health->execute(['shop' => $shop]);
            $row = $health->fetch(PDO::FETCH_ASSOC);

            return self::isUninstalled((string) $at, $row !== false ? $row : null);
        } catch (\PDOException) {
            return false;
        }
    }
}
