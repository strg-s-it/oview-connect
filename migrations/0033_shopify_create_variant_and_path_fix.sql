-- =============================================================================
-- connect/migrations/0033_shopify_create_variant_and_path_fix.sql
--
-- Zwei Nachtraege, weil die vorherigen Dateien bereits VOR den jeweiligen
-- Korrekturen eingespielt wurden (per Dateiname getrackt, siehe
-- connect_schema_migrations -- eine spaeter geaenderte Datei wird nach
-- erfolgreichem Import nicht erneut angewandt):
--
-- 1. Migration 0031 bekam die "create_variant"-Operation (Shopify:
--    productVariantsBulkCreate gegen ein bestehendes Produkt) erst
--    NACHTRAEGLICH hinzugefuegt -- der bereits eingespielte Stand von 0031
--    kennt sie noch nicht. INSERT ... WHERE NOT EXISTS, damit das gefahrlos
--    ist, egal ob die Operation inzwischen doch schon (teilweise) existiert.
--
-- 2. Migration 0032 (response_records_path-Fix) war zum Zeitpunkt des
--    Imports noch ohne den path_template-Fix (admin/api/2024-10 ->
--    admin/api/2026-07 bei "Lesen (Liste)", Inkonsistenz zu den anderen
--    beiden Operationen, vom Nutzer bestaetigt als Versehen). Reines UPDATE,
--    daher unabhaengig vom genauen Ausgangszustand gefahrlos.
-- =============================================================================

SET @shopify_connector_type_id = (SELECT id FROM connect_connector_types WHERE type_key = 'shopify');
SET @shopify_product_object_id = (SELECT id FROM connect_objects WHERE connector_type_id = @shopify_connector_type_id AND object_key = 'product');

UPDATE connect_object_operations
SET path_template = 'admin/api/2026-07/graphql.json'
WHERE object_id = @shopify_product_object_id AND operation_type = 'read_list';

INSERT INTO connect_object_operations (object_id, operation_type, http_method, path_template, body_template, response_records_path, static_params)
SELECT
    @shopify_product_object_id,
    'create_variant',
    'POST',
    'admin/api/2026-07/graphql.json',
    'mutation($productId: ID!, $sku: String, $barcode: String, $optionName: String!, $optionValue: String!, $strategy: ProductVariantsBulkCreateStrategy) { result: productVariantsBulkCreate(productId: $productId, strategy: $strategy, variants: [{barcode: $barcode, inventoryItem: {sku: $sku}, optionValues: [{optionName: $optionName, name: $optionValue}]}]) { productVariants { id title inventoryItem { id } } userErrors { field message } } }',
    NULL,
    NULL
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM connect_object_operations
    WHERE object_id = @shopify_product_object_id AND operation_type = 'create_variant'
);
