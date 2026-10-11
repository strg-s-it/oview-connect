-- =============================================================================
-- connect/migrations/0074_shop_fields.sql
--
-- Shop-Felder: zusaetzliche Werte, die nur ein bestimmter Shop hat, als Shopify-
-- Produkt-Metafield definiert (Admin -> Shop -> Shop-Felder). Anlass: danysmusicshop
-- pflegt den Netto-Einkaufspreis im Produkt-Metafield ...article_net_purchase_price
-- (Geldbetrag, verwendet wird amount), die alte Welt las ihn fest fuer diesen Shop
-- (jobs/scheduler_single_product_update.php).
--
--   connect_shop_fields                je Shop: Name, field_key, Metafield (namespace + key),
--                                      optionaler Teilwert (z.B. amount), Platz 1..5
--   Shopify-Produkt: Felder shop_metafield_1..5 = Rohwert des Platzes (jsonValue)
--   Shopify-Produkt read_list/read_one: 5 Metafield-Plaetze per GraphQL-Variablen,
--                                      gleiches Muster wie $locationId/$hasLocation (0061) -
--                                      ohne Shop-Felder per @include(if: false) ausgeschaltet
--
-- Connect\Sync\ObjectCache setzt die Variablen je Shop und legt den aufgeloesten Wert
-- im Zwischenspeicher als sf_<field_key> ab. In Mapping-Vorlagen holt die Umrechnung
-- "Shop-Feld" ihn heraus (fehlt er bei einem Shop, bleibt das Zielfeld leer).
--
-- Die Lese-Abfragen werden nur ergaenzt, wenn sie noch dem Stand von 0061 entsprechen
-- (Variablen-Kopf mit $hasLocation und Produkt-Teil "product { id ").
--
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

CREATE TABLE IF NOT EXISTS connect_shop_fields (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    shop                VARCHAR(190) NOT NULL,
    object_key          VARCHAR(64) NOT NULL DEFAULT 'product' COMMENT 'aktuell nur Shopify-Produkt',
    field_key           VARCHAR(64) NOT NULL COMMENT 'Schluessel fuer die Umrechnung Shop-Feld, im Cache als sf_<field_key>',
    label               VARCHAR(190) NOT NULL,
    metafield_namespace VARCHAR(190) NOT NULL,
    metafield_key       VARCHAR(190) NOT NULL,
    value_path          VARCHAR(190) NULL COMMENT 'Teilwert aus jsonValue, z.B. amount bei Geldbetraegen',
    slot                TINYINT UNSIGNED NOT NULL COMMENT '1..5 = Platz in der Produktabfrage',
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_shop_fields_key (shop, object_key, field_key),
    UNIQUE KEY uq_shop_fields_slot (shop, object_key, slot)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @shopify_connector_type_id = (SELECT id FROM connect_connector_types WHERE type_key = 'shopify');

SET @shopify_product_object_id = (SELECT id FROM connect_objects WHERE connector_type_id = @shopify_connector_type_id AND object_key = 'product');

INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id)
SELECT @shopify_product_object_id, CONCAT('shop_metafield_', n.slot), CONCAT('Shop-Feld Platz ', n.slot, ' (Rohwert, siehe Shop-Felder)'), 'string', CONCAT('node.product.sf', n.slot, '.jsonValue'), 0, NULL
FROM (SELECT 1 AS slot UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5) n
WHERE @shopify_product_object_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM connect_object_fields f WHERE f.object_id = @shopify_product_object_id AND f.field_key = CONCAT('shop_metafield_', n.slot));

UPDATE connect_object_operations
SET body_template = REPLACE(
        REPLACE(body_template,
            '$hasLocation: Boolean = false)',
            '$hasLocation: Boolean = false, $sf1Namespace: String, $sf1Key: String! = "-", $hasSf1: Boolean = false, $sf2Namespace: String, $sf2Key: String! = "-", $hasSf2: Boolean = false, $sf3Namespace: String, $sf3Key: String! = "-", $hasSf3: Boolean = false, $sf4Namespace: String, $sf4Key: String! = "-", $hasSf4: Boolean = false, $sf5Namespace: String, $sf5Key: String! = "-", $hasSf5: Boolean = false)'),
        'product { id ',
        'product { id sf1: metafield(namespace: $sf1Namespace, key: $sf1Key) @include(if: $hasSf1) { jsonValue } sf2: metafield(namespace: $sf2Namespace, key: $sf2Key) @include(if: $hasSf2) { jsonValue } sf3: metafield(namespace: $sf3Namespace, key: $sf3Key) @include(if: $hasSf3) { jsonValue } sf4: metafield(namespace: $sf4Namespace, key: $sf4Key) @include(if: $hasSf4) { jsonValue } sf5: metafield(namespace: $sf5Namespace, key: $sf5Key) @include(if: $hasSf5) { jsonValue } ')
WHERE object_id = @shopify_product_object_id AND operation_type IN ('read_list', 'read_one')
  AND body_template LIKE '%$hasLocation: Boolean = false)%'
  AND body_template LIKE '%product { id %'
  AND body_template NOT LIKE '%$hasSf1%';
