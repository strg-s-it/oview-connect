-- =============================================================================
-- connect/migrations/0079_notifications_reports_plans.sql
--
-- 1. Benachrichtigungen (eigene Karte in den Einstellungen, Connect\Notify\Notifier)
--    notify_email           Empfaenger fuer den Merchant, NULL = Shop-E-Mail aus Shopify
--    notify_problems        Probleme sofort melden (Rechnung nicht angelegt usw.),
--                           Kopie immer an CONNECT_ERROR_NOTIFICATION_EMAIL
--    weekly_report_enabled  Wochenbericht jeden Montag (Connect\Notify\WeeklyReport)
--    Uebernimmt die bisherige Fehler-Adresse der Rechnungskarte (invoice_error_notification_email).
--
-- 2. Wochenbericht: connect_report_mails merkt sich je Shop und Woche, ob gesendet wurde
--    (kein doppelter Bericht, auch wenn mehrere Scheduler gleichzeitig laufen).
--
-- 3. Abo (Connect\Billing\BillingService), wie in der alten Welt: OVIEW HELLOCASH / + / ++
--    steuert die Haeufigkeit des Lagerabgleichs (60/30/15 Min.) und den eigenen SMTP-Versand.
--    Gelesen aus Shopify (currentAppInstallation.activeSubscriptions).
--
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

ALTER TABLE connect_shops
    ADD COLUMN notify_email VARCHAR(190) NULL COMMENT 'Empfaenger Benachrichtigungen, NULL = Shop-E-Mail aus Shopify',
    ADD COLUMN notify_problems TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Probleme sofort per Mail melden',
    ADD COLUMN weekly_report_enabled TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Wochenbericht jeden Montag',
    ADD COLUMN plan VARCHAR(16) NULL COMMENT 'standard | plus | plusplus, NULL = unbekannt',
    ADD COLUMN plan_status VARCHAR(32) NULL COMMENT 'Status des Shopify-Abos (ACTIVE ...), none = keines',
    ADD COLUMN plan_subscription_id VARCHAR(190) NULL COMMENT 'GID des aktiven Shopify-Abos',
    ADD COLUMN plan_trial_ends_at DATETIME NULL COMMENT 'Ende des Testzeitraums, falls einer laeuft',
    ADD COLUMN plan_checked_at DATETIME NULL COMMENT 'zuletzt bei Shopify abgefragt';

UPDATE connect_shops SET notify_email = invoice_error_notification_email
WHERE invoice_error_notification_email IS NOT NULL AND invoice_error_notification_email <> '';

CREATE TABLE IF NOT EXISTS connect_report_mails (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    shop          VARCHAR(190) NOT NULL,
    report_type   VARCHAR(32) NOT NULL COMMENT 'weekly',
    period_start  DATE NOT NULL COMMENT 'Montag der berichteten Woche',
    recipient     VARCHAR(190) NULL,
    status        VARCHAR(16) NOT NULL COMMENT 'sent | failed | skipped',
    note          VARCHAR(255) NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_report_mails (shop, report_type, period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
