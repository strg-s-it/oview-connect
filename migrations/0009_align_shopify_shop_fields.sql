-- =============================================================================
-- connect/migrations/0009_align_shopify_shop_fields.sql
--
-- Gleicht das Shopify "Shop-Stammdaten"-Object gegen die TATSAECHLICHE
-- Nutzung in der alten Welt ab (inc/functions.php get_shop_information_v2,
-- get_tax) statt gegen eine freie Einschaetzung, welche Felder nuetzlich
-- waeren. Ergaenzt fehlende Felder (shop_owner, address1, plan_display_name)
-- und fuegt ein Steuer-Object hinzu (countries.json -- bewusst REST, da
-- dieser Bereich (Laender-Steuersaetze) nicht von der GraphQL-Umstellung
-- fuer Produkte/Varianten betroffen ist und in der alten Welt bereits
-- erprobt funktioniert).
-- =============================================================================

SET @shopify_connector_type_id = (SELECT id FROM connect_connector_types WHERE type_key = 'shopify');
SET @shopify_shop_object_id = (SELECT id FROM connect_objects WHERE connector_type_id = @shopify_connector_type_id AND object_key = 'shop');

INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_shop_object_id, 'shop_owner', 'Shop-Inhaber', 'string', 'billingAddress.company', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_shop_object_id, 'address1', 'Strasse', 'string', 'billingAddress.address1', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_shop_object_id, 'plan_display_name', 'Tarif', 'string', 'plan.displayName', 0, NULL);

UPDATE connect_object_operations
SET body_template = 'query { shop { name myshopifyDomain email currencyCode ianaTimezone taxesIncluded plan { displayName } billingAddress { company address1 city zip countryCodeV2 } } }'
WHERE object_id = @shopify_shop_object_id AND operation_type = 'read_list';

-- -----------------------------------------------------------------------------
-- Object: Steuer (Laender-Steuersaetze, wie get_tax() in der alten Welt)
-- Bewusst REST statt GraphQL -- siehe Kommentar oben. path_template zeigt
-- direkt auf countries.json, kein body_template (= kein POST-Body noetig,
-- GenericHttpConnector macht dann einen einfachen GET).
-- response_records_path zeigt auf "countries" -- Shopify liefert
-- {"countries":[{...}]} bei diesem REST-Endpunkt.
-- -----------------------------------------------------------------------------
INSERT INTO connect_objects (connector_type_id, object_key, label) VALUES (@shopify_connector_type_id, 'country_tax', 'Länder-Steuersatz');

SET @shopify_tax_object_id = LAST_INSERT_ID();

INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_tax_object_id, 'country_code', 'Land (ISO)', 'string', 'code', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_tax_object_id, 'country_name', 'Land', 'string', 'name', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_tax_object_id, 'tax', 'Steuersatz', 'number', 'tax', 0, NULL);

INSERT INTO connect_object_operations (object_id, operation_type, http_method, path_template, body_template, response_records_path, static_params) VALUES (@shopify_tax_object_id, 'read_list', 'GET', 'admin/api/2026-07/countries.json', NULL, 'countries', NULL);
