-- =============================================================================
-- connect/migrations/0067_document_pipeline.sql
--
-- Umbau "Rechnungen/Belege ueber die Oberflaeche statt im Code" (Plan vom
-- 2026-10-01, Phase 2). Bisher war die HelloCash-Rechnung komplett im Code
-- (Connect\Sync\InvoiceService), der MGM-Auftrag lief dagegen ueber eine
-- Mapping-Vorlage direkt auf die rohen Shopify-Felder (ohne Rabatte, Steuer-
-- Korrekturen und Kontrollen).
--
-- Neu:
--   1. Connector Type "connect_internal" (Connect intern, kein HTTP, bekommt
--      nie eine Connection) mit dem Object "order_prepared" = "Bestellung
--      (aufbereitet)". Seine Felder sind die Ausgabe von Connect\Sync\Document\
--      OrderPreparer: alle Shopify-Eigenheiten (Rabatte, taxable false, Netto/
--      Brutto, Versand-Aufteilung) sind darin bereits aufgeloest. Es dient in
--      Mapping-Vorlagen als QUELLE, z.B. "Bestellung (aufbereitet) -> HelloCash
--      Rechnung" oder "-> MGM Auftrag".
--      field_path = field_key, da der Datensatz von Connect selbst gebaut wird
--      (nicht aus einer API-Antwort gelesen).
--   2. connect_objects.document_profile: Belegprofil des Ziel-Objects (JSON,
--      siehe Connect\Sync\Document\DocumentProfile) -- Rabattart, Rundung,
--      Antwortpfade. Wird im Connector Builder gepflegt (Phase 3).
--
-- Aendert noch NICHTS am Verhalten: die bestehende HelloCash-Rechnung laeuft
-- weiter ueber InvoiceService, bis eine Vorlage auf Basis von order_prepared
-- angelegt und aktiviert wird.
-- =============================================================================

ALTER TABLE connect_objects
    ADD COLUMN document_profile LONGTEXT NULL COMMENT 'Belegprofil (JSON) fuer Rechnungen/Belege, siehe Connect\\Sync\\Document\\DocumentProfile';

INSERT INTO connect_connector_types (type_key, name, description, icon, is_builtin, auth_type, auth_field_schema, request_format, base_url_template)
VALUES ('connect_internal', 'Connect (intern)', 'Von Connect selbst aufbereitete Daten, z.B. die Shopify-Bestellung mit bereits aufgeloesten Rabatten und Steuern als Quelle fuer Rechnungen. Kein externes System, bekommt keine Connection.', NULL, 1, 'none', NULL, 'json', NULL);

SET @internal_type_id = LAST_INSERT_ID();

INSERT INTO connect_objects (connector_type_id, object_key, label) VALUES (@internal_type_id, 'order_prepared', 'Bestellung (aufbereitet)');

SET @prepared_object_id = LAST_INSERT_ID();

-- Kopffelder
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES
    (@prepared_object_id, 'order_number', 'Bestellnummer (z.B. #1153)', 'string', 'order_number', 0, NULL),
    (@prepared_object_id, 'created_at', 'Bestelldatum', 'date', 'created_at', 0, NULL),
    (@prepared_object_id, 'source_name', 'Bestellquelle (web, pos, ...)', 'string', 'source_name', 0, NULL),
    (@prepared_object_id, 'currency', 'Waehrung', 'string', 'currency', 0, NULL),
    (@prepared_object_id, 'email', 'E-Mail', 'string', 'email', 0, NULL),
    (@prepared_object_id, 'phone', 'Telefon', 'string', 'phone', 0, NULL),
    (@prepared_object_id, 'customer_first_name', 'Kunde Vorname', 'string', 'customer_first_name', 0, NULL),
    (@prepared_object_id, 'customer_last_name', 'Kunde Nachname', 'string', 'customer_last_name', 0, NULL),
    (@prepared_object_id, 'customer_full_name', 'Kunde Name (Vor- und Nachname)', 'string', 'customer_full_name', 0, NULL),
    (@prepared_object_id, 'billing_address1', 'Rechnungsadresse Strasse', 'string', 'billing_address1', 0, NULL),
    (@prepared_object_id, 'billing_zip', 'Rechnungsadresse PLZ', 'string', 'billing_zip', 0, NULL),
    (@prepared_object_id, 'billing_city', 'Rechnungsadresse Ort', 'string', 'billing_city', 0, NULL),
    (@prepared_object_id, 'billing_country', 'Rechnungsadresse Land (ISO, z.B. AT)', 'string', 'billing_country', 0, NULL),
    (@prepared_object_id, 'shipping_address1', 'Lieferadresse Strasse', 'string', 'shipping_address1', 0, NULL),
    (@prepared_object_id, 'shipping_zip', 'Lieferadresse PLZ', 'string', 'shipping_zip', 0, NULL),
    (@prepared_object_id, 'shipping_city', 'Lieferadresse Ort', 'string', 'shipping_city', 0, NULL),
    (@prepared_object_id, 'shipping_country', 'Lieferadresse Land (ISO, z.B. AT)', 'string', 'shipping_country', 0, NULL),
    (@prepared_object_id, 'address1', 'Adresse Strasse (Rechnung, sonst Lieferung)', 'string', 'address1', 0, NULL),
    (@prepared_object_id, 'zip', 'Adresse PLZ (Rechnung, sonst Lieferung)', 'string', 'zip', 0, NULL),
    (@prepared_object_id, 'city', 'Adresse Ort (Rechnung, sonst Lieferung)', 'string', 'city', 0, NULL),
    (@prepared_object_id, 'country', 'Adresse Land ISO (Rechnung, sonst Lieferung)', 'string', 'country', 0, NULL),
    (@prepared_object_id, 'payment_transaction_id', 'Zahlung Transaktions-ID', 'string', 'payment_transaction_id', 0, NULL),
    (@prepared_object_id, 'payment_authorization_code', 'Zahlung Autorisierungscode', 'string', 'payment_authorization_code', 0, NULL),
    (@prepared_object_id, 'total_gross', 'Gesamtbetrag brutto (laut Shopify)', 'number', 'total_gross', 0, NULL),
    (@prepared_object_id, 'total_tax', 'Steuersumme (laut Shopify)', 'number', 'total_tax', 0, NULL),
    (@prepared_object_id, 'target_customer_id', 'Kunden-ID im Zielsystem (aus Kunden-Vorschritt)', 'string', 'target_customer_id', 0, NULL),
    (@prepared_object_id, 'setting_cashier_id', 'Shop-Einstellung: Kassier-ID', 'string', 'setting_cashier_id', 0, NULL),
    (@prepared_object_id, 'setting_payment_method', 'Shop-Einstellung: Zahlungsart', 'string', 'setting_payment_method', 0, NULL),
    (@prepared_object_id, 'lines', 'Positionen (Artikel und Versand)', 'array', 'lines', 1, NULL);

