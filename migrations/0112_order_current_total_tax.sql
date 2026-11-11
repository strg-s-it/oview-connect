-- =============================================================================
-- connect/migrations/0112_order_current_total_tax.sql
--
-- Shopifys Steuersumme der Bestellung: bisher "totalTaxSet" -- das ist der Stand
-- vor nachtraeglichen Bearbeitungen. Der Gesamtbetrag kommt aus "currentTotalPriceSet"
-- (aktueller Stand), die Steuer-Kontrolle verglich also Steuer vom alten mit
-- Positionen vom neuen Stand. Live belegt (Kunde Revenued): #12043 entfernte Positionen
-- 9.27 EUR brutto -> Differenz 0.84 EUR = 10 % davon; #12301 Versand 22.50 -> 7.50 EUR
-- -> Differenz 1.36 EUR = 10 % auf die 15.00 EUR Reduktion.
--
-- Jetzt "currentTotalTaxSet" (Steuer nach Bearbeitungen/Retouren). Feld "total_tax"
-- und read_list/read_one entsprechend neu gesetzt (Stand: Migration 0110).
-- =============================================================================

SET @shopify_order_object_id = (
    SELECT o.id FROM connect_objects o
    JOIN connect_connector_types t ON t.id = o.connector_type_id
    WHERE t.type_key = 'shopify' AND o.object_key = 'order'
);

UPDATE connect_object_fields
SET field_path = 'node.currentTotalTaxSet.shopMoney.amount'
WHERE object_id = @shopify_order_object_id AND field_key = 'total_tax';

UPDATE connect_object_operations op
JOIN connect_objects o ON o.id = op.object_id
JOIN connect_connector_types t ON t.id = o.connector_type_id
SET op.body_template = 'query($cursor: String) { orders(first: 100, after: $cursor) { edges { node { id name createdAt displayFinancialStatus sourceName email phone customer { firstName lastName } currentTotalPriceSet { shopMoney { amount currencyCode } } totalShippingPriceSet { shopMoney { amount } } currentTotalTaxSet { shopMoney { amount } } shippingAddress { address1 city zip countryCodeV2 phone } billingAddress { address1 city zip countryCodeV2 phone } shippingLines(first: 1) { edges { node { originalPriceSet { shopMoney { amount } } discountedPriceSet { shopMoney { amount } } taxLines { rate priceSet { shopMoney { amount } } } } } } transactions(first: 1) { id authorizationCode } lineItems(first: 20) { edges { node { title name sku quantity currentQuantity originalUnitPriceSet { shopMoney { amount } } discountedUnitPriceSet { shopMoney { amount } } discountAllocations { allocatedAmountSet { shopMoney { amount } } } taxLines { rate priceSet { shopMoney { amount } } } variant { barcode } } } } } } pageInfo { hasNextPage endCursor } } }'
WHERE t.type_key = 'shopify' AND o.object_key = 'order' AND op.operation_type = 'read_list';

UPDATE connect_object_operations op
JOIN connect_objects o ON o.id = op.object_id
JOIN connect_connector_types t ON t.id = o.connector_type_id
SET op.body_template = 'query($id: ID!) { node: order(id: $id) { id name createdAt displayFinancialStatus sourceName email phone customer { firstName lastName } currentTotalPriceSet { shopMoney { amount currencyCode } } totalShippingPriceSet { shopMoney { amount } } currentTotalTaxSet { shopMoney { amount } } shippingAddress { address1 city zip countryCodeV2 phone } billingAddress { address1 city zip countryCodeV2 phone } shippingLines(first: 1) { edges { node { originalPriceSet { shopMoney { amount } } discountedPriceSet { shopMoney { amount } } taxLines { rate priceSet { shopMoney { amount } } } } } } transactions(first: 1) { id authorizationCode } lineItems(first: 50) { edges { node { title name sku quantity currentQuantity originalUnitPriceSet { shopMoney { amount } } discountedUnitPriceSet { shopMoney { amount } } discountAllocations { allocatedAmountSet { shopMoney { amount } } } taxLines { rate priceSet { shopMoney { amount } } } variant { barcode } } } } } }'
WHERE t.type_key = 'shopify' AND o.object_key = 'order' AND op.operation_type = 'read_one';
