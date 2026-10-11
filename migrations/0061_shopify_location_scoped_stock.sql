-- =============================================================================
-- connect/migrations/0061_shopify_location_scoped_stock.sql
--
-- Nutzer-Entscheidung (2026-09-27): "immer nur Produkte vom gewaehlten
-- Standort ... verwenden. das ist auch sowas wie ein Filter." -- Pruefung
-- ergab: connect_shops.stock_primary_location_id wurde bisher NUR beim
-- SCHREIBEN nach Shopify verwendet (StockSyncRunner, inventorySetQuantities),
-- beim LESEN liefert Shopifys "inventoryQuantity" laut eigener API-Doku IMMER
-- die Summe ueber ALLE Standorte -- ein Mehrstandort-Shop vergleicht/importiert
-- also bisher gegen den GESAMT-Bestand, nicht den des gewaehlten Standorts.
--
-- Neues Feld "location_stock_quantity": liest stattdessen gezielt
-- InventoryItem.inventoryLevel(locationId: $locationId), NULL wenn die
-- Variante an diesem Standort gar nicht gefuehrt wird (Shopify liefert dann
-- inventoryLevel: null) -- genau das Signal, um so ein Produkt wie in Shopify
-- nicht vorhanden zu behandeln (siehe Connect\Sync\ProductComparisonService/
-- StockSyncRunner).
--
-- $locationId/$hasLocation sind ALS VARIABLEN MIT DEFAULTWERT ergaenzt
-- ($hasLocation: Boolean = false) -- bestehende Aufrufe, die diese Variablen
-- nicht kennen, bleiben unveraendert gueltig (Feld wird per @include(if:)
-- uebersprungen, "inventoryQuantity" bleibt zusaetzlich als Alle-Standorte-
-- Summe erhalten -- KEIN Ersatz, ein Shop OHNE gewaehlten Standort behaelt
-- sein bisheriges Verhalten exakt bei). Die tatsaechlichen Werte werden zur
-- Laufzeit ergaenzt (Connect\Sync\ObjectCache::withLocationFilter()), nicht
-- hier hartcodiert -- pro Shop unterschiedlich.
--
-- Ob "location_stock_quantity" oder das bisherige "inventory_quantity" fuer
-- den Lagerbestand-Abgleich/Vergleich massgeblich ist, entscheidet weiterhin
-- der Admin im Connector Builder (is_stock_quantity-Markierung, wie jede
-- andere Rollen-Zuordnung in Connect) -- keine Sonderlogik im Anwendungscode
-- fuer einzelne Shops.
--
-- Wie bei allen bisherigen Aenderungen an dieser Operation: read_list UND
-- read_one werden komplett neu gesetzt (kein Diff-Update moeglich).
-- =============================================================================

SET @shopify_connector_type_id = (SELECT id FROM connect_connector_types WHERE type_key = 'shopify');
SET @shopify_product_object_id = (SELECT id FROM connect_objects WHERE connector_type_id = @shopify_connector_type_id AND object_key = 'product');

INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES
    (@shopify_product_object_id, 'location_stock_quantity', 'Lagerbestand am gewählten Standort', 'number', 'node.inventoryItem.inventoryLevel.quantities.0.quantity', 0, NULL);

UPDATE connect_object_operations
SET body_template = 'query($cursor: String, $productQuery: String, $locationId: ID = "gid://shopify/Location/0", $hasLocation: Boolean = false) { productVariants(first: 100, after: $cursor, query: $productQuery) { edges { node { id title sku barcode price inventoryQuantity inventoryItem { id inventoryLevel(locationId: $locationId) @include(if: $hasLocation) { quantities(names: ["available"]) { quantity } } } product { id title handle vendor productType status } } } pageInfo { hasNextPage endCursor } } }'
WHERE object_id = @shopify_product_object_id AND operation_type = 'read_list';

UPDATE connect_object_operations
SET body_template = 'query($id: ID!, $locationId: ID = "gid://shopify/Location/0", $hasLocation: Boolean = false) { node: productVariant(id: $id) { id title sku barcode price inventoryQuantity inventoryItem { id inventoryLevel(locationId: $locationId) @include(if: $hasLocation) { quantities(names: ["available"]) { quantity } } } product { id title handle vendor productType status } } }'
WHERE object_id = @shopify_product_object_id AND operation_type = 'read_one';
