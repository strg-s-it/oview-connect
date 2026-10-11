-- =============================================================================
-- connect/migrations/0062_order_line_tax_amount.sql
--
-- Behebt einen FALSCH-POSITIVEN Steuer-Kontroll-Abbruch, live beobachtet per
-- Mail (Shop "3bnyc1-n9", noch alte Welt, Bestellung #1218): computed_tax=2.24
-- vs. shopify_total_tax=1.76, Differenz genau 0.48 EUR. Nachgerechnet anhand
-- des mitgeschickten Rohdatensatzes:
--
--   Position "Fermentierter Fischdip": "taxable": false, aber Shopify liefert
--   TROTZDEM eine tax_lines-Zeile mit "rate": 0.19 UND "price": "0.00" (die
--   tatsaechliche Steuer ist 0, der Satz ist nur ein nomineller/hypothetischer
--   Wert). Sowohl die alte Welt (jobs/cron_sync_invoice.php) als auch Connect
--   (Connect\Sync\InvoiceService::buildItems(), bisher identisch uebernommen)
--   rechnen die Steuer je Position aus (Bruttopreis, Satz) ZURUECK statt
--   Shopifys eigenen, bereits korrekten Steuerbetrag je Position zu uebernehmen
--   -- fuer diese Position also faelschlich 3.00 - 3.00/1.19 = 0.48 EUR
--   "Phantom-Steuer", obwohl Shopify selbst 0.00 EUR ausweist. Summe aus
--   Shopifys EIGENEN tax_lines-Betraegen (0.79 + 0.00 + 0.58 Positionen +
--   0.39 Versand = 1.76) stimmt dagegen exakt mit total_tax ueberein.
--
-- Fix: neues Feld "line_tax_amount" (Shopifys eigener, bereits berechneter
-- Steuerbetrag je Position) -- Connect\Sync\InvoiceService nutzt das jetzt
-- statt der Rueckrechnung, sowohl fuer die Kontrollrechnung ALS AUCH fuer den
-- tatsaechlich an HelloCash gemeldeten Steuersatz der Position (zweiter,
-- eigenstaendiger Bug: ohne diesen Fix haette HelloCash fuer eine laut
-- Shopify NICHT besteuerte Position trotzdem 19% Steuer aus dem Bruttopreis
-- herausgerechnet).
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
    (@shopify_order_object_id, 'line_tax_amount', 'Steuerbetrag (von Shopify berechnet)', 'number', 'node.taxLines.0.priceSet.shopMoney.amount', 0, @shopify_line_items_field_id);

UPDATE connect_object_operations op
JOIN connect_objects o ON o.id = op.object_id
JOIN connect_connector_types t ON t.id = o.connector_type_id
SET op.body_template = 'query($cursor: String) { orders(first: 100, after: $cursor) { edges { node { id name createdAt displayFinancialStatus sourceName email phone customer { firstName lastName } currentTotalPriceSet { shopMoney { amount currencyCode } } totalShippingPriceSet { shopMoney { amount } } totalTaxSet { shopMoney { amount } } shippingAddress { address1 city zip countryCodeV2 phone } billingAddress { address1 city zip countryCodeV2 phone } shippingLines(first: 1) { edges { node { taxLines { rate priceSet { shopMoney { amount } } } } } } transactions(first: 1) { id authorizationCode } lineItems(first: 20) { edges { node { title sku quantity originalUnitPriceSet { shopMoney { amount } } taxLines { rate priceSet { shopMoney { amount } } } variant { barcode } } } } } } pageInfo { hasNextPage endCursor } } }'
WHERE t.type_key = 'shopify' AND o.object_key = 'order' AND op.operation_type = 'read_list';

UPDATE connect_object_operations op
JOIN connect_objects o ON o.id = op.object_id
JOIN connect_connector_types t ON t.id = o.connector_type_id
SET op.body_template = 'query($id: ID!) { node: order(id: $id) { id name createdAt displayFinancialStatus sourceName email phone customer { firstName lastName } currentTotalPriceSet { shopMoney { amount currencyCode } } totalShippingPriceSet { shopMoney { amount } } totalTaxSet { shopMoney { amount } } shippingAddress { address1 city zip countryCodeV2 phone } billingAddress { address1 city zip countryCodeV2 phone } shippingLines(first: 1) { edges { node { taxLines { rate priceSet { shopMoney { amount } } } } } } transactions(first: 1) { id authorizationCode } lineItems(first: 50) { edges { node { title sku quantity originalUnitPriceSet { shopMoney { amount } } taxLines { rate priceSet { shopMoney { amount } } } variant { barcode } } } } } }'
WHERE t.type_key = 'shopify' AND o.object_key = 'order' AND op.operation_type = 'read_one';
