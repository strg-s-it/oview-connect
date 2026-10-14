-- =============================================================================
-- connect/migrations/0101_shopify_activate_inventory_idempotent.sql
--
-- Live beobachtet: Shopify lehnt inventoryActivate (Operation "activate_inventory",
-- Migration 0100) ab: "The @idempotent directive is required for this mutation but was
-- not provided." Wie bei inventorySetQuantities (Migration 0039) gehoert die Direktive an
-- das MUTATIONSFELD, der Schluessel kommt als Variable. GenericHttpConnector::write()
-- ergaenzt $idempotencyKey selbst, sobald das Template "@idempotent" enthaelt.
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

SET @shopify_connector_type_id = (SELECT id FROM connect_connector_types WHERE type_key = 'shopify');
SET @shopify_product_object_id = (SELECT id FROM connect_objects WHERE connector_type_id = @shopify_connector_type_id AND object_key = 'product');

UPDATE connect_object_operations
SET body_template = 'mutation($inventoryItemId: ID!, $locationId: ID!, $idempotencyKey: String!) { result: inventoryActivate(inventoryItemId: $inventoryItemId, locationId: $locationId) @idempotent(key: $idempotencyKey) { inventoryLevel { id } userErrors { field message } } }'
WHERE object_id = @shopify_product_object_id AND operation_type = 'activate_inventory';
