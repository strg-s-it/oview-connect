-- =============================================================================
-- 0097 Logo eines Connector Types in der Datenbank
--
-- Bisher lag das Logo als Datei unter public/assets/logos/ (Migration 0076) --
-- der Upload landete aber im Live-Verzeichnis, das die Root-Crontab jede Minute
-- per rsync --delete aus httpdocs/connect ueberschreibt: hochgeladene Logos
-- verschwanden nach spaetestens einer Minute. Jetzt als data-URI
-- (data:image/png base64) in der Zeile selbst, max. 300 KB Bild.
-- logo_path bleibt fuer alte Eintraege (Rueckfall, falls die Datei existiert).
--
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

ALTER TABLE connect_connector_types
    ADD COLUMN logo_data MEDIUMTEXT NULL
        COMMENT 'Logo als data-URI (Bild base64), Vorrang vor logo_path';
