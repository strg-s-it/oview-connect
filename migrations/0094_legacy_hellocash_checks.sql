-- =============================================================================
-- 0094 Gepruefte helloCash-Tokens der Shops der alten Welt
--
-- Admin -> Betrieb -> Umstieg, "helloCash-Token pruefen": Connect probiert den Token aus
-- hellocash_binding der alten Welt (nur lesend) gegen die helloCash API v2 und merkt sich
-- hier, ob er schon alle Berechtigungen hat (z.B. weil der Merchant ihn bereits neu
-- generiert hat). Dann muss der Merchant fuer den Umzug keinen neuen Token erzeugen.
-- token_hash (SHA-256 des Tokens) erkennt, wenn sich der Token in der alten Welt seither
-- geaendert hat -- dann gilt das Ergebnis nicht mehr. Der Token selbst wird nicht gespeichert.
--
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

CREATE TABLE IF NOT EXISTS connect_legacy_hellocash_checks (
    shop        VARCHAR(100) NOT NULL,
    token_hash  CHAR(64) NULL COMMENT 'SHA-256 des geprueften Tokens, NULL = kein Token',
    access_ok   TINYINT(1) NULL COMMENT '1 = v2-Zugriff vollstaendig, 0 = unvollstaendig, NULL = nicht pruefbar',
    missing     TEXT NULL COMMENT 'JSON-Liste der Objects ohne Zugriff',
    error       VARCHAR(255) NULL,
    checked_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (shop)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
