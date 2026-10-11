-- =============================================================================
-- connect/migrations/0035_shopify_lookup_options.sql
--
-- Neue Shopify-Operation "lookup_options": liest den TATSAECHLICHEN
-- Options-Namen eines bestehenden Produkts live aus, BEVOR
-- Connect\Sync\ProductImportService eine neue Variante daran anlegt.
--
-- Grund (Altsystem-Erfahrung, siehe manual.php GetProductOptions): der
-- konfigurierte variant_option_name (connect_shops, Migration 0031) ist nur
-- der Name, unter dem SELBST erstellte Varianten angelegt werden -- weicht
-- er vom TATSAECHLICHEN Options-Namen des Ziel-Produkts ab (z.B. weil der
-- Merchant die Option in Shopify manuell von "Option" auf "Größe" umbenannt
-- hat), schlaegt productVariantsBulkCreate fehl oder legt faelschlich eine
-- zweite, eigene Option am Produkt an. Live-Abfrage direkt vor dem Schreiben
-- ist die einzig verlaessliche Quelle.
--
-- Technisch ueber die generische write()-Infrastruktur wiederverwendet
-- (kein neuer Connector-Code noetig): body_template ist eine reine
-- GraphQL-QUERY (keine Mutation) -- GenericHttpConnector::write() sendet sie
-- trotzdem korrekt (identischer Body-Aufbau wie bei Lesezugriffen) und
-- liefert die volle, rohe Antwort in WriteResult::responseData zurueck. Kein
-- "result:"-Alias noetig, da diese Operation keine Mutation ist und die
-- generische userErrors-Pruefung in write() hier ins Leere laeuft (kein
-- "result"-Schluessel vorhanden = keine Fehler erkannt, korrektes Verhalten).
-- =============================================================================

SET @shopify_connector_type_id = (SELECT id FROM connect_connector_types WHERE type_key = 'shopify');
SET @shopify_product_object_id = (SELECT id FROM connect_objects WHERE connector_type_id = @shopify_connector_type_id AND object_key = 'product');

INSERT INTO connect_object_operations (object_id, operation_type, http_method, path_template, body_template, response_records_path, static_params)
VALUES (
    @shopify_product_object_id,
    'lookup_options',
    'POST',
    'admin/api/2026-07/graphql.json',
    'query($id: ID!) { product(id: $id) { options { name } } }',
    NULL,
    NULL
);
