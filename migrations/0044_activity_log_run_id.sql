-- =============================================================================
-- connect/migrations/0044_activity_log_run_id.sql
--
-- Verknuepft connect_activity_log (Migration 0042/0043) mit dem Sync-Lauf
-- (connect_sync_runs), der einen Schreibvorgang ausgeloest hat -- NULL fuer
-- Vorgaenge ohne Lauf-Kontext (z.B. der manuelle Produkt-Import ueber den
-- "Importieren"-Button, Connect\Sync\ProductImportService, laeuft nie als
-- Hintergrund-Job). Damit kann admin/job_log.php (Detailansicht EINES Laufs)
-- neben dem Meilenstein-Log jetzt auch alle einzelnen Schreibvorgaenge dieses
-- Laufs anzeigen -- bisher waren "Details" (job_log.php, nur Text-Log) und
-- "Protokoll" (admin/activity_log.php, nur einzelne Schreibvorgaenge) zwei
-- getrennte Ansichten ohne Verbindung zueinander.
-- =============================================================================

ALTER TABLE connect_activity_log
    ADD COLUMN sync_run_id BIGINT UNSIGNED NULL AFTER shop,
    ADD KEY idx_activity_log_sync_run (sync_run_id);
