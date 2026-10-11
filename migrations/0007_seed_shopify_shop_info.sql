-- =============================================================================
-- connect/migrations/0007_seed_shopify_shop_info.sql
--
-- Shopify-Basisinfos, die (wie in der alten Welt, siehe index.php/
-- dashboard.php: get_shop_information_v2, get_tax, locations.json) oft
-- gebraucht werden: Shop-Stammdaten (Name, Domain, Waehrung, Zeitzone,
-- Steuer-Einstellung), Standorte, Sprachen.
--
-- "shop" ist als Object ein EINZELNES Objekt, keine Liste -- moeglich seit
-- der array_is_list()-Erweiterung in GenericHttpConnector::read(). Deshalb
-- hier KEIN "node."-Praefix bei den field_path-Werten (im Unterschied zu
-- den edges-Listen wie Produkt/Kategorie/Bestellung).
--
-- Feldnamen (v.a. shopLocales) noch mit einem echten Shop verifizieren --
-- ohne Live-Gegenprobe an dieser Stelle, siehe "Daten ansehen".
-- =============================================================================

SET @shopify_connector_type_id = (SELECT id FROM connect_connector_types WHERE type_key = 'shopify');

-- -----------------------------------------------------------------------------
-- Object: Shop (Stammdaten, einzelnes Objekt statt Liste)
-- -----------------------------------------------------------------------------
INSERT INTO connect_objects (connector_type_id, object_key, label) VALUES (@shopify_connector_type_id, 'shop', 'Shop-Stammdaten');

SET @shopify_shop_object_id = LAST_INSERT_ID();

INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_shop_object_id, 'name', 'Name', 'string', 'name', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_shop_object_id, 'domain', 'Domain', 'string', 'myshopifyDomain', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_shop_object_id, 'email', 'E-Mail', 'string', 'email', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_shop_object_id, 'currency', 'Waehrung', 'string', 'currencyCode', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_shop_object_id, 'timezone', 'Zeitzone', 'string', 'ianaTimezone', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_shop_object_id, 'taxes_included', 'Preise inkl. Steuer', 'bool', 'taxesIncluded', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_shop_object_id, 'city', 'Ort', 'string', 'billingAddress.city', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_shop_object_id, 'zip', 'PLZ', 'string', 'billingAddress.zip', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_shop_object_id, 'country', 'Land (ISO)', 'string', 'billingAddress.countryCodeV2', 0, NULL);

INSERT INTO connect_object_operations (object_id, operation_type, http_method, path_template, body_template, response_records_path, static_params) VALUES (@shopify_shop_object_id, 'read_list', 'POST', 'admin/api/2026-07/graphql.json', 'query { shop { name myshopifyDomain email currencyCode ianaTimezone taxesIncluded billingAddress { city zip countryCodeV2 } } }', 'data.shop', NULL);

-- -----------------------------------------------------------------------------
-- Object: Standort (Location) -- Liste, wie in der alten Welt fuer
-- Mehrstandort-Shops (siehe dashboard.php, hellocash_binding_locations)
-- -----------------------------------------------------------------------------
INSERT INTO connect_objects (connector_type_id, object_key, label) VALUES (@shopify_connector_type_id, 'location', 'Standort');

SET @shopify_location_object_id = LAST_INSERT_ID();

INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_location_object_id, 'shopify_id', 'Shopify-ID', 'string', 'node.id', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_location_object_id, 'name', 'Name', 'string', 'node.name', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_location_object_id, 'is_active', 'Aktiv', 'bool', 'node.isActive', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_location_object_id, 'city', 'Ort', 'string', 'node.address.city', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_location_object_id, 'zip', 'PLZ', 'string', 'node.address.zip', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_location_object_id, 'country', 'Land (ISO)', 'string', 'node.address.countryCode', 0, NULL);

INSERT INTO connect_object_operations (object_id, operation_type, http_method, path_template, body_template, response_records_path, static_params) VALUES (@shopify_location_object_id, 'read_list', 'POST', 'admin/api/2026-07/graphql.json', 'query { locations(first: 50) { edges { node { id name isActive address { city zip countryCode } } } } }', 'data.locations.edges', NULL);

-- -----------------------------------------------------------------------------
-- Object: Sprache (shopLocales -- liefert bereits eine flache Liste, kein
-- edges-Wrapper, deshalb field_path ohne "node."-Praefix)
-- -----------------------------------------------------------------------------
INSERT INTO connect_objects (connector_type_id, object_key, label) VALUES (@shopify_connector_type_id, 'language', 'Sprache');

SET @shopify_language_object_id = LAST_INSERT_ID();

INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_language_object_id, 'locale', 'Sprachcode', 'string', 'locale', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_language_object_id, 'is_primary', 'Standardsprache', 'bool', 'primary', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@shopify_language_object_id, 'published', 'Veroeffentlicht', 'bool', 'published', 0, NULL);

INSERT INTO connect_object_operations (object_id, operation_type, http_method, path_template, body_template, response_records_path, static_params) VALUES (@shopify_language_object_id, 'read_list', 'POST', 'admin/api/2026-07/graphql.json', 'query { shopLocales { locale primary published } }', 'data.shopLocales', NULL);
