-- =============================================================================
-- 0091 Sets und Bundles (Connect\Sync\BundleService)
--
-- Ein Set ist ein Shopify-Artikel (Vergleichswert bundle_match), der aus Einzelteilen des
-- Kassensystems besteht. Bestand des Sets in Shopify = Minimum ueber die Einzelteile
-- (Kassenbestand / Menge, abgerundet). Verkauf eines Sets in Shopify bucht die Einzelteile im
-- Kassensystem ab. Nur bei Richtung "Kassensystem fuehrt".
--
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

CREATE TABLE IF NOT EXISTS connect_bundles (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    shop          VARCHAR(100) NOT NULL,
    bundle_match  VARCHAR(190) NOT NULL COMMENT 'Vergleichswert des Set-Artikels in Shopify',
    label         VARCHAR(255) NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bundles_shop_match (shop, bundle_match)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS connect_bundle_components (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    bundle_id        INT UNSIGNED NOT NULL,
    component_match  VARCHAR(190) NOT NULL COMMENT 'Vergleichswert des Einzelteils (in beiden Systemen)',
    quantity         INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_bundle_components_bundle (bundle_id),
    CONSTRAINT fk_bundle_components_bundle FOREIGN KEY (bundle_id) REFERENCES connect_bundles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
