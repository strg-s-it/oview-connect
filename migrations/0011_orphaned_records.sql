-- =============================================================================
-- connect/migrations/0011_orphaned_records.sql
--
-- Erkennung verwaister Cache-Eintraege: ein Datensatz, der bei einem
-- VOLLSTAENDIGEN Sync-Durchlauf nicht mehr auftaucht, existiert vermutlich
-- nicht mehr in der Quelle (geloescht/archiviert) oder war eine Karteileiche
-- durch eine geaenderte "Eindeutige Kennung". Wird markiert, nicht geloescht --
-- nichts geht verloren, der Nutzer sieht in "Daten ansehen", was fehlt.
-- =============================================================================

ALTER TABLE connect_object_records ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'active' AFTER external_id;
