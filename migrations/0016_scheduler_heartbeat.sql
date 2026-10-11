-- =============================================================================
-- connect/migrations/0016_scheduler_heartbeat.sql
--
-- Ein Zeitstempel pro Worker-Host, der bei JEDEM Aufruf von bin/scheduler.php
-- aktualisiert wird (unabhaengig davon, ob ein Job geclaimt wurde) -- damit
-- im Jobs-Dashboard sichtbar ist, ob der Cronjob ueberhaupt regelmaessig
-- laeuft, statt das nur indirekt aus dem Vorhandensein neuer Sync-Laeufe
-- zu erraten. Pro Host eine Zeile (nicht Pro-PID), damit mehrere parallele
-- Worker (siehe Plan, "3 Server-IPs") spaeter einzeln sichtbar sind.
-- =============================================================================

CREATE TABLE IF NOT EXISTS connect_scheduler_heartbeats (
    host        VARCHAR(190) NOT NULL,
    last_run_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (host)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
