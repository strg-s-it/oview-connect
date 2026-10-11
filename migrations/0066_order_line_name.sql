-- =============================================================================
-- connect/migrations/0066_order_line_name.sql
--
-- Positionsname MIT Variante auf der Rechnung (Nutzer-Wunsch 2026-10-01,
-- #1151: Rechnung zeigte "Katze" statt "Katze - Mittel"). LineItem.name laut
-- Shopify-Doku: "The title of the product, optionally appended with the title
-- of the variant (if applicable)." -- bei Produkten ohne Varianten bleibt es
-- der reine Produkttitel.
--
-- "line_title" (reiner Produkttitel) bleibt unveraendert, da er beim
-- Vergleichswert "title" fuer den Artikelabgleich gebraucht wird
-- (InvoiceService::LINE_ITEM_MATCH_FIELDS) -- "line_name" ist nur die Anzeige.
--
-- Wie bei allen bisherigen Aenderungen an dieser Operation: read_list UND
-- read_one werden komplett neu gesetzt (kein Diff-Update moeglich).
-- =============================================================================

SET @shopify_order_object_id = (
    SELECT o.id FROM connect_objects o
    JOIN connect_connector_types t ON t.id = o.connector_type_id
    WHERE t.type_key = 'shopify' AND o.object_key = 'order'
);

SET @shopify_line_items_field_id = (SELECT id FROM connect_object_fields WHERE object_id = @shopify_order_object_id AND field_key = 'line_items');

INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES
    (@shopify_order_object_id, 'line_name', 'Positionsname (mit Variante)', 'string', 'node.name', 0, @shopify_line_items_field_id);

UPDATE connect_object_operations op
JOIN connect_objects o ON o.id = op.object_id
JOIN connect_connector_types t ON t.id = o.connector_type_id
SET op.body_template = 'query($cursor: String) { orders(first: 100, after: $cursor) { edges { node { id name createdAt displayFinancialStatus sourceName email phone customer { firstName lastName } currentTotalPriceSet { shopMoney { amount currencyCode } } totalShippingPriceSet { shopMoney { amount } } totalTaxSet { shopMoney { amount } } shippingAddress { address1 city zip countryCodeV2 phone } billingAddress { address1 city zip countryCodeV2 phone } shippingLines(first: 1) { edges { node { originalPriceSet { shopMoney { amount } } discountedPriceSet { shopMoney { amount } } taxLines { rate priceSet { shopMoney { amount } } } } } } transactions(first: 1) { id authorizationCode } lineItems(first: 20) { edges { node { title name sku quantity originalUnitPriceSet { shopMoney { amount } } discountedUnitPriceSet { shopMoney { amount } } discountAllocations { allocatedAmountSet { shopMoney { amount } } } taxLines { rate priceSet { shopMoney { amount } } } variant { barcode } } } } } } pageInfo { hasNextPage endCursor } } }'
WHERE t.type_key = 'shopify' AND o.object_key = 'order' AND op.operation_type = 'read_list';

UPDATE connect_object_operations op
JOIN connect_objects o ON o.id = op.object_id
JOIN connect_connector_types t ON t.id = o.connector_type_id
SET op.body_template = 'query($id: ID!) { node: order(id: $id) { id name createdAt displayFinancialStatus sourceName email phone customer { firstName lastName } currentTotalPriceSet { shopMoney { amount currencyCode } } totalShippingPriceSet { shopMoney { amount } } totalTaxSet { shopMoney { amount } } shippingAddress { address1 city zip countryCodeV2 phone } billingAddress { address1 city zip countryCodeV2 phone } shippingLines(first: 1) { edges { node { originalPriceSet { shopMoney { amount } } discountedPriceSet { shopMoney { amount } } taxLines { rate priceSet { shopMoney { amount } } } } } } transactions(first: 1) { id authorizationCode } lineItems(first: 50) { edges { node { title name sku quantity originalUnitPriceSet { shopMoney { amount } } discountedUnitPriceSet { shopMoney { amount } } discountAllocations { allocatedAmountSet { shopMoney { amount } } } taxLines { rate priceSet { shopMoney { amount } } } variant { barcode } } } } } }'
WHERE t.type_key = 'shopify' AND o.object_key = 'order' AND op.operation_type = 'read_one';
