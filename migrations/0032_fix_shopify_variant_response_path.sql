-- =============================================================================
-- connect/migrations/0032_fix_shopify_variant_response_path.sql
--
-- Korrektur-Nachtrag zu Migration 0030: dort wurde beim Umstellen der
-- read_list-Operation des Shopify-Produkt-Objekts auf die
-- "productVariants"-Wurzelabfrage nur body_template angepasst,
-- response_records_path zunaechst vergessen (stand noch auf
-- "data.products.edges" statt "data.productVariants.edges"). Wurde vor dem
-- Einspielen in dieser Session selbst korrigiert -- dieser Nachtrag stellt
-- sicher, dass eine bereits mit der alten Fassung von 0030 eingespielte
-- Installation trotzdem den richtigen Endzustand bekommt (Migrationen werden
-- per Dateiname getrackt, siehe connect_schema_migrations -- eine spaeter
-- korrigierte 0030-Datei wird bei bereits erfolgtem Import NICHT erneut
-- angewandt).
--
-- Nimmt zusaetzlich eine unabhaengig davon aufgefallene Inkonsistenz mit:
-- "Lesen (Liste)" zeigte im Connector Builder noch auf
-- admin/api/2024-10/graphql.json, waehrend "Lesen (einzeln)" und
-- "Aktualisieren" bereits admin/api/2026-07/graphql.json nutzen -- vom
-- Nutzer bestaetigt: Versehen, auf 2026-07 vereinheitlichen.
--
-- Reines UPDATE, daher gefahrlos mehrfach/unabhaengig vom genauen
-- Ausgangszustand ausfuehrbar (kein INSERT-Duplikate-Risiko).
-- =============================================================================

SET @shopify_connector_type_id = (SELECT id FROM connect_connector_types WHERE type_key = 'shopify');
SET @shopify_product_object_id = (SELECT id FROM connect_objects WHERE connector_type_id = @shopify_connector_type_id AND object_key = 'product');

UPDATE connect_object_operations
SET response_records_path = 'data.productVariants.edges',
    path_template = 'admin/api/2026-07/graphql.json'
WHERE object_id = @shopify_product_object_id AND operation_type = 'read_list';
