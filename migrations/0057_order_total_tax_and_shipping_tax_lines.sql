-- =============================================================================
-- connect/migrations/0057_order_total_tax_and_shipping_tax_lines.sql
--
-- Nutzer-Entscheidung (2026-09-27): die beiden in der alten Welt (jobs/
-- cron_sync_invoice.php) bewusst nicht nachgebauten Sicherheitsnetze fuer
-- die Rechnungserstellung -- Steuer-Kontrollrechnung und Versandkosten-
-- Aufteilung auf mehrere Steuersaetze -- werden jetzt doch nachgezogen
-- ("da Rechnungen das kritischste sind, bitte alles notwendige einbauen").
-- Diese Migration ergaenzt dafuer zwei fehlende Felder am Shopify-"Auftrag"-
-- Object:
--
--   - total_tax: Shopifys eigene Steuersumme der Bestellung (node.totalTaxSet),
--     Vergleichswert fuer die Kontrollrechnung in Connect\Sync\InvoiceService.
--   - shipping_tax_lines: ALLE Steuersaetze der ERSTEN Versandart (bisher
--     wurde in Migration 0055 nur taxLines.0.rate gelesen, ein einzelner
--     Satz) -- als wiederholende Gruppe mit rate + Steuerbetrag je Satz,
--     damit InvoiceService bei gemischten Steuersaetzen im Warenkorb (siehe
--     Bug in der alten Welt: Versandsteuer faelschlich nur mit EINEM Satz
--     berechnet) den Versand anteilig auf mehrere Rechnungspositionen
--     aufteilen kann, genau wie in der alten Welt.
--
-- Shopifys "taxLines" auf einer Versandart ist (anders als "lineItems") eine
-- EINFACHE Liste, keine Connection -- die Kind-Feld-Pfade sind deshalb OHNE
-- "node."-Praefix relativ zum jeweiligen Listen-Element (siehe
-- GenericHttpConnector::mapRepeatingGroup()).
--
-- Wie bei 0021/0024/0055/0056: das GraphQL-Query von read_list UND read_one
-- wird komplett neu gesetzt (kein Diff-Update moeglich).
--
-- Hinweis: der Installer trennt am Semikolon -- deshalb keines in Strings.
-- =============================================================================

SET @shopify_order_object_id = (
    SELECT o.id FROM connect_objects o
    JOIN connect_connector_types t ON t.id = o.connector_type_id
    WHERE t.type_key = 'shopify' AND o.object_key = 'order'
);

INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES
    (@shopify_order_object_id, 'total_tax', 'Steuersumme (Shopify)', 'number', 'node.totalTaxSet.shopMoney.amount', 0, NULL),
    (@shopify_order_object_id, 'shipping_tax_lines', 'Versand-Steuersaetze', 'array', 'node.shippingLines.edges.0.node.taxLines', 1, NULL);

SET @shipping_tax_lines_field_id = (SELECT id FROM connect_object_fields WHERE object_id = @shopify_order_object_id AND field_key = 'shipping_tax_lines');

INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES
    (@shopify_order_object_id, 'shipping_tax_line_rate', 'Steuersatz', 'number', 'rate', 0, @shipping_tax_lines_field_id),
    (@shopify_order_object_id, 'shipping_tax_line_amount', 'Steuerbetrag', 'number', 'priceSet.shopMoney.amount', 0, @shipping_tax_lines_field_id);

UPDATE connect_object_operations op
JOIN connect_objects o ON o.id = op.object_id
JOIN connect_connector_types t ON t.id = o.connector_type_id
SET op.body_template = 'query($cursor: String) { orders(first: 100, after: $cursor) { edges { node { id name createdAt displayFinancialStatus email phone customer { firstName lastName } currentTotalPriceSet { shopMoney { amount currencyCode } } totalShippingPriceSet { shopMoney { amount } } totalTaxSet { shopMoney { amount } } shippingAddress { address1 city zip countryCodeV2 phone } billingAddress { address1 city zip countryCodeV2 phone } shippingLines(first: 1) { edges { node { taxLines { rate priceSet { shopMoney { amount } } } } } } transactions(first: 1) { id authorizationCode } lineItems(first: 20) { edges { node { title sku quantity originalUnitPriceSet { shopMoney { amount } } taxLines { rate } variant { barcode } } } } } } pageInfo { hasNextPage endCursor } } }'
WHERE t.type_key = 'shopify' AND o.object_key = 'order' AND op.operation_type = 'read_list';

UPDATE connect_object_operations op
JOIN connect_objects o ON o.id = op.object_id
JOIN connect_connector_types t ON t.id = o.connector_type_id
SET op.body_template = 'query($id: ID!) { node: order(id: $id) { id name createdAt displayFinancialStatus email phone customer { firstName lastName } currentTotalPriceSet { shopMoney { amount currencyCode } } totalShippingPriceSet { shopMoney { amount } } totalTaxSet { shopMoney { amount } } shippingAddress { address1 city zip countryCodeV2 phone } billingAddress { address1 city zip countryCodeV2 phone } shippingLines(first: 1) { edges { node { taxLines { rate priceSet { shopMoney { amount } } } } } } transactions(first: 1) { id authorizationCode } lineItems(first: 50) { edges { node { title sku quantity originalUnitPriceSet { shopMoney { amount } } taxLines { rate } variant { barcode } } } } } }'
WHERE t.type_key = 'shopify' AND o.object_key = 'order' AND op.operation_type = 'read_one';
