-- =============================================================================
-- connect/migrations/0054_invoice_mail_settings.sql
--
-- Merchant-Einstellungen fuer die Rechnungserstellung (Connect\Sync\InvoiceService,
-- ausgeloest ueber connect_sync_mappings.mapping_kind='invoice', siehe Migration 0028
-- fuer das Vorbild "stock_sync"):
--
--   - invoice_cashier_id/invoice_payment_method: an HelloCash POST /invoices durchgereicht
--     (beide optional laut HelloCash-Schema, Standard-Zahlungsart "Webshop" wie in der alten Welt).
--   - invoice_mail_*: automatischer Mailversand der Rechnung an den Kunden -- STANDARDMAESSIG
--     AUS (invoice_mail_enabled=0), Merchant entscheidet bewusst. Eigene SMTP-Zugangsdaten
--     optional (wie in der alten Welt, settings.php) -- ohne sie greift der System-Versand.
--     invoice_mail_smtp_password liegt VERSCHLUESSELT (Connect\Security\Crypto, derselbe
--     Mechanismus wie connect_connections.credentials -- NICHT die alte Welt's eigene
--     inc/crypto_helper.php, Connect kennt die alte Welt zur Laufzeit nicht, siehe Plan
--     "Verhaeltnis zur bestehenden Codebasis").
--   - invoice_error_notification_email: eigene Fehler-Adresse des Merchants; leer = Standard-
--     Adresse aus connect/.env (CONNECT_ERROR_NOTIFICATION_EMAIL).
--
-- Hinweis: der Installer trennt am Semikolon -- deshalb keines in Strings.
-- =============================================================================

ALTER TABLE connect_shops
    ADD COLUMN invoice_cashier_id INT UNSIGNED NULL,
    ADD COLUMN invoice_payment_method VARCHAR(100) NOT NULL DEFAULT 'Webshop',
    ADD COLUMN invoice_mail_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN invoice_mail_sender_name VARCHAR(190) NULL,
    ADD COLUMN invoice_mail_sender_address VARCHAR(190) NULL,
    ADD COLUMN invoice_mail_subject VARCHAR(255) NULL,
    ADD COLUMN invoice_mail_message TEXT NULL,
    ADD COLUMN invoice_mail_smtp_host VARCHAR(190) NULL,
    ADD COLUMN invoice_mail_smtp_port SMALLINT UNSIGNED NULL,
    ADD COLUMN invoice_mail_smtp_username VARCHAR(190) NULL,
    ADD COLUMN invoice_mail_smtp_password TEXT NULL COMMENT 'verschluesselt, siehe Connect\\Security\\Crypto',
    ADD COLUMN invoice_mail_smtp_encryption VARCHAR(8) NOT NULL DEFAULT 'tls' COMMENT 'tls | ssl | none',
    ADD COLUMN invoice_error_notification_email VARCHAR(190) NULL COMMENT 'leer = Standardadresse aus .env';
