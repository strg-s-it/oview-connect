-- =============================================================================
-- connect/migrations/0015_sync_run_log.sql
--
-- Ungekuerztes Log pro Sync-Lauf (Meilensteine + im Fehlerfall voller
-- Stacktrace) fuer die neue Detailansicht (admin/job_log.php) im
-- Jobs-Dashboard (admin/jobs.php). error_summary bleibt wie bisher die kurze,
-- auf 2000 Zeichen gekuerzte Uebersicht fuer die Listenansicht.
-- =============================================================================

ALTER TABLE connect_sync_runs
  ADD COLUMN log LONGTEXT NULL COMMENT 'Ungekuerztes Zeilen-Log dieses Laufs (Meilensteine, im Fehlerfall Stacktrace)' AFTER error_summary;
