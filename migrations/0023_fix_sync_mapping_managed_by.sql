-- =============================================================================
-- connect/migrations/0023_fix_sync_mapping_managed_by.sql
--
-- Bugfix: der admin-seitige "Job-Vorlage aktivieren"-Handler in
-- _sync_mappings_body.php setzte managed_by beim INSERT nicht explizit,
-- wodurch die Spalte auf ihren Default 'merchant' zurueckfiel -- der Job war
-- dadurch in der Merchant-Ansicht ("Shopify-Daten"-Karte, filtert auf
-- managed_by = 'system') unsichtbar, obwohl er inhaltlich genau dorthin
-- gehoert (Merchants haben keine eigene Anlage-Moeglichkeit fuer Sync-Jobs
-- mehr, siehe _sync_mappings_body.php). Betrifft nur reine Cache-Mappings
-- (target_connection_id IS NULL) -- Uebersetzungs-Mappings/Aktivierungen
-- (admin/mappings.php) setzen managed_by bereits korrekt seit ihrer
-- Einfuehrung.
-- =============================================================================

UPDATE connect_sync_mappings
SET managed_by = 'system'
WHERE target_connection_id IS NULL AND managed_by = 'merchant';
