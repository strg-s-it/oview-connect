-- =============================================================================
-- connect/migrations/0024_shipping_address_phone.sql
--
-- Bugfix: das Feld "Telefon" fragte bisher Order.phone ab -- das ist ein
-- eigenstaendiges, oft LEERES Feld auf der Bestellung selbst. Die tatsaechlich
-- meist befuellte Telefonnummer liegt bei shippingAddress.phone (die
-- Kontaktnummer fuer die Zustellung, sichtbar in Shopify direkt bei der
-- Lieferadresse). Ergaenzt "phone" in der shippingAddress- UND
-- billingAddress-Unterauswahl beider Operationen (read_list, read_one) und
-- hängt das Feld "Telefon" auf shippingAddress.phone um.
-- =============================================================================

SET @shopify_connector_type_id = (SELECT id FROM connect_connector_types WHERE type_key = 'shopify');
SET @shopify_order_object_id = (SELECT id FROM connect_objects WHERE connector_type_id = @shopify_connector_type_id AND object_key = 'order');

UPDATE connect_object_operations
SET body_template = 'query($cursor: String) { orders(first: 100, after: $cursor) { edges { node { id name createdAt displayFinancialStatus email phone customer { firstName lastName } currentTotalPriceSet { shopMoney { amount currencyCode } } shippingAddress { address1 city zip countryCodeV2 phone } billingAddress { address1 city zip countryCodeV2 phone } transactions(first: 1) { id authorizationCode } lineItems(first: 20) { edges { node { sku quantity originalUnitPriceSet { shopMoney { amount } } taxLines { rate } } } } } } pageInfo { hasNextPage endCursor } } }'
WHERE object_id = @shopify_order_object_id AND operation_type = 'read_list';

UPDATE connect_object_operations
SET body_template = 'query($id: ID!) { node: order(id: $id) { id name createdAt displayFinancialStatus email phone customer { firstName lastName } currentTotalPriceSet { shopMoney { amount currencyCode } } shippingAddress { address1 city zip countryCodeV2 phone } billingAddress { address1 city zip countryCodeV2 phone } transactions(first: 1) { id authorizationCode } lineItems(first: 50) { edges { node { sku quantity originalUnitPriceSet { shopMoney { amount } } taxLines { rate } } } } } }'
WHERE object_id = @shopify_order_object_id AND operation_type = 'read_one';

UPDATE connect_object_fields
SET field_path = 'node.shippingAddress.phone'
WHERE object_id = @shopify_order_object_id AND field_key = 'phone';
