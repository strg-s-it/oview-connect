-- =============================================================================
-- connect/migrations/0080_stock_guard.sql
--
-- Sicherheitsbremse fuer den Lagerabgleich (Connect\Sync\StockGuard, Nutzer-Entscheidung
-- 2026-10-08). Bremst nur bei klaren Fehlerbildern der fuehrenden Quelle, nicht bei
-- vielen Aenderungen an sich (Wareneingang, Inventur, nach einem Ausfall):
--   - Liste der Quelle mehr als 20 Prozent kuerzer als beim letzten Lauf
--   - mind. 25 Artikel UND mind. 10 Prozent der Paare wuerden von Bestand auf 0 fallen
--   - Anteil Nullen in der Quelle mind. 15 Prozentpunkte hoeher als beim letzten Lauf
-- Erster Treffer: Lauf ausgelassen, keine Mail. Zweiter Treffer in Folge: Abgleich pausiert,
-- Mail nur an den Admin, Merchant sieht in der Uebersicht einen ruhigen Hinweis. Freigabe
-- im Admin (Jobs-Seite des Shops). Dazu: 3 fehlgeschlagene Laeufe in Folge = Mail an den
-- Admin, Entwarnung bei Rueckkehr.
--
-- Zustand als JSON (state, reason, examples, at, release_once, last_count, last_zero_share,
-- fail_streak, fail_notified) -- eine Spalte statt vieler.
--
-- Hinweis: der Installer trennt am Semikolon - deshalb keines in Strings oder Kommentaren.
-- =============================================================================

ALTER TABLE connect_shops
    ADD COLUMN stock_guard TEXT NULL COMMENT 'Sicherheitsbremse Lagerabgleich, JSON (Connect\\Sync\\StockGuard)';
