-- =============================================================================
-- connect/migrations/0012_sync_mappings_optional_target.sql
--
-- Fuer die erste Ausbaustufe des Schedulers ist ein Sync Mapping zunaechst
-- nur "diese Connection+Object regelmaessig im Cache aktualisieren" (nutzt
-- ObjectCache::refresh(), volle Pagination, Verwaisten-Erkennung) -- OHNE
-- Ziel-Connection/Feld-Mapping/Schreiben (das kommt als naechste Ausbaustufe
-- oben drauf). target_connection_id/target_object_id werden deshalb
-- nullable: NULL = reiner Cache-Sync, gesetzt = spaetere Mapping-Funktion.
-- =============================================================================

ALTER TABLE connect_sync_mappings MODIFY COLUMN target_connection_id INT UNSIGNED NULL;
ALTER TABLE connect_sync_mappings MODIFY COLUMN target_object_id INT UNSIGNED NULL;
