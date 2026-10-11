<?php

declare(strict_types=1);

namespace Connect\Connector;

/**
 * Beschreibt ein einzelnes Feld eines Objects (siehe connect_object_fields).
 * parentKey bildet verschachtelte, wiederholende Gruppen ab (z.B. MGM
 * "Positionen/zl/ArtNr" -- ArtNr hat als Parent das repeating-group-Feld
 * "Positionen.zl"). isIdentifier markiert das Feld, das als eindeutiger
 * externer Schluessel fuer die Zwischenspeicherung (connect_object_records)
 * dient, z.B. "shopify_id".
 */
final class FieldDefinition
{
    /**
     * @param ?string $comparisonRole title | sku | barcode | price -- markiert,
     *                welches Feld dieses Objects dem shop-weiten
     *                Vergleichswert (connect_shops.comparison_key) entspricht.
     *                Noetig, weil zwei Systeme fuer denselben Vergleichswert
     *                unterschiedliche field_keys nutzen koennen (z.B. Shopify
     *                "sku" vs. HelloCash "code") -- siehe
     *                Connect\Sync\StockSyncRunner. Dieselben Rollen (ausser
     *                "price") dienen auch als "Varianten-Vergleichswert" beim
     *                Produkt-Import (connect_shops.variant_comparison_key,
     *                Migration 0034) -- z.B. Vergleichswert=Barcode,
     *                Varianten-Vergleichswert=SKU: Datensaetze mit derselben
     *                SKU gehoeren zur selben Shopify-Produktgruppe, siehe
     *                Connect\Sync\ProductImportService. "price" ist ein
     *                Sonderfall (Migration 0041): kein waehlbarer
     *                Vergleichswert (ungeeignet, da nicht eindeutig/stabil),
     *                nur dafuer markiert, welches Feld beim Neuanlegen eines
     *                fehlenden Produkts in Shopify als Preis uebernommen wird.
     * @param bool $isStockQuantity markiert das Feld, das den Lagerbestand
     *                traegt (z.B. Shopify "inventory_quantity", HelloCash
     *                "stocks") -- genau eines pro Object, das am Lagerbestand-
     *                Abgleich teilnimmt.
     * @param bool $isVariantOption markiert das Feld, dessen Wert beim Import
     *                als neue Shopify-Variantenoption dient (z.B. Groesse/
     *                Farbe) -- nur relevant, wenn der Shop zusaetzlich einen
     *                Varianten-Vergleichswert gewaehlt hat, siehe
     *                Connect\Sync\ProductImportService.
     * @param ?int $maxLength maximale Zeichenlaenge des Werts im jeweiligen
     *                System (Migration 0049), null = unbekannt/kein Limit --
     *                siehe Connect\Sync\ProductComparisonService (Probleme-Karte).
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $dataType,
        public readonly ?string $path,
        public readonly bool $isRepeatingGroup = false,
        public readonly ?string $parentKey = null,
        public readonly bool $isIdentifier = false,
        public readonly ?string $comparisonRole = null,
        public readonly bool $isStockQuantity = false,
        public readonly bool $isVariantOption = false,
        public readonly ?int $maxLength = null
    ) {}
}
