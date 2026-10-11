-- =============================================================================
-- connect/migrations/0008_object_cache.sql
--
-- Generische Zwischenspeicherung ("Inventarisierung") fuer read()-Ergebnisse
-- -- pro Connection + Object + externer ID ein Datensatz als JSON. Ersetzt
-- damit fuer beliebige Connector Types, was die alte Welt mit fest
-- benannten Tabellen wie shopify_products macht, ohne pro Connector Type
-- eine eigene Tabelle zu brauchen.
--
-- is_identifier auf connect_object_fields markiert, welches Feld eines
-- Objects als externe ID fuer den Cache-Schluessel dient (z.B. shopify_id).
-- =============================================================================

-- Kein "IF NOT EXISTS" (erst ab MariaDB 10.0.2) -- unnoetig, da install.php
-- diese Migrationsdatei ohnehin nur einmal ausfuehrt (siehe connect_schema_migrations).
ALTER TABLE connect_object_fields ADD COLUMN is_identifier TINYINT(1) NOT NULL DEFAULT 0 AFTER is_repeating_group;

CREATE TABLE IF NOT EXISTS connect_object_records (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    connection_id INT UNSIGNED NOT NULL,
    object_key    VARCHAR(64) NOT NULL,
    external_id   VARCHAR(190) NOT NULL,
    data          LONGTEXT NOT NULL COMMENT 'JSON-kodiert: field_key => Wert, wie von read() geliefert',
    fetched_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_object_records (connection_id, object_key, external_id),
    KEY idx_object_records_lookup (connection_id, object_key),
    CONSTRAINT fk_object_records_connection
        FOREIGN KEY (connection_id) REFERENCES connect_connections (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Identifier-Felder fuer die bereits vorhandenen Shopify-Objects markieren.
UPDATE connect_object_fields f
JOIN connect_objects o ON o.id = f.object_id
JOIN connect_connector_types t ON t.id = o.connector_type_id
SET f.is_identifier = 1
WHERE t.type_key = 'shopify' AND f.field_key = 'shopify_id';

-- Der "Shop"-Object ist ein Einzelobjekt (kein "shopify_id"-Feld) -- die
-- Domain dient hier als eindeutiger Schluessel (1 Zeile pro Connection).
UPDATE connect_object_fields f
JOIN connect_objects o ON o.id = f.object_id
JOIN connect_connector_types t ON t.id = o.connector_type_id
SET f.is_identifier = 1
WHERE t.type_key = 'shopify' AND o.object_key = 'shop' AND f.field_key = 'domain';

-- shopLocales hat keine natuerliche ID -- locale-Code dient als Schluessel.
UPDATE connect_object_fields f
JOIN connect_objects o ON o.id = f.object_id
JOIN connect_connector_types t ON t.id = o.connector_type_id
SET f.is_identifier = 1
WHERE t.type_key = 'shopify' AND o.object_key = 'language' AND f.field_key = 'locale';
