-- =============================================================================
-- connect/migrations/0078_setup_wizard.sql
--
-- Einrichtung fuer neue Merchants (app/welcome.php): eine Frage pro Fenster, erst nach
-- "oView starten" geht es ins Dashboard (Connect\Setup\WelcomeGate).
--
--   connect_shops.setup_completed_at   Zeitpunkt von "oView starten". NULL = Einrichtung
--                                      noch offen. Die Antworten bis dahin stehen in
--                                      connect_shops.onboarding_state (Schluessel "wizard"),
--                                      wirksam werden sie erst beim Start.
--
-- Bestehende Shops mit verbundener Software gelten als eingerichtet und sehen die
-- Einrichtung nicht. Umgezogene Shops setzt ShopMigrator::apply().
--
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

ALTER TABLE connect_shops
    ADD COLUMN setup_completed_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Einrichtung abgeschlossen (oView starten), NULL = offen';

UPDATE connect_shops SET setup_completed_at = CURRENT_TIMESTAMP WHERE target_connection_id IS NOT NULL;
