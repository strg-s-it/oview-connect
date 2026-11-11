-- =============================================================================
-- connect/migrations/0111_invoice_tax_tolerance.sql
--
-- Steuer-Kontrolle mit einstellbarer Toleranz je Shop (nur Admin).
-- Hintergrund: Shopifys "total_tax" ist nach nachtraeglich geaenderten
-- Versandkosten nicht stimmig (Kunde Revenued, #12301: Versand 22.50 -> 7.50,
-- Differenz 1.36 EUR = 10 % Steuer auf die 15.00 EUR Reduktion), obwohl alle
-- Positions- und Versandsteuern mit den Saetzen uebereinstimmen.
--
-- invoice_tax_tolerance_percent: erlaubte Abweichung der selbst berechneten
-- Steuersumme von Shopifys Steuersumme in PROZENT von Shopifys Steuersumme
-- (mindestens aber die feste Grenze von 0.10 EUR). 0 = streng (Standard,
-- bisheriges Verhalten). Die Betrags-Kontrolle ist davon nicht betroffen.
-- =============================================================================

ALTER TABLE connect_shops
    ADD COLUMN invoice_tax_tolerance_percent DECIMAL(5,2) NOT NULL DEFAULT 0;
