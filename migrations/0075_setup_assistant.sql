-- =============================================================================
-- connect/migrations/0075_setup_assistant.sql
--
-- Einrichtungs-Assistent fuer den Merchant (Dashboard, Connect\Setup\SetupAssistant):
-- welche Schritte der Merchant bestaetigt hat. Noetig, weil einige Einstellungen
-- Standardwerte haben (Vergleichswert SKU, Lagerbestand in Shopify) - am Wert allein
-- ist nicht erkennbar, ob er bewusst gewaehlt wurde. Gesetzt beim Speichern der
-- jeweiligen Karte in den Einstellungen, per "Passt so" im Assistenten oder beim Umzug
-- aus der alten Welt (dort war alles schon eingerichtet).
--
-- JSON: {"comparison": "2026-10-05 12:00:00", ...}
--
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

ALTER TABLE connect_shops
    ADD COLUMN onboarding_state TEXT NULL COMMENT 'Einrichtungs-Assistent: bestaetigte Schritte als JSON';