SET @lines_field_id = (SELECT id FROM connect_object_fields WHERE object_id = @prepared_object_id AND field_key = 'lines');

-- Positionen (Artikel UND Versand, siehe OrderPreparer)
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES
    (@prepared_object_id, 'name', 'Bezeichnung (mit Variante, Versand = "Versand")', 'string', 'name', 0, @lines_field_id),
    (@prepared_object_id, 'title', 'Produkttitel (ohne Variante)', 'string', 'title', 0, @lines_field_id),
    (@prepared_object_id, 'sku', 'SKU', 'string', 'sku', 0, @lines_field_id),
    (@prepared_object_id, 'barcode', 'Barcode', 'string', 'barcode', 0, @lines_field_id),
    (@prepared_object_id, 'is_shipping', 'Ist Versand', 'bool', 'is_shipping', 0, @lines_field_id),
    (@prepared_object_id, 'quantity', 'Menge (Shopify)', 'number', 'quantity', 0, @lines_field_id),
    (@prepared_object_id, 'unit_price_original', 'Stueckpreis vor Rabatt', 'number', 'unit_price_original', 0, @lines_field_id),
    (@prepared_object_id, 'line_total', 'Positionssumme nach Rabatt (brutto)', 'number', 'line_total', 0, @lines_field_id),
    (@prepared_object_id, 'line_total_net', 'Positionssumme nach Rabatt (netto)', 'number', 'line_total_net', 0, @lines_field_id),
    (@prepared_object_id, 'discount_amount', 'Rabatt gesamt (Betrag)', 'number', 'discount_amount', 0, @lines_field_id),
    (@prepared_object_id, 'tax_rate_percent', 'Steuersatz in Prozent (20)', 'number', 'tax_rate_percent', 0, @lines_field_id),
    (@prepared_object_id, 'tax_rate_fraction', 'Steuersatz als Anteil (0.2)', 'number', 'tax_rate_fraction', 0, @lines_field_id),
    (@prepared_object_id, 'tax_amount', 'Steuerbetrag (laut Shopify)', 'number', 'tax_amount', 0, @lines_field_id),
    (@prepared_object_id, 'target_article_id', 'Artikel-ID im Zielsystem (Artikelabgleich)', 'string', 'target_article_id', 0, @lines_field_id),
    (@prepared_object_id, 'target_article_type', 'Artikel-Typ ("article", nur wenn Artikel gefunden)', 'string', 'target_article_type', 0, @lines_field_id),
    (@prepared_object_id, 'out_quantity', 'Ausgabe: Menge (nach Belegprofil)', 'number', 'out_quantity', 0, @lines_field_id),
    (@prepared_object_id, 'out_price', 'Ausgabe: Stueckpreis (nach Belegprofil)', 'number', 'out_price', 0, @lines_field_id),
    (@prepared_object_id, 'out_discount_unit', 'Ausgabe: Rabattart percent/absolute (nach Belegprofil)', 'string', 'out_discount_unit', 0, @lines_field_id),
    (@prepared_object_id, 'out_discount_value', 'Ausgabe: Rabattwert (nach Belegprofil)', 'number', 'out_discount_value', 0, @lines_field_id),
    (@prepared_object_id, 'out_line_total', 'Ausgabe: Positionssumme nach Rabatt (brutto)', 'number', 'out_line_total', 0, @lines_field_id);
