-- =============================================================================
-- 0088 Preissynchronisation und Sammelaenderungen (Connect\Sync\PriceSyncRunner,
-- Connect\Sync\BatchEditService, Connect\Sync\PriceWriter)
--
--   connect_batch_changes        eine Sammelaenderung (Filter, Aktion, Ziel, Fortschritt) --
--                                auch die Laeufe der Preissynchronisation (kind = sync) und
--                                Rueckgaengig-Auftraege (undo_of) stehen hier
--   connect_batch_change_items   je Artikel und Seite: alter/neuer Preis, Status -- Grundlage fuer
--                                Vorschau, Ausfuehrung im Hintergrund, Rueckgaengig und Verlauf
--   connect_price_sync_exclusions Artikel, die die Preissynchronisation nie anfasst
--   connect_shops.price_sync_last_run_at  wann die Preissynchronisation zuletzt lief
--
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

CREATE TABLE IF NOT EXISTS connect_batch_changes (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    shop          VARCHAR(100) NOT NULL,
    kind          VARCHAR(16) NOT NULL DEFAULT 'batch' COMMENT 'batch | sync | undo',
    title         VARCHAR(255) NOT NULL,
    filter        TEXT NULL COMMENT 'JSON',
    action        TEXT NULL COMMENT 'JSON',
    apply_to      VARCHAR(16) NOT NULL DEFAULT 'shopify' COMMENT 'shopify | target | both',
    status        VARCHAR(16) NOT NULL DEFAULT 'queued' COMMENT 'queued | running | done | failed | undone',
    total         INT UNSIGNED NOT NULL DEFAULT 0,
    done          INT UNSIGNED NOT NULL DEFAULT 0,
    failed        INT UNSIGNED NOT NULL DEFAULT 0,
    undo_of       INT UNSIGNED NULL,
    created_by    VARCHAR(190) NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at   TIMESTAMP NULL,
    PRIMARY KEY (id),
    KEY idx_batch_shop (shop, created_at),
    KEY idx_batch_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS connect_batch_change_items (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id        INT UNSIGNED NOT NULL,
    match_value     VARCHAR(190) NOT NULL,
    label           VARCHAR(255) NULL,
    side            VARCHAR(8) NOT NULL COMMENT 'shopify | target',
    external_id     VARCHAR(190) NOT NULL,
    product_id      VARCHAR(190) NULL COMMENT 'Shopify-Produkt (Varianten werden je Produkt gesammelt geschrieben)',
    old_price       DECIMAL(12,2) NULL,
    new_price       DECIMAL(12,2) NULL,
    old_compare_at  DECIMAL(12,2) NULL,
    new_compare_at  DECIMAL(12,2) NULL,
    set_compare_at  TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Vergleichspreis schreiben (auch leeren)',
    status          VARCHAR(12) NOT NULL DEFAULT 'pending' COMMENT 'pending | done | failed | skipped',
    error           VARCHAR(500) NULL,
    done_at         TIMESTAMP NULL,
    PRIMARY KEY (id),
    KEY idx_batch_items (batch_id, status),
    KEY idx_batch_items_match (match_value),
    CONSTRAINT fk_batch_items_batch FOREIGN KEY (batch_id) REFERENCES connect_batch_changes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS connect_price_sync_exclusions (
    shop         VARCHAR(100) NOT NULL,
    match_value  VARCHAR(190) NOT NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (shop, match_value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE connect_shops
    ADD COLUMN price_sync_last_run_at TIMESTAMP NULL;
