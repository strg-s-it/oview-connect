-- =============================================================================
-- connect/migrations/0077_consent_retention.sql
--
-- Nachweise der Zustimmungen (connect_shop_consents, Migration 0076) bleiben nach dem
-- Loeschen eines Shops erhalten und werden erst drei Jahre danach automatisch geloescht
-- (Connect\Maintenance\Retention, Regel "consents", .env CONNECT_RETENTION_CONSENT_DAYS,
-- Standard 1095 Tage). Grund: Ansprueche aus dem Vertrag verjaehren im Regelfall drei
-- Jahre ab Kenntnis (Paragraph 1489 ABGB), so lange wird der Nachweis gebraucht.
-- Datenschutzerklaerung Abschnitt 5 nennt diese Frist.
--
-- shop_deleted_at wird gesetzt bei shop/redact (PrivacyService::eraseShop) und beim
-- Entfernen im Admin (admin/shops.php). Markierte Zeilen zaehlen fuer den Shop nicht mehr
-- als Zustimmung -- installiert er die App neu, stimmt er neu zu.
--
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

ALTER TABLE connect_shop_consents
    ADD COLUMN shop_deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Shop geloescht, Nachweis bleibt bis Ablauf der Aufbewahrungsfrist';
