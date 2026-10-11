-- =============================================================================
-- connect/migrations/0045_connect_functions.sql
--
-- "Funktionen": explizite, admin-gepflegte Zuordnung "welche Mapping-Vorlage
-- gehoert zu welcher Aktion" -- ersetzt/ergaenzt die bisherige implizite
-- Auswahl per ORDER BY id DESC LIMIT 1 in Connect\Sync\ProductImportService
-- (dort zwei fast identische Lookups: importIntoTarget() und
-- resolveConfiguredFieldValues()). function_kind ist bewusst freier Text
-- statt ENUM -- der Code kennt aktuell nur 'import_missing' (der
-- "Importieren"-Button auf der Produkte-Seite), spaetere Werte (z.B.
-- 'update_existing' fuer ein kuenftiges "Aktualisieren") kommen ohne
-- Schema-Aenderung dazu.
--
-- Global wie connect_mapping_templates selbst (kein shop-Feld) -- gilt
-- automatisch fuer jeden Shop mit passender Connector-Kombination, ohne
-- Aktivierungsschritt. Kein enabled-Feld -- Existenz der Zeile = aktiv.
-- =============================================================================

CREATE TABLE connect_functions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    function_kind VARCHAR(32) NOT NULL,
    mapping_template_id INT UNSIGNED NOT NULL,
    label VARCHAR(190) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_kind_template (function_kind, mapping_template_id),
    CONSTRAINT fk_connect_functions_template FOREIGN KEY (mapping_template_id)
        REFERENCES connect_mapping_templates (id) ON DELETE CASCADE
);
