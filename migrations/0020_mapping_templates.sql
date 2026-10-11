-- =============================================================================
-- connect/migrations/0020_mapping_templates.sql
--
-- Loest Feld-Mappings von der (zwangslaeufig shop-gebundenen) Sync-Mapping-
-- Zeile und bindet sie stattdessen an eine neue, GLOBALE "Mapping-Vorlage"
-- (connect_mapping_templates): Quell-Connector-Type+Object -> Ziel-Connector-
-- Type+Object. Das entspricht der bereits bestehenden Modellierung von
-- Objects/Fields/Operations im Connector Builder (ebenfalls Connector-Type-
-- Ebene, nicht Connection-Ebene) -- die Feld-Zuordnung war die einzige Stelle,
-- die faelschlich pro Shop haette gepflegt werden muessen (z.B. allgemeine
-- HelloCash-Vorlagen waeren sonst fuer jeden Shop einzeln noetig gewesen).
--
-- Pro Shop bleibt nur noch eine "Aktivierung" (weiterhin connect_sync_mappings):
-- welche Vorlage, mit welchen konkreten Connections dieses Shops, welcher
-- Ausloeser. Bestandsdaten in connect_field_mappings werden bewusst geleert --
-- die Funktion wurde erst in der vorherigen Session gebaut und nie scharf
-- genutzt (alle bisherigen Eintraege hatten enabled=0), ein sauberer
-- Neustart ist hier risikolos.
--
-- Lookup-Tabellen werden aus demselben Grund ebenfalls global (vorher pro
-- Shop) -- eine globale Vorlage, die eine shop-spezifische Lookup-Tabelle
-- braucht, waere sonst wieder inkonsistent.
-- =============================================================================

CREATE TABLE IF NOT EXISTS connect_mapping_templates (
    id                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name                      VARCHAR(190) NOT NULL,
    source_connector_type_id INT UNSIGNED NOT NULL,
    source_object_id         INT UNSIGNED NOT NULL,
    target_connector_type_id INT UNSIGNED NOT NULL,
    target_object_id         INT UNSIGNED NOT NULL,
    created_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_mapping_templates_source_type
        FOREIGN KEY (source_connector_type_id) REFERENCES connect_connector_types (id) ON DELETE CASCADE,
    CONSTRAINT fk_mapping_templates_source_object
        FOREIGN KEY (source_object_id) REFERENCES connect_objects (id) ON DELETE CASCADE,
    CONSTRAINT fk_mapping_templates_target_type
        FOREIGN KEY (target_connector_type_id) REFERENCES connect_connector_types (id) ON DELETE CASCADE,
    CONSTRAINT fk_mapping_templates_target_object
        FOREIGN KEY (target_object_id) REFERENCES connect_objects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- connect_field_mappings: von sync_mapping_id (shop-gebunden) auf
-- mapping_template_id (global) umhaengen. Leeren vor dem Umbau, siehe
-- Kommentar oben -- kein produktiver Datensatz geht dadurch verloren.
DELETE FROM connect_field_mappings;
ALTER TABLE connect_field_mappings DROP FOREIGN KEY fk_field_mappings_sync_mapping;
ALTER TABLE connect_field_mappings DROP INDEX idx_field_mappings_sync_mapping;
ALTER TABLE connect_field_mappings DROP COLUMN sync_mapping_id;
ALTER TABLE connect_field_mappings ADD COLUMN mapping_template_id INT UNSIGNED NOT NULL AFTER id;
ALTER TABLE connect_field_mappings ADD KEY idx_field_mappings_template (mapping_template_id);
ALTER TABLE connect_field_mappings ADD CONSTRAINT fk_field_mappings_template
    FOREIGN KEY (mapping_template_id) REFERENCES connect_mapping_templates (id) ON DELETE CASCADE;

-- connect_sync_mappings: eine Aktivierung verweist jetzt auf eine Vorlage
-- (NULL bleibt weiterhin "reiner Cache-Sync ohne Vorlage/Ziel").
ALTER TABLE connect_sync_mappings
    ADD COLUMN mapping_template_id INT UNSIGNED NULL AFTER target_object_id,
    ADD CONSTRAINT fk_sync_mappings_template
        FOREIGN KEY (mapping_template_id) REFERENCES connect_mapping_templates (id) ON DELETE SET NULL;

-- Lookup-Tabellen: global statt pro Shop.
ALTER TABLE connect_lookup_tables DROP INDEX idx_lookup_tables_shop;
ALTER TABLE connect_lookup_tables DROP COLUMN shop;
