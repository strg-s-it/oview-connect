-- =============================================================================
-- connect/migrations/0021_order_billing_payment_tax_fields.sql
--
-- Ergaenzt das Shopify-"order"-Object um Felder, die fuer die MGM-Auftrags-
-- Uebersetzung (GefallAdd, siehe Mapping-Vorlage "Shopify-Bestellung -> MGM-
-- Auftrag") noch fehlten:
--   - phone: Telefonnummer (MGM gf__tel1)
--   - billingAddress: ECHTE Rechnungsadresse, bisher gab es nur shippingAddress
--     (MGM Name-Node = Rechnungsadresse, Zusatz.gf__la_* = Lieferadresse --
--     beide muessen unterscheidbar sein)
--   - transactions(first: 1): Zahlungs-Transaktions-ID/Autorisierungscode
--     (MGM gf_text, Zahlungsreferenz fuers spaetere BMD-Matching)
--   - line_tax_rate (taxLines.rate je Zeilenposition): fuer den UST-Code-
--     Lookup je Position (MGM zl/Ust)
--
-- WICHTIG: line_tax_rate liefert Shopify als Dezimalzahl (z.B. 0.2 fuer 20%).
-- Vor dem Anlegen der Lookup-Tabelle "Steuersatz -> UST-Code" den tatsaechlich
-- gelieferten Wert einmal live pruefen (Daten ansehen -> Vorschau), da der
-- exakte String (z.B. "0.2" vs. "0.20") fuer den Lookup-Treffer exakt passen
-- muss.
-- =============================================================================

SET @shopify_connector_type_id = (SELECT id FROM connect_connector_types WHERE type_key = 'shopify');
SET @shopify_order_object_id = (SELECT id FROM connect_objects WHERE connector_type_id = @shopify_connector_type_id AND object_key = 'order');
SET @shopify_line_items_field_id = (SELECT id FROM connect_object_fields WHERE object_id = @shopify_order_object_id AND field_key = 'line_items');

INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES
    (@shopify_order_object_id, 'phone', 'Telefon', 'string', 'node.phone', 0, NULL),
    (@shopify_order_object_id, 'billing_address1', 'Rechnungsadresse Strasse', 'string', 'node.billingAddress.address1', 0, NULL),
    (@shopify_order_object_id, 'billing_city', 'Rechnungsadresse Ort', 'string', 'node.billingAddress.city', 0, NULL),
    (@shopify_order_object_id, 'billing_zip', 'Rechnungsadresse PLZ', 'string', 'node.billingAddress.zip', 0, NULL),
    (@shopify_order_object_id, 'billing_country', 'Rechnungsadresse Land (ISO)', 'string', 'node.billingAddress.countryCodeV2', 0, NULL),
    (@shopify_order_object_id, 'payment_transaction_id', 'Zahlungs-Transaktions-ID', 'string', 'node.transactions.0.id', 0, NULL),
    (@shopify_order_object_id, 'payment_authorization_code', 'Zahlungs-Autorisierungscode', 'string', 'node.transactions.0.authorizationCode', 0, NULL);

INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES
    (@shopify_order_object_id, 'line_tax_rate', 'Steuersatz', 'number', 'node.taxLines.0.rate', 0, @shopify_line_items_field_id);

-- read_list (Liste/Vorschau/Cache-Sync) -- Basis ist die per Migration 0010
-- cursor-paginierte Fassung, hier um die neuen Felder ergaenzt.
UPDATE connect_object_operations
SET body_template = 'query($cursor: String) { orders(first: 100, after: $cursor) { edges { node { id name createdAt displayFinancialStatus email phone customer { firstName lastName } currentTotalPriceSet { shopMoney { amount currencyCode } } shippingAddress { address1 city zip countryCodeV2 } billingAddress { address1 city zip countryCodeV2 } transactions(first: 1) { id authorizationCode } lineItems(first: 20) { edges { node { sku quantity originalUnitPriceSet { shopMoney { amount } } taxLines { rate } } } } } } pageInfo { hasNextPage endCursor } } }'
WHERE object_id = @shopify_order_object_id AND operation_type = 'read_list';

-- read_one (Webhook-Einzelabruf, siehe Migration 0018) -- gleiches Alias-
-- Muster, dieselben neuen Felder ergaenzt.
UPDATE connect_object_operations
SET body_template = 'query($id: ID!) { node: order(id: $id) { id name createdAt displayFinancialStatus email phone customer { firstName lastName } currentTotalPriceSet { shopMoney { amount currencyCode } } shippingAddress { address1 city zip countryCodeV2 } billingAddress { address1 city zip countryCodeV2 } transactions(first: 1) { id authorizationCode } lineItems(first: 50) { edges { node { sku quantity originalUnitPriceSet { shopMoney { amount } } taxLines { rate } } } } } }'
WHERE object_id = @shopify_order_object_id AND operation_type = 'read_one';
