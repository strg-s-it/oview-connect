-- =============================================================================
-- connect/migrations/0110_order_current_state.sql
--
-- Nachtraeglich bearbeitete Shopify-Bestellungen (Positionen entfernt, Mengen
-- oder Versand geaendert, Haendler-Alltag): Shopify haelt dann den URSPRUNG
-- und den AKTUELLEN Stand. Connect las bisher gemischt:
--   - Positionsmenge aus LineItem.quantity   (urspruenglich, inkl. entfernter Ware)
--   - Gesamtbetrag aus currentTotalPriceSet  (aktuell)
--   - Steuersumme aus totalTaxSet            (ursprueglich)
-- Folge: Betrags- und Steuer-Kontrolle schlugen bei jeder bearbeiteten Bestellung
-- an (live #12242: Differenz = entfernte Position, #12301: Differenz = Steuer
-- auf gestrichenen Versand), es entstand keine Rechnung.
--
-- Jetzt durchgehend aktueller Stand:
--   - line_quantity   <- LineItem.currentQuantity   (ohne entfernte/erstattete Stuecke;
--                        Menge 0 wird vom OrderPreparer ohnehin uebersprungen)
--   - total_tax       <- Order.currentTotalTaxSet
--
-- Diff-Update per REPLACE statt kompletter Neuschreibung der Abfragen. Ist
-- "currentQuantity" schon enthalten, passiert nichts (mehrfach einspielbar).
-- Das Feld "quantity" bleibt in der Abfrage (wird nicht entfernt).
-- =============================================================================

SET @shopify_order_object_id = (
    SELECT o.id FROM connect_objects o
    JOIN connect_connector_types t ON t.id = o.connector_type_id
    WHERE t.type_key = 'shopify' AND o.object_key = 'order'
);

UPDATE connect_object_operations op
JOIN connect_objects o ON o.id = op.object_id
JOIN connect_connector_types t ON t.id = o.connector_type_id
SET op.body_template = REPLACE(REPLACE(op.body_template, ' totalTaxSet { shopMoney', ' currentTotalTaxSet { shopMoney'), ' sku quantity ', ' sku quantity currentQuantity ')
WHERE t.type_key = 'shopify' AND o.object_key = 'order'
  AND op.operation_type IN ('read_list', 'read_one')
  AND op.body_template NOT LIKE '%currentQuantity%';

UPDATE connect_object_fields
SET field_path = 'node.currentQuantity'
WHERE object_id = @shopify_order_object_id AND field_key = 'line_quantity' AND field_path = 'node.quantity';

UPDATE connect_object_fields
SET field_path = 'node.currentTotalTaxSet.shopMoney.amount'
WHERE object_id = @shopify_order_object_id AND field_key = 'total_tax' AND field_path = 'node.totalTaxSet.shopMoney.amount';
