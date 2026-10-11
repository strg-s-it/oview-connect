-- =============================================================================
-- 0093 Funktionswuensche (Connect\Wishes\WishBoard, app/wishes.php, admin/wishes.php)
--
-- Merchants schreiben Wuensche, andere Merchants sehen sie OHNE Shop-Namen und stimmen ab.
-- Sichtbar fuer alle erst nach Freigabe im Admin.
--
--   status   review   = wartet auf Freigabe (nur Verfasser und Admin sehen ihn)
--            open     = freigegeben, noch nicht entschieden
--            planned  = geplant
--            done     = umgesetzt (Verfasser und Unterstuetzer bekommen einen Hinweis in der App)
--            rejected = nicht geplant (nur Verfasser und Admin sehen ihn)
--            merged   = mit einem anderen Wunsch zusammengefuehrt (merged_into), Stimmen wandern mit
--
-- Die Stimme des Verfassers wird beim Anlegen mit eingetragen.
-- digest_sent_at: in der woechentlichen Admin-Zusammenfassung gemeldet.
--
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

CREATE TABLE IF NOT EXISTS connect_wishes (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    shop            VARCHAR(190) NOT NULL,
    title           VARCHAR(150) NOT NULL,
    body            TEXT NULL,
    status          VARCHAR(16) NOT NULL DEFAULT 'review',
    answer          TEXT NULL COMMENT 'oeffentliche Antwort von oView',
    merged_into     INT UNSIGNED NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at     DATETIME NULL,
    done_at         DATETIME NULL,
    digest_sent_at  DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_wish_status (status),
    KEY idx_wish_shop (shop)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS connect_wish_votes (
    wish_id     INT UNSIGNED NOT NULL,
    shop        VARCHAR(190) NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (wish_id, shop),
    KEY idx_wish_vote_shop (shop)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
