-- =============================================================================
-- connect/migrations/0017_shopify_product_read_one.sql
--
-- Neue Operation "read_one" fuers Shopify-Produkt-Object -- fuer gezieltes
-- Nachladen EINES Produkts (z.B. beim products/update-Webhook), statt immer
-- die komplette Liste neu abzurufen.
--
-- GraphQL-Alias-Trick: "product" wird per Alias auf "node" umbenannt, damit
-- die Antwortform (nach response_records_path="data") exakt "{"node": {...}}"
-- ist -- identisch zu einer Zeile aus der Listen-Antwort (data.products.edges[]
-- mit node.*). Die bestehenden field_path-Werte ("node.id",
-- "node.variants.edges.0.node.sku", ...) greifen dadurch unveraendert, ohne
-- eigene Feld-Definitionen fuer read_one.
-- =============================================================================

SET @shopify_connector_type_id = (SELECT id FROM connect_connector_types WHERE type_key = 'shopify');
SET @shopify_product_object_id = (SELECT id FROM connect_objects WHERE connector_type_id = @shopify_connector_type_id AND object_key = 'product');

INSERT INTO connect_object_operations (object_id, operation_type, http_method, path_template, body_template, response_records_path, static_params)
VALUES (
    @shopify_product_object_id,
    'read_one',
    'POST',
    'admin/api/2026-07/graphql.json',
    'query($id: ID!) { node: product(id: $id) { id title handle vendor productType status variants(first: 1) { edges { node { id sku barcode price inventoryQuantity } } } } }',
    'data',
    NULL
);
