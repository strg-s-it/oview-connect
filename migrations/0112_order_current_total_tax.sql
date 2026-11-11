-- =============================================================================
-- connect/migrations/0112_order_current_total_tax.sql
--
-- Shopifys Steuersumme der Bestellung: bisher "totalTaxSet" -- das ist der Stand
-- vor nachtraeglichen Bearbeitungen. Der Gesamtbetrag kommt aus "currentTotalPriceSet"
-- (aktueller Stand), die Steuer-Kontrolle verglich also Steuer vom alten mit
-- Positionen vom neuen Stand. Live belegt (Kunde Revenued): #12043 entfernte Positionen
-- 9.27 EUR brutto -> Differenz 0.84 EUR = 10 % davon, #12301 Versand 22.50 -> 7.50 EUR
-- -> Differenz 1.36 EUR = 10 % auf die 15.00 EUR Reduktion.
--
-- Jetzt "currentTotalTaxSet" (Steuer nach Bearbeitungen/Retouren). Die Abfragen werden
-- per REPLACE angepasst, NICHT komplett neu gesetzt: sonst gehen Ergaenzungen anderer
-- Migrationen verloren (siehe 0113). Trifft der Suchtext nicht zu, bleibt die Abfrage
-- unveraendert.
-- =============================================================================

SET @shopify_order_object_id = (
    SELECT o.id FROM connect_objects o
    JOIN connect_connector_types t ON t.id = o.connector_type_id
    WHERE t.type_key = 'shopify' AND o.object_key = 'order'
);

UPDATE connect_object_fields
SET field_path = 'node.currentTotalTaxSet.shopMoney.amount'
WHERE object_id = @shopify_order_object_id AND field_key = 'total_tax';

UPDATE connect_object_operations op
JOIN connect_objects o ON o.id = op.object_id
JOIN connect_connector_types t ON t.id = o.connector_type_id
SET op.body_template = REPLACE(op.body_template, ' totalTaxSet { shopMoney', ' currentTotalTaxSet { shopMoney')
WHERE t.type_key = 'shopify' AND o.object_key = 'order' AND op.operation_type IN ('read_list', 'read_one');
