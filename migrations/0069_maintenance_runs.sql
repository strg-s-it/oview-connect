-- =============================================================================
-- connect/migrations/0069_maintenance_runs.sql
--
-- Protokoll der automatischen Bereinigung (Connect\Maintenance\Retention), die
-- seit 2026-10-04 einmal taeglich aus bin/scheduler.php mitlaeuft (vorher nur
-- als eigene Plesk-Aufgabe bin/purge_privacy_data.php, ohne Spur in der
-- Datenbank). Anzeige unter Betrieb -> Datenbank, Karte "Automatische Bereinigung".
--
--   task     z.B. "retention"
--   trigger  scheduler | manual
--   result   JSON: geloeschte bzw. geleerte Zeilen je Regel
--
-- Hinweis: der Installer trennt am Semikolon -- deshalb keines in Strings oder Kommentaren.
-- =============================================================================

CREATE TABLE IF NOT EXISTS connect_maintenance_runs (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    task          VARCHAR(64) NOT NULL,
    trigger_type  VARCHAR(16) NOT NULL DEFAULT 'scheduler',
    host          VARCHAR(190) NULL,
    started_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at   TIMESTAMP NULL DEFAULT NULL,
    status        VARCHAR(16) NOT NULL DEFAULT 'running' COMMENT 'running, success, partial oder error',
    result        LONGTEXT NULL,
    error_message TEXT NULL,
    PRIMARY KEY (id),
    KEY idx_maintenance_task_started (task, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
