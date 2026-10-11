-- =============================================================================
-- connect/migrations/0014_country_tax_identifier.sql
--
-- Das Shopify-Object "country_tax" (Laender-Steuersaetze, siehe
-- 0009_align_shopify_shop_fields.sql) wurde NACH der generellen
-- is_identifier-Migration (0008_object_cache.sql) angelegt und hat deshalb
-- nie ein als Identifier markiertes Feld bekommen -- ObjectCache::refresh()
-- wuerde dafuer mit "kein als Identifier markiertes Feld" abbrechen.
-- country_code (ISO-Laendercode) ist pro Connection eindeutig und eignet
-- sich als natuerlicher Schluessel.
-- =============================================================================

UPDATE connect_object_fields f
JOIN connect_objects o ON o.id = f.object_id
JOIN connect_connector_types t ON t.id = o.connector_type_id
SET f.is_identifier = 1
WHERE t.type_key = 'shopify' AND o.object_key = 'country_tax' AND f.field_key = 'country_code';
