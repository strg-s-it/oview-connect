-- =============================================================================
-- connect/migrations/0053_connector_rate_limits.sql
--
-- Konfigurierbares Anfrage-Limit pro Connector Type (Shopify, HelloCash, MGM, ...),
-- siehe Connect\Http\RateLimiter. Zwei Spalten an connect_connector_types:
--   rate_limit_max_requests   Hoechstens so viele Aufrufe ...
--   rate_limit_window_seconds ... innerhalb von so vielen Sekunden.
-- Beide NULL = kein Limit (unveraendertes Verhalten, Standard fuer neue/bestehende
-- Connector Types, bis ein Admin explizit einen Wert eintraegt).
--
-- connect_rate_limit_state haelt den LAUFENDEN Zaehlerstand -- ein Fenster je
-- Connector Type, geteilt von allen gleichzeitig laufenden Scheduler-Prozessen
-- (und kuenftig mehreren Workern), deshalb in der DB statt im PHP-Prozess.
-- blocked_until ist unabhaengig vom Zaehler: wird gesetzt, wenn das Zielsystem
-- selbst mit HTTP 429 + Retry-After geantwortet hat (reaktiv), waehrend
-- rate_limit_max_requests/window die selbst auferlegte, VORBEUGENDE Bremse ist.
--
-- Startwert fuer HelloCash: 40 Aufrufe / 60 Sekunden -- identisch zur Grenze in
-- der alten Welt (jobs/scheduler.php, dort PRO WORKER/IP gezaehlt). Connect laeuft
-- heute nur von einer IP, das entspricht also demselben Sicherheitsabstand.
-- Shopify und MGM bewusst ohne Limit (NULL) -- bei Bedarf im Connector-Type-
-- Editor eintragen.
--
-- Hinweis: der Installer trennt am Semikolon -- deshalb keines in Strings.
-- =============================================================================

ALTER TABLE connect_connector_types
    ADD COLUMN rate_limit_max_requests INT UNSIGNED NULL COMMENT 'Hoechstens so viele ausgehende Aufrufe je rate_limit_window_seconds, NULL = kein Limit',
    ADD COLUMN rate_limit_window_seconds INT UNSIGNED NULL COMMENT 'Zeitfenster in Sekunden fuer rate_limit_max_requests';

CREATE TABLE IF NOT EXISTS connect_rate_limit_state (
    connector_type_id INT UNSIGNED NOT NULL,
    window_started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    request_count      INT UNSIGNED NOT NULL DEFAULT 0,
    blocked_until      TIMESTAMP NULL DEFAULT NULL COMMENT 'explizite Sperre durch ein echtes HTTP 429 mit Retry-After',
    PRIMARY KEY (connector_type_id),
    CONSTRAINT fk_rate_limit_state_type FOREIGN KEY (connector_type_id)
        REFERENCES connect_connector_types (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE connect_connector_types
SET rate_limit_max_requests = 40, rate_limit_window_seconds = 60
WHERE type_key = 'hellocash';
