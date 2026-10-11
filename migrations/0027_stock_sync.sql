-- =============================================================================
-- connect/migrations/0027_stock_sync.sql
--
-- Grundlage fuer den automatischen Lagerbestand-Abgleich (Connect\Sync\
-- StockSyncRunner): connect_shops.comparison_key/stock_sync_direction/
-- target_connection_id (Migration 0013) waren bisher nur gespeicherte
-- Einstellungen ohne Ausfuehrung. Fehlten dafuer:
--   - eine Moeglichkeit, pro Object zu markieren, WELCHES Feld dem shop-
--     weiten Vergleichswert entspricht (zwei Systeme nennen "SKU" evtl.
--     unterschiedlich, z.B. Shopify "sku" vs. HelloCash "code") und welches
--     Feld den Lagerbestand traegt -- beides generisch ueber alle Connector
--     Types hinweg, ohne Systemnamen im Code zu verdrahten.
--   - eine periodische, atomar geclaimte Ausfuehrung pro Shop (analog zum
--     bestehenden connect_sync_jobs-Claim-Verfahren in bin/scheduler.php,
--     aber pro Shop statt pro Sync Mapping, da der Lagerbestand-Abgleich
--     kein einzelnes Object cached, sondern zwei Objects gegeneinander
--     abgleicht).
-- =============================================================================

ALTER TABLE connect_object_fields
  ADD COLUMN comparison_role VARCHAR(16) NULL
      COMMENT 'title | sku | barcode -- Vergleichswert-Rolle fuer Stock-Sync-Matching, editierbar im Connector Builder (object_edit.php)',
  ADD COLUMN is_stock_quantity TINYINT(1) NOT NULL DEFAULT 0
      COMMENT 'markiert das Feld, das den Lagerbestand traegt (genau eines pro Object, das am Lagerbestand-Abgleich teilnimmt)';

ALTER TABLE connect_shops
  ADD COLUMN stock_sync_last_run_at TIMESTAMP NULL DEFAULT NULL
      COMMENT 'fuer den atomaren Scheduler-Claim (siehe bin/scheduler.php claimDueStockSyncShop()), nicht fuer die Anzeige',
  ADD COLUMN stock_sync_interval_minutes INT UNSIGNED NOT NULL DEFAULT 60
      COMMENT 'wie oft der Lagerbestand-Abgleich pro Shop laufen soll';

-- -----------------------------------------------------------------------------
-- connect_stock_sync_runs
-- Reines Ausfuehrungsprotokoll fuer die Admin-UI (analog connect_sync_runs),
-- shop- statt mapping-gebunden. NICHT fuer den Scheduler-Claim selbst
-- verwendet (das laeuft ueber connect_shops.stock_sync_last_run_at) --
-- gleiche Trennung von Claim-Zustand und Historie wie bei
-- connect_sync_jobs/connect_sync_runs.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS connect_stock_sync_runs (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    shop             VARCHAR(190) NOT NULL,
    started_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at         TIMESTAMP NULL DEFAULT NULL,
    status           VARCHAR(16) NOT NULL DEFAULT 'running' COMMENT 'running | success | partial | error | skipped',
    records_matched  INT UNSIGNED NOT NULL DEFAULT 0,
    records_written  INT UNSIGNED NOT NULL DEFAULT 0,
    records_failed   INT UNSIGNED NOT NULL DEFAULT 0,
    message          TEXT NULL,
    PRIMARY KEY (id),
    KEY idx_stock_sync_runs_shop (shop)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Shopify Produkt: fehlende Stuecke fuer die Schreib-Richtung ergaenzen.
-- -----------------------------------------------------------------------------
SET @shopify_connector_type_id = (SELECT id FROM connect_connector_types WHERE type_key = 'shopify');
SET @shopify_product_object_id = (SELECT id FROM connect_objects WHERE connector_type_id = @shopify_connector_type_id AND object_key = 'product');

-- Vergleichswert-Rollen + Lagerbestand-Feld an den bereits bestehenden Feldern
-- markieren (siehe Migration 0004) -- keine neuen Felder noetig, die gibt es
-- schon (title/sku/barcode/inventory_quantity).
UPDATE connect_object_fields SET comparison_role = 'title' WHERE object_id = @shopify_product_object_id AND field_key = 'title';
UPDATE connect_object_fields SET comparison_role = 'sku' WHERE object_id = @shopify_product_object_id AND field_key = 'sku';
UPDATE connect_object_fields SET comparison_role = 'barcode' WHERE object_id = @shopify_product_object_id AND field_key = 'barcode';
UPDATE connect_object_fields SET is_stock_quantity = 1 WHERE object_id = @shopify_product_object_id AND field_key = 'inventory_quantity';

-- Neues Feld: inventory_item_id der ersten Variante -- Shopifys Inventory-API
-- schreibt ueber die Inventory-Item-ID + Standort-ID, nicht ueber die
-- Produkt-/Variant-ID (siehe StockSyncRunner). War bisher nicht Teil der
-- read_list-Query.
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id)
VALUES (@shopify_product_object_id, 'inventory_item_id', 'Inventory-Item-ID (erste Variante)', 'string', 'node.variants.edges.0.node.inventoryItem.id', 0, NULL);

-- read_list-Query um inventoryItem{id} ergaenzen (gleiche Query wie zuletzt in
-- Migration 0010 gesetzt, nur um das eine Feld erweitert).
UPDATE connect_object_operations
SET body_template = 'query($cursor: String) { products(first: 100, after: $cursor) { edges { node { id title handle vendor productType status variants(first: 1) { edges { node { id sku barcode price inventoryQuantity inventoryItem { id } } } } } } pageInfo { hasNextPage endCursor } } }'
WHERE object_id = @shopify_product_object_id AND operation_type = 'read_list';

-- Neue update-Operation: setzt den Lagerbestand ABSOLUT (nicht Delta) ueber
-- inventorySetQuantities -- passend zur Richtung "target_to_shopify" (das
-- fuehrende System, z.B. HelloCash, kennt den wahren Bestand, Shopify wird
-- einfach auf diesen Wert gesetzt). Das Mutations-Feld wird bewusst als
-- "result" aliasiert (siehe GenericHttpConnector::write(), gleiche Konvention
-- wie der "node"-Alias bei readOne()) -- so kann write() userErrors generisch
-- und ohne Kenntnis des konkreten Mutationsnamens auslesen.
INSERT INTO connect_object_operations (object_id, operation_type, http_method, path_template, body_template, response_records_path, static_params)
VALUES (
    @shopify_product_object_id,
    'update',
    'POST',
    'admin/api/2026-07/graphql.json',
    'mutation($inventoryItemId: ID!, $locationId: ID!, $quantity: Int!) { result: inventorySetQuantities(input: {name: "available", reason: "correction", ignoreCompareQuantity: true, quantities: [{inventoryItemId: $inventoryItemId, locationId: $locationId, quantity: $quantity}]}) { userErrors { field message } } }',
    NULL,
    NULL
);
