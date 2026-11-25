-- =============================================================================
-- connect/migrations/0115_invoice_mail_bcc_merchant.sql
--
-- Blindkopie der Rechnungsmail an den Merchant (Kontrolle, ob der Mailversand
-- funktioniert; hilft auch dem Fulfillment). Gab es in der alten Welt, fehlte in Connect.
--
-- invoice_mail_bcc_merchant: 1 = Kopie an die Merchant-Adresse (Benachrichtigungs-Adresse,
-- sonst Shopify-Shop-E-Mail, sonst Absender-/Antwortadresse). Standard 1 (auch fuer
-- bestehende Shops). Wirkt nur, wenn invoice_mail_enabled = 1.
-- =============================================================================

ALTER TABLE connect_shops
    ADD COLUMN invoice_mail_bcc_merchant TINYINT(1) NOT NULL DEFAULT 1;
