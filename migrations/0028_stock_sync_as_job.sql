-- =============================================================================
-- connect/migrations/0028_stock_sync_as_job.sql
--
-- Lagerbestand-Abgleich (Connect\Sync\StockSyncRunner, Migration 0027) laeuft
-- ab jetzt als GANZ NORMALE connect_sync_mappings-Zeile statt einer eigenen
-- Schedule-/Claim-/History-Infrastruktur -- Nutzer-Feedback: soll in
-- derselben Job-Tabelle/History erscheinen wie jeder andere Sync-Job, mit
-- demselben "Jetzt ausfuehren"/Aktivieren/Verlauf. Die Ausfuehrungslogik
-- bleibt unterschiedlich: SyncRunner::run() delegiert bei
-- mapping_kind='stock_sync' an StockSyncRunner statt an die normale Cache-
-- Refresh-/Uebersetzungs-Logik, laeuft aber ueber denselben Scheduler-Claim
-- (connect_sync_jobs) und dieselbe Lauf-Historie (connect_sync_runs).
--
-- Ersetzt die in Migration 0027 eingefuehrte, separate Infrastruktur:
--   - connect_stock_sync_runs (eigene History) -> connect_sync_runs, wie
--     jeder andere Job auch.
--   - connect_shops.stock_sync_last_run_at/-interval_minutes (eigener
--     Scheduler-Claim in bin/scheduler.php) -> connect_sync_jobs/
--     connect_sync_mappings.schedule_interval_minutes, wie jeder andere Job.
-- =============================================================================

ALTER TABLE connect_sync_mappings
  ADD COLUMN mapping_kind VARCHAR(16) NOT NULL DEFAULT 'default'
      COMMENT 'default (Verhalten wie bisher, siehe target_connection_id) | stock_sync -- steuert die Ausfuehrungslogik in SyncRunner::run()';

DROP TABLE IF EXISTS connect_stock_sync_runs;

ALTER TABLE connect_shops
  DROP COLUMN stock_sync_last_run_at,
  DROP COLUMN stock_sync_interval_minutes;
