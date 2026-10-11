-- =============================================================================
-- connect/migrations/0059_auto_import_missing_products.sql
--
-- Automatischer Import fehlender Produkte (Shopify -> Zielsystem, z.B.
-- HelloCash) -- Nutzer-Entscheidung (2026-09-27), nach Pruefung von
-- jobs/cron_shopify_product_sync.php (laeuft aktiv fuer Shop "neherashop",
-- siehe Scheduler-URL .../scheduler.php?shop=neherashop&file=cron_shopify_
-- product_sync.php): legt automatisch HelloCash-Artikel fuer Shopify-Produkte
-- an, die dort noch fehlen -- bisher in Connect nur als manueller
-- "Importieren"-Klick verfuegbar (Connect\Sync\ProductImportService).
--
-- WICHTIG (Nutzer-Vorgabe): das ist eine ADMIN-ONLY-Einstellung -- der Admin
-- aktiviert das bei Bedarf FUER einen Merchant, der Merchant kann es nicht
-- selbst umschalten (gleiches Prinzip wie "neue Connection anlegen", siehe
-- app/_connections_body.php). Deshalb bewusst NICHT im selben Bereich wie
-- die anderen, merchant-editierbaren Einstellungen auf dieser Seite.
--
-- Kein enabled-Flag am connect_sync_mappings-Eintrag selbst noetig -- die
-- Spalte hier UND das Mapping werden zusammen verwaltet (siehe
-- app/_sync_mappings_body.php, admin-only Abschnitt).
-- =============================================================================

ALTER TABLE connect_shops
    ADD COLUMN auto_import_missing_to_target TINYINT(1) NOT NULL DEFAULT 0 AFTER invoice_suppress_and_adjust_stock;
