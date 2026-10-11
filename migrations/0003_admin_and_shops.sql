-- =============================================================================
-- connect/migrations/0003_admin_and_shops.sql
--
-- Zwei neue Tabellen fuer die Zugriffs-Trennung (siehe Plan-Diskussion):
--   - connect_admin_users: eigenes, von der alten Welt (admin_accounts)
--     unabhaengiges Login fuer den Betreiber-Bereich (connect.oview.at/admin).
--   - connect_shops: Register aller Shops, die Connect je geoeffnet haben --
--     Grundlage fuer die Shop-Auswahlliste im Admin-Bereich.
-- =============================================================================

CREATE TABLE IF NOT EXISTS connect_admin_users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email         VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL COMMENT 'password_hash(), nie im Klartext',
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS connect_shops (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    shop         VARCHAR(190) NOT NULL,
    display_name VARCHAR(190) NULL,
    first_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at  TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_shops_shop (shop)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
