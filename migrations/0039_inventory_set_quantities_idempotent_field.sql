-- =============================================================================
-- connect/migrations/0039_inventory_set_quantities_idempotent_field.sql
--
-- Fix zu Migration 0038: Shopify lieferte einen praeziseren Live-Fehler:
--   '@idempotent' can't be applied to mutations (allowed: fields);
--   Directive 'idempotent' is missing required arguments: key
--
-- Zwei Korrekturen gegenueber 0038:
--   1. Die Direktive gehoert an das MUTATIONSFELD (result: inventorySetQuantities(...)
--      @idempotent(...)), nicht an den "mutation(...)"-Operator selbst.
--   2. Sie braucht ein Pflichtargument "key" -- ein ganz normales GraphQL-
--      Variable ($idempotencyKey), KEIN HTTP-Header (die vorherige Annahme
--      in GenericHttpConnector war falsch und wurde dort korrigiert:
--      buildWriteBody() ergaenzt "idempotencyKey" jetzt generisch in den
--      Variablen, wenn body_template "@idempotent" enthaelt).
-- =============================================================================

SET @shopify_connector_type_id = (SELECT id FROM connect_connector_types WHERE type_key = 'shopify');
SET @shopify_product_object_id = (SELECT id FROM connect_objects WHERE connector_type_id = @shopify_connector_type_id AND object_key = 'product');

UPDATE connect_object_operations
SET body_template = 'mutation($inventoryItemId: ID!, $locationId: ID!, $quantity: Int!, $changeFromQuantity: Int!, $idempotencyKey: String!) { result: inventorySetQuantities(input: {name: "available", reason: "correction", quantities: [{inventoryItemId: $inventoryItemId, locationId: $locationId, quantity: $quantity, changeFromQuantity: $changeFromQuantity}]}) @idempotent(key: $idempotencyKey) { userErrors { field message } } }'
WHERE object_id = @shopify_product_object_id AND operation_type = 'update';
