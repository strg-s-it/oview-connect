-- =============================================================================
-- 0105 Verbindungsstatus (Heartbeat, Connect\Sync\ConnectionHealth)
--
-- Der Scheduler prueft jede Connection alle 10 Minuten mit einem leichten Lesezugriff
-- (testConnection) und speichert das Ergebnis hier -- sichtbar in der Shopliste
-- (admin/shops.php). Bewusst NICHT in connect_connections.status: dieses Feld steuert
-- andere Dinge (z.B. "bereit" fuer den Umzug) und soll nicht von einem kurzen Ausfall
-- umgeschaltet werden.
--
--   health_status       ok | error, NULL = noch nicht geprueft
--   health_checked_at   letzte Pruefung
--   health_since        seit wann der aktuelle Zustand besteht
--   health_message      Fehlertext der letzten fehlgeschlagenen Pruefung
--   health_failures     fehlgeschlagene Pruefungen in Folge (Mail an den Admin ab 2)
--   health_notified_at  Mail zum aktuellen Ausfall gesendet (wird bei Erholung geleert)
--
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

ALTER TABLE connect_connections
    ADD COLUMN health_status VARCHAR(16) NULL,
    ADD COLUMN health_checked_at TIMESTAMP NULL DEFAULT NULL,
    ADD COLUMN health_since TIMESTAMP NULL DEFAULT NULL,
    ADD COLUMN health_message VARCHAR(500) NULL,
    ADD COLUMN health_failures INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN health_notified_at TIMESTAMP NULL DEFAULT NULL;
