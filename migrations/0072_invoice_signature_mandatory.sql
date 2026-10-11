-- =============================================================================
-- connect/migrations/0072_invoice_signature_mandatory.sql
--
-- Signaturpflicht der Rechnung (HelloCash v2: signature_mandatory, Standard false).
-- Die alte Welt schickte das Feld bei jeder Rechnung mit (Einstellung je Shop,
-- Standard true), Connect bisher nie. Neu:
--   connect_shops.invoice_signature_mandatory  Einstellung je Shop (Einstellungen ->
--                                              Rechnungen, Umzug uebernimmt den Wert)
--   Feld setting_signature_mandatory           in "Bestellung (aufbereitet)", fuer die
--                                              Beleg-Vorlage (Zielfeld signature_mandatory)
-- Standard 0 = unveraendertes Verhalten fuer bestehende Connect-Shops.
--
-- Hinweis: der Installer trennt am Semikolon -- deshalb keines in Strings oder Kommentaren.
-- =============================================================================

ALTER TABLE connect_shops
    ADD COLUMN invoice_signature_mandatory TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Rechnung mit Signaturpflicht anlegen (HelloCash signature_mandatory)';

INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id)
SELECT o.id, 'setting_signature_mandatory', 'Shop-Einstellung: Signatur erforderlich (true/false)', 'bool', 'setting_signature_mandatory', 0, NULL
FROM connect_objects o
JOIN connect_connector_types t ON t.id = o.connector_type_id
WHERE t.type_key = 'connect_internal' AND o.object_key = 'order_prepared'
  AND NOT EXISTS (SELECT 1 FROM connect_object_fields f WHERE f.object_id = o.id AND f.field_key = 'setting_signature_mandatory');
