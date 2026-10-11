-- =============================================================================
-- connect/migrations/0001_create_schema.sql
--
-- Grundschema fuer "Connect". Kompatibel mit MariaDB 5.x (siehe Plan):
--   - kein nativer JSON-Typ  -> LONGTEXT-Spalten, En-/Dekodierung in PHP
--   - keine CHECK-Constraints, CTEs, Window-Functions oder generierte
--     Spalten (alles erst ab MariaDB 10.2)
--   - pro Tabelle nur EINE automatisch gesetzte Zeitspalte (created_at via
--     DEFAULT CURRENT_TIMESTAMP); updated_at wird bewusst explizit im
--     PHP-Code gesetzt, um nicht von der genauen 5.x-Subversion abhaengig
--     zu sein (DEFAULT CURRENT_TIMESTAMP auf mehreren Spalten pro Tabelle
--     ist erst ab MySQL 5.6.5 / spaeteren MariaDB-Versionen sicher garantiert)
--   - reservierte Woerter als Spaltennamen vermieden (z.B. "key" -> "type_key",
--     "role" -> "mapping_role")
--
-- Engine/Charset: InnoDB (Foreign Keys, Transaktionen) + utf8mb4 (wie die
-- bestehende oView-Datenbank).
-- =============================================================================

-- -----------------------------------------------------------------------------
-- connect_connector_types
-- Die "Vorlage" fuer ein anzubindendes System (Shopify, HelloCash, MGM/AGILA,
-- oder ein von einem Shop selbst im Connector-Builder definiertes System).
-- is_builtin=1 markiert von uns vorgefertigte Vorlagen; technisch laeuft
-- JEDE Zeile (builtin oder nicht) ueber denselben GenericHttpConnector.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS connect_connector_types (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    type_key            VARCHAR(64) NOT NULL COMMENT 'z.B. "shopify", "hellocash", "mgm_agila", oder ein frei vergebener Slug',
    name                VARCHAR(190) NOT NULL,
    description         TEXT NULL,
    icon                VARCHAR(64) NULL,
    is_builtin          TINYINT(1) NOT NULL DEFAULT 0,
    auth_type           VARCHAR(32) NOT NULL COMMENT 'none | api_key | bearer | basic | custom_headers',
    auth_field_schema   LONGTEXT NULL COMMENT 'JSON-kodiert: welche Zugangsdaten-Felder das Formular zeigt',
    request_format      VARCHAR(16) NOT NULL DEFAULT 'json' COMMENT 'json | xml',
    base_url_template   VARCHAR(255) NULL,
    created_by_shop     VARCHAR(190) NULL COMMENT 'NULL = global verfuegbare, von uns gepflegte Vorlage',
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_connector_types_type_key (type_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- connect_connections
-- Eine konkrete, pro Shop konfigurierte Instanz eines Connector Type
-- (z.B. "Shopify von Shop X" oder "MGM-Installation von Shop Y").
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS connect_connections (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    shop                VARCHAR(190) NOT NULL COMMENT 'Mandanten-Schluessel, wie im Hauptprojekt (Shopify-Subdomain)',
    connector_type_id   INT UNSIGNED NOT NULL,
    label               VARCHAR(190) NOT NULL COMMENT 'frei vergebener Anzeigename, z.B. "MGM Crystalp"',
    credentials         LONGTEXT NULL COMMENT 'JSON-kodiert, VERSCHLUESSELT ueber Connect\\Security\\Crypto',
    config              LONGTEXT NULL COMMENT 'JSON-kodiert, z.B. Basis-URL-Override fuer diese Connection',
    status              VARCHAR(32) NOT NULL DEFAULT 'pending' COMMENT 'pending | active | error',
    last_test_at        TIMESTAMP NULL DEFAULT NULL,
    last_test_result    TEXT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_connections_shop (shop),
    KEY idx_connections_connector_type (connector_type_id),
    CONSTRAINT fk_connections_connector_type
        FOREIGN KEY (connector_type_id) REFERENCES connect_connector_types (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- connect_objects
-- Eine Entitaet, die ein Connector Type anbietet (z.B. "Produkt", "Auftrag").
-- settings_schema deklariert zusaetzliche, objekt-spezifische Einstellungen
-- fuer Sync Mappings, die dieses Objekt nutzen (z.B. Mail-Versand bei Invoice).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS connect_objects (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    connector_type_id   INT UNSIGNED NOT NULL,
    object_key          VARCHAR(64) NOT NULL COMMENT 'z.B. "product", "stock", "order", "location"',
    label               VARCHAR(190) NOT NULL,
    settings_schema     LONGTEXT NULL COMMENT 'JSON-kodiert, siehe Tabellenkommentar',
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_objects_type_key (connector_type_id, object_key),
    CONSTRAINT fk_objects_connector_type
        FOREIGN KEY (connector_type_id) REFERENCES connect_connector_types (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- connect_object_fields
-- Ein Datenfeld eines Objects. parent_field_id bildet verschachtelte,
-- wiederholende Gruppen ab (z.B. MGM "Positionen/zl/ArtNr" -> ArtNr hat
-- als Parent das repeating-group-Feld "Positionen/zl").
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS connect_object_fields (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    object_id           INT UNSIGNED NOT NULL,
    field_key           VARCHAR(190) NOT NULL COMMENT 'z.B. "sku", "Positionen.zl.ArtNr"',
    label               VARCHAR(190) NOT NULL,
    data_type           VARCHAR(32) NOT NULL DEFAULT 'string' COMMENT 'string | number | date | bool',
    field_path          VARCHAR(255) NULL COMMENT 'JSONPath/XPath zur Extraktion bzw. Platzhaltername fuer Schreib-Templates',
    is_repeating_group  TINYINT(1) NOT NULL DEFAULT 0,
    parent_field_id     INT UNSIGNED NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_object_fields_object (object_id),
    KEY idx_object_fields_parent (parent_field_id),
    CONSTRAINT fk_object_fields_object
        FOREIGN KEY (object_id) REFERENCES connect_objects (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_object_fields_parent
        FOREIGN KEY (parent_field_id) REFERENCES connect_object_fields (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- connect_object_operations
-- Wie ein Object tatsaechlich gelesen/geschrieben wird (HTTP-Details).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS connect_object_operations (
    id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    object_id              INT UNSIGNED NOT NULL,
    operation_type         VARCHAR(32) NOT NULL COMMENT 'read_list | create | update',
    http_method            VARCHAR(8) NOT NULL DEFAULT 'GET',
    path_template          VARCHAR(255) NOT NULL COMMENT 'relativ zur Connection-Basis-URL, kann {{platzhalter}} enthalten',
    body_template          LONGTEXT NULL COMMENT 'JSON- oder XML-Template mit Platzhaltern + Repeat-Block-Syntax (nur bei create/update)',
    response_records_path  VARCHAR(255) NULL COMMENT 'Pfad zum wiederholenden Datensatz-Knoten (nur bei read_list)',
    static_params          LONGTEXT NULL COMMENT 'JSON-kodiert: fixe Query-/Body-Parameter, die immer mitgeschickt werden',
    created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_object_operations_object (object_id),
    CONSTRAINT fk_object_operations_object
        FOREIGN KEY (object_id) REFERENCES connect_objects (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- connect_lookup_tables / connect_lookup_table_entries
-- Wertetabellen fuer Transforms, z.B. Land-ISO-Code -> MGM-NamenID.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS connect_lookup_tables (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    shop        VARCHAR(190) NOT NULL,
    name        VARCHAR(190) NOT NULL,
    description TEXT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_lookup_tables_shop (shop)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS connect_lookup_table_entries (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    lookup_table_id  INT UNSIGNED NOT NULL,
    source_value     VARCHAR(255) NOT NULL,
    target_value     VARCHAR(255) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_lookup_entries_table (lookup_table_id),
    CONSTRAINT fk_lookup_entries_table
        FOREIGN KEY (lookup_table_id) REFERENCES connect_lookup_tables (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- connect_connection_locations
-- Standorte einer Connection (z.B. Shopify-Filialen). Bei Ein-Standort-
-- Systemen (die meisten ERPs) genuegt ein einzelner impliziter Eintrag.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS connect_connection_locations (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    connection_id        INT UNSIGNED NOT NULL,
    external_location_id VARCHAR(190) NOT NULL COMMENT 'ID des Standorts im externen System',
    label                VARCHAR(190) NOT NULL,
    is_default           TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_connection_locations (connection_id, external_location_id),
    CONSTRAINT fk_connection_locations_connection
        FOREIGN KEY (connection_id) REFERENCES connect_connections (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- connect_sync_mappings
-- Verknuepft ein Source-Object (Connection A) mit einem Target-Object
-- (Connection B): Richtung, Trigger, Standort-Handling, objekt-spezifische
-- Einstellungen (settings, ausgefuellt gemaess dem settings_schema des
-- jeweiligen Objects).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS connect_sync_mappings (
    id                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    shop                      VARCHAR(190) NOT NULL,
    name                      VARCHAR(190) NOT NULL,
    source_connection_id      INT UNSIGNED NOT NULL,
    source_object_id          INT UNSIGNED NOT NULL,
    target_connection_id      INT UNSIGNED NOT NULL,
    target_object_id          INT UNSIGNED NOT NULL,
    trigger_type              VARCHAR(32) NOT NULL DEFAULT 'schedule' COMMENT 'schedule | webhook | manual',
    schedule_interval_minutes INT UNSIGNED NULL,
    enabled                   TINYINT(1) NOT NULL DEFAULT 0,
    location_mode             VARCHAR(32) NOT NULL DEFAULT 'none' COMMENT 'none | primary | aggregate_sum | per_location',
    primary_location_id       INT UNSIGNED NULL,
    settings                  LONGTEXT NULL COMMENT 'JSON-kodiert, siehe connect_objects.settings_schema',
    created_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_sync_mappings_shop (shop),
    KEY idx_sync_mappings_source (source_connection_id, source_object_id),
    KEY idx_sync_mappings_target (target_connection_id, target_object_id),
    CONSTRAINT fk_sync_mappings_source_connection
        FOREIGN KEY (source_connection_id) REFERENCES connect_connections (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_sync_mappings_source_object
        FOREIGN KEY (source_object_id) REFERENCES connect_objects (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_sync_mappings_target_connection
        FOREIGN KEY (target_connection_id) REFERENCES connect_connections (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_sync_mappings_target_object
        FOREIGN KEY (target_object_id) REFERENCES connect_objects (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_sync_mappings_primary_location
        FOREIGN KEY (primary_location_id) REFERENCES connect_connection_locations (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- connect_sync_mapping_locations
-- Nur relevant bei location_mode = 'per_location': explizite Zuordnung
-- mehrerer Quell-Standorte auf einen Ziel-Standort (z.B. mehrere Shopify-
-- Filialen -> ein ERP-Lager).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS connect_sync_mapping_locations (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    sync_mapping_id   INT UNSIGNED NOT NULL,
    source_location_id INT UNSIGNED NOT NULL,
    target_location_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY idx_sync_mapping_locations_mapping (sync_mapping_id),
    CONSTRAINT fk_sync_mapping_locations_mapping
        FOREIGN KEY (sync_mapping_id) REFERENCES connect_sync_mappings (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_sync_mapping_locations_source
        FOREIGN KEY (source_location_id) REFERENCES connect_connection_locations (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_sync_mapping_locations_target
        FOREIGN KEY (target_location_id) REFERENCES connect_connection_locations (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- connect_field_mappings
-- Feld-Ebene innerhalb eines Sync Mapping. mapping_role unterscheidet
-- "value" (Wert wird uebertragen) von "match_key" (Feld dient nur dem
-- Zuordnen zweier Datensaetze, wie frueher Title/SKU/Barcode).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS connect_field_mappings (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    sync_mapping_id   INT UNSIGNED NOT NULL,
    mapping_role      VARCHAR(16) NOT NULL DEFAULT 'value' COMMENT 'value | match_key',
    source_field_id   INT UNSIGNED NULL COMMENT 'NULL bei transform_type = static',
    target_field_id   INT UNSIGNED NOT NULL,
    transform_type    VARCHAR(32) NOT NULL DEFAULT 'direct' COMMENT 'direct | static | lookup | concat | date_format',
    transform_config  LONGTEXT NULL COMMENT 'JSON-kodiert: statischer Wert, lookup_table_id, Format-String, etc.',
    sort_order        INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_field_mappings_sync_mapping (sync_mapping_id),
    CONSTRAINT fk_field_mappings_sync_mapping
        FOREIGN KEY (sync_mapping_id) REFERENCES connect_sync_mappings (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_field_mappings_source_field
        FOREIGN KEY (source_field_id) REFERENCES connect_object_fields (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_field_mappings_target_field
        FOREIGN KEY (target_field_id) REFERENCES connect_object_fields (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- connect_sync_mapping_actions
-- Post-Sync Actions: Nebenwirkungen nach einer (nicht) erfolgreichen
-- Synchronisation, die ueber reines Feld-Schreiben hinausgehen.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS connect_sync_mapping_actions (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    sync_mapping_id   INT UNSIGNED NOT NULL,
    action_type       VARCHAR(32) NOT NULL COMMENT 'send_email | write_back_response_field | create_placeholder_record',
    action_config     LONGTEXT NULL COMMENT 'JSON-kodiert, abhaengig von action_type',
    run_when          VARCHAR(16) NOT NULL DEFAULT 'on_success' COMMENT 'on_success | on_error',
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sync_mapping_actions_mapping (sync_mapping_id),
    CONSTRAINT fk_sync_mapping_actions_mapping
        FOREIGN KEY (sync_mapping_id) REFERENCES connect_sync_mappings (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- connect_sync_jobs
-- Warteschlangen-Tabelle nach Vorbild von oview_cronjobs: atomares Claim-
-- Verfahren erlaubt beliebig viele parallele Worker (siehe Plan, Abschnitt
-- "Portabilitaet"). cursor haelt den Fortschritt fest, damit ein Job bei
-- Zeitueberschreitung mit demselben Stand fortgesetzt werden kann, statt
-- (wie heute bei cron_hellocash_products_1-10.php etc.) in vorab
-- aufgeteilten Dateien zu arbeiten.
-- BIGINT statt INT als PK, da diese Tabelle mit Sync-Frequenz waechst.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS connect_sync_jobs (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sync_mapping_id  INT UNSIGNED NOT NULL,
    status           VARCHAR(16) NOT NULL DEFAULT 'pending' COMMENT 'pending | running | done | error',
    cursor_state     LONGTEXT NULL COMMENT 'JSON-kodiert: Fortschritt fuer Resume nach Zeitbudget-Ende',
    scheduled_for    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'dient zugleich als created_at dieser Zeile -- siehe Kommentar oben, nur EINE CURRENT_TIMESTAMP-Spalte pro Tabelle erlaubt',
    started_at       TIMESTAMP NULL DEFAULT NULL,
    ended_at         TIMESTAMP NULL DEFAULT NULL,
    locked_by        VARCHAR(190) NULL COMMENT 'Worker-Kennung (Host/PID), zur Beobachtung bei mehreren parallelen Workern',
    attempt_count    INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_sync_jobs_mapping (sync_mapping_id),
    KEY idx_sync_jobs_claim (status, scheduled_for),
    CONSTRAINT fk_sync_jobs_mapping
        FOREIGN KEY (sync_mapping_id) REFERENCES connect_sync_mappings (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- connect_sync_runs
-- Ausfuehrungsprotokoll fuer die UI (Sync-Historie), analog zu
-- hellofy_sync_log_stock. BIGINT als PK aus demselben Grund wie oben.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS connect_sync_runs (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sync_mapping_id  INT UNSIGNED NOT NULL,
    job_id           BIGINT UNSIGNED NULL,
    started_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at         TIMESTAMP NULL DEFAULT NULL,
    status           VARCHAR(16) NOT NULL DEFAULT 'running' COMMENT 'running | success | partial | error',
    records_read     INT UNSIGNED NOT NULL DEFAULT 0,
    records_written  INT UNSIGNED NOT NULL DEFAULT 0,
    records_failed   INT UNSIGNED NOT NULL DEFAULT 0,
    error_summary    TEXT NULL,
    PRIMARY KEY (id),
    KEY idx_sync_runs_mapping (sync_mapping_id),
    KEY idx_sync_runs_job (job_id),
    CONSTRAINT fk_sync_runs_mapping
        FOREIGN KEY (sync_mapping_id) REFERENCES connect_sync_mappings (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_sync_runs_job
        FOREIGN KEY (job_id) REFERENCES connect_sync_jobs (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
