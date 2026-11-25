-- =============================================================================
-- 0116 Letzte und naechste Abbuchung des Shopify-Abos
--
-- Shopify nennt je Abo nur das Ende der laufenden Abrechnungsperiode (currentPeriodEnd).
-- Connect::Billing::ChargeInfo leitet daraus ab: letzte Abbuchung = Periodenende minus
-- Intervall (30 Tage bzw. 1 Jahr), naechste Abbuchung = Periodenende. Waehrend Testzeitraum
-- oder bei Testabos gab es keine echte Abbuchung: last = NULL, next gesetzt.
--
-- connect_shops: Shops in Connect (BillingService::refresh)
-- connect_legacy_scope_checks: Shops nur der alten Welt (Umstieg -> "Berechtigungen jetzt pruefen")
--
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

ALTER TABLE connect_shops
    ADD COLUMN plan_last_charge_at DATETIME NULL COMMENT 'letzte Abbuchung (abgeleitet), NULL = keine echte Abbuchung oder unbekannt',
    ADD COLUMN plan_next_charge_at DATETIME NULL COMMENT 'Ende der laufenden Abrechnungsperiode = naechste Abbuchung';

ALTER TABLE connect_legacy_scope_checks
    ADD COLUMN last_charge_at DATETIME NULL COMMENT 'letzte Abbuchung (abgeleitet), NULL = keine echte Abbuchung oder unbekannt',
    ADD COLUMN next_charge_at DATETIME NULL COMMENT 'Ende der laufenden Abrechnungsperiode = naechste Abbuchung';
