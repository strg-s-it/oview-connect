-- =============================================================================
-- 0104 Rueckbuchung bei Kassenverkauf (Connect\Sync\StockBackRunner, mapping_kind='stock_back')
--
-- Portiert aus der alten Welt (jobs/cron_sync_stock_back.php, "Zurueck beim Verkauf"):
-- Bei Richtung "Shopify fuehrt" wuerde der Lagerabgleich einen Kassenverkauf sonst wieder
-- ueberschreiben (Kasse 10 -> 9, Shopify bleibt 10, naechster Abgleich setzt die Kasse auf 10).
-- Der Job erkennt neue Kassenrechnungen, liest den aktuellen Bestand der verkauften Artikel
-- und setzt Shopify darauf. Optional legt er je Rechnung eine Bestellung in Shopify an
-- ("Dummy-Bestellung", Notiz "POS helloCash #Nr"), damit Kassenverkaeufe im Shopify-Reporting
-- erscheinen.
--
--   stock_back_enabled       1 = Rueckbuchung aktiv (nur sinnvoll bei Richtung shopify_to_target)
--   stock_back_dummy_order   1 = zusaetzlich Dummy-Bestellung in Shopify anlegen
--   stock_back_since         nur Rechnungen NACH diesem Zeitpunkt (verhindert, dass alte
--                            Rechnungen nachtraeglich verarbeitet werden)
--
-- connect_stock_back_log: je Kassenrechnung genau eine Zeile = atomarer Claim (UNIQUE), damit
-- eine Rechnung auch bei mehreren Workern nur einmal verarbeitet wird. Fehler duerfen bis zu
-- fuenfmal erneut versucht werden, haengende "pending"-Zeilen nach 30 Minuten.
--
-- Zusaetzlich noetig im Connector Builder (helloCash, Object "invoice_read", siehe HANDOFF.md):
-- read_list und read_one fuer GET /invoices. Ohne sie ueberspringt der Job mit Hinweis.
--
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

ALTER TABLE connect_shops
    ADD COLUMN stock_back_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN stock_back_dummy_order TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN stock_back_since DATETIME NULL;

CREATE TABLE IF NOT EXISTS connect_stock_back_log (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    shop           VARCHAR(190) NOT NULL,
    invoice_id     VARCHAR(64) NOT NULL COMMENT 'Kennung der Kassenrechnung im Zielsystem',
    invoice_number VARCHAR(64) NULL,
    status         VARCHAR(16) NOT NULL DEFAULT 'pending' COMMENT 'pending | success | skipped | error',
    stock_status   VARCHAR(16) NULL COMMENT 'success | error, NULL = noch nicht versucht',
    dummy_status   VARCHAR(16) NULL COMMENT 'success | skipped | error, NULL = noch nicht versucht',
    attempts       INT UNSIGNED NOT NULL DEFAULT 1,
    message        TEXT NULL,
    claimed_at     TIMESTAMP NULL DEFAULT NULL,
    finished_at    TIMESTAMP NULL DEFAULT NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stock_back_shop_invoice (shop, invoice_id),
    KEY idx_stock_back_shop_created (shop, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
