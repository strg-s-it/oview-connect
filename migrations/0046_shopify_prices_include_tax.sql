-- =============================================================================
-- connect/migrations/0046_shopify_prices_include_tax.sql
--
-- Ob die in Shopify gepflegten Preise Netto oder Brutto (inkl. MwSt.) sind --
-- pro Shop unterschiedlich (haengt von Shopifys "taxesIncluded"-Einstellung
-- ab, siehe connect_objects "shop"/Feld "taxes_included", Migration 0007).
-- NULL = noch nicht festgelegt. Bewusst eine PERSISTENTE, vom Merchant/Admin
-- editierbare Einstellung (app/_sync_mappings_body.php) statt einer Live-
-- Abfrage bei jedem Import -- robuster (kein Import-Abbruch bei einem
-- kurzzeitigen API-Fehler) und ueberschreibbar, falls die Shopify-Einstellung
-- die tatsaechliche Preispflege nicht korrekt widerspiegelt. Vorbelegung kann
-- trotzdem per Knopfdruck aus Shopify uebernommen werden (siehe Settings-Seite).
--
-- Gebraucht von Connect\Mapping\MappingEngine (transform_type='derived_price',
-- z.B. HelloCash "net_selling_price"/"gross_selling_price" aus Shopifys
-- einzelnem Preis-Feld + manuell eingegebenem Steuersatz berechnet).
-- =============================================================================

ALTER TABLE connect_shops
    ADD COLUMN shopify_prices_include_tax TINYINT(1) NULL AFTER variant_option_name;
