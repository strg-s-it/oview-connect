-- =============================================================================
-- connect/migrations/0034_variant_comparison_key.sql
--
-- Vereinfacht/korrigiert den Ansatz aus Migration 0031: nach Rueckmeldung des
-- Nutzers braucht die Varianten-Gruppierung beim Import KEIN eigenes,
-- zusaetzlich zu markierendes Feld (comparison_role='variant_group') --
-- im Altsystem war das schlicht EINE der bereits vorhandenen Vergleichswert-
-- Rollen (Titel/SKU/Barcode), nur eine ANDERE als der gerade aktive
-- Produkt-Vergleichswert (Beispiel: Vergleichswert=Barcode, "Varianten-
-- Vergleichswert"=SKU -- Datensaetze mit derselben SKU gehoeren zusammen).
-- comparison_role='variant_group' bleibt als erlaubter Wert ungenutzt
-- (kein Schaden, einfach nicht mehr im Connector Builder waehlbar, siehe
-- object_edit.php).
--
-- Zusaetzlich, wie besprochen: ein System sollte grundsaetzlich wissen, ob
-- es ueberhaupt Varianten kennt (Shopify ja, HelloCash/MGM nein) --
-- supports_variants an connect_connector_types, EINMALIG pro System
-- gesetzt, statt es implizit an "hat der Admin ein Feld markiert" zu
-- erkennen. Der Varianten-Import-Zweig (Connect\Sync\ProductImportService)
-- ist nur aktiv, wenn die ZIEL-Connection dieses Imports zu einem
-- Connector Type mit supports_variants=1 gehoert.
-- =============================================================================

ALTER TABLE connect_connector_types
  ADD COLUMN supports_variants TINYINT(1) NOT NULL DEFAULT 0
      COMMENT 'kennt dieses System Produktvarianten (mehrere Auspraegungen eines Produkts, z.B. Groesse/Farbe)? Nur Shopify aktuell -- steuert, ob der Varianten-Import-Zweig ueberhaupt greifen kann';

UPDATE connect_connector_types SET supports_variants = 1 WHERE type_key = 'shopify';

ALTER TABLE connect_shops
  ADD COLUMN variant_comparison_key VARCHAR(16) NULL
      COMMENT 'title | sku | barcode, muss vom shop-weiten comparison_key abweichen -- Feld-Rolle, die beim Import erkennt, welche Datensaetze des varianten-losen Systems zur selben Produktgruppe gehoeren. NULL = Varianten-Import deaktiviert (Default, nur wenige Shops brauchen das)';
