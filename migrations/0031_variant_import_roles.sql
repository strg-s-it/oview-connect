-- =============================================================================
-- connect/migrations/0031_variant_import_roles.sql
--
-- Grundlage fuer "Import als Variante eines bestehenden Shopify-Produkts"
-- (Connect\Sync\ProductImportService) statt immer ein eigenstaendiges neues
-- Produkt anzulegen -- Gegenstueck zum Altsystem-Verhalten
-- (manual.php hc_mapping_variant/mappings_variant), hier aber generisch ueber
-- Feld-Rollen statt hartkodierter Feldnamen.
--
-- comparison_role bekommt den neuen erlaubten Wert 'variant_group' (Whitelist
-- in admin/object_edit.php, Spalte selbst ist bereits ein freies VARCHAR(16)
-- seit Migration 0027, keine Schemaaenderung noetig). is_variant_option ist
-- neu, analog zu is_stock_quantity.
--
-- Beide Rollen sind OPTIONAL und nur auf einem varianten-losen Quellsystem
-- sinnvoll (z. B. HelloCash "Artikel") -- ohne sie bleibt der Import
-- unveraendert (immer ein neues, eigenstaendiges Shopify-Produkt).
-- =============================================================================

ALTER TABLE connect_object_fields
  ADD COLUMN is_variant_option TINYINT(1) NOT NULL DEFAULT 0
      COMMENT 'markiert das Feld, dessen Wert beim Import als neue Shopify-Variantenoption dient (z.B. Groesse/Farbe) -- nur zusammen mit einem comparison_role=variant_group-Feld desselben Objects wirksam';

ALTER TABLE connect_shops
  ADD COLUMN variant_option_name VARCHAR(100) NOT NULL DEFAULT 'Option'
      COMMENT 'Name der Shopify-Produktoption, unter der eine neu importierte Variante angelegt wird (z.B. "Größe") -- editierbar unter Einstellungen, Default wie im Altsystem hartkodiert';

-- -----------------------------------------------------------------------------
-- Neue Shopify-Operation "create_variant": haengt eine neue Variante an ein
-- BEREITS BESTEHENDES Produkt (produktId aus der von ProductImportService
-- ueber ProductComparisonService abgeleiteten Gruppierungs-Zuordnung, siehe
-- Klassenkommentar dort). $strategy bleibt bei diesem Anwendungsfall leer
-- (kein "Standalone-Variante entfernen" noetig, das Produkt hat schon eine
-- echte erste Variante) -- dieselbe Operation koennte spaeter auch fuer den
-- "neues Produkt + erste Variante"-Fall wiederverwendet werden (dann mit
-- strategy=REMOVE_STANDALONE_VARIANT), das ist aber bewusst NICHT Teil
-- dieser Aenderung: ohne bekannte Gruppierung legt ProductImportService ein
-- neues Produkt weiterhin ueber die normale, admin-konfigurierte Mapping-
-- Vorlage an (unveraendertes Verhalten aus dem vorigen Baustein).
--
-- "result:"-Alias-Konvention (siehe Migration 0027) noetig, damit
-- GenericHttpConnector::write() userErrors generisch auslesen kann.
-- -----------------------------------------------------------------------------
SET @shopify_connector_type_id2 = (SELECT id FROM connect_connector_types WHERE type_key = 'shopify');
SET @shopify_product_object_id2 = (SELECT id FROM connect_objects WHERE connector_type_id = @shopify_connector_type_id2 AND object_key = 'product');

INSERT INTO connect_object_operations (object_id, operation_type, http_method, path_template, body_template, response_records_path, static_params)
VALUES (
    @shopify_product_object_id2,
    'create_variant',
    'POST',
    'admin/api/2026-07/graphql.json',
    'mutation($productId: ID!, $sku: String, $barcode: String, $optionName: String!, $optionValue: String!, $strategy: ProductVariantsBulkCreateStrategy) { result: productVariantsBulkCreate(productId: $productId, strategy: $strategy, variants: [{barcode: $barcode, inventoryItem: {sku: $sku}, optionValues: [{optionName: $optionName, name: $optionValue}]}]) { productVariants { id title inventoryItem { id } } userErrors { field message } } }',
    NULL,
    NULL
);
