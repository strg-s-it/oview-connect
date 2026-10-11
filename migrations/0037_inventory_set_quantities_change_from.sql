-- =============================================================================
-- connect/migrations/0037_inventory_set_quantities_change_from.sql
--
-- Fix zu Migration 0036: nach Entfernen von "ignoreCompareQuantity" lieferte
-- Shopify einen neuen Live-Fehler:
--   GraphQL-Fehler: InventoryQuantityInput must include the following
--   argument: changeFromQuantity.
--
-- Bei der verwendeten API-Version ist "changeFromQuantity" (der bisher
-- bekannte Bestand) offenbar ein Pflichtfeld je Position -- vermutlich als
-- Schutz gegen ein versehentliches Ueberschreiben ohne zu wissen, wovon man
-- ausgeht. StockSyncRunner::runForShop() wurde entsprechend angepasst
-- (schreibt jetzt den bisher gecachten Zielbestand als changeFromQuantity
-- mit und bricht bei unbekanntem Zielbestand sauber ab, statt zu schreiben).
-- =============================================================================

SET @shopify_connector_type_id = (SELECT id FROM connect_connector_types WHERE type_key = 'shopify');
SET @shopify_product_object_id = (SELECT id FROM connect_objects WHERE connector_type_id = @shopify_connector_type_id AND object_key = 'product');

UPDATE connect_object_operations
SET body_template = 'mutation($inventoryItemId: ID!, $locationId: ID!, $quantity: Int!, $changeFromQuantity: Int!) { result: inventorySetQuantities(input: {name: "available", reason: "correction", quantities: [{inventoryItemId: $inventoryItemId, locationId: $locationId, quantity: $quantity, changeFromQuantity: $changeFromQuantity}]}) { userErrors { field message } } }'
WHERE object_id = @shopify_product_object_id AND operation_type = 'update';
