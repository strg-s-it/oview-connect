<?php

declare(strict_types=1);

namespace Connect\Sync;

use Connect\Connector\ConnectorFactory;
use PDO;

/**
 * Automatischer Import fehlender Produkte (Shopify -> Zielsystem, z.B.
 * HelloCash) -- ausgeloest ueber connect_sync_mappings.mapping_kind=
 * 'auto_import_products' (Migration 0059, gleiches Prinzip wie mapping_kind=
 * 'stock_sync'/'invoice', siehe Connect\Sync\SyncRunner::run()).
 *
 * Portiert aus der alten Welt (jobs/cron_shopify_product_sync.php, laeuft
 * dort aktiv fuer Shop "neherashop") -- bisher in Connect nur als manueller
 * "Importieren"-Klick auf der Merchant-Seite "Produkte" verfuegbar (siehe
 * Connect\Sync\ProductImportService::importIntoTarget()). Diese Klasse
 * wiederholt fuer JEDE fehlende Zeile exakt dieselbe, bereits gebaute und
 * getestete Logik -- kein eigener Import-Code, nur die Batch-Steuerung drumherum.
 *
 * NUR fuer die Richtung "fehlt im Zielsystem" (missing_on_target) -- die
 * umgekehrte Richtung (fehlt in Shopify) bleibt bewusst manuell (Nutzer-
 * Entscheidung 2026-09-27), siehe ProductImportService-Klassenkommentar
 * "kein automatischer Bulk-Import".
 *
 * WICHTIG (Nutzer-Vorgabe): connect_shops.auto_import_missing_to_target ist
 * eine ADMIN-ONLY-Einstellung -- der Merchant kann sie nicht selbst umschalten
 * (siehe app/_sync_mappings_body.php, admin-only Abschnitt).
 *
 * Legacy-Sonderfaelle (fixer 20%-Steuersatz, Kategorie-Filter "AT-01" fuer
 * Shop "neherashop", siehe jobs/cron_shopify_product_sync.php) NICHT
 * hartcodiert nachgebaut, sondern generisch:
 *   - Steuersatz: ueber eine Mapping-Vorlage mit transform_type='manual_input'
 *     + "Vorbelegung aus Einstellung" = connect_shops.default_tax_rate (siehe
 *     ProductImportService::SHOP_SETTING_DEFAULT_SOURCES/
 *     manualInputFieldsForTargetImport(), weiter unten in runForShop()
 *     automatisch als Merchant-Eingabe behandelt) -- Nutzer-Entscheidung
 *     (2026-09-28): "die Steuer soll aus den Einstellungen verwendet werden",
 *     NICHT ein fester Wert direkt in der Vorlage (transform_type='static'),
 *     das war mein urspruenglicher, korrigierter Vorschlag -- ein fester Wert
 *     haette bei einer spaeteren Aenderung des Steuersatzes still von der
 *     echten Einstellung abweichen koennen.
 *   - Kategorie-Filter (Migration 0060, connect_shops.auto_import_filter_*):
 *     der Admin waehlt ein beliebiges Feld des Shopify-"Produkt"-Objects
 *     (nicht auf "product_type"/Kategorie festgelegt) UND einen Text, der
 *     darin enthalten sein muss (Teilstring, gross-/kleinschreibungsunab-
 *     haengig) -- siehe applyFilter().
 *
 * Der in der alten Welt zusaetzliche Standort-Filter wird bewusst NICHT
 * nachgebaut, siehe Migration 0060-Kommentar: er loeste dort ein reines
 * Mehrfachzeilen-Problem einer pro-Standort-VIEW, das es in Connects
 * identifier-basiertem Objekt-Cache (connect_object_records) strukturell
 * nicht gibt.
 */
