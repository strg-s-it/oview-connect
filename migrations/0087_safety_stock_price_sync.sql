-- =============================================================================
-- 0087 Sicherheitsbestand und Preissynchronisation (Connect\Sync\ShopStockRules,
-- Connect\Sync\PriceSyncRunner)
--
--   stock_safety_buffer       Shopify zeigt "Bestand im Kassensystem minus Puffer" (nie unter 0),
--                             gegen Ueberverkauf, wenn im Laden gleichzeitig verkauft wird. Nur bei
--                             Richtung "Kassensystem fuehrt" (target_to_shopify). 0 = aus.
--   price_sync_mode           off | target_to_shopify | shopify_to_target
--   price_sync_max_change_pct groessere Spruenge werden nicht automatisch geschrieben, sondern zur
--                             Pruefung angezeigt (Tippfehler-Schutz). 0 = keine Grenze.
--   price_sync_confirmed_at   erst nach Bestaetigung der Vorschau (alle Unterschiede) wird geschrieben
--
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

ALTER TABLE connect_shops
    ADD COLUMN stock_safety_buffer INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN price_sync_mode VARCHAR(24) NOT NULL DEFAULT 'off',
    ADD COLUMN price_sync_max_change_pct INT UNSIGNED NOT NULL DEFAULT 30,
    ADD COLUMN price_sync_confirmed_at TIMESTAMP NULL;
