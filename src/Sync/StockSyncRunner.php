<?php

declare(strict_types=1);

namespace Connect\Sync;

use Connect\Connector\ConnectorFactory;
use Connect\Connector\ConnectorInterface;
use Connect\Connector\Generic\GenericHttpConnector;
use Connect\Connector\ObjectDefinition;
use PDO;

/**
 * Automatischer Lagerbestand-Abgleich zwischen Shopify und dem shop-eigenen
 * Zielsystem (connect_shops.target_connection_id, z.B. HelloCash), gesteuert
 * ueber die bereits bestehenden Merchant-Einstellungen comparison_key/
 * stock_sync_direction/stock_sync_enabled (Migration 0013,
 * public/app/_sync_mappings_body.php).
 *
 * BEWUSST eine eigene Klasse statt eine Erweiterung von MappingEngine: deren
 * translateAndWrite() schreibt jeden Quell-Datensatz genau EINMAL (Dedup ueber
 * connect_translation_log) -- richtig fuer "Bestellung -> Rechnung", aber
 * falsch fuer Lagerbestand, der sich bei jedem Lauf aendern darf/soll.
 *
 * Wird trotzdem wie jeder andere Sync-Job ausgefuehrt/protokolliert: eine
 * ganz normale connect_sync_mappings-Zeile mit mapping_kind='stock_sync'
 * (Migration 0028) durchlaeuft denselben Scheduler-Claim
 * (connect_sync_jobs) und dieselbe Lauf-Historie (connect_sync_runs) wie
 * jeder Cache-/Uebersetzungs-Job -- SyncRunner::run() delegiert bei diesem
 * mapping_kind hierher, statt seine eigene Logik auszufuehren. runForShop()
 * selbst kennt daher weder Job- noch Lauf-Protokollierung, sondern liefert
 * nur ein Ergebnis zurueck, das der Aufrufer (SyncRunner) in den ganz
 * normalen Lauf-Datensatz eintraegt.
 *
 * Liest BEIDE Seiten nur aus dem Cache (connect_object_records), nie live --
 * die Konsistenz zwischen Shopify- und Ziel-Cache haengt damit an deren
 * jeweils eigenem Sync-Intervall, es gibt keine erzwungene Ausfuehrungs-
 * reihenfolge. Stattdessen erkennt runForShop() selbst, ob auf beiden Seiten
 * schon Daten vorhanden sind, und ueberspringt den Zyklus sonst (kein
 * fehlerhaftes Nullen von Bestaenden bei einem frisch verbundenen Shop) --
 * bei ausreichend haeufigen Cache-Intervallen (z.B. stuendlich) konvergiert
 * das von selbst innerhalb kurzer Zeit.
 *
 * Matching zwischen den beiden Systemen laeuft NICHT ueber hartkodierte
 * Feldnamen (die unterscheiden sich pro System, z.B. Shopify "sku" vs.
 * HelloCash "code"), sondern ueber die im Connector Builder gesetzten Rollen
 * connect_object_fields.comparison_role/is_stock_quantity (Migration 0027).
 * Ein Object ohne beide Markierungen kann nicht teilnehmen (wird
 * uebersprungen, keine Exception).
 *
 * Schreib-Konvention (siehe runForShop()): ist Shopify das Ziel, wird der
 * Lagerbestand ABSOLUT gesetzt (inventorySetQuantities, passend zur
 * Richtung "target_to_shopify" -- das fuehrende System kennt den wahren
 * Bestand). Ist ein anderes System das Ziel (aktuell: HelloCash), wird ein
 * DELTA geschrieben (Feld "stock_change") -- HelloCashs change-stock-
 * Endpunkt kennt keine absolute Wert-Setzung. Ein drittes Zielsystem mit
 * eigener Schreib-Semantik braeuchte hier eine weitere Fallunterscheidung.
 */
final class StockSyncRunner
{
    /**
     * Max. Live-Kontrollen (= Schreibkandidaten) je Lauf. Jede kostet je einen Einzelabruf
     * auf beiden Seiten -- beim ersten Abgleich eines Shops koennen es hunderte sein, der
     * Rest folgt in den naechsten Laeufen statt einen Lauf ueber die Anfrage-Limits zu ziehen.
     */
    private const MAX_LIVE_CHECKS_PER_RUN = 150;

