-- =============================================================================
-- 0107 Anmelde-Hinweise je Shop (Connect\Auth\AuthIssueLog)
--
-- bootstrap.php merkt sich hier, wenn Anfragen eines Shops an der Anmeldung scheitern oder nur dank
-- Seiten-Token durchkommen -- sichtbar in der Shopliste (admin/shops.php), damit ein Cookie-Problem
-- bei einem Merchant sofort auffaellt. Eine Zeile je Shop und Art, mit Zaehler.
--
--   kind   cookie_fehlt        Anfrage ohne Session-Cookie scheiterte (401/403)
--          cookie_blockiert    Cookie fehlt, die Anfrage lief aber ueber den Seiten-Token durch
--          link_abgelaufen     Zugangslink abgelaufen und keine Session (401)
--          sitzung_abgelaufen  Cookie kam an, Session passt aber nicht (401)
--          csrf                Cookie kam an, Sicherheitstoken passt nicht (403)
--   hits   Anzahl seit first_seen_at
--   detail grober Browser (z.B. Chrome 154, Mac)
--
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

CREATE TABLE IF NOT EXISTS connect_auth_issues (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    shop          VARCHAR(190) NOT NULL,
    kind          VARCHAR(32) NOT NULL,
    hits          INT UNSIGNED NOT NULL DEFAULT 1,
    detail        VARCHAR(190) NULL,
    first_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_auth_issues_shop_kind (shop, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
