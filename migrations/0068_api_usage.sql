-- =============================================================================
-- connect/migrations/0068_api_usage.sql
--
-- Verbrauch ausgehender API-Aufrufe sichtbar machen (Betrieb -> Jobs, Karte
-- "Shopify", und admin/shopify_activity.php), siehe Connect\Http\ApiUsageRecorder.
--
-- connect_api_usage_hourly: eine Zeile je Connection und Stunde mit Summen --
-- bewusst KEIN Eintrag je Aufruf, damit die Tabelle klein bleibt. Aufbewahrung
-- 30 Tage (bin/purge_privacy_data.php).
--   calls      Anzahl Aufrufe (inkl. Wiederholung nach Drosselung)
--   cost       Summe der von Shopify gemeldeten Abfragekosten (GraphQL actualQueryCost)
--   throttled  Antworten mit THROTTLED (Shopify GraphQL) oder HTTP 429
--   errors     sonstige fehlgeschlagene Aufrufe (HTTP >= 400 oder GraphQL-Fehler)
--
-- connect_api_bucket: zuletzt gemeldeter Stand des Shopify-Kosten-Eimers je
-- Connection (extensions.cost.throttleStatus: verfuegbar, Maximum, Nachfuellrate
-- pro Sekunde -- Maximum und Rate haengen vom Shopify-Tarif des Shops ab).
--
-- Hinweis: der Installer trennt am Semikolon -- deshalb keines in Strings oder Kommentaren.
-- =============================================================================

CREATE TABLE IF NOT EXISTS connect_api_usage_hourly (
    connection_id INT UNSIGNED NOT NULL,
    hour_start    DATETIME NOT NULL,
    calls         INT UNSIGNED NOT NULL DEFAULT 0,
    cost          INT UNSIGNED NOT NULL DEFAULT 0,
    throttled     INT UNSIGNED NOT NULL DEFAULT 0,
    errors        INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (connection_id, hour_start),
    KEY idx_api_usage_hour (hour_start),
    CONSTRAINT fk_api_usage_connection FOREIGN KEY (connection_id)
        REFERENCES connect_connections (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS connect_api_bucket (
    connection_id       INT UNSIGNED NOT NULL,
    currently_available DECIMAL(10,1) NULL,
    maximum_available   DECIMAL(10,1) NULL,
    restore_rate        DECIMAL(10,1) NULL,
    last_throttled_at   TIMESTAMP NULL DEFAULT NULL,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (connection_id),
    CONSTRAINT fk_api_bucket_connection FOREIGN KEY (connection_id)
        REFERENCES connect_connections (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
