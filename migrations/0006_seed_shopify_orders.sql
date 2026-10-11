-- =============================================================================
-- connect/migrations/0006_seed_shopify_orders.sql
--
-- Shopify-Object "Bestellung" (order) via GraphQL -- nutzt die bereits
-- vorhandenen Scopes read_orders/read_all_orders. Wird als Vorlage fuer die
-- spaetere Shopify-Bestellung -> MGM-Auftrag-Kette gebraucht, aktuell nur
-- zum Lesen/Vorschauen (kein Sync Mapping noch).
--
-- Feldnamen gegen die echte Shopify GraphQL Admin API (2026-07) noch mit
-- einem echten Shop verifizieren (siehe geplanter Test mit Crystalp) --
-- displayFinancialStatus/lineItems-Struktur nach bestem Wissen, aber ohne
-- Live-Gegenprobe an dieser Stelle.
-- =============================================================================

SET @shopify_connector_type_id = (SELECT id FROM connect_connector_types WHERE type_key = 'shopify');

INSERT INTO connect_objects (connector_type_id, object_key, label) VALUES (@shopify_connector_type_id, 'order', 'Bestellung');

SET @shopify_order_object_id = LAST_INSERT_ID();

INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_order_object_id, 'shopify_id', 'Shopify-ID', 'string', 'node.id', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_order_object_id, 'order_number', 'Bestellnummer', 'string', 'node.name', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_order_object_id, 'created_at', 'Erstellt am', 'string', 'node.createdAt', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_order_object_id, 'financial_status', 'Zahlungsstatus', 'string', 'node.displayFinancialStatus', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_order_object_id, 'email', 'E-Mail', 'string', 'node.email', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_order_object_id, 'total_price', 'Gesamtbetrag', 'number', 'node.currentTotalPriceSet.shopMoney.amount', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_order_object_id, 'currency', 'Waehrung', 'string', 'node.currentTotalPriceSet.shopMoney.currencyCode', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_order_object_id, 'customer_first_name', 'Kunde Vorname', 'string', 'node.customer.firstName', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_order_object_id, 'customer_last_name', 'Kunde Nachname', 'string', 'node.customer.lastName', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_order_object_id, 'shipping_address1', 'Lieferadresse Strasse', 'string', 'node.shippingAddress.address1', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_order_object_id, 'shipping_city', 'Lieferadresse Ort', 'string', 'node.shippingAddress.city', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_order_object_id, 'shipping_zip', 'Lieferadresse PLZ', 'string', 'node.shippingAddress.zip', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_order_object_id, 'shipping_country', 'Lieferadresse Land (ISO)', 'string', 'node.shippingAddress.countryCodeV2', 0, NULL);

-- Wiederholende Gruppe: Zeilenpositionen
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_order_object_id, 'line_items', 'Positionen', 'array', 'node.lineItems.edges', 1, NULL);

SET @shopify_line_items_field_id = LAST_INSERT_ID();

INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_order_object_id, 'line_sku', 'SKU', 'string', 'node.sku', 0, @shopify_line_items_field_id);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_order_object_id, 'line_quantity', 'Menge', 'number', 'node.quantity', 0, @shopify_line_items_field_id);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_order_object_id, 'line_price', 'Einzelpreis', 'number', 'node.originalUnitPriceSet.shopMoney.amount', 0, @shopify_line_items_field_id);

INSERT INTO connect_object_operations (object_id, operation_type, http_method, path_template, body_template, response_records_path, static_params) VALUES (@shopify_order_object_id, 'read_list', 'POST', 'admin/api/2026-07/graphql.json', 'query { orders(first: 20) { edges { node { id name createdAt displayFinancialStatus email customer { firstName lastName } currentTotalPriceSet { shopMoney { amount currencyCode } } shippingAddress { address1 city zip countryCodeV2 } lineItems(first: 20) { edges { node { sku quantity originalUnitPriceSet { shopMoney { amount } } } } } } } pageInfo { hasNextPage endCursor } } }', 'data.orders.edges', NULL);
