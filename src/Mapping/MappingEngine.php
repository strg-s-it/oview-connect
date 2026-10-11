<?php

declare(strict_types=1);

namespace Connect\Mapping;

use Connect\Connector\FieldDefinition;
use PDO;

/**
 * Reine Uebersetzungslogik: nimmt einen bereits gelesenen Quell-Datensatz
 * (Form wie von ConnectorInterface::read()/readOne() geliefert, inkl.
 * aufgeloester wiederholender Gruppen, siehe GenericHttpConnector::mapRowToFields())
 * und die Feld-Mappings eines Sync Mapping (connect_field_mappings) und baut
 * daraus die Werte fuer ConnectorInterface::write() auf dem Zielsystem.
 *
 * Keine I/O ausser dem Lookup-Tabellen-Lesen -- ruft nie selbst read()/write()
 * auf, das bleibt Aufgabe von Connect\Sync\SyncRunner (Orchestrierung), damit
 * diese Klasse isoliert testbar bleibt.
 */
final class MappingEngine
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $sourceRow
     * @param array<int, array<string, mixed>> $fieldMappingRows Zeilen aus connect_field_mappings
     * @param array<int, FieldDefinition> $sourceFieldsById
     * @param array<int, FieldDefinition> $targetFieldsById
     * @param array<string, mixed> $activationSettings Pro-Shop-Konstanten aus
     *        connect_sync_mappings.settings (siehe connect_objects.settings_schema
     *        des Ziel-Objects), z.B. HelloCash cashier_id/invoice_paymentMethod --
     *        Werte, die fuer JEDEN Shop, der diese Vorlage aktiviert, unterschiedlich
     *        sein koennen, anders als eine globale Mapping-Vorlage es sonst erlaubt.
     *        Werden als Basis unter das Ergebnis der Feld-Zuordnungen gemischt --
     *        eine tatsaechliche Feld-Zuordnung auf denselben Ziel-Schluessel gewinnt.
     * @param array<int, mixed> $manualValues target_field_id => vom Merchant beim
     *        Import selbst eingegebener Wert (siehe transform_type='manual_input',
     *        z.B. HelloCash "Steuersatz" -- kann nicht aus Shopify abgeleitet und
     *        nicht sinnvoll als fester Wert in der Vorlage hinterlegt werden, da er
     *        pro Produkt/Land unterschiedlich ist). Kommt aus Connect\Sync\
     *        ProductImportService::importIntoTarget(), nicht aus $sourceRow.
     * @param array<string, mixed> $priceContext siehe transform_type='derived_price'
     *        weiter unten -- aktuell nur 'taxes_included' (bool): ob Shopifys
     *        Preis-Feld Netto oder Brutto ist (connect_shops.
     *        shopify_prices_include_tax, siehe Settings-Seite). Kommt ebenfalls
     *        aus ProductImportService, nicht aus $sourceRow.
     * @return array<string, mixed> field_key => Wert, fertig fuer ConnectorInterface::write()
     */
    public function translate(array $sourceRow, array $fieldMappingRows, array $sourceFieldsById, array $targetFieldsById, array $activationSettings = [], array $manualValues = [], array $priceContext = []): array
    {
        $topLevel = [];
        /** @var array<int, array<string, mixed>> $derivedPriceMappings Zielfelder mit
         *  transform_type='derived_price' -- brauchen das Ergebnis eines ANDEREN
         *  bereits berechneten Zielfelds (den Steuersatz), werden deshalb NACH
         *  allen anderen Top-Level-Feldern in einem zweiten Durchgang aufgeloest. */
        $derivedPriceMappings = [];
        /** @var array<string, array<int, array<string, mixed>>> $grouped Ziel-Gruppen-Key => Mapping-Zeilen */
        $grouped = [];

        foreach ($fieldMappingRows as $fieldMapping) {
            if (($fieldMapping['mapping_role'] ?? 'value') === 'match_key') {
                // match_key dient dem Auffinden eines bestehenden Ziel-
                // Datensatzes (Update-Fall) -- v1 schreibt fuer MGM-Auftraege
                // immer neu (create), match_key hat hier noch keine Wirkung
                // (siehe Plan, "Nicht Teil von v1").
                continue;
            }

            $targetField = $targetFieldsById[(int) $fieldMapping['target_field_id']] ?? null;
            if ($targetField === null) {
                continue;
            }

            if ($targetField->parentKey !== null) {
                $grouped[$targetField->parentKey][] = $fieldMapping;
            } elseif (($fieldMapping['transform_type'] ?? '') === 'derived_price') {
                $derivedPriceMappings[] = $fieldMapping;
            } else {
                $topLevel[] = $fieldMapping;
            }
        }

        $result = [];

        foreach ($topLevel as $fieldMapping) {
            $targetField = $targetFieldsById[(int) $fieldMapping['target_field_id']];
            $value = $this->applyTransform($fieldMapping, $sourceRow, $sourceFieldsById, $manualValues);
            if (!self::omitValue($fieldMapping, $value)) {
                $result[$targetField->key] = $value;
            }
        }

        foreach ($grouped as $targetGroupKey => $childMappings) {
            $result[$targetGroupKey] = $this->translateGroup($childMappings, $sourceRow, $sourceFieldsById, $targetFieldsById, $manualValues);
        }

        foreach ($derivedPriceMappings as $fieldMapping) {
            $targetField = $targetFieldsById[(int) $fieldMapping['target_field_id']];
            $result[$targetField->key] = $this->applyDerivedPrice($fieldMapping, $sourceRow, $sourceFieldsById, $targetFieldsById, $result, $priceContext);
        }

        return array_merge($activationSettings, $result);
    }

    /**
     * transform_type='derived_price': Shopify liefert i.d.R. NUR EINEN Preis
     * pro Produkt (Netto ODER Brutto, je Shop-Einstellung "taxesIncluded"),
     * viele Zielsysteme (z.B. HelloCash) brauchen aber BEIDE Werte. Berechnet
     * den jeweils fehlenden Preis aus einem Basispreis + dem Steuersatz eines
     * ANDEREN, bereits aufgeloesten Zielfelds (siehe transform_config:
     * {"role": "net"|"gross", "tax_rate_target_field_id": N,
     * "base_price_target_field_id"?: N}) -- der Steuersatz kann selbst z.B.
     * transform_type='manual_input' sein (Merchant traegt ihn beim Import
     * ein, siehe HelloCash "Steuersatz"), MUSS es aber nicht (funktioniert
     * mit jedem bereits berechneten Zielfeld).
     *
     * Der Basispreis kommt ENTWEDER vom gemappten Quellfeld (source_field_id,
     * z.B. Shopifys "Preis" direkt) ODER -- falls kein Quellfeld gesetzt ist
     * -- von einem ANDEREN, bereits berechneten Zielfeld
     * (base_price_target_field_id, z.B. wenn "Brutto-Verkaufspreis" schon
     * direkt von Shopify gemappt ist und "Netto-Verkaufspreis" daraus
     * berechnet werden soll, statt denselben Quell-Preis ein zweites Mal
     * separat zu mappen).
     *
     * @param array<string, mixed> $fieldMapping
     * @param array<string, mixed> $sourceRow
     * @param array<int, FieldDefinition> $sourceFieldsById
     * @param array<int, FieldDefinition> $targetFieldsById
     * @param array<string, mixed> $resultSoFar bereits berechnete Zielfelder (alles AUSSER anderer derived_price-Felder)
     * @param array<string, mixed> $priceContext siehe translate()
     */
    private function applyDerivedPrice(array $fieldMapping, array $sourceRow, array $sourceFieldsById, array $targetFieldsById, array $resultSoFar, array $priceContext): ?float
    {
        $config = $fieldMapping['transform_config'] !== null
            ? (json_decode((string) $fieldMapping['transform_config'], true) ?: [])
            : [];

        $sourceField = $fieldMapping['source_field_id'] !== null ? ($sourceFieldsById[(int) $fieldMapping['source_field_id']] ?? null) : null;
        if ($sourceField !== null) {
            $baseValue = $sourceRow[$sourceField->key] ?? null;
        } else {
            $baseField = $targetFieldsById[(int) ($config['base_price_target_field_id'] ?? 0)] ?? null;
            $baseValue = $baseField !== null ? ($resultSoFar[$baseField->key] ?? null) : null;
        }
        if ($baseValue === null || $baseValue === '' || !is_numeric($baseValue)) {
            return null;
        }

        $taxRateField = $targetFieldsById[(int) ($config['tax_rate_target_field_id'] ?? 0)] ?? null;
        $taxRateValue = $taxRateField !== null ? ($resultSoFar[$taxRateField->key] ?? null) : null;
        $taxRate = is_numeric($taxRateValue) ? (float) $taxRateValue : 0.0;

        $basePrice = (float) $baseValue;
        $taxesIncluded = (bool) ($priceContext['taxes_included'] ?? false);

        // $basePrice ist GENAU EINER von beiden (je nach Shopify-Shop-
        // Einstellung "taxesIncluded") -- der jeweils andere wird daraus
        // berechnet, nicht eigenstaendig aus Shopify gelesen (das liefert
        // Shopify so gar nicht).
        if ($taxesIncluded) {
            $gross = $basePrice;
            $net = $taxRate > 0 ? $basePrice / (1 + $taxRate / 100) : $basePrice;
        } else {
            $net = $basePrice;
            $gross = $basePrice * (1 + $taxRate / 100);
        }

        $role = (string) ($config['role'] ?? 'gross');

        return round($role === 'net' ? $net : $gross, 2);
    }

    /**
     * Uebersetzt eine wiederholende Gruppe (z.B. Shopify "line_items" ->
     * MGM "Positionen"): die Quell-Gruppe wird aus dem parentKey des
     * Quell-Felds der ERSTEN Kind-Mapping abgeleitet -- v1-Vereinfachung,
     * die genau EINE wiederholende Gruppe je Objektpaar voraussetzt (siehe
     * Plan, "Nicht Teil von v1").
     *
     * @param array<int, array<string, mixed>> $childMappings
     * @param array<string, mixed> $sourceRow
     * @param array<int, FieldDefinition> $sourceFieldsById
     * @param array<int, FieldDefinition> $targetFieldsById
     * @param array<int, mixed> $manualValues siehe translate() -- gilt hier fuer
     *        JEDE Zeile der Gruppe gleich (keine Pro-Zeile-Eingabe, v1-Vereinfachung).
     * @return array<int, array<string, mixed>>
     */
    private function translateGroup(array $childMappings, array $sourceRow, array $sourceFieldsById, array $targetFieldsById, array $manualValues = []): array
    {
        $sourceGroupKey = null;
        foreach ($childMappings as $childMapping) {
            $sourceField = $childMapping['source_field_id'] !== null ? ($sourceFieldsById[(int) $childMapping['source_field_id']] ?? null) : null;
            if ($sourceField !== null && $sourceField->parentKey !== null) {
                $sourceGroupKey = $sourceField->parentKey;
                break;
            }
        }

        $items = $sourceGroupKey !== null && is_array($sourceRow[$sourceGroupKey] ?? null) ? $sourceRow[$sourceGroupKey] : [];

        $lines = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $line = [];
            foreach ($childMappings as $childMapping) {
                $targetField = $targetFieldsById[(int) $childMapping['target_field_id']] ?? null;
                if ($targetField === null) {
                    continue;
                }
                $value = $this->applyTransform($childMapping, $item, $sourceFieldsById, $manualValues);
                if (!self::omitValue($childMapping, $value)) {
                    $line[$targetField->key] = $value;
                }
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * transform_config.omit_if_empty ("nur senden, wenn Wert vorhanden", je
     * Feldzuordnung im Mapping-Editor): leere Werte (null/'') werden dann gar
     * nicht erst als Schluessel ausgegeben -- noetig fuer APIs, die ein
     * optionales Feld mit null ablehnen (z.B. HelloCash discount_unit/
     * article_id ohne Rabatt bzw. ohne gefundenen Artikel).
     *
     * @param array<string, mixed> $fieldMapping
     */
    private static function omitValue(array $fieldMapping, mixed $value): bool
    {
        if ($value !== null && $value !== '') {
            return false;
        }
        $config = $fieldMapping['transform_config'] !== null
            ? (json_decode((string) $fieldMapping['transform_config'], true) ?: [])
            : [];

        return !empty($config['omit_if_empty']);
    }

    /**
     * @param array<string, mixed> $fieldMapping Eine Zeile aus connect_field_mappings
     * @param array<string, mixed> $row Quell-Zeile (Top-Level-Datensatz ODER ein einzelnes Gruppen-Element)
     * @param array<int, FieldDefinition> $sourceFieldsById
     * @param array<int, mixed> $manualValues siehe translate()
     */
    private function applyTransform(array $fieldMapping, array $row, array $sourceFieldsById, array $manualValues = []): mixed
    {
        $config = $fieldMapping['transform_config'] !== null
            ? (json_decode((string) $fieldMapping['transform_config'], true) ?: [])
            : [];

        $sourceField = $fieldMapping['source_field_id'] !== null ? ($sourceFieldsById[(int) $fieldMapping['source_field_id']] ?? null) : null;
        $sourceValue = $sourceField !== null ? ($row[$sourceField->key] ?? null) : null;

        switch ($fieldMapping['transform_type']) {
            case 'static':
                return $config['value'] ?? null;

            case 'manual_input':
                // Wert kommt NICHT aus $row (Shopify liefert ihn nicht) und
                // ist auch kein fester Wert in der Vorlage -- sondern vom
                // Merchant beim Import selbst eingegeben (siehe
                // Connect\Sync\ProductImportService::importIntoTarget()).
                return $manualValues[(int) $fieldMapping['target_field_id']] ?? null;

            case 'lookup':
                return $this->lookupValue((int) ($config['lookup_table_id'] ?? 0), (string) $sourceValue, $config['default'] ?? null);

            case 'concat':
                $parts = [(string) $sourceValue];
                foreach ((array) ($config['additional_source_field_ids'] ?? []) as $additionalFieldId) {
                    $additionalField = $sourceFieldsById[(int) $additionalFieldId] ?? null;
                    $parts[] = $additionalField !== null ? (string) ($row[$additionalField->key] ?? '') : '';
                }

                return implode((string) ($config['separator'] ?? ' '), $parts);

            case 'differs_from':
                // Fuer Faelle wie MGM "Abweichende Lieferadresse (nur
                // uebertragen, wenn abweichend)": liefert den Quellwert nur,
                // wenn er sich vom Vergleichsfeld unterscheidet -- sonst
                // null (rendert als leer, siehe XmlTemplateRenderer). Der
                // Vergleich laeuft je Zielfeld einzeln (z.B. Strasse gegen
                // Strasse, PLZ gegen PLZ) statt einmal fuer die "ganze
                // Adresse" -- einfacher umzusetzen und deckt den ueblichen
                // Fall ab, dass entweder alle Adressfelder gleich sind oder
                // alle unterschiedlich.
                $compareField = $sourceFieldsById[(int) ($config['compare_field_id'] ?? 0)] ?? null;
                $compareValue = $compareField !== null ? ($row[$compareField->key] ?? null) : null;

                return ((string) $sourceValue !== (string) $compareValue) ? $sourceValue : null;

            case 'date_format':
                if ($sourceValue === null || $sourceValue === '') {
                    return null;
                }

                try {
                    return (new \DateTimeImmutable((string) $sourceValue))->format((string) ($config['format'] ?? 'Y-m-d'));
                } catch (\Exception) {
                    // Nicht parsebares Datum -- lieber leer als ein
                    // fehlerhaftes Schreiben ins Zielsystem provozieren.
                    return null;
                }

            case 'text_template':
                return self::renderTextTemplate((string) ($config['template'] ?? ''), $row);

            case 'shop_field':
                // Shop-Feld (Migration 0074, Connect\Sync\ShopFields): je Shop definiert,
                // im Cache als sf_<field_key>. Shops ohne dieses Feld -> null.
                $value = $row[\Connect\Sync\ShopFields::CACHE_PREFIX . (string) ($config['field_key'] ?? '')] ?? null;

                return $value === '' ? null : $value;

            case 'direct':
            default:
                return $sourceValue;
        }
    }

    private function lookupValue(int $lookupTableId, string $sourceValue, ?string $default): ?string
    {
        if ($lookupTableId <= 0) {
            return $default;
        }

        $stmt = $this->pdo->prepare(
            'SELECT target_value FROM connect_lookup_table_entries WHERE lookup_table_id = :id AND source_value = :source_value'
        );
        $stmt->execute(['id' => $lookupTableId, 'source_value' => $sourceValue]);
        $value = $stmt->fetchColumn();

        return $value !== false ? (string) $value : $default;
    }

    /**
     * transform_type='text_template' ("Text mit Platzhaltern"): freier Text, in dem
     * {{feld_key}} durch den Wert des Quellfelds ersetzt wird, z.B. Rechnungstext
     * "Shopify {{order_number}}" -- per Maske aenderbar statt fest im Code.
     * {{feld_key|d.m.Y}} formatiert einen Datumswert (PHP-date-Format, Zeitzone
     * Europe/Vienna wie beim Mail-Platzhalter %DATUM%). Unbekannte/leere Felder
     * werden zu ''. Ergebnis leer -> null (damit greift "Nur senden, wenn Wert
     * vorhanden").
     *
     * @param array<string, mixed> $row
     */
    public static function renderTextTemplate(string $template, array $row): ?string
    {
        $text = preg_replace_callback(
            '/\{\{\s*([A-Za-z0-9_]+)\s*(?:\|([^}]*))?\}\}/',
            static function (array $m) use ($row): string {
                $value = $row[$m[1]] ?? null;
                if ($value === null || is_array($value)) {
                    return '';
                }
                if (is_bool($value)) {
                    return $value ? 'ja' : 'nein';
                }
                $format = trim($m[2] ?? '');
                if ($format !== '' && (string) $value !== '') {
                    try {
                        return (new \DateTimeImmutable((string) $value))
                            ->setTimezone(new \DateTimeZone('Europe/Vienna'))
                            ->format($format);
                    } catch (\Exception) {
                        return (string) $value;
                    }
                }

                return (string) $value;
            },
            $template
        );
        $text = trim((string) $text);

        return $text !== '' ? $text : null;
    }
}
