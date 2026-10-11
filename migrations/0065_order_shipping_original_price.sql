-- =============================================================================
-- connect/migrations/0065_order_shipping_original_price.sql
--
-- Versand-Rabatt auf der Rechnung sichtbar machen (wie in der alten Welt,
-- z.B. Gratisversand = "100 % Rabatt -7,50"): dafuer wird der Versandpreis
-- VOR Rabatt gebraucht -- ShippingLine.originalPriceSet ("The shipping price
-- without any discounts applied. If the parent order.taxesIncluded field is
-- true, then this price includes taxes."). Der rabattierte Preis
-- (shipping_discounted_price, Migration 0063) bleibt unveraendert.
--
-- Die Logik steckt in Connect\Sync\InvoiceService::buildShippingItems().
-- Wie bei allen bisherigen Aenderungen an dieser Operation: read_list UND
-- read_one werden komplett neu gesetzt (kein Diff-Update moeglich).
-- =============================================================================

SET @shopify_order_object_id = (
    SELECT o.id FROM connect_objects o
    JOIN connect_connector_types t ON t.id = o.connector_type_id
    WHERE t.type_key = 'shopify' AND o.object_key = 'order'
);

INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES
    (@shopify_order_object_id, 'shipping_original_price', 'Versandkosten vor Rabatt', 'number', 'node.shippingLines.edges.0.node.originalPriceSet.shopMoney.amount', 0, NULL);

UPDATE connect_object_operations op
JOIN connect_objects o ON o.id = op.object_id
JOIN connect_connector_types t ON t.id = o.connector_type_id
SET op.body_template = 'query($cursor: String) { orders(first: 100, after: $cursor) { edges { node { id name createdAt displayFinancialStatus sourceName email phone customer { firstName lastName } currentTotalPriceSet { shopMoney { amount currencyCode } } totalShippingPriceSet { shopMoney { amount } } totalTaxSet { shopMoney { amount } } shippingAddress { address1 city zip countryCodeV2 phone } billingAddress { address1 city zip countryCodeV2 phone } shippingLines(first: 1) { edges { node { originalPriceSet { shopMoney { amount } } discountedPriceSet { shopMoney { amount } } taxLines { rate priceSet { shopMoney { amount } } } } } } transactions(first: 1) { id authorizationCode } lineItems(first: 20) { edges { node { title sku quantity originalUnitPriceSet { shopMoney { amount } } discountedUnitPriceSet { shopMoney { amount } } discountAllocations { allocatedAmountSet { shopMoney { amount } } } taxLines { rate priceSet { shopMoney { amount } } } variant { barcode } } } } } } pageInfo { hasNextPage endCursor } } }'
WHERE t.type_key = 'shopify' AND o.object_key = 'order' AND op.operation_type = 'read_list';

UPDATE connect_object_operations op
JOIN connect_objects o ON o.id = op.object_id
JOIN connect_connector_types t ON t.id = o.connector_type_id
SET op.body_template = 'query($id: ID!) { node: order(id: $id) { id name createdAt displayFinancialStatus sourceName email phone customer { firstName lastName } currentTotalPriceSet { shopMoney { amount currencyCode } } totalShippingPriceSet { shopMoney { amount } } totalTaxSet { shopMoney { amount } } shippingAddress { address1 city zip countryCodeV2 phone } billingAddress { address1 city zip countryCodeV2 phone } shippingLines(first: 1) { edges { node { originalPriceSet { shopMoney { amount } } discountedPriceSet { shopMoney { amount } } taxLines { rate priceSet { shopMoney { amount } } } } } } transactions(first: 1) { id authorizationCode } lineItems(first: 50) { edges { node { title sku quantity originalUnitPriceSet { shopMoney { amount } } discountedUnitPriceSet { shopMoney { amount } } discountAllocations { allocatedAmountSet { shopMoney { amount } } } taxLines { rate priceSet { shopMoney { amount } } } variant { barcode } } } } } }'
WHERE t.type_key = 'shopify' AND o.object_key = 'order' AND op.operation_type = 'read_one';
