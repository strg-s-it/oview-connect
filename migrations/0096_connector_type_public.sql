-- =============================================================================
-- 0096 Connector Type oeffentlich/privat
--
-- Privat = wird Haendlern im Setup-Assistenten (app/welcome.php) und im
-- Verbindungs-Assistenten der Einstellungen nicht zur Auswahl angeboten -- nur
-- Shops, die schon eine Connection dieses Typs haben (z.B. MGM nur fuer Crystalp).
-- Admin-Seiten zeigen weiterhin alle Typen. Standard: oeffentlich (wie bisher).
--
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

ALTER TABLE connect_connector_types
    ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 1
        COMMENT '1 = im Haendler-Assistenten fuer alle Shops waehlbar, 0 = nur fuer Shops mit bestehender Connection';
