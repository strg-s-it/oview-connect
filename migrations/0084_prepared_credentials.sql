-- =============================================================================
-- 0084 Vorab hinterlegte Zugangsdaten fuer den Umzug (Connect\Setup\TargetAccessCheck)
--
-- Merchants der alten Welt erstellen vor dem Umzug einen neuen Token fuer die Kassen-API
-- (helloCash v2 prueft Berechtigungen je Token, die alten v1-Tokens haben sie nicht) und
-- tragen ihn ueber den persoenlichen Link aus der Nachricht ein (public/prepare.php).
-- Der Token wird sofort gegen jedes Object geprueft und verschluesselt hier abgelegt.
-- Steht der Shop schon in Connect, wird er zusaetzlich direkt in dessen Connection
-- uebernommen. Der Umzug (ShopMigrator) nimmt ihn statt des alten Tokens.
-- Die alte Welt bleibt beim alten Token, bis der Shop umgestellt ist.
--
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

CREATE TABLE IF NOT EXISTS connect_prepared_credentials (
    shop               VARCHAR(100) NOT NULL,
    connector_type_id  INT UNSIGNED NOT NULL,
    token_encrypted    TEXT NULL COMMENT 'nur gespeichert, wenn die Pruefung bestanden ist',
    access             TEXT NULL COMMENT 'JSON: Ergebnis je Object der letzten Pruefung',
    access_ok          TINYINT(1) NOT NULL DEFAULT 0,
    attempts           INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Versuche im aktuellen Fenster (Bremse)',
    attempts_since     TIMESTAMP NULL,
    submitted_at       TIMESTAMP NULL COMMENT 'letzter erfolgreich gepruefter Token',
    applied_at         TIMESTAMP NULL COMMENT 'in eine Connect-Connection uebernommen',
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (shop)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
