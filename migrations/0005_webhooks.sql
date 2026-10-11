-- =============================================================================
-- connect/migrations/0005_webhooks.sql
--
-- Webhook-Infrastruktur fuer Connect, unabhaengig von der alten Welt:
--   - connect_webhook_subscriptions: welche Topics sind fuer welche
--     Connection bei Shopify registriert (Ergebnis von WebhookRegistrar).
--   - connect_webhook_events: eingehende, HMAC-geprueft empfangene Events,
--     analog zum Muster der alten Welt (hellocash_webhook_order_paid),
--     aber Connector-Type-uebergreifend nutzbar.
-- =============================================================================

CREATE TABLE IF NOT EXISTS connect_webhook_subscriptions (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    connection_id      INT UNSIGNED NOT NULL,
    topic              VARCHAR(64) NOT NULL COMMENT 'z.B. orders/paid, orders/create, products/update',
    external_webhook_id VARCHAR(190) NOT NULL COMMENT 'ID des Webhook-Abos beim externen System (z.B. Shopify webhook id)',
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_webhook_subscriptions (connection_id, topic),
    CONSTRAINT fk_webhook_subscriptions_connection
        FOREIGN KEY (connection_id) REFERENCES connect_connections (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS connect_webhook_events (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    shop          VARCHAR(190) NOT NULL,
    connection_id INT UNSIGNED NULL COMMENT 'NULL, falls zum Zeitpunkt des Empfangs keine passende Connection gefunden wurde',
    topic         VARCHAR(64) NOT NULL,
    payload       LONGTEXT NOT NULL COMMENT 'roher, bereits HMAC-verifizierter Request-Body',
    status        VARCHAR(16) NOT NULL DEFAULT 'received' COMMENT 'received | processed | error',
    error_message TEXT NULL,
    received_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at  TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_webhook_events_shop_topic (shop, topic),
    KEY idx_webhook_events_status (status),
    CONSTRAINT fk_webhook_events_connection
        FOREIGN KEY (connection_id) REFERENCES connect_connections (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
