-- =============================================================================
-- connect/migrations/0047_default_tax_rate.sql
--
-- Standard-Steuersatz je Shop (z.B. 20 fuer 20%) -- Vorbelegung fuer das
-- manuelle "Steuersatz"-Eingabefeld auf der Produkte-Seite (siehe
-- Connect\Sync\ProductImportService::manualInputFieldsForTargetImport(),
-- transform_type='manual_input'), analog zum Altsystem-Fallback aus
-- Shopifys /countries.json (dort NIE auf Netto/Brutto angewandt, siehe
-- Recherche -- hier nur Vorbelegung, der Merchant kann pro Produkt trotzdem
-- ueberschreiben, z.B. bei ermaessigten Steuersaetzen). NULL = keine
-- Vorbelegung, Feld bleibt leer.
-- =============================================================================

ALTER TABLE connect_shops
    ADD COLUMN default_tax_rate DECIMAL(5,2) NULL AFTER shopify_prices_include_tax;
