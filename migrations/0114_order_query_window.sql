-- =============================================================================
-- connect/migrations/0114_order_query_window.sql
--
-- Stellt das 90-Tage-Fenster fuer Shopify-Bestellungen wieder her. Migration 0051 hat die
-- Variable $orderQuery in die read_list-Abfrage eingefuegt (Objekt "Bestellung" hat
-- max_age_days = 90, age_filter_param = orderQuery, age_filter_format = created_at:>={date}),
-- die Migrationen 0055 bis 0066 haben die Abfrage danach komplett neu gesetzt und sie wieder
-- entfernt. Folge: Die Einstellung blieb ohne Wirkung, der Cache las alle Bestellungen.
--
-- Wie 0051: per REPLACE und idempotent. Ohne Wirkung, wenn die Abfrage $orderQuery schon
-- enthaelt oder von Hand veraendert wurde (der Suchtext trifft dann nicht zu).
-- =============================================================================

UPDATE connect_object_operations op
JOIN connect_objects o ON o.id = op.object_id
JOIN connect_connector_types t ON t.id = o.connector_type_id
SET op.body_template = REPLACE(REPLACE(op.body_template, 'query($cursor: String)', 'query($cursor: String, $orderQuery: String)'), 'orders(first: 100, after: $cursor)', 'orders(first: 100, after: $cursor, query: $orderQuery)')
WHERE t.type_key = 'shopify'
  AND o.object_key = 'order'
  AND op.operation_type = 'read_list'
  AND op.body_template LIKE '%orders(first: 100, after: $cursor)%'
  AND op.body_template NOT LIKE '%orderQuery%';
