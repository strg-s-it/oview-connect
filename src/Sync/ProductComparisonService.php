<?php

declare(strict_types=1);

namespace Connect\Sync;

use Connect\Connector\ConnectorFactory;
use PDO;

/**
 * Rein lesende Vergleichsuebersicht zwischen Shopify- und Ziel-Cache, fuer
 * die Merchant-Seite "Produkte" (connect/public/app/_products_body.php).
 * Nutzt denselben Objekt-/Feld-Kontext wie der schreibende Lagerbestand-
 * Abgleich (siehe Connect\Sync\StockSyncRunner, ProductMatchContext) -- ist
 * aber bewusst eine eigene, kleinere Klasse statt eines "trockenen Laufs"
 * von StockSyncRunner: sie will ALLE Zeilen sehen (auch bereits synchrone
 * und beidseitig fehlende), nicht nur die, die einen Schreibzugriff
 * ausgeloest haetten, und macht keine Schreibzugriffe.
 *
 * Anders als StockSyncRunner::runForShop() ist diese Klasse NICHT an
 * connect_shops.stock_sync_enabled gebunden -- die Uebersicht soll auch VOR
 * dem Aktivieren des automatischen Abgleichs nutzbar sein (Kontrolle der
 * Konfiguration).
 */
final class ProductComparisonService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ConnectorFactory $connectorFactory
    ) {}

    /**
     * @return array{status: string, message: ?string, comparisonKey: ?string, rows: array<int, array{match_value: ?string, label: ?string, shopify_quantity: ?int, target_quantity: ?int, status: string, sku: ?string, barcode: ?string, price: ?string, variant_title: ?string}>}
     *         status: skipped | error | success
     */
    public function buildReport(string $shop): array
    {
        try {
            $context = ProductMatchContext::resolve($this->pdo, $this->connectorFactory, $shop);
        } catch (ProductMatchUnresolvedException $e) {
            return ['status' => $e->status, 'message' => $e->getMessage(), 'comparisonKey' => null, 'rows' => []];
        }

        $objectCache = new ObjectCache($this->pdo);
        $shopifyRecords = $objectCache->getCached($context->shopifyConnectionId, $context->shopifyObject->key);
        $targetRecords = $objectCache->getCached($context->targetConnectionId, $context->targetObject->key);

        if (count($shopifyRecords) === 0 && count($targetRecords) === 0) {
            return [
                'status' => 'skipped',
                'message' => 'Noch keine Daten im Cache -- weder Shopify noch Zielsystem.',
                'comparisonKey' => $context->comparisonKey,
                'rows' => [],
            ];
        }

        // Sicherheitsbestand und Preis-Vergleich (Connect\Sync\ShopStockRules, Migration 0087)
        $rules = ShopStockRules::forShop($this->pdo, $shop);
        // Sets und Bundles (Migration 0091): Set-Artikel gibt es nur in Shopify -- Status "bundle" statt
        // "fehlt im Zielsystem", damit der automatische Import sie nicht ins Kassensystem anlegt
        $bundles = (new BundleService($this->pdo))->definitions($shop);

        $shopifyTitleField = ProductMatchContext::titleField($context->shopifyObject);
        $targetTitleField = ProductMatchContext::titleField($context->targetObject);

        // Zusaetzliche, rein informative Spalten fuer die Produkte-Seite
        // (app/_products_body.php) -- feste Auswahl statt admin-konfigurierbar
        // (Nutzer-Entscheidung): alle Rollen-Felder, die noch nicht ohnehin
        // als Vergleichswert/Label auftauchen, plus Shopifys "variant_title"
        // (kein eigenes Rollen-Konzept dafuer noetig, siehe
        // ProductMatchContext::fieldWithKey()). null, wenn ein Objekt das
        // jeweilige Feld gar nicht markiert hat -- Spalte bleibt dann in der
        // Anzeige einfach leer/verborgen.
        $shopifySkuField = ProductMatchContext::fieldWithComparisonRole($context->shopifyObject, 'sku');
        $targetSkuField = ProductMatchContext::fieldWithComparisonRole($context->targetObject, 'sku');
        $shopifyBarcodeField = ProductMatchContext::fieldWithComparisonRole($context->shopifyObject, 'barcode');
        $targetBarcodeField = ProductMatchContext::fieldWithComparisonRole($context->targetObject, 'barcode');
        $shopifyPriceField = ProductMatchContext::fieldWithComparisonRole($context->shopifyObject, 'price');
        // Preis des Zielsystems passend zur Preis-Basis des Shops (Brutto: Rolle "Preis", Netto: "Preis netto")
        $targetPriceField = ShopStockRules::targetPriceField($context->targetObject, $rules['prices_gross']);
        $shopifyVariantTitleField = ProductMatchContext::fieldWithKey($context->shopifyObject, 'variant_title');

        // Laengen-Pruefung (Altsystem: "SKU in Shopify zu lang (mehr als 20
        // Zeichen)"), generisch ueber connect_object_fields.max_length
        // (Migration 0049): fuer jede Rolle, die BEIDE Objekte markiert haben,
        // gilt das Limit des GEGENfelds fuer die Werte dieser Seite -- ein zu
        // langer Shopify-SKU passt z.B. nicht in den HelloCash-Artikel-Code.
        // Nur Rollen mit Text-Wert (kein Preis).
        $lengthChecks = [];
        foreach (['title' => 'Titel', 'sku' => 'SKU', 'barcode' => 'Barcode'] as $role => $roleLabel) {
            $shopifyRoleField = ProductMatchContext::fieldWithComparisonRole($context->shopifyObject, $role);
            $targetRoleField = ProductMatchContext::fieldWithComparisonRole($context->targetObject, $role);
            if ($shopifyRoleField === null || $targetRoleField === null) {
                continue;
            }
            if ($targetRoleField->maxLength !== null) {
                $lengthChecks["too_long_shopify_{$role}"] = [
                    'record' => 'shopify',
                    'field' => $shopifyRoleField,
                    'limit' => $targetRoleField->maxLength,
                    'label' => "{$roleLabel} in Shopify zu lang (mehr als {$targetRoleField->maxLength} Zeichen)",
                ];
            }
            if ($shopifyRoleField->maxLength !== null) {
                $lengthChecks["too_long_target_{$role}"] = [
                    'record' => 'target',
                    'field' => $targetRoleField,
                    'limit' => $shopifyRoleField->maxLength,
                    'label' => "{$roleLabel} im Zielsystem zu lang (mehr als {$shopifyRoleField->maxLength} Zeichen)",
                ];
            }
        }

        // sku/barcode/price: Shopify-Wert bevorzugt (auf einer importierbaren
        // Zeile hat ohnehin nur EINE Seite einen Wert), Zielsystem als
        // Fallback -- konsistent mit "label" oben. Fuer eine bereits
        // gematchte Zeile (in_sync/diff) sind das Identitaets-/Preisfelder,
        // die normalerweise ohnehin uebereinstimmen sollten; ein Abweichen
        // ist hier kein eigener Status, nur informativ sichtbar.
        $extraFieldsFor = function (?array $shopifyRecord, ?array $targetRecord) use (
            $shopifySkuField,
            $targetSkuField,
            $shopifyBarcodeField,
            $targetBarcodeField,
            $shopifyPriceField,
            $targetPriceField,
            $shopifyVariantTitleField,
            $lengthChecks
        ): array {
            $flags = [];
            foreach ($lengthChecks as $flagKey => $check) {
                $checkedRecord = $check['record'] === 'shopify' ? $shopifyRecord : $targetRecord;
                $checkedValue = $checkedRecord !== null ? ($checkedRecord[$check['field']->key] ?? null) : null;
                if ($checkedValue !== null && $checkedValue !== '' && mb_strlen((string) $checkedValue) > $check['limit']) {
                    $flags[] = $flagKey;
                }
            }

            $sku = $shopifyRecord !== null && $shopifySkuField !== null ? self::stringOrNull($shopifyRecord[$shopifySkuField->key] ?? null) : null;
            $sku ??= $targetRecord !== null && $targetSkuField !== null ? self::stringOrNull($targetRecord[$targetSkuField->key] ?? null) : null;

            $barcode = $shopifyRecord !== null && $shopifyBarcodeField !== null ? self::stringOrNull($shopifyRecord[$shopifyBarcodeField->key] ?? null) : null;
            $barcode ??= $targetRecord !== null && $targetBarcodeField !== null ? self::stringOrNull($targetRecord[$targetBarcodeField->key] ?? null) : null;

            $price = $shopifyRecord !== null && $shopifyPriceField !== null ? self::stringOrNull($shopifyRecord[$shopifyPriceField->key] ?? null) : null;
            $price ??= $targetRecord !== null && $targetPriceField !== null ? self::stringOrNull($targetRecord[$targetPriceField->key] ?? null) : null;

            return [
                'sku' => $sku,
                'barcode' => $barcode,
                'price' => $price,
                'variant_title' => $shopifyRecord !== null && $shopifyVariantTitleField !== null ? self::stringOrNull($shopifyRecord[$shopifyVariantTitleField->key] ?? null) : null,
                'flags' => $flags,
            ];
        };

        // Nur fuer den Gegenseiten-Lookup -- verwaiste Datensaetze (in der
        // Quelle nicht mehr vorhanden, siehe ObjectCache::markOrphaned())
        // werden unten in beiden Schleifen komplett uebersprungen, damit
        // geloeschte Produkte nicht faelschlich als "fehlt beim anderen
        // System" auftauchen.
        $targetIndex = [];
        foreach ($targetRecords as $record) {
            if (($record['_status'] ?? 'active') === 'orphaned') {
                continue;
            }
            $value = $record[$context->targetMatchField->key] ?? null;
            if ($value !== null && $value !== '') {
                $targetIndex[(string) $value] = $record;
            }
        }

        $shopifyIndex = [];
        foreach ($shopifyRecords as $record) {
            if (($record['_status'] ?? 'active') === 'orphaned') {
                continue;
            }
            $value = $record[$context->shopifyMatchField->key] ?? null;
            if ($value !== null && $value !== '') {
                $shopifyIndex[(string) $value] = true;
            }
        }

        // Mehrfach vorkommende Vergleichswerte je Seite (Altsystem-Pruefung
        // "Vergleichswert-Duplikate"): solche Datensaetze sind nicht eindeutig
        // zuordenbar -- ohne eigenen Status wuerde $targetIndex oben stillschweigend
        // den letzten gewinnen lassen und z.B. 5 Shopify-Varianten mit gleicher
        // SKU alle als "gematcht" mit demselben Ziel-Artikel zeigen.
        $shopifyDuplicates = ComparisonValueCheck::duplicateValues($shopifyRecords, $context->shopifyMatchField->key);
        $targetDuplicates = ComparisonValueCheck::duplicateValues($targetRecords, $context->targetMatchField->key);

        $rows = [];

        foreach ($shopifyRecords as $record) {
            if (($record['_status'] ?? 'active') === 'orphaned') {
                continue;
            }

            // Nur wenn ein Standort gewaehlt ist (connect_shops.stock_primary_
            // location_id) UND das markierte Lagerbestandsfeld dort NULL ist
            // (nicht nur fehlend) -- z.B. Shopifys "location_stock_quantity"
            // (Migration 0061): am gewaehlten Standort gar nicht gefuehrt, fuer
            // die Vergleichsuebersicht (und den automatischen Produktimport,
            // der genau diese Zeilen nutzt) wie ein in Shopify nicht
            // existierendes Produkt behandeln (Nutzer-Entscheidung 2026-09-27:
            // "immer nur Produkte vom gewaehlten Standort ... verwenden").
            // An die Bedingung "Standort gewaehlt" geknuepft, damit Shops ohne
            // Standort-Einschraenkung ihr bisheriges Verhalten (null -> Status
            // "unknown" weiter unten, sichtbar statt versteckt) unveraendert behalten.
            if ($context->locationId !== null
                && array_key_exists($context->shopifyStockField->key, $record)
                && $record[$context->shopifyStockField->key] === null
            ) {
                continue;
            }

            $value = $record[$context->shopifyMatchField->key] ?? null;
            $label = self::stringOrNull($shopifyTitleField !== null ? ($record[$shopifyTitleField->key] ?? null) : null);

            if ($value === null || $value === '') {
                $rows[] = self::row(null, $label, null, null, 'no_comparison_value_shopify', $extraFieldsFor($record, null));
                continue;
            }

            $shopifyQuantity = self::quantity($record, $context->shopifyStockField->key);

            if (isset($shopifyDuplicates[(string) $value])) {
                $rows[] = self::row((string) $value, $label, $shopifyQuantity, null, 'duplicate_shopify', $extraFieldsFor($record, null));
                continue;
            }
            if (isset($targetDuplicates[(string) $value])) {
                $rows[] = self::row((string) $value, $label, $shopifyQuantity, null, 'duplicate_target', $extraFieldsFor($record, null));
                continue;
            }

            $targetRecord = $targetIndex[(string) $value] ?? null;

            if ($targetRecord === null) {
                $rows[] = self::row((string) $value, $label, $shopifyQuantity, null, isset($bundles[(string) $value]) ? 'bundle' : 'missing_on_target', $extraFieldsFor($record, null));
                continue;
            }

            $targetQuantity = self::quantity($targetRecord, $context->targetStockField->key);
            $targetLabel = self::stringOrNull($targetTitleField !== null ? ($targetRecord[$targetTitleField->key] ?? null) : null);

            // Mit Sicherheitsbestand soll Shopify "Kassenbestand minus Puffer" zeigen -- das ist dann synchron
            $expectedShopify = $targetQuantity !== null ? ShopStockRules::shopifyQuantity($targetQuantity, $rules['buffer'], $context->direction) : null;
            if ($shopifyQuantity === null || $targetQuantity === null) {
                $status = 'unknown';
            } elseif ($shopifyQuantity !== $expectedShopify) {
                $status = 'diff';
            } else {
                $status = 'in_sync';
            }

            $extra = $extraFieldsFor($record, $targetRecord);
            // Preisvergleich auf derselben Basis wie Shopify (Brutto oder Netto, siehe targetPriceField())
            if ($shopifyPriceField !== null && $targetPriceField !== null) {
                $extra['shopify_price'] = ShopStockRules::price($record[$shopifyPriceField->key] ?? null);
                $extra['target_price'] = ShopStockRules::price($targetRecord[$targetPriceField->key] ?? null);
                if (ShopStockRules::pricesDiffer($extra['shopify_price'], $extra['target_price'])) {
                    $extra['flags'][] = 'price_diff';
                }
            }

            $rows[] = self::row((string) $value, $label ?? $targetLabel, $shopifyQuantity, $targetQuantity, $status, $extra);
        }

        foreach ($targetRecords as $record) {
            if (($record['_status'] ?? 'active') === 'orphaned') {
                continue;
            }

            $value = $record[$context->targetMatchField->key] ?? null;
            $label = self::stringOrNull($targetTitleField !== null ? ($record[$targetTitleField->key] ?? null) : null);

            if ($value === null || $value === '') {
                $rows[] = self::row(null, $label, null, null, 'no_comparison_value_target', $extraFieldsFor(null, $record));
                continue;
            }

            // Jeden doppelten Ziel-Datensatz einzeln auflisten (auch wenn
            // Shopify den Wert hat) -- sonst waeren die Verursacher der
            // "Duplikate in Zielsystem"-Zaehlung nicht auffindbar.
            if (isset($targetDuplicates[(string) $value])) {
                $rows[] = self::row((string) $value, $label, null, self::quantity($record, $context->targetStockField->key), 'duplicate_target', $extraFieldsFor(null, $record));
                continue;
            }

            if (isset($shopifyIndex[(string) $value])) {
                continue; // bereits in der Shopify-Schleife oben verarbeitet
            }

            $targetQuantity = self::quantity($record, $context->targetStockField->key);
            $rows[] = self::row((string) $value, $label, null, $targetQuantity, 'missing_on_shopify', $extraFieldsFor(null, $record));
        }

        // Zaehlung je Laengen-Pruefung = Anzahl Zeilen mit dem jeweiligen Flag
        // (entspricht der Zeilenzahl des verlinkten Filters auf der Seite).
        $problems = [];
        foreach ($lengthChecks as $flagKey => $check) {
            $count = 0;
            foreach ($rows as $row) {
                if (in_array($flagKey, $row['flags'], true)) {
                    $count++;
                }
            }
            $problems[] = ['key' => $flagKey, 'label' => $check['label'], 'count' => $count];
        }
        // Preisunterschiede (Migration 0087): nur wenn beide Seiten ein Preisfeld (Rolle "Preis") haben
        if ($shopifyPriceField !== null && $targetPriceField !== null) {
            $problems[] = [
                'key' => 'price_diff',
                'label' => 'Preis weicht ab (Shopify ↔ Zielsystem, ' . ($rules['prices_gross'] ? 'brutto' : 'netto') . ')',
                'count' => count(array_filter($rows, static fn (array $r): bool => in_array('price_diff', $r['flags'], true))),
            ];
        }

        return [
            'status' => 'success',
            'message' => null,
            'comparisonKey' => $context->comparisonKey,
            'rows' => $rows,
            'problems' => $problems,
            'buffer' => $context->direction === 'target_to_shopify' ? $rules['buffer'] : 0,
            'prices_comparable' => $shopifyPriceField !== null && $targetPriceField !== null,
            'prices_gross' => $rules['prices_gross'],
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    private static function quantity(array $record, string $fieldKey): ?int
    {
        if (!array_key_exists($fieldKey, $record) || $record[$fieldKey] === null) {
            return null;
        }

        return (int) $record[$fieldKey];
    }

    /**
     * Normalisiert einen aus dem JSON-Cache gelesenen Feldwert (mixed --
     * koennte je nach Quelle auch ein int/bool sein) auf ?string, BEVOR er an
     * einen strict_types=1-typisierten ?string-Parameter uebergeben wird.
     */
    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    /**
     * @param array{sku: ?string, barcode: ?string, price: ?string, variant_title: ?string} $extra
     * @return array{match_value: ?string, label: ?string, shopify_quantity: ?int, target_quantity: ?int, status: string, sku: ?string, barcode: ?string, price: ?string, variant_title: ?string}
     */
    private static function row(?string $matchValue, ?string $label, ?int $shopifyQuantity, ?int $targetQuantity, string $status, array $extra = []): array
    {
        return [
            'match_value' => $matchValue,
            'label' => $label,
            'shopify_quantity' => $shopifyQuantity,
            'target_quantity' => $targetQuantity,
            'status' => $status,
            'sku' => $extra['sku'] ?? null,
            'barcode' => $extra['barcode'] ?? null,
            'price' => $extra['price'] ?? null,
            'variant_title' => $extra['variant_title'] ?? null,
            'flags' => $extra['flags'] ?? [],
            'shopify_price' => $extra['shopify_price'] ?? null,
            'target_price' => $extra['target_price'] ?? null,
        ];
    }
}
