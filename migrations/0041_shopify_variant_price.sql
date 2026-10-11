-- =============================================================================
-- connect/migrations/0041_shopify_variant_price.sql
--
-- Behebt: beim Import eines fehlenden Produkts nach Shopify
-- (Connect\Sync\ProductImportService::importIntoShopify()) wurde bisher KEIN
-- Preis gesetzt -- die Shopify-Operation "create_variant" (Migration 0031/
-- 0033/0039) kannte dafuer weder eine Variable noch ein Feld im Mutations-
-- Body.
--
-- 1. Neue erlaubte comparison_role "price" (Whitelist-Aenderung ist reiner
--    Anwendungscode, siehe admin/object_edit.php + FieldDefinition.php) --
--    markiert, welches Feld eines Objects den Preis traegt. Anders als
--    title/sku/barcode NICHT als shop-weiter Vergleichswert waehlbar (siehe
--    _sync_mappings_body.php -- dortige Dropdowns bleiben eigene, separate
--    Whitelists mit nur title/sku/barcode).
-- 2. Das Shopify-Produkt-Objekt hat bereits ein Feld "price" (node.price,
--    siehe Migration 0030) -- bekommt hier die neue Rolle zugewiesen, damit
--    ProductImportService es ueber fieldWithComparisonRole($shopifyObject,
--    'price') findet. Fuer das jeweilige Zielsystem (z.B. HelloCash) muss der
--    Admin sein Preis-Feld im Connector Builder noch selbst mit der neuen
--    Rolle "Preis" markieren (object_edit.php) -- analog zu SKU/Barcode,
--    keine Migration moeglich, da das Feld pro Connection/Object individuell
--    im Connector Builder angelegt wird.
-- 3. "create_variant"-Operation um die optionale Variable $price ($price:
--    Money, Shopifys nativer Skalar fuer Geldbetraege ohne Waehrung) sowie
--    das Feld "price" im variants-Input ergaenzt -- rein additiv (UPDATE),
--    bestehende Aufrufe ohne $price bleiben unveraendert gueltig (fehlende
--    optionale GraphQL-Variable = null).
-- =============================================================================

SET @shopify_connector_type_id = (SELECT id FROM connect_connector_types WHERE type_key = 'shopify');
SET @shopify_product_object_id = (SELECT id FROM connect_objects WHERE connector_type_id = @shopify_connector_type_id AND object_key = 'product');

UPDATE connect_object_fields
SET comparison_role = 'price'
WHERE object_id = @shopify_product_object_id AND field_key = 'price';

UPDATE connect_object_operations
SET body_template = 'mutation($productId: ID!, $sku: String, $barcode: String, $price: Money, $optionName: String!, $optionValue: String!, $strategy: ProductVariantsBulkCreateStrategy) { result: productVariantsBulkCreate(productId: $productId, strategy: $strategy, variants: [{barcode: $barcode, price: $price, inventoryItem: {sku: $sku}, optionValues: [{optionName: $optionName, name: $optionValue}]}]) { productVariants { id title inventoryItem { id } } userErrors { field message } } }'
WHERE object_id = @shopify_product_object_id AND operation_type = 'create_variant';
