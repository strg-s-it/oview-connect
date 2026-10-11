-- =============================================================================
-- connect/migrations/0048_country_tax_rates.sql
--
-- Globale (nicht shop-gebundene) Referenztabelle Land-ISO-Code (2-stellig,
-- wie Shopifys "shop"-Object liefert, Feld "country" -> billingAddress.
-- countryCodeV2, Migration 0007) -> Standard-Steuersatz. Ersetzt den
-- Altsystem-Ansatz (get_tax() in inc/functions.php, REST /countries.json --
-- laut Recherche fraglich, ob dieser Endpunkt bei modernen/Markets-Shops
-- noch zuverlaessig einen Satz liefert): eine EIGENE, gepflegte
-- Referenztabelle statt einer moeglicherweise veralteten Shopify-API.
--
-- Bewusst KEINE connect_lookup_tables-Wiederverwendung (die sind pro Shop
-- gedacht, siehe deren Kommentar "Wertetabellen fuer Transforms" -- eine
-- Laender-Steuersatz-Tabelle ist dagegen universelles Wissen, nicht shop-
-- spezifisch, analog zu connect_mapping_templates).
--
-- Nur STANDARD-Saetze -- ermaessigte Saetze je Produkt bleiben weiterhin
-- manuelle Eingabe (siehe Migration 0046/0047, transform_type='manual_input').
-- Werte Stand 2026, koennen sich aendern -- einfache UPDATE-Anweisung zum
-- Korrigieren, kein eigenes Admin-UI in dieser Runde.
-- =============================================================================

CREATE TABLE connect_country_tax_rates (
    country_code  CHAR(2) NOT NULL PRIMARY KEY,
    country_name  VARCHAR(100) NOT NULL,
    standard_rate DECIMAL(5,2) NOT NULL,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO connect_country_tax_rates (country_code, country_name, standard_rate) VALUES
    ('AT', 'Österreich', 20.00),
    ('BE', 'Belgien', 21.00),
    ('BG', 'Bulgarien', 20.00),
    ('HR', 'Kroatien', 25.00),
    ('CY', 'Zypern', 19.00),
    ('CZ', 'Tschechien', 21.00),
    ('DK', 'Dänemark', 25.00),
    ('EE', 'Estland', 22.00),
    ('FI', 'Finnland', 25.50),
    ('FR', 'Frankreich', 20.00),
    ('DE', 'Deutschland', 19.00),
    ('GR', 'Griechenland', 24.00),
    ('HU', 'Ungarn', 27.00),
    ('IE', 'Irland', 23.00),
    ('IT', 'Italien', 22.00),
    ('LV', 'Lettland', 21.00),
    ('LT', 'Litauen', 21.00),
    ('LU', 'Luxemburg', 17.00),
    ('MT', 'Malta', 18.00),
    ('NL', 'Niederlande', 21.00),
    ('PL', 'Polen', 23.00),
    ('PT', 'Portugal', 23.00),
    ('RO', 'Rumänien', 19.00),
    ('SK', 'Slowakei', 20.00),
    ('SI', 'Slowenien', 22.00),
    ('ES', 'Spanien', 21.00),
    ('SE', 'Schweden', 25.00),
    ('CH', 'Schweiz', 8.10),
    ('GB', 'Vereinigtes Königreich', 20.00),
    ('NO', 'Norwegen', 25.00);
