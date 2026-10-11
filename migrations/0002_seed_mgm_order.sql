-- =============================================================================
-- connect/migrations/0002_seed_mgm_order.sql
--
-- Seed-Daten fuer den MGM/AGILA-Connector Type: vorerst nur die vollstaendig
-- dokumentierte Funktion "Auftrag anlegen" (GefallAdd). Die Lagerstand-
-- Abfrage folgt in einer spaeteren Migration, sobald der genaue Cmd-Name
-- dafuer bekannt ist (im MGM-Handbuch ist nur das allgemeine dataset-
-- Antwortformat dokumentiert, keine konkrete Abfrage-Funktion).
--
-- Nutzt MySQL/MariaDB-Session-Variablen (SET @var = LAST_INSERT_ID()), um
-- ohne Stored Procedures zwischen den INSERTs auf die jeweils neu erzeugte
-- ID zu verweisen. Funktioniert, weil install.php alle Statements einer
-- Migration ueber dieselbe PDO-Verbindung ausfuehrt (eine MySQL-Session).
-- =============================================================================

-- -----------------------------------------------------------------------------
-- Connector Type: MGM/AGILA ERP
-- Basis-URL ist bewusst NICHT hier hinterlegt (base_url_template = NULL),
-- da sie pro Kunde unterschiedlich ist (eigener Server/eigene IP:Port) --
-- wird stattdessen pro Connection im auth_field_schema abgefragt.
-- -----------------------------------------------------------------------------
INSERT INTO connect_connector_types (type_key, name, description, icon, is_builtin, auth_type, auth_field_schema, request_format, base_url_template) VALUES ('mgm_agila', 'MGM/AGILA ERP', 'XML-Schnittstelle des AGILA-ERP von mgm Software Team GmbH. Basis-URL ist pro Kunde unterschiedlich.', NULL, 1, 'bearer', '[{"key":"base_url","label":"Basis-URL","type":"text","required":true,"placeholder":"https://80.120.83.142:8002"},{"key":"token","label":"JWT-Token","type":"password","required":true}]', 'xml', NULL);

SET @mgm_connector_type_id = LAST_INSERT_ID();

-- -----------------------------------------------------------------------------
-- Object: Auftrag (order)
-- -----------------------------------------------------------------------------
INSERT INTO connect_objects (connector_type_id, object_key, label) VALUES (@mgm_connector_type_id, 'order', 'Auftrag');

SET @mgm_order_object_id = LAST_INSERT_ID();

-- Einfache Felder -- field_path entspricht bewusst dem field_key selbst,
-- da dieser Name direkt als {{platzhalter}} im body_template verwendet wird.
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@mgm_order_object_id, 'datum', 'Datum', 'date', 'datum', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@mgm_order_object_id, 'nakurz', 'NamenID (Land-Mapping)', 'string', 'nakurz', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@mgm_order_object_id, 'bestellnummer', 'Bestellnummer Webshop', 'string', 'bestellnummer', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@mgm_order_object_id, 'zahlungsreferenz', 'Zahlungsreferenz', 'string', 'zahlungsreferenz', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@mgm_order_object_id, 'gesamtbetrag_brutto', 'Gesamtbetrag Brutto', 'number', 'gesamtbetrag_brutto', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@mgm_order_object_id, 'email', 'E-Mail', 'string', 'email', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@mgm_order_object_id, 'telefon', 'Telefon', 'string', 'telefon', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@mgm_order_object_id, 'lieferadresse_name1', 'Lieferadresse Zeile 1', 'string', 'lieferadresse_name1', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@mgm_order_object_id, 'lieferadresse_name2', 'Lieferadresse Zeile 2', 'string', 'lieferadresse_name2', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@mgm_order_object_id, 'lieferadresse_strasse', 'Lieferadresse Strasse', 'string', 'lieferadresse_strasse', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@mgm_order_object_id, 'lieferadresse_land', 'Lieferadresse Land (ISO)', 'string', 'lieferadresse_land', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@mgm_order_object_id, 'lieferadresse_plz', 'Lieferadresse PLZ', 'string', 'lieferadresse_plz', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@mgm_order_object_id, 'lieferadresse_ort', 'Lieferadresse Ort', 'string', 'lieferadresse_ort', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@mgm_order_object_id, 'name_email', 'Rechnungsadresse E-Mail', 'string', 'name_email', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@mgm_order_object_id, 'name_name1', 'Rechnungsadresse Zeile 1', 'string', 'name_name1', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@mgm_order_object_id, 'name_name2', 'Rechnungsadresse Zeile 2', 'string', 'name_name2', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@mgm_order_object_id, 'name_strasse', 'Rechnungsadresse Strasse', 'string', 'name_strasse', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@mgm_order_object_id, 'name_plz', 'Rechnungsadresse PLZ', 'string', 'name_plz', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@mgm_order_object_id, 'name_ort', 'Rechnungsadresse Ort', 'string', 'name_ort', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@mgm_order_object_id, 'name_land', 'Rechnungsadresse Land (ISO)', 'string', 'name_land', 0, NULL);

