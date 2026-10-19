<?php

declare(strict_types=1);

namespace Connect\Setup;

use Connect\Privacy\PrivacyService;
use PDO;
use PDOException;

/**
 * Entfernt einen Shop komplett aus Connect (Admin -> Shops -> Entfernen und Umstieg -> Aufraeumen
 * fuer Shops ohne Abo). Loescht Aktivierungen, Connections (kaskadiert auf Mappings, Jobs, Laeufe),
 * Webhook-Events und alle Tabellen aus PrivacyService::SHOP_DATA_TABLES, zuletzt den Shop-Eintrag.
 * Die alte Welt bleibt unberuehrt.
 */
final class ShopRemover
{
    public static function remove(PDO $pdo, string $shop): void
    {
        // connect_lookup_tables bewusst NICHT geloescht: seit Migration 0020 global und von anderen
        // Shops mitgenutzt. Aktivierungen zuerst ausdruecklich (wie PrivacyService::eraseShop).
        $pdo->prepare('DELETE FROM connect_sync_mappings WHERE shop = :shop')->execute(['shop' => $shop]);
        $pdo->prepare('DELETE FROM connect_connections WHERE shop = :shop')->execute(['shop' => $shop]);
        $pdo->prepare('DELETE FROM connect_webhook_events WHERE shop = :shop')->execute(['shop' => $shop]);
        // Zustimmungen bleiben als Nachweis (3 Jahre, Migration 0077), nur markiert
        LegalTexts::markShopDeleted($pdo, $shop);
        foreach (PrivacyService::SHOP_DATA_TABLES as $table) {
            try {
                $pdo->prepare("DELETE FROM {$table} WHERE shop = :shop")->execute(['shop' => $shop]);
            } catch (PDOException) {
                // Tabelle fehlt (Migration nicht eingespielt)
            }
        }
        $pdo->prepare('DELETE FROM connect_shops WHERE shop = :shop')->execute(['shop' => $shop]);
    }
}
