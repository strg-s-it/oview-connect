-- =============================================================================
-- connect/migrations/0038_inventory_set_quantities_idempotent.sql
--
-- Fix zu Migration 0037: Shopify liefert einen weiteren Live-Fehler:
--   GraphQL-Fehler: The @idempotent directive is required for this mutation
--   but was not provided.
--
-- inventorySetQuantities gehoert offenbar zu den Mutationen, die bei der
-- verwendeten API-Version zwingend als "@idempotent" markiert sein muessen
-- (Schutz gegen doppelte Ausfuehrung bei Netzwerk-Retries). Die Direktive
-- allein reicht nicht -- Shopify erwartet dazu einen Idempotency-Key-Header
-- pro Anfrage, den GenericHttpConnector::executeRequest() jetzt automatisch
-- setzt, wenn body_template "@idempotent" enthaelt (siehe dortiger
-- Kommentar) -- rein an dieser Migration erkennbar, kein zusaetzlicher
-- Schritt hier noetig.
-- =============================================================================

SET @shopify_connector_type_id = (SELECT id FROM connect_connector_types WHERE type_key = 'shopify');
SET @shopify_product_object_id = (SELECT id FROM connect_objects WHERE connector_type_id = @shopify_connector_type_id AND object_key = 'product');

UPDATE connect_object_operations
SET body_template = 'mutation($inventoryItemId: ID!, $locationId: ID!, $quantity: Int!, $changeFromQuantity: Int!) @idempotent { result: inventorySetQuantities(input: {name: "available", reason: "correction", quantities: [{inventoryItemId: $inventoryItemId, locationId: $locationId, quantity: $quantity, changeFromQuantity: $changeFromQuantity}]}) { userErrors { field message } } }'
WHERE object_id = @shopify_product_object_id AND operation_type = 'update';
