-- =============================================================================
-- connect/migrations/0022_sync_job_templates.sql
--
-- Wendet dasselbe Vorlage/Aktivierung-Muster wie bei den Mapping-Vorlagen
-- (Migration 0020) auch auf reine Cache-Sync-Jobs an: bisher war die Liste
-- "welche Objects werden fuer einen Shop automatisch synchronisiert" hart in
-- admin/shops.php kodiert ($autoSyncObjectKeys) -- nicht admin-editierbar,
-- nicht zentral einsehbar. Jetzt eine globale, admin-gepflegte Tabelle
-- (connect_sync_job_templates), analog zu Objects/Fields/Operations im
-- Connector Builder bereits Connector-Type-Ebene.
--
-- Pro Shop bleibt weiterhin nur eine "Aktivierung" (weiterhin
-- connect_sync_mappings, wie schon bei den Mapping-Vorlagen): welche
-- Job-Vorlage, mit welcher Connection dieses Shops, welches Intervall
-- (Default aus der Vorlage, pro Aktivierung ueberschreibbar).
-- =============================================================================

CREATE TABLE IF NOT EXISTS connect_sync_job_templates (
    id                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name                      VARCHAR(190) NOT NULL,
    connector_type_id         INT UNSIGNED NOT NULL,
    object_id                 INT UNSIGNED NOT NULL,
    default_interval_minutes INT UNSIGNED NOT NULL,
    auto_activate             TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'bei 1: wird fuer jede neue/per Migrationsbruecke uebernommene Connection dieses Connector Types automatisch aktiviert (siehe admin/shops.php)',
    created_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sync_job_templates_object (object_id),
    CONSTRAINT fk_sync_job_templates_type
        FOREIGN KEY (connector_type_id) REFERENCES connect_connector_types (id) ON DELETE CASCADE,
    CONSTRAINT fk_sync_job_templates_object
        FOREIGN KEY (object_id) REFERENCES connect_objects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE connect_sync_mappings
    ADD COLUMN sync_job_template_id INT UNSIGNED NULL AFTER source_object_id,
    ADD CONSTRAINT fk_sync_mappings_job_template
        FOREIGN KEY (sync_job_template_id) REFERENCES connect_sync_job_templates (id) ON DELETE SET NULL;

-- Seed: die bisher hart in admin/shops.php kodierten Auto-Sync-Objects als
-- echte, admin-editierbare Job-Vorlagen anlegen (auto_activate=1, damit sich
-- am Verhalten fuer neue/migrierte Shops nichts aendert).
SET @shopify_connector_type_id = (SELECT id FROM connect_connector_types WHERE type_key = 'shopify');

INSERT INTO connect_sync_job_templates (name, connector_type_id, object_id, default_interval_minutes, auto_activate)
SELECT CONCAT('Shopify: ', o.label), @shopify_connector_type_id, o.id, seed.interval_minutes, 1
FROM (
    SELECT 'product' AS object_key, 60 AS interval_minutes
    UNION ALL SELECT 'collection', 60
    UNION ALL SELECT 'shop', 1440
    UNION ALL SELECT 'location', 1440
    UNION ALL SELECT 'language', 1440
    UNION ALL SELECT 'country_tax', 1440
) seed
JOIN connect_objects o ON o.connector_type_id = @shopify_connector_type_id AND o.object_key = seed.object_key;

-- Bereits (durch die alte, hart kodierte Logik) angelegte Sync Mappings
-- rueckwirkend mit der passenden neuen Vorlage verknuepfen, damit sie in der
-- neuen Oberflaeche nicht als "ohne Vorlage" erscheinen.
UPDATE connect_sync_mappings m
JOIN connect_connections c ON c.id = m.source_connection_id
JOIN connect_sync_job_templates jt ON jt.connector_type_id = c.connector_type_id AND jt.object_id = m.source_object_id
SET m.sync_job_template_id = jt.id
WHERE m.target_connection_id IS NULL AND m.sync_job_template_id IS NULL;
