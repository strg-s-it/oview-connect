-- =============================================================================
-- connect/migrations/0060_auto_import_filter.sql
--
-- Optionaler Filter fuer den automatischen Produktimport (Migration 0059) --
-- Nutzer-Entscheidung (2026-09-27), nach Pruefung von jobs/cron_shopify_
-- product_sync.php: dort filtert Shop "neherashop" zusaetzlich auf
-- "category LIKE '%AT-01%'" (Shopifys REST-Feld "category" entspricht dem
-- bereits vorhandenen Connect-Feld "product_type", siehe Migration 0004).
--
-- Generisch gehalten (nicht auf "product_type" fest verdrahtet) -- der Admin
-- waehlt im Admin-only-Bereich von app/_sync_mappings_body.php ein beliebiges
-- Feld des Shopify-"Produkt"-Objects UND einen Text, der darin enthalten sein
-- muss (Teilstring, gross-/kleinschreibungsunabhaengig), siehe
-- Connect\Sync\AutoProductImportRunner.
--
-- Der in der alten Welt zusaetzliche Standort-Filter (a.location_id = ...)
-- wird bewusst NICHT nachgebaut: er diente dort dazu, Mehrfach-Zeilen einer
-- pro-Standort-Lagerbestands-VIEW zu vermeiden -- Connects Objekt-Cache
-- (connect_object_records) speichert jedes Produkt/jede Variante dagegen
-- bereits genau EINMAL, unabhaengig von der Anzahl Standorte (Schluessel ist
-- die Shopify-ID, nicht Produkt+Standort) -- die urspruengliche Ursache fuer
-- die vom Nutzer erinnerten Duplikate besteht in Connects Architektur nicht.
-- =============================================================================

ALTER TABLE connect_shops
    ADD COLUMN auto_import_filter_field_key VARCHAR(190) NULL AFTER auto_import_missing_to_target,
    ADD COLUMN auto_import_filter_value VARCHAR(190) NULL AFTER auto_import_filter_field_key;
