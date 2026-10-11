-- =============================================================================
-- connect/migrations/0076_welcome_and_legal.sql
--
-- Erstes Fenster fuer den Merchant (app/welcome.php): Software waehlen (Kacheln mit
-- Logo) und Datenschutz, AVV und Haftungshinweis zustimmen - erst danach geht es
-- ins Dashboard (Connect\Setup\WelcomeGate, geprueft in bootstrap.php).
--
--   connect_legal_texts        Rechtstexte je Art (privacy, dpa, liability) mit Version,
--                              gepflegt unter Admin -> Betrieb -> Rechtstexte. Erst ein
--                              veroeffentlichter Text muss zugestimmt werden. Neue Version
--                              = alle Merchants stimmen neu zu.
--   connect_shop_consents      Nachweis: welcher Shop welcher Version wann zugestimmt hat
--                              (mit IP und Browser)
--   connect_settings           globale Schalter, z.B. new_installs_to_connect (Neuinstalla-
--                              tionen direkt in Connect, Admin -> Shops)
--   connect_connector_types.logo_path   Logo fuer die Kacheln (Upload im Connector Type)
--
-- Vorbelegt: Entwurf (nicht veroeffentlicht) des Haftungshinweises mit dem Text der
-- alten Welt. Datenschutz und AVV muessen in Connect neu erfasst werden.
--
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

CREATE TABLE IF NOT EXISTS connect_legal_texts (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    doc_key       VARCHAR(32) NOT NULL COMMENT 'privacy | dpa | liability',
    version       INT UNSIGNED NOT NULL,
    title         VARCHAR(190) NOT NULL,
    summary       VARCHAR(500) NULL COMMENT 'Kurztext auf der Kachel',
    body          MEDIUMTEXT NOT NULL,
    published_at  TIMESTAMP NULL DEFAULT NULL COMMENT 'NULL = Entwurf',
    updated_by    VARCHAR(190) NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_legal_doc_version (doc_key, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS connect_shop_consents (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    shop         VARCHAR(190) NOT NULL,
    doc_key      VARCHAR(32) NOT NULL,
    version      INT UNSIGNED NOT NULL,
    accepted_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip           VARCHAR(64) NULL,
    user_agent   VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_shop_consents_shop (shop, doc_key, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS connect_settings (
    setting_key    VARCHAR(64) NOT NULL,
    setting_value  TEXT NULL,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE connect_connector_types
    ADD COLUMN logo_path VARCHAR(255) NULL COMMENT 'Logo fuer die Auswahl-Kacheln, relativ zu public/assets/logos/';

INSERT INTO connect_legal_texts (doc_key, version, title, summary, body, published_at)
SELECT 'liability', 1, 'Haftung für erstellte Belege',
       'Ich nehme zur Kenntnis, dass der Anbieter der App (strg-S) keine Haftung für die Richtigkeit der erstellten Belege übernimmt.',
       'Wenn du die automatische Rechnungsanlage aktivierst, werden bei jedem Verkauf automatisch Rechnungen in deinem Zielsystem erstellt. Dabei übernimmt Shopify die Steuerberechnung.\n\nWir übernehmen keine Haftung für die Richtigkeit der erstellten Belege. Bitte prüfe die ersten Rechnungen und deine Steuereinstellungen in Shopify sorgfältig.\n\nWenn die Rechnungserstellung aktiviert ist, werden die Kundeninformationen vorübergehend gespeichert und danach gelöscht, ohne dass die Daten weiterverarbeitet werden.',
       NULL
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM connect_legal_texts WHERE doc_key = 'liability');
