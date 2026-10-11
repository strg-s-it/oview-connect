-- =============================================================================
-- connect/migrations/0058_invoice_pos_only_and_suppress.sql
--
-- Nutzer-Entscheidung (2026-09-27): die beiden in der alten Welt (settings.php/
-- jobs/cron_sync_invoice.php) vorhandenen Sondermodi fuer die Rechnungserstellung
-- werden nachgebaut ("hatte sicher einen Sinn") -- Pruefung ergab: mindestens
-- fuer den Shop "neherashop" ist die komplette Rechnungssynchronisation
-- deaktiviert (siehe jobs/debug_fetch_shopify_invoices_neherashop.php,
-- Kommentar "da für nehera die Rechnungssynchronisation ... deaktiviert ist").
--
--   - invoice_pos_only: nur Kassenbestellungen (Shopify POS) bekommen
--     automatisch eine Rechnung, Online-Bestellungen werden ignoriert.
--   - invoice_suppress_and_adjust_stock: es wird GAR KEINE Rechnung angelegt,
--     stattdessen nur der Lagerbestand im Zielsystem je verkaufter Position
--     reduziert (fuer Haendler, die anderswo -- z.B. eigenes Kassensystem --
--     bereits eine Rechnung/einen Beleg erzeugen und oView nur zur
--     Lagerbestandsfuehrung nutzen wollen).
--
-- Fuer invoice_pos_only wird Shopifys "sourceName" je Bestellung benoetigt
-- (Web-Shop = "web", Shopify POS = "pos") -- am Shopify-"Auftrag"-Object bisher
-- nicht vorhanden, wird hier ergaenzt.
--
-- invoice_suppress_and_adjust_stock braucht KEINE neuen Felder -- nutzt
-- dieselbe Artikel-Ermittlung wie die normale Positions-Zuordnung
-- (Connect\Sync\InvoiceService::findArticleMatchFields()), schreibt aber ueber
-- eine vom Admin im Connector Builder anzulegende Operation "adjust_stock" auf
-- dem Artikel-Object (siehe InvoiceService-Kommentar zu adjustStockForOrder()
-- fuer die genaue Einrichtung, z.B. bei HelloCash: POST auf
-- "articles/{{id}}/change-stock", kein body_template).
--
-- Hinweis: der Installer trennt am Semikolon -- deshalb keines in Strings.
-- =============================================================================

ALTER TABLE connect_shops
    ADD COLUMN invoice_pos_only TINYINT(1) NOT NULL DEFAULT 0 AFTER invoice_creation_enabled,
    ADD COLUMN invoice_suppress_and_adjust_stock TINYINT(1) NOT NULL DEFAULT 0 AFTER invoice_pos_only;

SET @shopify_order_object_id = (
    SELECT o.id FROM connect_objects o
    JOIN connect_connector_types t ON t.id = o.connector_type_id
    WHERE t.type_key = 'shopify' AND o.object_key = 'order'
);

INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES
    (@shopify_order_object_id, 'source_name', 'Bestellquelle', 'string', 'node.sourceName', 0, NULL);

UPDATE connect_object_operations op
JOIN connect_objects o ON o.id = op.object_id
JOIN connect_connector_types t ON t.id = o.connector_type_id
SET op.body_template = 'query($cursor: String) { orders(first: 100, after: $cursor) { edges { node { id name createdAt displayFinancialStatus sourceName email phone customer { firstName lastName } currentTotalPriceSet { shopMoney { amount currencyCode } } totalShippingPriceSet { shopMoney { amount } } totalTaxSet { shopMoney { amount } } shippingAddress { address1 city zip countryCodeV2 phone } billingAddress { address1 city zip countryCodeV2 phone } shippingLines(first: 1) { edges { node { taxLines { rate priceSet { shopMoney { amount } } } } } } transactions(first: 1) { id authorizationCode } lineItems(first: 20) { edges { node { title sku quantity originalUnitPriceSet { shopMoney { amount } } taxLines { rate } variant { barcode } } } } } } pageInfo { hasNextPage endCursor } } }'
WHERE t.type_key = 'shopify' AND o.object_key = 'order' AND op.operation_type = 'read_list';

UPDATE connect_object_operations op
JOIN connect_objects o ON o.id = op.object_id
JOIN connect_connector_types t ON t.id = o.connector_type_id
SET op.body_template = 'query($id: ID!) { node: order(id: $id) { id name createdAt displayFinancialStatus sourceName email phone customer { firstName lastName } currentTotalPriceSet { shopMoney { amount currencyCode } } totalShippingPriceSet { shopMoney { amount } } totalTaxSet { shopMoney { amount } } shippingAddress { address1 city zip countryCodeV2 phone } billingAddress { address1 city zip countryCodeV2 phone } shippingLines(first: 1) { edges { node { taxLines { rate priceSet { shopMoney { amount } } } } } } transactions(first: 1) { id authorizationCode } lineItems(first: 50) { edges { node { title sku quantity originalUnitPriceSet { shopMoney { amount } } taxLines { rate } variant { barcode } } } } } }'
WHERE t.type_key = 'shopify' AND o.object_key = 'order' AND op.operation_type = 'read_one';