-- Wiederholende Gruppe: Positionen (Zeilenpositionen)
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@mgm_order_object_id, 'positionen', 'Positionen', 'array', 'positionen', 1, NULL);

SET @mgm_positionen_field_id = LAST_INSERT_ID();

INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@mgm_order_object_id, 'art_nr', 'Artikelnummer (MGM-intern)', 'string', 'art_nr', 0, @mgm_positionen_field_id);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@mgm_order_object_id, 'menge', 'Menge', 'number', 'menge', 0, @mgm_positionen_field_id);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@mgm_order_object_id, 'brutto', 'Brutto-Einzelpreis', 'number', 'brutto', 0, @mgm_positionen_field_id);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES (@mgm_order_object_id, 'ust', 'USt-Code (Mapping)', 'string', 'ust', 0, @mgm_positionen_field_id);

-- -----------------------------------------------------------------------------
-- Operation: create (GefallAdd)
-- GfKreis/GfTyp sind laut Handbuch immer fix ("001"/"AB") -> static_params,
-- nicht als Feld vom Aufrufer erwartet. path_template ist bewusst leer --
-- die komplette XML geht per POST direkt an die Basis-URL.
-- -----------------------------------------------------------------------------
INSERT INTO connect_object_operations (object_id, operation_type, http_method, path_template, body_template, response_records_path, static_params) VALUES (@mgm_order_object_id, 'create', 'POST', '', '<?xml version="1.0"?>\n<mgmDaten>\n<Cmd>GefallAdd</Cmd>\n<Params>\n<Gefall>\n<GfKreis>{{GfKreis}}</GfKreis>\n<GfTyp>{{GfTyp}}</GfTyp>\n<Datum>{{datum}}</Datum>\n<Nakurz>{{nakurz}}</Nakurz>\n<Zusatz>\n<gf_bnummer>{{bestellnummer}}</gf_bnummer>\n<gf_text>{{zahlungsreferenz}}</gf_text>\n<gf_btr1>{{gesamtbetrag_brutto}}</gf_btr1>\n<gf__email>{{email}}</gf__email>\n<gf__tel1>{{telefon}}</gf__tel1>\n<gf__la_name1>{{lieferadresse_name1}}</gf__la_name1>\n<gf__la_name2>{{lieferadresse_name2}}</gf__la_name2>\n<gf__la_str>{{lieferadresse_strasse}}</gf__la_str>\n<gf__la_int>{{lieferadresse_land}}</gf__la_int>\n<gf__la_plz>{{lieferadresse_plz}}</gf__la_plz>\n<gf__la_ort>{{lieferadresse_ort}}</gf__la_ort>\n</Zusatz>\n</Gefall>\n<Name>\n<EMail>{{name_email}}</EMail>\n<Name1>{{name_name1}}</Name1>\n<Name2>{{name_name2}}</Name2>\n<Str>{{name_strasse}}</Str>\n<Plz>{{name_plz}}</Plz>\n<Ort>{{name_ort}}</Ort>\n<Int>{{name_land}}</Int>\n</Name>\n<Positionen>\n{{#each positionen}}\n<zl ArtNr="{{art_nr}}" Menge="{{menge}}" Brutto="{{brutto}}" Ust="{{ust}}"/>\n{{/each}}\n</Positionen>\n</Params>\n</mgmDaten>', NULL, '{"GfKreis":"001","GfTyp":"AB"}');
