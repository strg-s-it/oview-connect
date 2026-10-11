-- =============================================================================
-- connect/migrations/0050_shop_live_in_connect.sql
--
-- Routing-Schalter zwischen alter Welt (oView) und neuer Welt (Connect), pro
-- Shop. live_in_connect = 1 heisst: Connect ist fuer diesen Shop zustaendig
-- (Einstieg, Webhooks, geplante Jobs), die alte Welt verarbeitet ihn nicht
-- mehr. 0 heisst: alte Welt wie bisher. Wird explizit im Admin umgelegt
-- (admin/shops.php) und ist jederzeit umkehrbar -- bewusst NICHT aus dem
-- Verbindungsstatus abgeleitet, weil "Token uebernehmen" die Connection jedes
-- Mal auf "pending" zuruecksetzt und der Shop sonst zwischen den Welten
-- hin- und herspringen wuerde.
--
-- Die alte Welt liest den Schalter direkt aus diesem Schema (siehe
-- inc/connect_routing.php dort), genau wie schon connect_beta_is_eligible().
-- Ist die Spalte nicht lesbar, laeuft die alte Welt unveraendert weiter.
--
-- Hinweis: der Installer trennt am Semikolon -- deshalb keines in Strings.
-- =============================================================================

ALTER TABLE connect_shops
    ADD COLUMN live_in_connect TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Connect ist zustaendig, 0 = alte Welt',
    ADD COLUMN live_changed_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Zeitpunkt der letzten Umstellung';

-- Bestehendes Verhalten erhalten: Shops, die heute schon automatisch nach
-- Connect weitergeleitet werden (Eintrag in connect_shops plus aktive
-- Connection, dieselbe Bedingung wie connect_beta_is_eligible()), starten als
-- "live". Alle anderen bleiben in der alten Welt, bis sie umgelegt werden.
UPDATE connect_shops s
SET s.live_in_connect = 1, s.live_changed_at = CURRENT_TIMESTAMP
WHERE EXISTS (
    SELECT 1 FROM connect_connections c
    WHERE c.shop = s.shop AND c.status = 'active'
);
