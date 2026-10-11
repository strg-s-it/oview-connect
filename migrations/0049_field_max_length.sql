-- =============================================================================
-- connect/migrations/0049_field_max_length.sql
--
-- Optionale maximale Zeichenlaenge je Feld (z.B. HelloCash Artikel-"code": 20).
-- Altsystem-Pruefung "SKU in Shopify zu lang (mehr als 20 Zeichen)" generisch:
-- Connect\Sync\ProductComparisonService vergleicht die Werte einer Seite mit
-- dem Limit des Gegenfelds gleicher comparison_role (Probleme-Karte auf der
-- Produkte-Seite), Connect\Sync\ProductImportService bricht einen Import mit
-- zu langem Wert klar ab statt eine 422-Antwort des Zielsystems zu
-- provozieren. NULL = kein Limit bekannt/geprueft.
-- =============================================================================

ALTER TABLE connect_object_fields
    ADD COLUMN max_length INT UNSIGNED NULL;
