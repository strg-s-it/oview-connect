-- =============================================================================
-- connect/migrations/0019_translation_mappings.sql
--
-- Grundlage fuer die Mapping Engine (Sync Mapping mit gesetztem Ziel statt
-- nur Cache-Refresh, siehe Connect\Sync\SyncRunner::translateAndWrite()):
--
-- trigger_topic haelt fest, welches Shopify-Webhook-Topic ein
-- webhook-getriggertes Mapping sofort ausloesen soll (z.B. "orders/paid") --
-- nur relevant bei trigger_type = 'webhook'.
--
-- connect_translation_log verhindert doppelte Zielsystem-Schreibvorgaenge
-- (z.B. Shopify liefert Webhooks "at least once", ein Scheduler-Nachlauf
-- koennte denselben Datensatz sonst ein zweites Mal uebersetzen) und haelt
-- fest, welcher Ziel-Datensatz (z.B. MGM-Auftragsnummer) aus welchem
-- Quell-Datensatz entstanden ist. Bewusst KEIN Unique-Verstoss bei einem
-- erneuten Versuch nach einem Fehler -- siehe ON DUPLICATE KEY UPDATE in
-- SyncRunner: ein fehlgeschlagener Versuch blockiert einen spaeteren
-- erfolgreichen Retry nicht, nur ein bereits ERFOLGREICHER Eintrag tut das.
-- =============================================================================

ALTER TABLE connect_sync_mappings
  ADD COLUMN trigger_topic VARCHAR(64) NULL
      COMMENT 'nur bei trigger_type=webhook, z.B. orders/paid -- welches Shopify-Webhook-Topic diese Uebersetzung sofort abloesen soll'
      AFTER trigger_type;

CREATE TABLE IF NOT EXISTS connect_translation_log (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sync_mapping_id    INT UNSIGNED NOT NULL,
    source_external_id VARCHAR(190) NOT NULL,
    target_external_id VARCHAR(190) NULL,
    status             VARCHAR(16) NOT NULL DEFAULT 'success' COMMENT 'success | error',
    error_message      TEXT NULL,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_translation_log (sync_mapping_id, source_external_id),
    CONSTRAINT fk_translation_log_mapping FOREIGN KEY (sync_mapping_id)
        REFERENCES connect_sync_mappings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
