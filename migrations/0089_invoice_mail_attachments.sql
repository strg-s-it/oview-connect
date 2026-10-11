-- =============================================================================
-- 0089 Anhaenge an der Rechnungsmail (Connect\Mail\ShopAttachments)
--
-- Der Merchant laedt PDFs hoch (z.B. Widerrufsbelehrung, AGB, Pflegehinweise), die jeder
-- Rechnungsmail zusaetzlich zur Rechnung angehaengt werden. Als Datei in der Datenbank (wie das
-- Branding), damit der rsync auf die Worker sie nicht beruehrt -- die Rechnungsmail wird auf den
-- Workern versendet.
--
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

CREATE TABLE IF NOT EXISTS connect_shop_attachments (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    shop        VARCHAR(100) NOT NULL,
    filename    VARCHAR(190) NOT NULL,
    mime        VARCHAR(64) NOT NULL DEFAULT 'application/pdf',
    data        MEDIUMBLOB NOT NULL,
    size        INT UNSIGNED NOT NULL,
    enabled     TINYINT(1) NOT NULL DEFAULT 1,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_shop_attachments_shop (shop)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
