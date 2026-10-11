-- =============================================================================
-- 0092 Mail bei niedrigem Lagerstand (Connect\Notify\LowStockAlert)
--
-- connect_shops.low_stock_threshold: ab diesem Bestand (kleiner oder gleich) gilt ein Artikel
-- als knapp. NULL oder 0 = aus. Einmal am Tag (ab 7 Uhr) kommt eine Sammelmail an die Adresse
-- aus "Benachrichtigungen" - nur wenn seit der letzten Mail neue Artikel knapp geworden sind.
-- connect_low_stock_items merkt sich die knappen Artikel, damit derselbe Artikel nicht jeden
-- Tag erneut gemeldet wird. Wieder aufgefuellte Artikel fallen heraus und werden beim
-- naechsten Unterschreiten wieder gemeldet.
--
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

ALTER TABLE connect_shops
    ADD COLUMN low_stock_threshold INT UNSIGNED NULL DEFAULT NULL COMMENT 'Mail, wenn Bestand <= Wert, NULL/0 = aus',
    ADD COLUMN low_stock_checked_on DATE NULL DEFAULT NULL COMMENT 'letzte taegliche Pruefung';

CREATE TABLE IF NOT EXISTS connect_low_stock_items (
    shop          VARCHAR(100) NOT NULL,
    match_value   VARCHAR(190) NOT NULL,
    label         VARCHAR(255) NULL,
    quantity      INT NOT NULL,
    first_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notified_at   TIMESTAMP NULL,
    PRIMARY KEY (shop, match_value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
