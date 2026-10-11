-- =============================================================================
-- connect/migrations/0051_privacy_and_order_window.sql
--
-- Datenschutz (DSGVO) in Connect:
--
-- 1. connect_privacy_requests: Protokoll der Shopify-Pflicht-Webhooks
--    (customers/data_request, customers/redact, shop/redact) und des
--    Lebenszyklus-Webhooks app/uninstalled. Speichert bewusst KEINE
--    E-Mail/Telefonnummer des Kunden, nur die Shopify-Kunden-ID, Zaehler und
--    bei einer Datenauskunft das Ergebnis (wird nach kurzer Frist geloescht,
--    siehe bin/purge_privacy_data.php).
--
-- 2. Zeitfenster fuer gecachte Objekte (Datenminimierung): bisher holte der
--    Abgleich ALLE Bestellungen eines Shops seit Beginn samt Name, E-Mail,
--    Telefon und Adressen in connect_object_records. Jetzt kann pro Object ein
--    Zeitfenster gesetzt werden (max_age_days) und der Name der GraphQL-Variable
--    plus das Format des Suchfilters (age_filter_param, age_filter_format,
--    Platzhalter {date} = Stichtag als YYYY-MM-DD). ObjectCache::refresh()
--    setzt die Variable beim Abruf, sofern der Aufrufer sie nicht selbst
--    mitgibt. Fuer Shopify-Bestellungen: 90 Tage.
--
-- Hinweis: der Installer trennt am Semikolon -- deshalb keines in Strings.
-- =============================================================================

CREATE TABLE IF NOT EXISTS connect_privacy_requests (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    shop                VARCHAR(190) NOT NULL,
    topic               VARCHAR(64) NOT NULL COMMENT 'customers/data_request, customers/redact, shop/redact oder app/uninstalled',
    shopify_customer_id VARCHAR(64) NULL,
    status              VARCHAR(16) NOT NULL DEFAULT 'received' COMMENT 'received, processed oder error',
    summary             TEXT NULL COMMENT 'Zaehler je Tabelle (JSON), keine Personendaten',
    result              LONGTEXT NULL COMMENT 'nur bei customers/data_request: gefundene Daten (JSON), wird nach kurzer Frist geloescht',
    error_message       TEXT NULL,
    received_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at        TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_privacy_requests_shop (shop, received_at),
    KEY idx_privacy_requests_received (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE connect_objects
    ADD COLUMN max_age_days INT NULL COMMENT 'Zeitfenster in Tagen fuer den Listen-Abruf, NULL = unbegrenzt',
    ADD COLUMN age_filter_param VARCHAR(64) NULL COMMENT 'Name der Abruf-Variable, die den Zeitfilter aufnimmt (z.B. orderQuery)',
    ADD COLUMN age_filter_format VARCHAR(190) NULL COMMENT 'Filtertext mit Platzhalter {date}, z.B. created_at:>={date}';

-- Shopify-Bestellungen: Body um die optionale Variable orderQuery erweitern.
-- Idempotent (NOT LIKE) und ohne Wirkung, falls der Body von Hand veraendert wurde.
UPDATE connect_object_operations op
JOIN connect_objects o ON o.id = op.object_id
JOIN connect_connector_types t ON t.id = o.connector_type_id
SET op.body_template = REPLACE(REPLACE(op.body_template, 'query($cursor: String)', 'query($cursor: String, $orderQuery: String)'), 'orders(first: 100, after: $cursor)', 'orders(first: 100, after: $cursor, query: $orderQuery)')
WHERE t.type_key = 'shopify'
  AND o.object_key = 'order'
  AND op.operation_type = 'read_list'
  AND op.body_template LIKE '%orders(first: 100, after: $cursor)%'
  AND op.body_template NOT LIKE '%orderQuery%';

-- Zeitfenster nur setzen, wenn der Body tatsaechlich umgestellt ist -- sonst
-- wuerde ObjectCache eine Variable schicken, die die Abfrage nicht deklariert.
UPDATE connect_objects o
JOIN connect_connector_types t ON t.id = o.connector_type_id
SET o.max_age_days = 90, o.age_filter_param = 'orderQuery', o.age_filter_format = 'created_at:>={date}'
WHERE t.type_key = 'shopify'
  AND o.object_key = 'order'
  AND EXISTS (
      SELECT 1 FROM connect_object_operations op
      WHERE op.object_id = o.id AND op.operation_type = 'read_list' AND op.body_template LIKE '%$orderQuery%'
  );
