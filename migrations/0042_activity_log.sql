-- =============================================================================
-- connect/migrations/0042_activity_log.sql
--
-- Deckt die eine Schreib-Aktion ab, die bisher NIRGENDS dauerhaft protokolliert
-- wurde: der manuelle Produkt-Import nach Shopify (Connect\Sync\
-- ProductImportService::importIntoShopify(), Operationen "create_product"/
-- "create_variant") -- das Ergebnis wurde bisher nur als HTTP-Antwort an den
-- Browser geschickt und war danach unwiderruflich weg. Andere Schreibvorgaenge
-- haben bereits eigene, dediziert modellierte Logs (Lagerbestand-Anpassungen:
-- connect_stock_sync_log, Migration 0029; Uebersetzungen/Mappings:
-- connect_translation_log, Migration 0019) -- die neue Admin-Seite
-- admin/activity_log.php fasst ALLE DREI in einer gemeinsamen, chronologisch
-- sortierten Ansicht zusammen (per UNION ALL, keine weitere Migration fuer die
-- beiden bestehenden Tabellen noetig).
-- =============================================================================

CREATE TABLE IF NOT EXISTS connect_activity_log (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    shop           VARCHAR(190) NOT NULL,
    connector_type VARCHAR(64) NULL COMMENT 'z.B. "shopify" -- rein informativ, keine FK (Objekt kann geloescht sein)',
    object_key     VARCHAR(190) NULL,
    operation      VARCHAR(64) NOT NULL COMMENT 'z.B. create_product, create_variant',
    status         VARCHAR(16) NOT NULL COMMENT 'success | error',
    message        TEXT NULL,
    external_id    VARCHAR(190) NULL COMMENT 'z.B. die neu angelegte Shopify-Varianten-ID, falls erfolgreich',
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_activity_log_shop_created (shop, created_at),
    KEY idx_activity_log_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