final class AutoProductImportRunner
{
    /** Sicherheitsnetz pro Lauf, wie das "limit 20" im alten cron_shopify_product_sync.php. */
    private const MAX_IMPORTS_PER_RUN = 20;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ConnectorFactory $connectorFactory
    ) {
    }

    /**
     * @return array{status: string, matched: int, written: int, failed: int, message: string}
     *         status: skipped | success | partial | error
     */
    public function runForShop(string $shop): array
    {
        // Admin-only-Schalter (siehe Klassenkommentar) -- bewusst hier geprueft,
        // nicht erst beim Anlegen der Mapping-Zeile, damit ein Admin die
        // Funktion jederzeit auch wieder OHNE die Mapping-Zeile zu loeschen
        // deaktivieren kann.
        $settingsStmt = $this->pdo->prepare(
            'SELECT auto_import_missing_to_target, auto_import_filter_field_key, auto_import_filter_value
             FROM connect_shops WHERE shop = :shop'
        );
        $settingsStmt->execute(['shop' => $shop]);
        $settings = $settingsStmt->fetch();

        if ($settings === false) {
            return self::result('skipped', 0, 0, 0, 'Shop-Einstellungen (connect_shops) nicht gefunden.');
        }
        if ((int) $settings['auto_import_missing_to_target'] !== 1) {
            return self::result('skipped', 0, 0, 0, 'Automatischer Produktimport ist für diesen Shop nicht aktiviert (Admin-Einstellung).');
        }

        $report = (new ProductComparisonService($this->pdo, $this->connectorFactory))->buildReport($shop);
        if ($report['status'] !== 'success') {
            return self::result($report['status'], 0, 0, 0, $report['message'] ?? 'Vergleich nicht möglich.');
        }

        $missingRows = array_values(array_filter(
            $report['rows'],
            static fn (array $row): bool => ($row['status'] ?? '') === 'missing_on_target' && ($row['match_value'] ?? '') !== ''
        ));

        if ($missingRows === []) {
            return self::result('success', 0, 0, 0, 'Keine fehlenden Produkte gefunden.');
        }

        $filterFieldKey = trim((string) ($settings['auto_import_filter_field_key'] ?? ''));
        $filterValue = trim((string) ($settings['auto_import_filter_value'] ?? ''));
        $filterNote = null;

        if ($filterFieldKey !== '' && $filterValue !== '') {
            $beforeCount = count($missingRows);
            $missingRows = $this->applyFilter($shop, $missingRows, $filterFieldKey, $filterValue);
            $filterNote = "Filter \"{$filterFieldKey}\" enthält \"{$filterValue}\": {$beforeCount} auf " . count($missingRows) . ' eingegrenzt.';

            if ($missingRows === []) {
                return self::result('success', 0, 0, 0, "Keine fehlenden Produkte nach Filter. {$filterNote}");
            }
        }

        $batch = array_slice($missingRows, 0, self::MAX_IMPORTS_PER_RUN);
        $importer = new ProductImportService($this->pdo, $this->connectorFactory);

        // manual_input-Zielfelder (z.B. HelloCash "Steuersatz") brauchen sonst eine
        // Eingabe des Merchants auf der Produkte-Seite -- hier ist keiner da. Wo die
        // Mapping-Vorlage dafuer eine "Vorbelegung aus Einstellung" hinterlegt hat
        // (siehe manualInputFieldsForTargetImport(), z.B. connect_shops.
        // default_tax_rate unter "Preis/Steuer"), wird dieser Wert automatisch wie
        // eine Merchant-Eingabe behandelt -- Nutzer-Entscheidung (2026-09-28): "die
        // Steuer soll aus den Einstellungen verwendet werden". Ein Feld OHNE
        // Vorbelegung bleibt weiterhin ein echter Fehler (siehe importIntoTarget()),
        // damit nicht heimlich mit einem falschen/leeren Wert angelegt wird. Einmal
        // pro Lauf aufgeloest, nicht je Produkt -- die Werte kommen aus den
        // Shop-Einstellungen, nicht aus dem Produkt selbst.
        $manualFieldValues = [];
        foreach ($importer->manualInputFieldsForTargetImport($shop) as $field) {
            if ($field['default_value'] !== null) {
                $manualFieldValues[$field['field_key']] = $field['default_value'];
            }
        }

        $written = 0;
        $failed = 0;
        $errorMessages = [];

        foreach ($batch as $row) {
            $matchValue = (string) $row['match_value'];
            $result = $importer->importMissingProduct($shop, $matchValue, 'target', null, $manualFieldValues);

            if ($result['success']) {
                $written++;
            } else {
                $failed++;
                $errorMessages[] = "{$matchValue}: {$result['message']}";
            }
        }

        $status = $failed === 0 ? 'success' : ($written > 0 ? 'partial' : 'error');
        $remaining = count($missingRows) - count($batch);
        $message = "{$written} von " . count($batch) . ' fehlenden Produkten automatisch angelegt'
            . ($remaining > 0 ? " (weitere {$remaining} folgen im nächsten Lauf)" : '') . '.';
        if ($filterNote !== null) {
            $message .= ' ' . $filterNote;
        }
        if ($errorMessages !== []) {
            $message .= ' Fehler: ' . implode(' | ', $errorMessages);
        }

        return self::result($status, count($batch), $written, $failed, $message);
    }

    /**
     * Grenzt die fehlenden Zeilen auf die ein, deren Shopify-Datensatz im
     * gewaehlten Feld den gewuenschten Text enthaelt (Teilstring, gross-/
     * kleinschreibungsunabhaengig) -- siehe Klassenkommentar/Migration 0060.
     * Findet sich das Feld nicht (mehr) oder ist der Cache leer, bleibt die
     * Liste UNVERAENDERT (kein stiller Totalausfall durch eine spaeter
     * geloeschte Feld-Zuordnung) -- der Filter wirkt dann einfach nicht.
     *
     * @param list<array{match_value: ?string}> $missingRows
     * @return list<array{match_value: ?string}>
     */
    private function applyFilter(string $shop, array $missingRows, string $filterFieldKey, string $filterValue): array
    {
        try {
            $context = ProductMatchContext::resolve($this->pdo, $this->connectorFactory, $shop);
        } catch (ProductMatchUnresolvedException) {
            return $missingRows;
        }

        $cachedRows = (new ObjectCache($this->pdo))->getCached($context->shopifyConnectionId, $context->shopifyObject->key);
        if ($cachedRows === []) {
            return $missingRows;
        }

        $matchFieldKey = $context->shopifyMatchField->key;
        $byMatchValue = [];
        foreach ($cachedRows as $cachedRow) {
            $key = (string) ($cachedRow[$matchFieldKey] ?? '');
            if ($key !== '') {
                $byMatchValue[$key] = $cachedRow;
            }
        }

        return array_values(array_filter($missingRows, static function (array $row) use ($byMatchValue, $filterFieldKey, $filterValue): bool {
            $cachedRow = $byMatchValue[(string) ($row['match_value'] ?? '')] ?? null;
            if ($cachedRow === null || !array_key_exists($filterFieldKey, $cachedRow)) {
                return false;
            }

            return stripos((string) $cachedRow[$filterFieldKey], $filterValue) !== false;
        }));
    }

    /**
     * @return array{status: string, matched: int, written: int, failed: int, message: string}
     */
    private static function result(string $status, int $matched, int $written, int $failed, string $message): array
    {
        return ['status' => $status, 'matched' => $matched, 'written' => $written, 'failed' => $failed, 'message' => $message];
    }
}
