-- =============================================================================
-- 0098 Aktives Shopify-Abo der Shops der alten Welt
--
-- Die Live-Pruefung (Umstieg -> "Berechtigungen jetzt pruefen", Aufraeumen) fragt neben den
-- Berechtigungen auch currentAppInstallation.activeSubscriptions ab (nur lesend). Ergebnis hier:
-- Name des aktiven Abos, leer = kein aktives Abo, NULL = unbekannt (nicht pruefbar).
-- Grundlage fuer admin/cleanup.php: installiert, aber ohne Abo = auswaehlbar.
--
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

ALTER TABLE connect_legacy_scope_checks
    ADD COLUMN subscription VARCHAR(255) NULL
        COMMENT 'aktives Abo laut Shopify, leer = keines, NULL = unbekannt' AFTER error;
