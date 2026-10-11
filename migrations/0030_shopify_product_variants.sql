-- =============================================================================
-- connect/migrations/0030_shopify_product_variants.sql
--
-- Behebt eine bewusst dokumentierte, aber fuer Mehr-Varianten-Shops echte
-- Luecke: das Shopify-Produkt-Objekt las bisher NUR die erste Variante
-- (Migration 0004: Feldlabels "SKU (erste Variante)" etc., Query
-- "variants(first: 1)"). Bei einem Produkt mit mehreren Varianten
-- (Groesse/Farbe) waren alle ausser der ersten fuer Connect unsichtbar --
-- weder Lagerbestand-Abgleich (StockSyncRunner) noch Produkte-Vergleich
-- (ProductComparisonService) noch Import (ProductImportService) sahen sie.
--
-- Stellt das Object von "1 Zeile = 1 Produkt" auf "1 Zeile = 1 Variante" um,
-- ueber Shopifys Wurzelabfrage "productVariants" (bzw. "productVariant" fuer
-- read_one) statt "products" -- jede Variante bekommt das zugehoerige Produkt
-- als verschachtelte Unterstruktur mit. Kein Code muss dafuer angepasst
-- werden: GenericHttpConnector/ObjectCache/StockSyncRunner/
-- ProductComparisonService/ProductImportService sind bereits vollstaendig
-- generisch (arbeiten nur ueber field_path + comparison_role/is_stock_quantity).
--
-- ACHTUNG Nebenwirkung: das externe-ID-Schema aendert sich (vorher Produkt-ID,
-- jetzt Varianten-ID) -- bestehende connect_object_records fuer dieses Object
-- werden deshalb unten geloescht, der naechste Sync befuellt den Cache neu.
-- webhooks/product_update.php wird in einem separaten Schritt angepasst
-- (nutzt refreshOne() mit einer Produkt-ID, die es so nicht mehr gibt).
-- =============================================================================

SET @shopify_connector_type_id = (SELECT id FROM connect_connector_types WHERE type_key = 'shopify');
SET @shopify_product_object_id = (SELECT id FROM connect_objects WHERE connector_type_id = @shopify_connector_type_id AND object_key = 'product');

-- -----------------------------------------------------------------------------
-- Felder: Produkt-Ebene -> node.product.*, Varianten-Ebene bleibt an der
-- Wurzel (node.*), da "node" jetzt direkt die Variante ist.
-- -----------------------------------------------------------------------------
UPDATE connect_object_fields SET field_path = 'node.product.title' WHERE object_id = @shopify_product_object_id AND field_key = 'title';
UPDATE connect_object_fields SET field_path = 'node.product.handle' WHERE object_id = @shopify_product_object_id AND field_key = 'handle';
UPDATE connect_object_fields SET field_path = 'node.product.vendor' WHERE object_id = @shopify_product_object_id AND field_key = 'vendor';
UPDATE connect_object_fields SET field_path = 'node.product.productType' WHERE object_id = @shopify_product_object_id AND field_key = 'product_type';
UPDATE connect_object_fields SET field_path = 'node.product.status' WHERE object_id = @shopify_product_object_id AND field_key = 'status';

UPDATE connect_object_fields SET field_path = 'node.sku', label = 'SKU' WHERE object_id = @shopify_product_object_id AND field_key = 'sku';
UPDATE connect_object_fields SET field_path = 'node.barcode', label = 'Barcode' WHERE object_id = @shopify_product_object_id AND field_key = 'barcode';
UPDATE connect_object_fields SET field_path = 'node.price', label = 'Preis' WHERE object_id = @shopify_product_object_id AND field_key = 'price';
UPDATE connect_object_fields SET field_path = 'node.inventoryQuantity', label = 'Lagerbestand' WHERE object_id = @shopify_product_object_id AND field_key = 'inventory_quantity';
UPDATE connect_object_fields SET field_path = 'node.inventoryItem.id' WHERE object_id = @shopify_product_object_id AND field_key = 'inventory_item_id';

-- shopify_id (Identifier) zeigt weiterhin auf "node.id" -- unveraendert im
-- Pfad, aendert aber seine Bedeutung: node ist jetzt die Variante, "node.id"
-- liefert also die VARIANTEN-ID statt der Produkt-ID.

-- Neue Felder: Produkt-ID (fuer Gruppierung/Anzeige, siehe ProductImportService
-- Varianten-Zweig) + Varianten-eigener Titel (z.B. "Rot / M").
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id)
VALUES (@shopify_product_object_id, 'product_id', 'Produkt-ID', 'string', 'node.product.id', 0, NULL);
INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id)
VALUES (@shopify_product_object_id, 'variant_title', 'Varianten-Titel', 'string', 'node.title', 0, NULL);

-- -----------------------------------------------------------------------------
-- Operationen: read_list/read_one auf die Varianten-Wurzelabfragen umstellen.
-- $productQuery ist optional (Standard: kein Filter -> ganzer Katalog) --
-- fuer einen auf ein Produkt eingeschraenkten Refresh (siehe
-- webhooks/product_update.php) mit "product_id:<numerische ID>" befuellt.
-- -----------------------------------------------------------------------------
UPDATE connect_object_operations
SET body_template = 'query($cursor: String, $productQuery: String) { productVariants(first: 100, after: $cursor, query: $productQuery) { edges { node { id title sku barcode price inventoryQuantity inventoryItem { id } product { id title handle vendor productType status } } } pageInfo { hasNextPage endCursor } } }',
    response_records_path = 'data.productVariants.edges'
WHERE object_id = @shopify_product_object_id AND operation_type = 'read_list';

UPDATE connect_object_operations
SET body_template = 'query($id: ID!) { node: productVariant(id: $id) { id title sku barcode price inventoryQuantity inventoryItem { id } product { id title handle vendor productType status } } }'
WHERE object_id = @shopify_product_object_id AND operation_type = 'read_one';

-- -----------------------------------------------------------------------------
-- Bestehender Cache: externe-ID-Schema hat sich geaendert (Produkt-ID ->
-- Varianten-ID) -- alte Zeilen sind nicht mehr sinnvoll interpretierbar,
-- naechster Sync baut den Cache unter dem neuen Schema neu auf.
-- -----------------------------------------------------------------------------
DELETE r FROM connect_object_records r
JOIN connect_connections c ON c.id = r.connection_id
WHERE r.object_key = 'product' AND c.connector_type_id = @shopify_connector_type_id;
