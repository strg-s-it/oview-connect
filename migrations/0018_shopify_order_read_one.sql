-- =============================================================================
-- connect/migrations/0018_shopify_order_read_one.sql
--
-- Neue Operation "read_one" fuers Shopify-Bestellung-Object -- analog zu
-- 0017_shopify_product_read_one.sql. Fuer den order_paid-Webhook: der
-- schlanke Webhook-Payload enthaelt zwar admin_graphql_api_id, aber nicht
-- alle Felder/Zeilenpositionen in der von Connect erwarteten Form -- die
-- vollstaendige Bestellung wird deshalb gezielt nachgeladen, statt sich auf
-- den Webhook-Payload selbst zu verlassen.
--
-- Gleicher GraphQL-Alias-Trick wie bei Produkt: "order" wird per Alias auf
-- "node" umbenannt, damit die Antwortform identisch zu einer Zeile aus der
-- Listen-Antwort (data.orders.edges[] mit node.*) ist -- die bestehenden
-- field_path-Werte (inkl. der Zeilenpositionen-Felder) greifen dadurch
-- unveraendert.
-- =============================================================================

SET @shopify_connector_type_id = (SELECT id FROM connect_connector_types WHERE type_key = 'shopify');
SET @shopify_order_object_id = (SELECT id FROM connect_objects WHERE connector_type_id = @shopify_connector_type_id AND object_key = 'order');

INSERT INTO connect_object_operations (object_id, operation_type, http_method, path_template, body_template, response_records_path, static_params)
VALUES (
    @shopify_order_object_id,
    'read_one',
    'POST',
    'admin/api/2026-07/graphql.json',
    'query($id: ID!) { node: order(id: $id) { id name createdAt displayFinancialStatus email customer { firstName lastName } currentTotalPriceSet { shopMoney { amount currencyCode } } shippingAddress { address1 city zip countryCodeV2 } lineItems(first: 50) { edges { node { sku quantity originalUnitPriceSet { shopMoney { amount } } } } } } }',
    'data',
    NULL
);
