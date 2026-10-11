-- =============================================================================
-- 0090 Produkt-Check (Connect\Sync\ProductCheck)
--
-- Je Shop der letzte Produkt-Check: Shopify-Massenabfrage (bulkOperationRunQuery) laeuft bei
-- Shopify im Hintergrund, Connect holt das Ergebnis ab, sobald es fertig ist, und speichert
-- Punktwert, Zaehler je Pruefung und die Liste der Produkte mit Befunden (JSON).
--
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

CREATE TABLE IF NOT EXISTS connect_product_checks (
    shop          VARCHAR(100) NOT NULL,
    operation_id  VARCHAR(190) NULL COMMENT 'gid der Shopify-Massenabfrage',
    status        VARCHAR(16) NOT NULL DEFAULT 'running' COMMENT 'running | done | failed',
    score         TINYINT UNSIGNED NULL COMMENT '0-100',
    products      INT UNSIGNED NULL,
    summary       TEXT NULL COMMENT 'JSON: Pruefung => Anzahl Produkte',
    issues        MEDIUMTEXT NULL COMMENT 'JSON: Liste der Produkte mit Befunden',
    error         VARCHAR(500) NULL,
    started_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at   TIMESTAMP NULL,
    PRIMARY KEY (shop)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