    /** @var array<string, true|string> Listen ohne Einzelabfrage: in diesem Lauf frisch geladen (true) oder Fehlertext */
    private array $listRefreshedThisRun = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ConnectorFactory $connectorFactory
    ) {
    }

    /**
     * @return array{status: string, matched: int, written: int, failed: int, message: string, adjustments: array<int, array{match_value: string, label: ?string, direction: string, old_quantity: ?int, new_quantity: int}>}
     *         status: skipped | success | partial | error
     */
    public function runForShop(string $shop, ?int $runId = null): array
    {
        $this->listRefreshedThisRun = [];

        // Der Aktivierungs-Schalter gehoert bewusst hierher statt in
        // ProductMatchContext::resolve() -- die rein lesende
        // ProductComparisonService soll eine Vergleichsuebersicht auch
        // liefern koennen, solange der automatische Abgleich noch
        // deaktiviert ist (z.B. zur Kontrolle VOR dem Aktivieren).
        $enabledStmt = $this->pdo->prepare('SELECT stock_sync_enabled FROM connect_shops WHERE shop = :shop');
        $enabledStmt->execute(['shop' => $shop]);
        $enabledValue = $enabledStmt->fetchColumn();

        if ($enabledValue === false) {
            return self::result('skipped', 0, 0, 0, 'Shop-Einstellungen (connect_shops) nicht gefunden.', []);
        }
        if ((int) $enabledValue !== 1) {
            return self::result('skipped', 0, 0, 0, 'Automatischer Lagerbestandsabgleich ist fuer diesen Shop nicht aktiviert.', []);
        }

        try {
            $context = ProductMatchContext::resolve($this->pdo, $this->connectorFactory, $shop);
        } catch (ProductMatchUnresolvedException $e) {
            return self::result($e->status, 0, 0, 0, $e->getMessage(), []);
        }

        // Ohne Shopify-Standort wird nichts abgeglichen: Shopify fuehrt den Bestand je Standort,
        // ohne Auswahl ist unklar, welcher Bestand gemeint ist (und Schreiben nach Shopify geht gar nicht).
        // Der Hinweis dazu erscheint in Connect (MerchantOverview, Produkte-Seite).
        if ($context->locationId === null || $context->locationId === '') {
            return self::result('skipped', 0, 0, 0, 'Kein Shopify-Standort gewählt – Lagerabgleich pausiert. Bitte unter Einstellungen → Lagerbestand den Standort wählen.', []);
        }

        $shopifyObject = $context->shopifyObject;
        $shopifyMatchField = $context->shopifyMatchField;
        $shopifyStockField = $context->shopifyStockField;
        $targetObject = $context->targetObject;
        $targetMatchField = $context->targetMatchField;
        $targetStockField = $context->targetStockField;
        $shopifyConnector = $context->shopifyConnector;
        $targetConnector = $context->targetConnector;
        $shopifyConnectionId = $context->shopifyConnectionId;
        $targetConnectionId = $context->targetConnectionId;
        $direction = $context->direction;
        $locationId = $context->locationId;
        // Sicherheitsbestand (Connect\Sync\ShopStockRules, Migration 0087) -- nur bei "Kassensystem fuehrt"
        $safetyBuffer = ShopStockRules::forShop($this->pdo, $shop)['buffer'];

        $objectCache = new ObjectCache($this->pdo);
        // Verwaiste Zeilen (in der Quelle geloescht, siehe ObjectCache::
        // markOrphaned()) IMMER ausschliessen -- ohne diesen Filter versucht
        // ein Lagerbestand-Schreibversuch gegen z.B. ein bereits in Shopify
        // geloeschtes Produkt/Inventory Item ("The specified inventory item
        // could not be found.", live beobachtet). ProductComparisonService/
        // ProductImportService::findExistingProductId() filtern das schon
        // laenger -- hier fehlte es bisher, echter Bug.
        // Standortwechsel: Shopify-Datensaetze, die noch mit einem ANDEREN (oder ohne) Standort geladen
        // wurden (_location_id, siehe ObjectCache::refresh()), tragen den Bestand des falschen Standorts.
        // Bis zum naechsten Abruf werden sie nicht abgeglichen.
        $locationScoped = $shopifyStockField->key === 'location_stock_quantity';
        $staleLocation = 0;
        $shopifyRecords = [];
        foreach ($objectCache->getCached($shopifyConnectionId, $shopifyObject->key) as $record) {
            if (($record['_status'] ?? 'active') === 'orphaned') {
                continue;
            }
            if ($locationScoped && (string) ($record['_location_id'] ?? '') !== (string) $locationId) {
                $staleLocation++;
                continue;
            }
            $shopifyRecords[] = $record;
        }
        $targetRecords = array_values(array_filter(
            $objectCache->getCached($targetConnectionId, $targetObject->key),
            static fn (array $record): bool => ($record['_status'] ?? 'active') !== 'orphaned'
        ));

        // Das System erkennt selbst, ob schon Daten vorhanden sind -- kein
        // UI-Lock, kein fester Vorlauf noetig (siehe Klassenkommentar). Ein
        // leerer Cache auf einer Seite (z.B. frisch verbundener Shop, erster
        // Cache-Lauf noch nicht durch) fuehrt hier nur zu einem
        // uebersprungenen Zyklus, nicht zu faelschlich auf 0 gesetzten
        // Lagerbestaenden.
        if (count($shopifyRecords) === 0 || count($targetRecords) === 0) {
            return self::result(
                'skipped',
                0,
                0,
                0,
                'Noch keine Daten auf beiden Seiten -- Shopify: ' . count($shopifyRecords) . ', Ziel: ' . count($targetRecords) . ' Datensaetze.'
                    . ($staleLocation > 0 ? " {$staleLocation} Shopify-Produkte warten auf den Abruf mit dem gewählten Standort." : ''),
                []
            );
        }

        // Fuer die Anzeige in connect_stock_sync_log (Merchant-Uebersicht
        // "Lagerbestaende") -- unabhaengig vom shop-weit gewaehlten
        // comparisonKey (der z.B. auch "sku" sein kann), damit dort ein
        // lesbarer Produktname statt nur des Vergleichswerts steht.
        $sourceTitleField = ProductMatchContext::titleField($direction === 'target_to_shopify' ? $targetObject : $shopifyObject);
        $destTitleField = ProductMatchContext::titleField($direction === 'target_to_shopify' ? $shopifyObject : $targetObject);

        if ($direction === 'target_to_shopify') {
            $sourceObject = $targetObject;
            $sourceConnector = $targetConnector;
            $sourceConnectionId = $targetConnectionId;
            $sourceRecords = $targetRecords;
            $sourceMatchField = $targetMatchField;
            $sourceStockField = $targetStockField;
            $destRecords = $shopifyRecords;
            $destMatchField = $shopifyMatchField;
            $destStockField = $shopifyStockField;
            $destObject = $shopifyObject;
            $destConnector = $shopifyConnector;
            $destConnectionId = $shopifyConnectionId;
            $destIsShopify = true;
        } else {
            $sourceObject = $shopifyObject;
            $sourceConnector = $shopifyConnector;
            $sourceConnectionId = $shopifyConnectionId;
            $sourceRecords = $shopifyRecords;
            $sourceMatchField = $shopifyMatchField;
            $sourceStockField = $shopifyStockField;
            $destRecords = $targetRecords;
            $destMatchField = $targetMatchField;
            $destStockField = $targetStockField;
            $destObject = $targetObject;
            $destConnector = $targetConnector;
            $destConnectionId = $targetConnectionId;
            $destIsShopify = false;
        }

        $destIdentifierField = ObjectCache::requireIdentifierField($destObject);

        // Mehrfach vorkommende Vergleichswerte (auf EINER der beiden Seiten)
        // sind nicht eindeutig zuordenbar -- ohne diesen Schutz gewinnt beim
        // Indexieren unten stillschweigend der letzte Datensatz, und mehrere
        // Quell-Datensaetze wuerden nacheinander ihren Bestand in DENSELBEN
        // Ziel-Datensatz schreiben (siehe ComparisonValueCheck, Altsystem-
        // Pruefung "Vergleichswert-Duplikate").
        $sourceDuplicates = ComparisonValueCheck::duplicateValues($sourceRecords, $sourceMatchField->key);
        $destDuplicates = ComparisonValueCheck::duplicateValues($destRecords, $destMatchField->key);
        $skippedDuplicates = 0;

        $destIndex = [];
        foreach ($destRecords as $record) {
            $key = $record[$destMatchField->key] ?? null;
            if ($key !== null && $key !== '') {
                $destIndex[(string) $key] = $record;
            }
        }

        // Sicherheitsbremse (Migration 0080, Connect\Sync\StockGuard): geplante Aenderungen
        // ueber ALLE Paare (vor der 150er-Grenze) gegen klare Fehlerbilder der Quelle pruefen.
        $guard = new StockGuard($this->pdo);
        $guardState = $guard->load($shop);
        $plan = null;
        if ($guardState !== null) {
            if (StockGuard::isPaused($guardState)) {
                return self::result('skipped', 0, 0, 0, 'Sicherheitsbremse: Lagerabgleich angehalten seit ' . (string) ($guardState['at'] ?? '?')
                    . ' – Freigabe im Admin (Jobs dieses Shops). ' . implode(' ', (array) ($guardState['reason'] ?? [])), []);
            }
            $plan = $this->planSummary($sourceRecords, $destIndex, $sourceMatchField->key, $sourceStockField->key, $destStockField->key, $sourceDuplicates, $destDuplicates, $sourceTitleField?->key);
            if (empty($guardState['release_once'])) {
                $reasons = $guard->evaluate($guardState, $plan);
                if ($reasons !== []) {
                    return self::result('skipped', 0, 0, 0, $guard->hit($shop, $guardState, $reasons, $plan['examples']), []);
                }
            }
            $guard->pass($shop, $guardState, $plan);
        }

        $matched = 0;
        $written = 0;
        $failed = 0;
        $errorMessages = [];
        $adjustments = [];
        $liveChecks = 0;     // Live-Kontrollen in diesem Lauf (je 2 API-Aufrufe)
        $liveCorrected = 0;  // Cache war veraltet, live gleich -> nicht geschrieben
        $deferred = 0;       // ueber dem Limit, im naechsten Lauf

        foreach ($sourceRecords as $sourceRecord) {
            $matchValue = $sourceRecord[$sourceMatchField->key] ?? null;
            if ($matchValue === null || $matchValue === '') {
                continue;
            }

            if (isset($sourceDuplicates[(string) $matchValue]) || isset($destDuplicates[(string) $matchValue])) {
                $skippedDuplicates++;
                continue;
            }

            $destRecord = $destIndex[(string) $matchValue] ?? null;
            if ($destRecord === null) {
                continue;
            }

            $matched++;

            // array_key_exists statt ?? 0 -- ein FEHLENDES Feld (z.B. HelloCash
            // "stocks", das laut Handoff nur per Detail-Nachladen in den Cache
            // kommt, siehe connect_objects.hydrate_details_after_list) ist NICHT
            // dasselbe wie ein Bestand von 0. Wurde vorher gleichgesetzt --
            // Symptom: eine Delta-Schreibung gegen eine faelschlich als "0"
            // angenommene Ziel-Menge hat den Zielbestand um die volle
            // Quell-Menge zu hoch verschoben (z.B. 100 -> 199 statt -> 99),
            // statt den Lauf abzubrechen.
            // NULL (nicht nur ein fehlender Schluessel) heisst bei einem
            // standort-gebundenen Lagerbestandsfeld (z.B. Shopifys
            // "location_stock_quantity", Migration 0061): dieses Produkt wird
            // am gewaehlten Standort (connect_shops.stock_primary_location_id)
            // gar nicht gefuehrt -- kein Fehler, sondern bewusst ausserhalb
            // des Abgleichs (Nutzer-Entscheidung 2026-09-27: "immer nur
            // Produkte vom gewaehlten Standort ... verwenden").
            if (!array_key_exists($sourceStockField->key, $sourceRecord) || $sourceRecord[$sourceStockField->key] === null) {
                continue; // Quell-Menge unbekannt bzw. an diesem Standort nicht gefuehrt
            }
            // Sicherheitsbestand (Migration 0087): Shopify zeigt Kassenbestand minus Puffer
            $sourceQuantity = ShopStockRules::shopifyQuantity((int) $sourceRecord[$sourceStockField->key], $safetyBuffer, $direction);

            $destQuantityKnown = array_key_exists($destStockField->key, $destRecord) && $destRecord[$destStockField->key] !== null;
            $currentDestQuantity = $destQuantityKnown ? (int) $destRecord[$destStockField->key] : null;

            if ($currentDestQuantity !== null && $sourceQuantity === $currentDestQuantity) {
                continue; // bereits synchron, kein Schreibzugriff noetig
            }

            // Live-Kontrolle vor jedem Schreiben (Nutzer-Entscheidung 2026-10-06): der Cache
            // kann auf einer Seite veraltet sein -- HelloCash-Bestaende kommen nur per
            // Detail-Nachladen in Stapeln und koennen Stunden alt sein. Ohne Kontrolle:
            //   - Zielsystem fuehrt: alter, hoeherer HelloCash-Wert ueberschreibt einen frischen
            //     Shopify-Verkauf (Shopifys changeFromQuantity greift nicht, weil der
            //     Shopify-Cache aktuell ist) -> Ueberverkauf
            //   - Shopify fuehrt: Delta nach HelloCash auf einem veralteten Ausgangswert
            // Deshalb beide Datensaetze einzeln live nachladen (aktualisiert zugleich den Cache)
            // und nur mit diesen Werten rechnen. Nur fuer Abweichungen, also meist wenige.
            if ($liveChecks >= self::MAX_LIVE_CHECKS_PER_RUN) {
                $deferred++;
                continue;
            }
            $liveChecks++;
            try {
                $liveSource = $this->liveRecord($objectCache, $sourceConnector, $sourceConnectionId, $sourceObject, $sourceRecord);
                $liveDest = $this->liveRecord($objectCache, $destConnector, $destConnectionId, $destObject, $destRecord);
            } catch (\Throwable $e) {
                $failed++;
                $errorMessages[] = "\"{$matchValue}\": Live-Kontrolle fehlgeschlagen, nicht geschrieben ({$e->getMessage()}).";
                continue;
            }
            if ($liveSource === null || $liveDest === null) {
                continue; // inzwischen geloescht -- naechster Cache-Lauf markiert es als verwaist
            }
            if (!array_key_exists($sourceStockField->key, $liveSource) || $liveSource[$sourceStockField->key] === null) {
                continue;
            }
            $sourceRecord = $liveSource;
            $destRecord = $liveDest;
            $sourceQuantity = ShopStockRules::shopifyQuantity((int) $liveSource[$sourceStockField->key], $safetyBuffer, $direction);
            $destQuantityKnown = array_key_exists($destStockField->key, $liveDest) && $liveDest[$destStockField->key] !== null;
            $currentDestQuantity = $destQuantityKnown ? (int) $liveDest[$destStockField->key] : null;
            if ($currentDestQuantity !== null && $sourceQuantity === $currentDestQuantity) {
                $liveCorrected++;
                continue; // der Cache war veraltet, live sind beide gleich
            }

            if ($destIsShopify) {
                $inventoryItemId = $destRecord['inventory_item_id'] ?? null;
                if ($inventoryItemId === null || $inventoryItemId === '' || $locationId === null || $locationId === '') {
                    $failed++;
                    $errorMessages[] = "Shopify-Ziel fuer \"{$matchValue}\" ohne inventory_item_id oder ohne primaeren Standort.";
                    continue;
                }

                // Absolute Setzung -- ABER Shopify verlangt inzwischen
                // "changeFromQuantity" (den bisher bekannten Bestand) als
                // Pflichtfeld je Position (Live-Fehler: "InventoryQuantityInput
                // must include the following argument: changeFromQuantity"),
                // vermutlich als Schutz gegen ein versehentliches Ueberschreiben
                // ohne zu wissen, wovon man ausgeht. Der urspruengliche Plan
                // ("der bisherige Wert ist unproblematisch, wenn unbekannt")
                // stimmt damit nicht mehr -- ohne bekannten Ziel-Bestand kann
                // nicht geschrieben werden, gleiche Absicherung wie im
                // Nicht-Shopify-Zweig unten.
                if ($currentDestQuantity === null) {
                    $failed++;
                    $errorMessages[] = "Shopify-Ziel fuer \"{$matchValue}\": bisheriger Lagerbestand im Cache unbekannt -- changeFromQuantity kann nicht gesetzt werden.";
                    continue;
                }

                $writeData = [
                    'inventoryItemId' => $inventoryItemId,
                    'locationId' => $locationId,
                    'quantity' => $sourceQuantity,
                    'changeFromQuantity' => $currentDestQuantity,
                ];
            } else {
                if ($currentDestQuantity === null) {
                    $failed++;
                    $errorMessages[] = "\"{$matchValue}\": Ziel-Lagerbestand im Cache unbekannt (Detail-Nachladen noch nicht aktiv/gelaufen?) -- Delta kann nicht berechnet werden.";
                    continue;
                }

                $writeData = [
                    $destIdentifierField->key => $destRecord[$destIdentifierField->key] ?? null,
                    'stock_change' => $sourceQuantity - $currentDestQuantity,
                ];
            }

            $writeResult = $destConnector->write($destObject->key, 'update', $writeData);
            // Volles Protokoll (inkl. rohem Request/Response) -- ersetzt fuer
            // admin/activity_log.php das strukturierte connect_stock_sync_log
            // (das bleibt separat bestehen, nur fuer die Merchant-Seite
            // "Lagerbestaende" und schreibt NUR bei Erfolg, siehe SyncRunner::
            // run()) -- hier soll auch ein FEHLGESCHLAGENER Einzel-
            // Schreibversuch mit vollem Request/Response sichtbar sein, nicht
            // nur in der kurzen $errorMessages-Zusammenfassung des Laufs.
            $oldQuantityLabel = $currentDestQuantity !== null ? (string) $currentDestQuantity : '?';
            ActivityLog::log(
                $this->pdo,
                $shop,
                $destIsShopify ? 'shopify' : null,
                $destObject->key,
                'stock_update',
                $writeResult->success,
                $writeResult->success
                    ? "Lagerbestand fuer \"{$matchValue}\": {$oldQuantityLabel} -> {$sourceQuantity}."
                    : $writeResult->message,
                (string) ($destRecord[$destIdentifierField->key] ?? '') ?: null,
                $writeResult->requestBody,
                $writeResult->responseBody,
                $runId
            );
            if ($writeResult->success) {
                $written++;

                // Cache SOFORT mit dem jetzt bekannten neuen Wert nachziehen,
                // ohne live nachzufragen -- sonst wuerde der naechste Lauf mit
                // dem alten gecachten Wert erneut ein Delta berechnen und den
                // bereits korrekten Zielbestand nochmal veraendern (siehe
                // Klassenkommentar).
                $destExternalId = (string) ($destRecord[$destIdentifierField->key] ?? '');
                if ($destExternalId !== '') {
                    $objectCache->updateCachedFields($destConnectionId, $destObject->key, $destExternalId, [$destStockField->key => $sourceQuantity]);
                }

                // Fuer connect_stock_sync_log (siehe SyncRunner::run()) --
                // diese Klasse schreibt den Log selbst nicht (kennt weder
                // Job- noch Lauf-Protokollierung, siehe Klassenkommentar),
                // liefert die Rohdaten dafuer nur im Ergebnis-Array zurueck.
                $label = ($destTitleField !== null ? ($destRecord[$destTitleField->key] ?? null) : null)
                    ?? ($sourceTitleField !== null ? ($sourceRecord[$sourceTitleField->key] ?? null) : null);
                $adjustments[] = [
                    'match_value' => (string) $matchValue,
                    'label' => $label !== null && $label !== '' ? (string) $label : null,
                    'direction' => $direction,
                    'old_quantity' => $currentDestQuantity,
                    'new_quantity' => $sourceQuantity,
                ];
            } else {
                $failed++;
                $errorMessages[] = "\"{$matchValue}\": {$writeResult->message}";
            }
        }

        // Sets und Bundles (Connect\Sync\BundleService, Migration 0091): Shopify-Bestand eines Sets =
        // Minimum ueber die Kassenbestaende der Einzelteile. Einzelteile aus dem Zwischenspeicher
        // (bei helloCash in jedem Lauf frisch ueber die v1-Zusatzliste), das Set selbst wird vor dem
        // Schreiben live kontrolliert (changeFromQuantity).
        $bundleNotes = [];
        $bundleService = new BundleService($this->pdo);
        if ($destIsShopify && $bundleService->activeFor($shop, $direction)) {
            $definitions = $bundleService->definitions($shop);
            $targetQuantities = [];
            foreach ($sourceRecords as $record) {
                $value = (string) ($record[$sourceMatchField->key] ?? '');
                if ($value !== '' && !isset($sourceDuplicates[$value])) {
                    $targetQuantities[$value] = array_key_exists($sourceStockField->key, $record) && $record[$sourceStockField->key] !== null ? (int) $record[$sourceStockField->key] : null;
                }
            }
            foreach ($definitions as $bundleMatch => $definition) {
                $destRecord = $destIndex[$bundleMatch] ?? null;
                if ($destRecord === null || isset($destDuplicates[$bundleMatch])) {
                    $bundleNotes[] = "Set \"{$bundleMatch}\" in Shopify nicht (eindeutig) gefunden.";
                    continue;
                }
                $available = BundleService::availableSets($definition['components'], $targetQuantities);
                if ($available === null) {
                    $bundleNotes[] = "Set \"{$bundleMatch}\": Bestand eines Einzelteils unbekannt – übersprungen.";
                    continue;
                }
                $desired = ShopStockRules::shopifyQuantity($available, $safetyBuffer, $direction);
                $current = array_key_exists($destStockField->key, $destRecord) && $destRecord[$destStockField->key] !== null ? (int) $destRecord[$destStockField->key] : null;
                if ($current === $desired) {
                    continue;
                }
                if ($liveChecks >= self::MAX_LIVE_CHECKS_PER_RUN) {
                    $deferred++;
                    continue;
                }
                $liveChecks++;
                try {
                    $liveDest = $this->liveRecord($objectCache, $destConnector, $destConnectionId, $destObject, $destRecord);
                } catch (\Throwable $e) {
                    $failed++;
                    $errorMessages[] = "Set \"{$bundleMatch}\": Live-Kontrolle fehlgeschlagen ({$e->getMessage()}).";
                    continue;
                }
                if ($liveDest === null || !array_key_exists($destStockField->key, $liveDest) || $liveDest[$destStockField->key] === null) {
                    continue;
                }
                $current = (int) $liveDest[$destStockField->key];
                if ($current === $desired) {
                    $liveCorrected++;
                    continue;
                }
                $inventoryItemId = $liveDest['inventory_item_id'] ?? null;
                if ($inventoryItemId === null || $inventoryItemId === '' || $locationId === null || $locationId === '') {
                    $failed++;
                    $errorMessages[] = "Set \"{$bundleMatch}\": ohne inventory_item_id oder ohne primären Standort.";
                    continue;
                }
                $writeResult = $destConnector->write($destObject->key, 'update', [
                    'inventoryItemId' => $inventoryItemId,
                    'locationId' => $locationId,
                    'quantity' => $desired,
                    'changeFromQuantity' => $current,
                ]);
                ActivityLog::log(
                    $this->pdo, $shop, 'shopify', $destObject->key, 'stock_update', $writeResult->success,
                    $writeResult->success ? "Set \"{$bundleMatch}\": {$current} -> {$desired} (aus den Einzelteilen)." : $writeResult->message,
                    (string) ($liveDest[$destIdentifierField->key] ?? '') ?: null, $writeResult->requestBody, $writeResult->responseBody, $runId
                );
                if ($writeResult->success) {
                    $written++;
                    $objectCache->updateCachedFields($destConnectionId, $destObject->key, (string) ($liveDest[$destIdentifierField->key] ?? ''), [$destStockField->key => $desired]);
                    $adjustments[] = [
                        'match_value' => (string) $bundleMatch,
                        'label' => $definition['label'] ?? ($destTitleField !== null ? ($liveDest[$destTitleField->key] ?? null) : null),
                        'direction' => $direction,
                        'old_quantity' => $current,
                        'new_quantity' => $desired,
                    ];
                } else {
                    $failed++;
                    $errorMessages[] = "Set \"{$bundleMatch}\": {$writeResult->message}";
                }
            }
        }

        $status = $failed > 0 ? ($written > 0 ? 'partial' : 'error') : 'success';
        $message = "{$matched} Datensaetze gematcht, {$written} geschrieben, {$failed} fehlgeschlagen.";
        if ($bundleNotes !== []) {
            $message .= ' Sets: ' . implode(' ', array_slice($bundleNotes, 0, 5));
        }
        if ($safetyBuffer > 0 && $direction === 'target_to_shopify') {
            $message .= " Sicherheitsbestand {$safetyBuffer}: Shopify zeigt den Kassenbestand minus {$safetyBuffer}.";
        }
        if ($liveCorrected > 0) {
            $message .= " {$liveCorrected} nicht geschrieben, weil die Live-Kontrolle beide Seiten gleich zeigte (Zwischenspeicher war veraltet).";
        }
        if ($staleLocation > 0) {
            $message .= " {$staleLocation} Shopify-Produkte nicht abgeglichen: noch nicht mit dem gewählten Standort geladen (folgt nach dem nächsten Abruf).";
        }
        if ($deferred > 0) {
            $message .= " {$deferred} weitere Abweichungen folgen im nächsten Lauf (max. " . self::MAX_LIVE_CHECKS_PER_RUN . ' je Lauf).';
        }
        if ($skippedDuplicates > 0) {
            $message .= " {$skippedDuplicates} wegen mehrfach vorkommendem Vergleichswert uebersprungen (siehe Produkte-Seite, Probleme).";
        }
        if (count($errorMessages) > 0) {
            $message .= ' Fehler: ' . implode(' | ', array_slice($errorMessages, 0, 10));
        }

        return self::result($status, $matched, $written, $failed, $message, $adjustments);
    }

    /**
     * Ueberblick fuer die Sicherheitsbremse aus dem Zwischenspeicher, ueber ALLE Paare:
     * Groesse der Quelle, Anteil Nullen darin, Zahl der Paare und wie viele davon von
     * Bestand > 0 auf 0 fielen (mit Beispielen fuer die Mail).
     *
     * @param array<int, array<string, mixed>> $sourceRecords
     * @param array<string, array<string, mixed>> $destIndex
     * @param array<string, mixed> $sourceDuplicates
     * @param array<string, mixed> $destDuplicates
     * @return array{source_count: int, zero_share: ?float, pairs: int, to_zero: int, examples: list<string>}
     */
    private function planSummary(array $sourceRecords, array $destIndex, string $matchKey, string $sourceStockKey, string $destStockKey, array $sourceDuplicates, array $destDuplicates, ?string $titleKey): array
    {
        $known = 0;
        $zeros = 0;
        $pairs = 0;
        $toZero = 0;
        $examples = [];

        foreach ($sourceRecords as $record) {
            $quantity = $record[$sourceStockKey] ?? null;
            if ($quantity !== null && $quantity !== '') {
                $known++;
                if ((int) $quantity <= 0) {
                    $zeros++;
                }
            }

            $matchValue = (string) ($record[$matchKey] ?? '');
            if ($matchValue === '' || isset($sourceDuplicates[$matchValue]) || isset($destDuplicates[$matchValue]) || !isset($destIndex[$matchValue])) {
                continue;
            }
            $pairs++;
            $destQuantity = $destIndex[$matchValue][$destStockKey] ?? null;
            if ($quantity !== null && $quantity !== '' && $destQuantity !== null && (int) $destQuantity > 0 && (int) $quantity <= 0) {
                $toZero++;
                if (count($examples) < 10) {
                    $label = $titleKey !== null ? (string) ($record[$titleKey] ?? '') : '';
                    $examples[] = ($label !== '' ? $label . ' ' : '') . "({$matchValue}): " . (int) $destQuantity . ' -> ' . (int) $quantity;
                }
            }
        }

        return [
            'source_count' => count($sourceRecords),
            'zero_share' => $known > 0 ? round($zeros / $known * 100, 1) : null,
            'pairs' => $pairs,
            'to_zero' => $toZero,
            'examples' => $examples,
        ];
    }

    /**
     * Einen Datensatz live nachladen (aktualisiert dabei den Cache) und frisch zurueckgeben.
     * Shopify erwartet die Kennung als GraphQL-Variable "id", REST-Systeme wie HelloCash als
     * Platzhalter mit dem Namen des Kennungsfelds -- beides wird mitgegeben.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>|null null = im System nicht mehr gefunden
     */
    private function liveRecord(ObjectCache $cache, ConnectorInterface $connector, int $connectionId, ObjectDefinition $object, array $record): ?array
    {
        $identifierKey = ObjectCache::requireIdentifierField($object)->key;
        $externalId = (string) ($record[$identifierKey] ?? '');
        if ($externalId === '') {
            throw new \RuntimeException('Datensatz ohne Kennung');
        }

        // Ohne Einzelabfrage (z.B. MGM "Artikel-Lagerstand": nur Lesen (Liste), alle Artikel
        // in EINER Antwort) die ganze Liste einmal pro Lauf frisch laden statt je Artikel.
        if ($connector instanceof GenericHttpConnector && !$connector->hasOperation($object->key, 'read_one')) {
            $runKey = $connectionId . ':' . $object->key;
            if (!isset($this->listRefreshedThisRun[$runKey])) {
                try {
                    $cache->refresh($connector, $connectionId, $object);
                    $this->listRefreshedThisRun[$runKey] = true;
                } catch (\Throwable $e) {
                    // Fehler merken: nicht fuer jeden weiteren Artikel erneut abrufen, aber auch
                    // nie mit dem alten Zwischenspeicher weiterschreiben
                    $this->listRefreshedThisRun[$runKey] = 'Liste nicht abrufbar: ' . $e->getMessage();
                }
            }
            if ($this->listRefreshedThisRun[$runKey] !== true) {
                throw new \RuntimeException((string) $this->listRefreshedThisRun[$runKey]);
            }
            $fresh = $cache->getCachedOne($connectionId, $object->key, $externalId);

            return $fresh !== null && ($fresh['_status'] ?? 'active') !== 'orphaned' ? $fresh : null;
        }

        if (!$cache->refreshOne($connector, $connectionId, $object, [$identifierKey => $externalId, 'id' => $externalId])) {
            return null;
        }

        return $cache->getCachedOne($connectionId, $object->key, $externalId);
    }

    /**
     * @param array<int, array{match_value: string, label: ?string, direction: string, old_quantity: ?int, new_quantity: int}> $adjustments
     * @return array{status: string, matched: int, written: int, failed: int, message: string, adjustments: array<int, array{match_value: string, label: ?string, direction: string, old_quantity: ?int, new_quantity: int}>}
     */
    private static function result(string $status, int $matched, int $written, int $failed, string $message, array $adjustments): array
    {
        return ['status' => $status, 'matched' => $matched, 'written' => $written, 'failed' => $failed, 'message' => $message, 'adjustments' => $adjustments];
    }
}
