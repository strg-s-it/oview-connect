-- =============================================================================
-- connect/migrations/0113_repair_order_query_0106.sql
--
-- Reparatur: Migration 0110 hat die Shopify-Bestellabfrage (read_list/read_one) komplett
-- neu gesetzt, ausgehend vom Stand 0066, und dabei die Ergaenzung aus Migration 0106
-- verloren: displayFulfillmentStatus, note, tags. Folge: Rechnungs-Filter (Notiz/Tags)
-- und der Ausloeser "versendet" sahen leere Werte.
--
-- Fuegt den Teil wieder ein, per REPLACE und nur wenn er fehlt (idempotent): enthaelt
-- die Abfrage displayFulfillmentStatus schon, bleibt sie unveraendert.
-- =============================================================================

UPDATE connect_object_operations op
JOIN connect_objects o ON o.id = op.object_id
JOIN connect_connector_types t ON t.id = o.connector_type_id
SET op.body_template = REPLACE(op.body_template, 'displayFinancialStatus sourceName', 'displayFinancialStatus displayFulfillmentStatus note tags sourceName')
WHERE t.type_key = 'shopify' AND o.object_key = 'order' AND op.operation_type IN ('read_list', 'read_one')
  AND op.body_template NOT LIKE '%displayFulfillmentStatus%';
