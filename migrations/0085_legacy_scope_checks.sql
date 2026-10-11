-- =============================================================================
-- 0085 Live gepruefte Shopify-Berechtigungen der Shops der alten Welt
--
-- Admin -> Betrieb -> Umstieg, "Berechtigungen jetzt pruefen": fuer Shops, die (noch) nicht in
-- Connect angelegt sind, fragt Connect mit dem Token aus oview_stores (nur lesend)
-- /admin/oauth/access_scopes.json ab und merkt sich das Ergebnis hier -- in die alte
-- Datenbank wird dafuer nicht geschrieben. Connect\Notify\MerchantDirectory bevorzugt diesen
-- Stand vor oview_stores.scopes (der nur beim Oeffnen der App bzw. Bestaetigen aktualisiert wird).
--
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

CREATE TABLE IF NOT EXISTS connect_legacy_scope_checks (
    shop        VARCHAR(100) NOT NULL,
    granted     TEXT NULL COMMENT 'JSON-Liste der erteilten Scopes, NULL = Pruefung fehlgeschlagen',
    error       VARCHAR(255) NULL,
    checked_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (shop)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
