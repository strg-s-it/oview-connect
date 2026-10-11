-- =============================================================================
-- connect/migrations/0071_worker_api_minute.sql
--
-- API-Aufrufe je Worker, System (Connector Type) und Minute -- fuer die
-- Lastverteilung (Betrieb -> Lastverteilung, admin/load.php), z.B. "worker2:
-- HelloCash 16/min, Shopify 109/min". Geschrieben von Connect\Http\ApiUsageRecorder.
--   worker   CONNECT_WORKER_NAME bzw. Rechnername, "web" fuer Webhooks/Admin-Klicks
--   type_key connect_connector_types.type_key (shopify, hellocash, ...)
-- Aufbewahrung kurz (Connect\Maintenance\Retention, Standard 3 Tage).
--
-- Hinweis: der Installer trennt am Semikolon -- deshalb keines in Strings oder Kommentaren.
-- =============================================================================

CREATE TABLE IF NOT EXISTS connect_worker_api_minute (
    worker       VARCHAR(64) NOT NULL,
    type_key     VARCHAR(64) NOT NULL,
    minute_start DATETIME NOT NULL,
    calls        INT UNSIGNED NOT NULL DEFAULT 0,
    errors       INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (worker, type_key, minute_start),
    KEY idx_worker_api_minute (minute_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
