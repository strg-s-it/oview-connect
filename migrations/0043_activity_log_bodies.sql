-- =============================================================================
-- connect/migrations/0043_activity_log_bodies.sql
--
-- Ergaenzt connect_activity_log (Migration 0042) um die ROHEN Sende-/
-- Antwort-Texte (GraphQL-Body inkl. Variablen bzw. rohe JSON-Antwort) --
-- die bisherige "message"-Spalte war nur eine kurze, zusammengefasste
-- Meldung, fuer echtes Debugging (z.B. "was genau wurde an Shopify
-- geschickt/zurueckgegeben") reicht das nicht. Siehe Connect\Connector\
-- WriteResult (jetzt mit requestBody/responseBody) und
-- Connect\Sync\ActivityLog::log().
-- =============================================================================

ALTER TABLE connect_activity_log
    ADD COLUMN request_body MEDIUMTEXT NULL AFTER message,
    ADD COLUMN response_body MEDIUMTEXT NULL AFTER request_body;
