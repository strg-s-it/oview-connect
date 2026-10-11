-- =============================================================================
-- 0086 Code-Stand je Worker (Connect\Version)
--
-- bin/scheduler.php meldet bei jedem Heartbeat den Fingerabdruck des Codes dieser Installation.
-- Betrieb -> Lastverteilung zeigt ihn je Worker und markiert Abweichungen von main -- dann fehlt
-- auf dem Worker der rsync nach einem Upload.
--
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

ALTER TABLE connect_scheduler_heartbeats
    ADD COLUMN code_version VARCHAR(16) NULL COMMENT 'Connect\\Version::hash() dieser Installation',
    ADD COLUMN code_updated_at VARCHAR(16) NULL COMMENT 'juengste Programmdatei (Y-m-d H:i)';
