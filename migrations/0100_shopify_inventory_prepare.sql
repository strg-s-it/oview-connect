-- =============================================================================
-- connect/migrations/0100_shopify_inventory_prepare.sql
--
-- Zwei kleine Shopify-Operationen fuer den Anfangsbestand beim Import NACH Shopify
-- (Connect\Sync\ProductImportService::writeInitialStockToShopify()): ein per API
-- frisch angelegtes Produkt hat keine Bestandsfuehrung und ist nicht zwingend am
-- gewaehlten Standort gefuehrt -- ohne beides laesst sich der Bestand nicht setzen.
--
--   track_inventory    inventoryItemUpdate(tracked: true)  -- Bestandsfuehrung einschalten
--   activate_inventory inventoryActivate                   -- am Standort fuehren (idempotent)
--
-- Das eigentliche Setzen laeuft ueber die bestehende "update"-Operation (inventorySetQuantities,
-- wie im Lagerabgleich). Mutationsfeld jeweils als "result" aliasiert (siehe Migration 0027),
-- damit GenericHttpConnector::write() die userErrors generisch auswertet. Fehlen die Operationen
-- (Migration nicht eingespielt), ueberspringt der Code diese Schritte.
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

SET @shopify_connector_type_id = (SELECT id FROM connect_connector_types WHERE type_key = 'shopify');
SET @shopify_product_object_id = (SELECT id FROM connect_objects WHERE connector_type_id = @shopify_connector_type_id AND object_key = 'product');

INSERT INTO connect_object_operations (object_id, operation_type, http_method, path_template, body_template, response_records_path, static_params)
VALUES (
    @shopify_product_object_id,
    'track_inventory',
    'POST',
    'admin/api/2026-07/graphql.json',
    'mutation($inventoryItemId: ID!) { result: inventoryItemUpdate(id: $inventoryItemId, input: {tracked: true}) { inventoryItem { id tracked } userErrors { field message } } }',
    NULL,
    NULL
);

INSERT INTO connect_object_operations (object_id, operation_type, http_method, path_template, body_template, response_records_path, static_params)
VALUES (
    @shopify_product_object_id,
    'activate_inventory',
    'POST',
    'admin/api/2026-07/graphql.json',
    'mutation($inventoryItemId: ID!, $locationId: ID!) { result: inventoryActivate(inventoryItemId: $inventoryItemId, locationId: $locationId) { inventoryLevel { id } userErrors { field message } } }',
    NULL,
    NULL
);
