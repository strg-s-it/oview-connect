-- =============================================================================
-- connect/migrations/0064_order_line_discount_allocations.sql
--
-- Fix fuer Migration 0063 (live beobachtet 2026-10-01, Bestellung #1149,
-- Shop strgs-s-it-demo): ein Rabattcode auf die ganze Bestellung ("across",
-- 15% "Bestellrabatt") wurde NICHT abgezogen -- Rechnung 500 EUR statt
-- 425 EUR. Ursache: LineItem.discountedUnitPriceSet enthaelt laut Shopify-Doku
-- nur Positions-Rabatte, "It doesn't include order-level or code-based
-- discounts". Die Steuer-Kontrolle hat das nicht bemerkt, weil sie Shopifys
-- eigene (korrekte) Steuerbetraege aufsummiert.
--
-- Neu: die einzelnen Rabatt-Zuteilungen je Position (LineItem.
-- discountAllocations, enthaelt Positions- UND Bestellrabatte -- dieselbe
-- Quelle wie die alte Welt, jobs/cron_sync_invoice.php "discount_
-- allocations"). Da der Feldpfad keine Summen/Wildcards kennt
-- (GenericHttpConnector::extractByPath()), werden die ersten drei
-- Zuteilungen als eigene Felder gelesen und in Connect\Sync\InvoiceService
-- aufsummiert. Mehr als drei Rabatte auf EINER Position faengt die neue
-- Brutto-Kontrolle in InvoiceService ab (Rechnung wird dann nicht angelegt).
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
    (@shopify_order_object_id, 'line_discount_1', 'Rabatt-Zuteilung 1 (Position gesamt)', 'number', 'node.discountAllocations.0.allocatedAmountSet.shopMoney.amount', 0, @shopify_line_items_field_id),
    (@shopify_order_object_id, 'line_discount_2', 'Rabatt-Zuteilung 2 (Position gesamt)', 'number', 'node.discountAllocations.1.allocatedAmountSet.shopMoney.amount', 0, @shopify_line_items_field_id),
    (@shopify_order_object_id, 'line_discount_3', 'Rabatt-Zuteilung 3 (Position gesamt)', 'number', 'node.discountAllocations.2.allocatedAmountSet.shopMoney.amount', 0, @shopify_line_items_field_id);

UPDATE connect_object_operations op
JOIN connect_objects o ON o.id = op.object_id
JOIN connect_connector_types t ON t.id = o.connector_type_id
SET op.body_template = 'query($cursor: String) { orders(first: 100, after: $cursor) { edges { node { id name createdAt displayFinancialStatus sourceName email phone customer { firstName lastName } currentTotalPriceSet { shopMoney { amount currencyCode } } totalShippingPriceSet { shopMoney { amount } } totalTaxSet { shopMoney { amount } } shippingAddress { address1 city zip countryCodeV2 phone } billingAddress { address1 city zip countryCodeV2 phone } shippingLines(first: 1) { edges { node { discountedPriceSet { shopMoney { amount } } taxLines { rate priceSet { shopMoney { amount } } } } } } transactions(first: 1) { id authorizationCode } lineItems(first: 20) { edges { node { title sku quantity originalUnitPriceSet { shopMoney { amount } } discountedUnitPriceSet { shopMoney { amount } } discountAllocations { allocatedAmountSet { shopMoney { amount } } } taxLines { rate priceSet { shopMoney { amount } } } variant { barcode } } } } } } pageInfo { hasNextPage endCursor } } }'
WHERE t.type_key = 'shopify' AND o.object_key = 'order' AND op.operation_type = 'read_list';

UPDATE connect_object_operations op
JOIN connect_objects o ON o.id = op.object_id
JOIN connect_connector_types t ON t.id = o.connector_type_id
SET op.body_template = 'query($id: ID!) { node: order(id: $id) { id name createdAt displayFinancialStatus sourceName email phone customer { firstName lastName } currentTotalPriceSet { shopMoney { amount currencyCode } } totalShippingPriceSet { shopMoney { amount } } totalTaxSet { shopMoney { amount } } shippingAddress { address1 city zip countryCodeV2 phone } billingAddress { address1 city zip countryCodeV2 phone } shippingLines(first: 1) { edges { node { discountedPriceSet { shopMoney { amount } } taxLines { rate priceSet { shopMoney { amount } } } } } } transactions(first: 1) { id authorizationCode } lineItems(first: 50) { edges { node { title sku quantity originalUnitPriceSet { shopMoney { amount } } discountedUnitPriceSet { shopMoney { amount } } discountAllocations { allocatedAmountSet { shopMoney { amount } } } taxLines { rate priceSet { shopMoney { amount } } } variant { barcode } } } } } }'
WHERE t.type_key = 'shopify' AND o.object_key = 'order' AND op.operation_type = 'read_one';
