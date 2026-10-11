-- =============================================================================
-- connect/migrations/0013_shop_settings.sql
--
-- Ersetzt das bisherige "Merchant legt selbst einen Sync-Job an" durch feste,
-- pro Shop gespeicherte Einstellungen (Vergleichswert, Lagerbestand-Richtung/
-- -Standort, Rechnungsanlage-Toggle) -- analog zu hellocash_binding.mapping /
-- hellofy_sync_settings_stock / hellofy_sync_settings_invoice in der alten
-- Welt, hier generisch fuer ein beliebiges Ziel-System (aktuell MGM/AGILA).
--
-- Nur die EINSTELLUNGEN werden hier gespeichert -- die tatsaechliche
-- Ausfuehrung (Lagerbestand nach MGM schreiben, Auftraege aus Shopify-
-- Bestellungen anlegen) ist bewusst NICHT Teil dieser Migration/dieses
-- Bausteins (siehe HANDOFF.md, Mapping Engine/Phase 3).
-- =============================================================================

ALTER TABLE connect_shops
  ADD COLUMN comparison_key VARCHAR(16) NOT NULL DEFAULT 'sku'
      COMMENT 'title | sku | barcode -- Vergleichswert fuers Produkt-Matching',
  ADD COLUMN target_connection_id INT UNSIGNED NULL
      COMMENT 'Ziel-Connection (z.B. MGM) fuer Lagerabgleich/Auftragsanlage, admin-seitig gesetzt',
  ADD COLUMN stock_sync_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN stock_sync_direction VARCHAR(32) NOT NULL DEFAULT 'shopify_to_target'
      COMMENT 'shopify_to_target | target_to_shopify -- wo wird der Lagerbestand bearbeitet',
  ADD COLUMN stock_primary_location_id VARCHAR(64) NULL
      COMMENT 'externe Shopify-Location-ID (aus connect_object_records, object_key=location)',
  ADD COLUMN invoice_creation_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ADD CONSTRAINT fk_shops_target_connection FOREIGN KEY (target_connection_id)
      REFERENCES connect_connections (id) ON DELETE SET NULL;

-- Kennzeichnet automatisch (system-)angelegte Sync Mappings (z.B. die feste
-- Shopify-Produkte-Synchronisierung) gegenueber vom Admin manuell angelegten
-- -- Merchants sehen system-managed Mappings nur lesend, koennen sie nicht
-- bearbeiten/loeschen (siehe _sync_mappings_body.php).
ALTER TABLE connect_sync_mappings
  ADD COLUMN managed_by VARCHAR(16) NOT NULL DEFAULT 'merchant'
      COMMENT 'system | merchant -- system-managed Mappings sind fuer Merchants nur lesbar';
