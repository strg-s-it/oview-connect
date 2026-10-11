-- =============================================================================
-- 0095 Kundenzuordnung bei Rechnungen einstellbar
--
-- Bisher immer: Kunde per E-Mail suchen, sonst per Name und Adresse, sonst neu anlegen.
-- Merchant-Wunsch: nur per E-Mail-Adresse (wie in der alten Welt fest fuer fgnpc9-wa).
--   email_name  E-Mail, sonst Vor-/Nachname + PLZ/Ort/Strasse (Standard)
--   email       nur E-Mail -- nicht gefunden = neuer Kunde, Bestellung ohne E-Mail = kein Kunde
-- Gilt fuer beide Wege der Rechnungserstellung (DocumentPipeline und InvoiceService).
--
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

ALTER TABLE connect_shops
    ADD COLUMN invoice_customer_match VARCHAR(16) NOT NULL DEFAULT 'email_name'
        COMMENT 'Kundenzuordnung bei Rechnungen: email_name oder email';
