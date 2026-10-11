-- =============================================================================
-- connect/migrations/0036_fix_inventory_set_quantities.sql
--
-- Fix: die "update"-Operation des Shopify-Produkt-Objekts (Migration 0027,
-- StockSyncRunner-Schreibrichtung target_to_shopify) schickte
-- "ignoreCompareQuantity: true" im inventorySetQuantities-Input mit --
-- laut echtem Live-Fehler des Nutzers akzeptiert InventorySetQuantitiesInput
-- dieses Argument (bei der verwendeten API-Version) nicht:
--   GraphQL-Fehler: InputObject 'InventorySetQuantitiesInput' doesn't accept
--   argument 'ignoreCompareQuantity'
--
-- Entfernt, da ohnehin kein "compareQuantity" je Position mitgeschickt wird
-- (nur inventoryItemId/locationId/quantity) -- ohne compareQuantity prueft
-- Shopify gar nichts gegen einen Vergleichswert, das Flag war unnoetig.
-- =============================================================================

SET @shopify_connector_type_id = (SELECT id FROM connect_connector_types WHERE type_key = 'shopify');
SET @shopify_product_object_id = (SELECT id FROM connect_objects WHERE connector_type_id = @shopify_connector_type_id AND object_key = 'product');

UPDATE connect_object_operations
SET body_template = 'mutation($inventoryItemId: ID!, $locationId: ID!, $quantity: Int!) { result: inventorySetQuantities(input: {name: "available", reason: "correction", quantities: [{inventoryItemId: $inventoryItemId, locationId: $locationId, quantity: $quantity}]}) { userErrors { field message } } }'
WHERE object_id = @shopify_product_object_id AND operation_type = 'update';
