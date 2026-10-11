-- =============================================================================
-- connect/migrations/0029_stock_sync_log.sql
--
-- Bislang protokolliert connect_sync_runs (Migration 0015/0028) nur Aggregat-
-- Zahlen pro Lauf (records_matched/written/failed + eine gekuerzte
-- Fehlermeldung) -- fuer die neue Merchant-Ansicht "Lagerbestaende" (Uebersicht,
-- WELCHE Produkte konkret angepasst wurden) fehlt ein Zeilen-Log. Dieses Log
-- wird bewusst NICHT von Connect\Sync\StockSyncRunner selbst geschrieben
-- (die Klasse kennt laut ihrem Klassenkommentar bewusst keine eigene
-- Protokollierung), sondern von Connect\Sync\SyncRunner direkt nach dem
-- Aufruf, analog zu connect_sync_runs.
-- =============================================================================

CREATE TABLE IF NOT EXISTS connect_stock_sync_log (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    shop           VARCHAR(190) NOT NULL,
    run_id         BIGINT UNSIGNED NULL COMMENT 'kein FK-Constraint, gleiche Konvention wie sonst im Schema -- verweist auf connect_sync_runs.id',
    match_value    VARCHAR(255) NOT NULL COMMENT 'Wert des shop-weiten Vergleichswerts (comparison_key), der Quelle und Ziel verbunden hat',
    label          VARCHAR(255) NULL COMMENT 'Anzeige-Titel, aus dem Feld mit comparison_role=title, falls im Connector Builder gesetzt',
    direction      VARCHAR(20) NOT NULL COMMENT 'shopify_to_target | target_to_shopify, siehe connect_shops.stock_sync_direction',
    old_quantity   INT NULL COMMENT 'NULL, wenn der bisherige Zielbestand nicht bekannt war (z.B. Shopify als Ziel, absolute Setzung)',
    new_quantity   INT NOT NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_stock_sync_log_shop_created (shop, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
