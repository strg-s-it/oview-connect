-- =============================================================================
-- connect/migrations/0063_order_discounts_and_net_price.sql
--
-- Nutzer-Entscheidung (2026-09-28): zwei bisher hartcodierte Annahmen in
-- Connect\Sync\InvoiceService beheben, die es in der alten Welt (jobs/
-- cron_sync_invoice.php) so nicht gibt bzw. die dort ueber Einstellungen
-- geloest sind:
--
--   1. Rabatte: Positions- UND Versand-Rabatte wurden bisher komplett
--      ignoriert -- die Rechnung zeigte immer den vollen, unrabattierten
--      Preis. Fix nutzt NICHT (wie die alte Welt) discount_allocations/
--      discount_applications manuell nachzurechnen (fragil: discount_
--      applications.value ist bei Shopify ein Union-Typ Prozent/Betrag, die
--      alte Welt behandelt es IMMER als Prozent -- ein fixer Rabattbetrag auf
--      den Versand waere dort bereits falsch interpretiert worden), sondern
--      Shopifys eigene, bereits fertig berechnete Endpreise:
--        - LineItem.discountedUnitPriceSet (Stueckpreis NACH Rabatt)
--        - ShippingLine.discountedPriceSet (Versandpreis NACH Rabatt)
--      Damit ist die Rechnung IMMER korrekt, unabhaengig davon, ob der Rabatt
--      prozentual oder als fixer Betrag angelegt wurde.
--
--   2. Netto/Brutto: connect_shops.shopify_prices_include_tax (Migration
--      0046, bisher NUR vom Produktimport genutzt) wurde in der Rechnungs-
--      erstellung komplett ignoriert -- Positions-/Versandpreise wurden immer
--      als Brutto behandelt. Bei einem Shop mit Netto-Preisen waere die
--      Rechnung dadurch zu niedrig ausgefallen. Fix in InvoiceService: bei
--      shopify_prices_include_tax=0 wird der von Shopify gemeldete
--      Steuerbetrag (line_tax_amount/shipping_tax_line_amount, Migration
--      0057/0062 -- NICHT ein zurueckgerechneter Wert) zum Netto-Preis
--      addiert, um den tatsaechlich an HelloCash zu meldenden Bruttopreis zu
--      erhalten.
--
-- Diese Migration ergaenzt nur die dafuer noetigen NEUEN Felder (Punkt 2
-- braucht keine neuen Felder, nutzt die bereits vorhandene Spalte/die
-- bereits vorhandenen line_tax_amount/shipping_tax_line_amount-Felder) --
-- die eigentliche Logik steckt in Connect\Sync\InvoiceService.
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
    (@shopify_order_object_id, 'line_discounted_price', 'Stückpreis nach Rabatt', 'number', 'node.discountedUnitPriceSet.shopMoney.amount', 0, @shopify_line_items_field_id),
    (@shopify_order_object_id, 'shipping_discounted_price', 'Versandkosten nach Rabatt', 'number', 'node.shippingLines.edges.0.node.discountedPriceSet.shopMoney.amount', 0, NULL);

UPDATE connect_object_operations op
JOIN connect_objects o ON o.id = op.object_id
JOIN connect_connector_types t ON t.id = o.connector_type_id
SET op.body_template = 'query($cursor: String) { orders(first: 100, after: $cursor) { edges { node { id name createdAt displayFinancialStatus sourceName email phone customer { firstName lastName } currentTotalPriceSet { shopMoney { amount currencyCode } } totalShippingPriceSet { shopMoney { amount } } totalTaxSet { shopMoney { amount } } shippingAddress { address1 city zip countryCodeV2 phone } billingAddress { address1 city zip countryCodeV2 phone } shippingLines(first: 1) { edges { node { discountedPriceSet { shopMoney { amount } } taxLines { rate priceSet { shopMoney { amount } } } } } } transactions(first: 1) { id authorizationCode } lineItems(first: 20) { edges { node { title sku quantity originalUnitPriceSet { shopMoney { amount } } discountedUnitPriceSet { shopMoney { amount } } taxLines { rate priceSet { shopMoney { amount } } } variant { barcode } } } } } } pageInfo { hasNextPage endCursor } } }'
WHERE t.type_key = 'shopify' AND o.object_key = 'order' AND op.operation_type = 'read_list';

UPDATE connect_object_operations op
JOIN connect_objects o ON o.id = op.object_id
JOIN connect_connector_types t ON t.id = o.connector_type_id
SET op.body_template = 'query($id: ID!) { node: order(id: $id) { id name createdAt displayFinancialStatus sourceName email phone customer { firstName lastName } currentTotalPriceSet { shopMoney { amount currencyCode } } totalShippingPriceSet { shopMoney { amount } } totalTaxSet { shopMoney { amount } } shippingAddress { address1 city zip countryCodeV2 phone } billingAddress { address1 city zip countryCodeV2 phone } shippingLines(first: 1) { edges { node { discountedPriceSet { shopMoney { amount } } taxLines { rate priceSet { shopMoney { amount } } } } } } transactions(first: 1) { id authorizationCode } lineItems(first: 50) { edges { node { title sku quantity originalUnitPriceSet { shopMoney { amount } } discountedUnitPriceSet { shopMoney { amount } } taxLines { rate priceSet { shopMoney { amount } } } variant { barcode } } } } } }'
WHERE t.type_key = 'shopify' AND o.object_key = 'order' AND op.operation_type = 'read_one';
