-- =============================================================================
-- connect/migrations/0082_merchant_messages.sql
--
-- Nachrichten an Merchants (Admin -> Betrieb -> Nachrichten, Connect\Notify\MessageService):
-- Rundschreiben an Shops der alten UND neuen Welt, gefiltert, mit Platzhaltern je Shop
-- (z.B. persoenlicher Link zum Erteilen neuer Shopify-Berechtigungen), Versand im
-- Hintergrund ueber den Scheduler von office@oview.at, optional zusaetzlich als Hinweis in
-- der Uebersicht der App.
--
--   connect_messages            die Nachricht (Art service/info, Betreff, Text, Filter, Status)
--   connect_message_recipients  je Shop: Adresse, Status, Zeit, Fehler, in der App gelesen
--   connect_message_optouts     Abmeldungen von Informations-Mails (Service-Mails gehen immer)
--
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

CREATE TABLE IF NOT EXISTS connect_messages (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    kind             VARCHAR(16) NOT NULL DEFAULT 'service' COMMENT 'service = zum Vertrag, info = Information mit Abmelde-Link',
    subject          VARCHAR(255) NOT NULL,
    body             MEDIUMTEXT NOT NULL,
    in_app           TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'zusaetzlich als Hinweis in der Uebersicht (nur Shops in Connect)',
    filter           TEXT NULL COMMENT 'JSON der gewaehlten Filter, zur Nachvollziehbarkeit',
    status           VARCHAR(16) NOT NULL DEFAULT 'draft' COMMENT 'draft | queued | done',
    recipient_count  INT UNSIGNED NOT NULL DEFAULT 0,
    created_by       VARCHAR(190) NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    queued_at        DATETIME NULL,
    finished_at      DATETIME NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS connect_message_recipients (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    message_id  INT UNSIGNED NOT NULL,
    shop        VARCHAR(190) NOT NULL,
    world       VARCHAR(16) NOT NULL COMMENT 'connect | legacy',
    email       VARCHAR(190) NULL,
    status      VARCHAR(16) NOT NULL DEFAULT 'pending' COMMENT 'pending | sent | failed | skipped',
    error       VARCHAR(255) NULL,
    sent_at     DATETIME NULL,
    read_at     DATETIME NULL COMMENT 'Hinweis in der App weggeklickt',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_message_recipient (message_id, shop),
    KEY idx_message_recipient_status (status, message_id),
    KEY idx_message_recipient_shop (shop, read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS connect_message_optouts (
    shop        VARCHAR(190) NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (shop)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
