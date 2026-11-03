-- =============================================================================
-- 0108 Kontaktdaten der Shops (Connect\Notify\ShopContacts, admin/export.php)
--
-- Name, echte Shop-URL (primaryDomain), Telefon und Mail laut Shopify-Stammdaten, je Shop eine Zeile.
-- Wird auf Knopfdruck live bei Shopify abgefragt (Shops beider Welten) und fuer den CSV-Export gemerkt.
--
-- MariaDB 5.5: nur EINE TIMESTAMP-Spalte darf DEFAULT CURRENT_TIMESTAMP haben, fetched_at setzt der Code.
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

CREATE TABLE IF NOT EXISTS connect_shop_contacts (
    shop          VARCHAR(190) NOT NULL,
    shop_name     VARCHAR(255) NULL,
    company       VARCHAR(255) NULL,
    url           VARCHAR(255) NULL COMMENT 'primaryDomain, die echte Adresse des Shops',
    email         VARCHAR(190) NULL,
    contact_email VARCHAR(190) NULL,
    phone         VARCHAR(64) NULL,
    address       VARCHAR(255) NULL,
    country       VARCHAR(8) NULL,
    error         VARCHAR(250) NULL,
    fetched_at    TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (shop)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
