-- =============================================================================
-- connect/migrations/0056_order_line_barcode.sql
--
-- Ergaenzt das Shopify-"Auftrag"-Object um "line_barcode" (Barcode der
-- Variante je Bestellposition) -- fehlte bisher: line_sku (Migration 0006)
-- und line_title (Migration 0055) waren schon da, line_barcode nicht, obwohl
-- "barcode" neben "sku"/"title" ein regulaerer, waehlbarer Vergleichswert ist
-- (connect_shops.comparison_key, siehe app/_sync_mappings_body.php). Ohne
-- dieses Feld konnte Connect\Sync\InvoiceService den Positions-Abgleich bei
-- Rechnungen nur fuer comparison_key='sku' durchfuehren.
--
-- Barcode liegt bei Shopify an der VARIANTE, nicht an der Bestellposition
-- selbst -- node.variant kann bei geloeschter Variante null sein, field_path-
-- Aufloesung (GenericHttpConnector::extractByPath) behandelt das wie jedes
-- andere optionale Feld (liefert dann null statt zu fehlern).
--
-- Wie bei 0021/0024/0055: das GraphQL-Query von read_list UND read_one wird
-- komplett neu gesetzt (kein Diff-Update moeglich).
--
-- Hinweis: der Installer trennt am Semikolon -- deshalb keines in Strings.
-- =============================================================================

SET @shopify_order_object_id = (
    SELECT o.id FROM connect_objects o
    JOIN connect_connector_types t ON t.id = o.connector_type_id
    WHERE t.type_key = 'shopify' AND o.object_key = 'order'
);

SET @shopify_line_items_field_id = (SELECT id FROM connect_object_fields WHERE object_id = @shopify_order_object_id AND field_key = 'line_items');

INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES
    (@shopify_order_object_id, 'line_barcode', 'Barcode je Position', 'string', 'node.variant.barcode', 0, @shopify_line_items_field_id);

UPDATE connect_object_operations op
JOIN connect_objects o ON o.id = op.object_id
JOIN connect_connector_types t ON t.id = o.connector_type_id
SET op.body_template = 'query($cursor: String) { orders(first: 100, after: $cursor) { edges { node { id name createdAt displayFinancialStatus email phone customer { firstName lastName } currentTotalPriceSet { shopMoney { amount currencyCode } } totalShippingPriceSet { shopMoney { amount } } shippingAddress { address1 city zip countryCodeV2 phone } billingAddress { address1 city zip countryCodeV2 phone } shippingLines(first: 1) { edges { node { taxLines { rate } } } } transactions(first: 1) { id authorizationCode } lineItems(first: 20) { edges { node { title sku quantity originalUnitPriceSet { shopMoney { amount } } taxLines { rate } variant { barcode } } } } } } pageInfo { hasNextPage endCursor } } }'
WHERE t.type_key = 'shopify' AND o.object_key = 'order' AND op.operation_type = 'read_list';

UPDATE connect_object_operations op
JOIN connect_objects o ON o.id = op.object_id
JOIN connect_connector_types t ON t.id = o.connector_type_id
SET op.body_template = 'query($id: ID!) { node: order(id: $id) { id name createdAt displayFinancialStatus email phone customer { firstName lastName } currentTotalPriceSet { shopMoney { amount currencyCode } } totalShippingPriceSet { shopMoney { amount } } shippingAddress { address1 city zip countryCodeV2 phone } billingAddress { address1 city zip countryCodeV2 phone } shippingLines(first: 1) { edges { node { taxLines { rate } } } } transactions(first: 1) { id authorizationCode } lineItems(first: 50) { edges { node { title sku quantity originalUnitPriceSet { shopMoney { amount } } taxLines { rate } variant { barcode } } } } } }'
WHERE t.type_key = 'shopify' AND o.object_key = 'order' AND op.operation_type = 'read_one';
