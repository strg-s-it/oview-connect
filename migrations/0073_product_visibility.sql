-- =============================================================================
-- connect/migrations/0073_product_visibility.sql
--
-- Ausverkaufte Produkte in Shopify automatisch auf "Entwurf" setzen und bei
-- Bestand wieder aktivieren (Connect\Sync\ProductVisibilityRunner, Job-Art
-- mapping_kind = 'product_visibility'). Allgemeine Fassung des Sonder-Jobs
-- jobs/cron_shopify_products_hide.php der alten Welt (dort fest fuer
-- danysmusicshop: nur Produkte mit einer Variante, Aktivieren nur mit
-- Beschreibung) - hier sind das Admin-Einstellungen je Shop.
--
--   connect_shops.visibility_*            Einstellungen (Admin-only, Jobs/Einstellungen)
--   connect_product_visibility_log        welches Produkt wann aus- bzw. eingeblendet wurde
--                                         (auch fuer "nur selbst ausgeblendete wieder aktivieren")
--   Shopify-Produkt: Feld product_description_start (die ersten Zeichen der Beschreibung)
--   Shopify-Produkt: Operation set_status (productUpdate, Status ACTIVE/DRAFT)
--
-- Die Lese-Abfragen werden nur ergaenzt, wenn sie noch dem Stand von 0061
-- entsprechen (REPLACE auf den Produkt-Teil). Wurde die Abfrage im Connector
-- Builder von Hand geaendert, bitte dort "description(truncateAt: 20)" im
-- Teil product { ... } ergaenzen - der Job meldet das sonst.
--
-- Nebenbei: connect_sync_mappings.mapping_kind war VARCHAR(16) (Migration 0028) -
-- zu kurz fuer 'product_visibility' (18) und das schon bestehende
-- 'auto_import_products' (20, Migration 0059). Je nach SQL-Modus wurde das beim
-- Speichern abgelehnt oder zu 'auto_import_prod' abgeschnitten (dann lief der
-- automatische Produktimport nie). Spalte verbreitert, abgeschnittene Zeilen repariert.
--
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

ALTER TABLE connect_sync_mappings
    MODIFY COLUMN mapping_kind VARCHAR(32) NOT NULL DEFAULT 'default';

UPDATE connect_sync_mappings SET mapping_kind = 'auto_import_products' WHERE mapping_kind = 'auto_import_prod';

ALTER TABLE connect_shops
    ADD COLUMN visibility_sync_enabled TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Ausverkaufte Produkte ausblenden / wieder einblenden (Admin-only)',
    ADD COLUMN visibility_single_variant_only TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Nur Produkte mit genau einer Variante ausblenden',
    ADD COLUMN visibility_require_description TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Nur aktivieren, wenn eine Beschreibung vorhanden ist',
    ADD COLUMN visibility_only_matched TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Nur Produkte, die im Zielsystem zugeordnet sind (Vergleichswert)',
    ADD COLUMN visibility_reactivate_mode VARCHAR(16) NOT NULL DEFAULT 'own' COMMENT 'own = nur von Connect ausgeblendete, all = alle Entwuerfe, never = nie';

CREATE TABLE IF NOT EXISTS connect_product_visibility_log (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    shop        VARCHAR(190) NOT NULL,
    run_id      BIGINT UNSIGNED NULL COMMENT 'verweist auf connect_sync_runs.id (kein FK, wie sonst im Schema)',
    product_id  VARCHAR(190) NOT NULL COMMENT 'Shopify-Produkt-GID',
    label       VARCHAR(255) NULL,
    action      VARCHAR(8) NOT NULL COMMENT 'hide | show',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_visibility_log_shop_product (shop, product_id, id),
    KEY idx_visibility_log_shop_created (shop, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @shopify_connector_type_id = (SELECT id FROM connect_connector_types WHERE type_key = 'shopify');

SET @shopify_product_object_id = (SELECT id FROM connect_objects WHERE connector_type_id = @shopify_connector_type_id AND object_key = 'product');

INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id)
SELECT @shopify_product_object_id, 'product_description_start', 'Beschreibung (Anfang)', 'string', 'node.product.description', 0, NULL
FROM DUAL
WHERE @shopify_product_object_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM connect_object_fields f WHERE f.object_id = @shopify_product_object_id AND f.field_key = 'product_description_start');

UPDATE connect_object_operations
SET body_template = REPLACE(body_template, 'product { id title handle vendor productType status }', 'product { id title handle vendor productType status description(truncateAt: 20) }')
WHERE object_id = @shopify_product_object_id AND operation_type IN ('read_list', 'read_one')
  AND body_template NOT LIKE '%description(%';

INSERT INTO connect_object_operations (object_id, operation_type, http_method, path_template, body_template, response_records_path, static_params)
SELECT @shopify_product_object_id, 'set_status', 'POST', 'admin/api/2026-07/graphql.json',
       'mutation($productId: ID!, $status: ProductStatus!) { result: productUpdate(product: {id: $productId, status: $status}) { product { id status } userErrors { field message } } }',
       NULL, NULL
FROM DUAL
WHERE @shopify_product_object_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM connect_object_operations op WHERE op.object_id = @shopify_product_object_id AND op.operation_type = 'set_status');
