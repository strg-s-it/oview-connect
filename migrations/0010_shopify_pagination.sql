-- =============================================================================
-- connect/migrations/0010_shopify_pagination.sql
--
-- Ergaenzt die GraphQL-Queries fuer Produkt/Kategorie/Bestellung um
-- Cursor-basierte Pagination ($cursor-Variable, after: $cursor), damit
-- GenericHttpConnector::read() (jetzt mit eingebauter Pagination-Schleife)
-- wirklich ALLE Datensaetze holt, nicht nur die erste Seite. first von 50
-- auf 100 pro Seite erhoeht (weniger Roundtrips fuer grosse Kataloge).
--
-- "Shop"/"Sprache"/"Länder-Steuersatz" sind bewusst NICHT paginiert (Einzel-
-- objekt bzw. schon vollstaendige Liste ohne pageInfo).
-- =============================================================================

SET @shopify_connector_type_id = (SELECT id FROM connect_connector_types WHERE type_key = 'shopify');

UPDATE connect_object_operations op
JOIN connect_objects o ON o.id = op.object_id
SET op.body_template = 'query($cursor: String) { products(first: 100, after: $cursor) { edges { node { id title handle vendor productType status variants(first: 1) { edges { node { id sku barcode price inventoryQuantity } } } } } pageInfo { hasNextPage endCursor } } }'
WHERE o.connector_type_id = @shopify_connector_type_id AND o.object_key = 'product' AND op.operation_type = 'read_list';

UPDATE connect_object_operations op
JOIN connect_objects o ON o.id = op.object_id
SET op.body_template = 'query($cursor: String) { collections(first: 100, after: $cursor) { edges { node { id title handle } } pageInfo { hasNextPage endCursor } } }'
WHERE o.connector_type_id = @shopify_connector_type_id AND o.object_key = 'collection' AND op.operation_type = 'read_list';

UPDATE connect_object_operations op
JOIN connect_objects o ON o.id = op.object_id
SET op.body_template = 'query($cursor: String) { orders(first: 100, after: $cursor) { edges { node { id name createdAt displayFinancialStatus email customer { firstName lastName } currentTotalPriceSet { shopMoney { amount currencyCode } } shippingAddress { address1 city zip countryCodeV2 } lineItems(first: 20) { edges { node { sku quantity originalUnitPriceSet { shopMoney { amount } } } } } } } pageInfo { hasNextPage endCursor } } }'
WHERE o.connector_type_id = @shopify_connector_type_id AND o.object_key = 'order' AND op.operation_type = 'read_list';

UPDATE connect_object_operations op
JOIN connect_objects o ON o.id = op.object_id
SET op.body_template = 'query($cursor: String) { locations(first: 100, after: $cursor) { edges { node { id name isActive address { city zip countryCode } } } pageInfo { hasNextPage endCursor } } }'
WHERE o.connector_type_id = @shopify_connector_type_id AND o.object_key = 'location' AND op.operation_type = 'read_list';
