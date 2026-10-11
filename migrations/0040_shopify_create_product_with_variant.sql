-- =============================================================================
-- connect/migrations/0040_shopify_create_product_with_variant.sql
--
-- Neue Shopify-Operation "create_product": erster Schritt beim Anlegen der
-- ALLERERSTEN Variante einer neuen Produktgruppe (Connect\Sync\
-- ProductImportService::createNewProduct()) -- legt ein neues Produkt AN,
-- direkt mit der gewuenschten Variantenoption (z.B. "Größe" -> "Grau"), als
-- DRAFT (Merchant kann vor Veroeffentlichung pruefen, wie im Altsystem
-- manual.php). Setzt noch KEINE SKU/Barcode/Preis auf der Variante -- das
-- macht wie gehabt die bereits bestehende "create_variant"-Operation
-- (Migration 0031/0039) als ZWEITER Schritt, jetzt mit
-- strategy=REMOVE_STANDALONE_VARIANT, um die beim Anlegen automatisch
-- erzeugte Standalone-Variante durch die echte zu ersetzen -- exakt dieselbe
-- Zwei-Schritt-Sequenz wie im Altsystem manual.php (dort produktiv bewaehrt).
--
-- "result:"-Alias-Konvention (siehe Migration 0027) noetig, damit
-- GenericHttpConnector::write() userErrors generisch auslesen kann.
--
-- Noch NICHT mit "@idempotent" versehen (anders als inventorySetQuantities/
-- create_variant) -- ob productCreate das ebenfalls braucht, ist unbekannt,
-- bis zum ersten Live-Test. Absichtlich nicht vorab geraten (siehe die
-- mehrfachen Ueberraschungen bei den anderen Mutationen in dieser Session) --
-- wird bei Bedarf in einer Folge-Migration nachgezogen.
-- =============================================================================

SET @shopify_connector_type_id = (SELECT id FROM connect_connector_types WHERE type_key = 'shopify');
SET @shopify_product_object_id = (SELECT id FROM connect_objects WHERE connector_type_id = @shopify_connector_type_id AND object_key = 'product');

INSERT INTO connect_object_operations (object_id, operation_type, http_method, path_template, body_template, response_records_path, static_params)
VALUES (
    @shopify_product_object_id,
    'create_product',
    'POST',
    'admin/api/2026-07/graphql.json',
    'mutation($title: String!, $optionName: String!, $optionValue: String!) { result: productCreate(product: {title: $title, status: DRAFT, productOptions: [{name: $optionName, values: [{name: $optionValue}]}]}) { product { id } userErrors { field message } } }',
    NULL,
    NULL
);
