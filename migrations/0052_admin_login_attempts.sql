-- =============================================================================
-- connect/migrations/0052_admin_login_attempts.sql
--
-- Schutz des Admin-Logins vor Durchprobieren von Passwoertern (Brute-Force):
-- jeder Login-Versuch wird protokolliert, Connect\Auth\AdminAuth sperrt nach zu
-- vielen Fehlversuchen fuer eine Weile (siehe dort: 5 je Konto, 20 je IP, 15
-- Minuten).
--
-- Datensparsam: die E-Mail-Adresse wird nur als SHA-256-Hash gespeichert (auch
-- fuer nicht existierende Konten, damit ein Angreifer nicht erkennt, welche
-- Adressen es gibt), die IP-Adresse fuer die IP-Grenze. Eintraege werden nach 30
-- Tagen durch bin/purge_privacy_data.php geloescht.
--
-- Hinweis: der Installer trennt am Semikolon -- deshalb keines in Strings.
-- =============================================================================

CREATE TABLE IF NOT EXISTS connect_admin_login_attempts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email_hash   CHAR(64) NOT NULL COMMENT 'SHA-256 der kleingeschriebenen E-Mail, nie die Adresse selbst',
    ip           VARCHAR(45) NOT NULL DEFAULT '' COMMENT 'leer, wenn keine verlaessliche Client-IP vorliegt',
    success      TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_attempts_email (email_hash, attempted_at),
    KEY idx_login_attempts_ip (ip, attempted_at),
    KEY idx_login_attempts_at (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
