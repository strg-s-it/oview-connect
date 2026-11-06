-- =============================================================================
-- 0109 Sprache der Haendler-Oberflaeche (Connect\I18n\Lang)
--
-- Je Shop die gewaehlte Sprache der Haendler-Ansicht und des Startassistenten:
-- de (Standard) oder en. Gesetzt ueber die Flagge in der Kopfzeile oder in den Einstellungen.
-- Ohne diese Migration merkt sich Connect die Sprache nur in der Sitzung.
-- =============================================================================

ALTER TABLE connect_shops
    ADD COLUMN language VARCHAR(5) NOT NULL DEFAULT 'de';
