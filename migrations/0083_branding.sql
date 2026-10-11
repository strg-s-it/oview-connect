-- =============================================================================
-- 0083 Branding (Admin -> Betrieb -> Branding, Connect\Setup\Branding)
--
--   connect_brand_assets   Logo und Favicon als Datei in der Datenbank, ausgeliefert ueber
--                          public/brand.php. Bewusst NICHT unter public/assets/ - der Code
--                          wird per rsync auf main und die Worker kopiert, ein Upload dort
--                          koennte beim naechsten Abgleich verschwinden.
--
-- Name, Farbe, Schrift und Mail-Fusszeile stehen in connect_settings (brand_*).
--
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

CREATE TABLE IF NOT EXISTS connect_brand_assets (
    asset_key   VARCHAR(32) NOT NULL COMMENT 'logo | favicon',
    mime        VARCHAR(64) NOT NULL,
    data        MEDIUMBLOB NOT NULL,
    hash        CHAR(40) NOT NULL COMMENT 'sha1 der Datei, fuer Cache-Busting',
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (asset_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
