-- =============================================================================
-- connect/migrations/0055_order_line_title_and_shipping.sql
--
-- Ergaenzt das bestehende Shopify-"Auftrag"-Object (bisher fuer MGM aufgebaut,
-- siehe Migrationen 0006/0018/0021/0024) um drei fuer die HelloCash-Rechnungs-
-- erstellung (Connect\Sync\InvoiceService) noetige Felder, die MGM nicht brauchte:
--   - line_title: Artikelname je Position (HelloCash-Rechnungspositionen brauchen
--     zwingend einen Namen, "items[].name" ist Pflichtfeld -- das Object hatte
--     bisher nur sku/quantity/price/tax_rate je Position).
--   - shipping_price / shipping_tax_rate: Versandkosten als EIGENE Rechnungsposition,
--     bewusst vereinfacht auf GENAU EINEN Steuersatz (der der ersten Versandart) --
--     die alte Welt teilt bei mehreren Steuersaetzen im Warenkorb den Versand anteilig
--     auf mehrere Positionen auf (siehe jobs/cron_sync_invoice.php), das ist fuer
--     Connect v1 bewusst nicht nachgebaut (Nutzer-Entscheidung, "Nicht Teil von v1").
--
-- Wie bei 0021/0024: das GraphQL-Query von read_list UND read_one wird komplett neu
-- gesetzt (kein Diff-Update moeglich), field_path-Konvention wie ueberall in diesem
-- Object ("node."-Praefix, Array-Index als Zahl im Pfad, siehe payment_transaction_id).
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
    (@shopify_order_object_id, 'line_title', 'Artikelname', 'string', 'node.title', 0, @shopify_line_items_field_id),
    (@shopify_order_object_id, 'shipping_price', 'Versandkosten', 'number', 'node.totalShippingPriceSet.shopMoney.amount', 0, NULL),
    (@shopify_order_object_id, 'shipping_tax_rate', 'Versand-Steuersatz', 'number', 'node.shippingLines.edges.0.node.taxLines.0.rate', 0, NULL);

UPDATE connect_object_operations op
JOIN connect_objects o ON o.id = op.object_id
JOIN connect_connector_types t ON t.id = o.connector_type_id
SET op.body_template = 'query($cursor: String) { orders(first: 100, after: $cursor) { edges { node { id name createdAt displayFinancialStatus email phone customer { firstName lastName } currentTotalPriceSet { shopMoney { amount currencyCode } } totalShippingPriceSet { shopMoney { amount } } shippingAddress { address1 city zip countryCodeV2 phone } billingAddress { address1 city zip countryCodeV2 phone } shippingLines(first: 1) { edges { node { taxLines { rate } } } } transactions(first: 1) { id authorizationCode } lineItems(first: 20) { edges { node { title sku quantity originalUnitPriceSet { shopMoney { amount } } taxLines { rate } } } } } } pageInfo { hasNextPage endCursor } } }'
WHERE t.type_key = 'shopify' AND o.object_key = 'order' AND op.operation_type = 'read_list';

UPDATE connect_object_operations op
JOIN connect_objects o ON o.id = op.object_id
JOIN connect_connector_types t ON t.id = o.connector_type_id
SET op.body_template = 'query($id: ID!) { node: order(id: $id) { id name createdAt displayFinancialStatus email phone customer { firstName lastName } currentTotalPriceSet { shopMoney { amount currencyCode } } totalShippingPriceSet { shopMoney { amount } } shippingAddress { address1 city zip countryCodeV2 phone } billingAddress { address1 city zip countryCodeV2 phone } shippingLines(first: 1) { edges { node { taxLines { rate } } } } transactions(first: 1) { id authorizationCode } lineItems(first: 50) { edges { node { title sku quantity originalUnitPriceSet { shopMoney { amount } } taxLines { rate } } } } } }'
WHERE t.type_key = 'shopify' AND o.object_key = 'order' AND op.operation_type = 'read_one';
