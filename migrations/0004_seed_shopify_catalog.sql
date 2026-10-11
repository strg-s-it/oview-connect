-- =============================================================================
-- connect/migrations/0004_seed_shopify_catalog.sql
--
-- Shopify als Connector Type -- Objekte "Produkt" und "Kategorie" via
-- GraphQL Admin API (REST fuer Produkte/Varianten ist seit Anfang 2025 fuer
-- Public Apps abgeschaltet, siehe Recherche). body_template enthaelt die
-- GraphQL-Query als reinen Text (keine {{platzhalter}} -- GraphQL nutzt
-- seine eigenen $variablen, siehe GenericHttpConnector::read()).
--
-- Auth: Shopify erwartet den Header "X-Shopify-Access-Token" (nicht
-- "Authorization: Bearer") -- abgebildet ueber auth_type=api_key mit einem
-- FIXEN header_name-Feld (type=hidden) im auth_field_schema.
--
-- Pagination (GraphQL cursor/pageInfo) ist hier noch NICHT umgesetzt --
-- MVP liest die erste Seite (bis zu 50 Datensaetze); echte Pagination
-- kommt mit dem Sync-Runner (Phase 4).
-- =============================================================================

INSERT INTO connect_connector_types (type_key, name, description, icon, is_builtin, auth_type, auth_field_schema, request_format, base_url_template) VALUES ('shopify', 'Shopify', 'Shopify Admin API (GraphQL). Basis-URL ist der Shop selbst (https://{shop}.myshopify.com).', NULL, 1, 'api_key', '[{"key":"header_name","type":"hidden","value":"X-Shopify-Access-Token"},{"key":"base_url","label":"Shop-Domain (Basis-URL)","type":"text","required":true,"placeholder":"https://mein-shop.myshopify.com"},{"key":"header_value","label":"Access Token","type":"password","required":true}]', 'json', NULL);

SET @shopify_connector_type_id = LAST_INSERT_ID();

-- -----------------------------------------------------------------------------
-- Object: Produkt
-- -----------------------------------------------------------------------------
INSERT INTO connect_objects (connector_type_id, object_key, label) VALUES (@shopify_connector_type_id, 'product', 'Produkt');

SET @shopify_product_object_id = LAST_INSERT_ID();

INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_product_object_id, 'shopify_id', 'Shopify-ID', 'string', 'node.id', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_product_object_id, 'title', 'Titel', 'string', 'node.title', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_product_object_id, 'handle', 'Handle', 'string', 'node.handle', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_product_object_id, 'vendor', 'Hersteller', 'string', 'node.vendor', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_product_object_id, 'product_type', 'Produktart', 'string', 'node.productType', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_product_object_id, 'status', 'Status', 'string', 'node.status', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_product_object_id, 'sku', 'SKU (erste Variante)', 'string', 'node.variants.edges.0.node.sku', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_product_object_id, 'barcode', 'Barcode (erste Variante)', 'string', 'node.variants.edges.0.node.barcode', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_product_object_id, 'price', 'Preis (erste Variante)', 'number', 'node.variants.edges.0.node.price', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_product_object_id, 'inventory_quantity', 'Lagerbestand (erste Variante)', 'number', 'node.variants.edges.0.node.inventoryQuantity', 0, NULL);

INSERT INTO connect_object_operations (object_id, operation_type, http_method, path_template, body_template, response_records_path, static_params) VALUES (@shopify_product_object_id, 'read_list', 'POST', 'admin/api/2026-07/graphql.json', 'query { products(first: 50) { edges { node { id title handle vendor productType status variants(first: 1) { edges { node { id sku barcode price inventoryQuantity } } } } } pageInfo { hasNextPage endCursor } } }', 'data.products.edges', NULL);

-- -----------------------------------------------------------------------------
-- Object: Kategorie (Shopify-Collection, custom + smart zusammengefasst)
-- -----------------------------------------------------------------------------
INSERT INTO connect_objects (connector_type_id, object_key, label) VALUES (@shopify_connector_type_id, 'collection', 'Kategorie');

SET @shopify_collection_object_id = LAST_INSERT_ID();

INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_collection_object_id, 'shopify_id', 'Shopify-ID', 'string', 'node.id', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_collection_object_id, 'title', 'Titel', 'string', 'node.title', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_collection_object_id, 'handle', 'Handle', 'string', 'node.handle', 0, NULL);

INSERT INTO connect_object_operations (object_id, operation_type, http_method, path_template, body_template, response_records_path, static_params) VALUES (@shopify_collection_object_id, 'read_list', 'POST', 'admin/api/2026-07/graphql.json', 'query { collections(first: 50) { edges { node { id title handle } } pageInfo { hasNextPage endCursor } } }', 'data.collections.edges', NULL);
