-- =============================================================================
-- connect/migrations/0106_invoice_trigger_and_filters.sql
--
-- 1. Auslöser der Rechnungserstellung je Shop (Händler-Einstellung):
--      paid      = Bestellung bezahlt (orders/paid, Standard wie bisher)
--      create    = Bestellung angelegt (orders/create)
--      fulfilled = Bestellung versendet/erfuellt (orders/fulfilled)
-- 2. Filter (nur Admin): Bestellungen, die NICHT abgerechnet werden sollen
--    (z.B. Dummy-Kunde, Notiz "Rechnung extern"), weil sie ueber ein anderes
--    System laufen. Auswertung in Connect\Sync\InvoiceFilter.
-- 3. Zusaetzliche Bestellfelder fuer Filter und Nachlauf: Notiz, Tags,
--    Versandstatus (read_list + read_one der Shopify-Bestellabfrage).
--
-- Die Abfragen werden per REPLACE ergaenzt (nicht komplett neu gesetzt): trifft
-- der Suchtext nicht zu, bleibt die Abfrage unveraendert.
-- Hinweis: der Installer trennt am Semikolon -- deshalb keines in Strings.
-- =============================================================================

ALTER TABLE connect_shops
    ADD COLUMN invoice_trigger VARCHAR(16) NOT NULL DEFAULT 'paid' AFTER invoice_creation_enabled;

CREATE TABLE IF NOT EXISTS connect_invoice_filters (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    shop       VARCHAR(190) NOT NULL,
    field_key  VARCHAR(32) NOT NULL COMMENT 'note | tags | email | customer_name | source_name',
    match_type VARCHAR(16) NOT NULL DEFAULT 'contains' COMMENT 'contains | equals',
    value      VARCHAR(190) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_invoice_filters_shop (shop)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @shopify_order_object_id = (
    SELECT o.id FROM connect_objects o
    JOIN connect_connector_types t ON t.id = o.connector_type_id
    WHERE t.type_key = 'shopify' AND o.object_key = 'order'
);

INSERT INTO connect_object_fields (object_id, field_key, label, data_type, field_path, is_repeating_group, parent_field_id) VALUES
    (@shopify_order_object_id, 'note', 'Notiz', 'string', 'node.note', 0, NULL),
    (@shopify_order_object_id, 'tags', 'Tags', 'string', 'node.tags', 0, NULL),
    (@shopify_order_object_id, 'fulfillment_status', 'Versandstatus', 'string', 'node.displayFulfillmentStatus', 0, NULL);

UPDATE connect_object_operations op
JOIN connect_objects o ON o.id = op.object_id
JOIN connect_connector_types t ON t.id = o.connector_type_id
SET op.body_template = REPLACE(op.body_template, 'displayFinancialStatus sourceName', 'displayFinancialStatus displayFulfillmentStatus note tags sourceName')
WHERE t.type_key = 'shopify' AND o.object_key = 'order' AND op.operation_type IN ('read_list', 'read_one');
