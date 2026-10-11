-- =============================================================================
-- connect/migrations/0070_rate_limit_per_ip.sql
--
-- Anfrage-Limit (Migration 0053, Connect\Http\RateLimiter) je ausgehender IP
-- statt nur je Connector Type: HelloCash begrenzt pro IP -- laufen Scheduler auf
-- mehreren Worker-Abos mit eigener IP (CONNECT_OUTBOUND_IP in connect/.env), hat
-- jede IP ihr eigenes Kontingent, und eine Sperre nach HTTP 429 gilt nur fuer die
-- IP, die sie ausgeloest hat.
--
-- scope = die konfigurierte Quell-IP, leer = Standard-IP des Servers (bisheriges
-- Verhalten, bestehende Zeilen bleiben unveraendert gueltig).
--
-- Hinweis: der Installer trennt am Semikolon -- deshalb keines in Strings oder Kommentaren.
-- =============================================================================

ALTER TABLE connect_rate_limit_state
    ADD COLUMN scope VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'ausgehende IP (CONNECT_OUTBOUND_IP), leer = Standard-IP' AFTER connector_type_id,
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (connector_type_id, scope);
