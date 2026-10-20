<?php

declare(strict_types=1);

namespace Connect\Sync;

use Connect\Connector\ConnectorFactory;
use Connect\Connector\Generic\GenericHttpConnector;
use Connect\Mapping\MappingEngine;
use Connect\Sync\Document\DocumentPipeline;
use PDO;

/**
 * Fuehrt EIN Sync Mapping aus und protokolliert das Ergebnis in
 * connect_sync_runs. Zwei grundverschiedene Betriebsarten, unterschieden
 * anhand von target_connection_id:
 *
 *   - NULL (Ausbaustufe 1): "diese Connection+Object regelmaessig im
 *     Hintergrund-Cache aktualisieren" -- ruft ObjectCache::refresh()
 *     unbegrenzt auf, inkl. Verwaisten-Erkennung. Kein Schreiben, kein
 *     Zielsystem.
 *   - gesetzt (Mapping Engine, siehe Plan "Mapping Engine v1"): uebersetzt
 *     Datensaetze via Connect\Mapping\MappingEngine und schreibt sie ins
 *     Zielsystem (aktuell immer operationType='create', siehe Plan
 *     "Nicht Teil von v1" -- kein Update-Fall). Zwei Ausloeser rufen
 *     denselben Kern (translateAndWrite()) auf:
 *       - runTranslationForRecord(): EIN Datensatz, webhook-getriggert
 *         (siehe webhooks/order_paid.php), sofort nach Eintreffen.
 *       - run(): Sicherheitsnetz fuer den Scheduler -- prueft die zuletzt
 *         gecachten Quell-Datensaetze (connect_object_records) auf noch
 *         nicht erfolgreich uebersetzte und holt sie nach (faengt verpasste/
 *         fehlgeschlagene Webhook-Zustellungen ab).
 *
 * Dedup gegen doppeltes Schreiben (z.B. Shopify liefert Webhooks "at least
 * once", oder der Nachlauf findet einen Datensatz, den der Webhook bereits
 * erledigt hat) laeuft ueber connect_translation_log (siehe translateAndWrite()).
 *
 * Sammelt zusaetzlich ein Zeilen-Log (Meilensteine + im Fehlerfall der volle
 * Stacktrace, nicht nur die Fehlermeldung) und speichert es UNGEKUERZT in
 * connect_sync_runs.log -- error_summary bleibt die kurze, auf 2000 Zeichen
 * gekuerzte Übersicht fuer Listenansichten (siehe admin/jobs.php), log ist
 * fuer die Detailansicht (admin/job_log.php) gedacht.
 */
final class SyncRunner
{
    /**
     * Sicherheitsgrenze fuer den Scheduler-Nachlauf (run() mit gesetztem
     * target_connection_id) -- gleiches Prinzip wie MAX_PAGES im Connector:
     * verhindert, dass ein einzelner Lauf unbegrenzt lang laeuft, kein
     * Anspruch auf "alles in einem Rutsch" (der naechste Scheduler-Tick holt
     * den Rest nach).
     */
    private const MAX_BATCH_TRANSLATIONS = 50;

    /** Nachlauf schaut hoechstens so weit zurueck (wie die alte Welt: nur "heute", hier 48 h). */
    private const BACKFILL_MAX_HOURS = 48;

    /**
     * Default-Stapelgroesse fuer das Detail-Nachladen in run() (siehe
     * ObjectCache::hydrateDetails()), falls am Object keine eigene
     * hydrate_batch_size gesetzt ist -- ein read_one-Aufruf pro Datensatz
     * und Lauf waere bei Katalogen mit tausenden Datensaetzen (z.B.
     * HelloCash-Artikel-Lagerbestand) zu langsam/riskant fuer Rate-Limits.
     * Bei diesem Wert braucht ein 5000er-Katalog ca. 25 Laeufe fuer einen
     * vollstaendigen Umlauf -- bei stuendlichem Intervall also gut einen
     * Tag, was fuer Lagerbestand ausreichend aktuell ist.
     */
    private const DEFAULT_HYDRATE_BATCH_SIZE = 200;

    /** @var string[] */
    private array $logLines = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ConnectorFactory $connectorFactory
    ) {
    }

    /**
     * @param array<string, mixed> $mapping Zeile aus connect_sync_mappings
     */
    public function run(array $mapping, ?int $jobId = null): void
    {
        $this->logLines = [];
        self::resetSourceIps();
        $runId = $this->startRun((int) $mapping['id'], $jobId);
        $startedAt = microtime(true);

        try {
            // Schreibende Jobs (Lagerbestand, automatischer Produktimport, Nachlauf von
            // Uebersetzungen) nur fuer Shops, die in Connect laufen (live_in_connect = 1).
            // Sonst schrieben beim Umzug -- Einstellungen und Aktivierungen schon
            // uebernommen, Shop aber noch nicht umgeschaltet -- beide Welten parallel.
            // Reine Lese-Jobs (Zwischenspeicher fuellen) laufen weiter, damit beim
            // Umschalten schon alle Daten da sind.
            $kind = (string) ($mapping['mapping_kind'] ?? 'default');
            $writes = in_array($kind, ['stock_sync', 'stock_back', 'auto_import_products', 'invoice', 'product_visibility'], true) || $mapping['target_connection_id'] !== null;
            if ($writes && !$this->shopIsLive((string) $mapping['shop'])) {
                $this->log("Übersprungen: Shop \"{$mapping['shop']}\" läuft noch in der alten Welt (live_in_connect = 0) -- schreibende Jobs erst nach dem Umschalten.");
                $this->finishRun($runId, 'success', 0, 0, 0, null);

                return;
            }

            // mapping_kind='stock_sync' (Migration 0028): eigene Ausfuehrungs-
            // logik (Connect\Sync\StockSyncRunner) statt Cache-Refresh oder
            // Uebersetzen -- laeuft aber ganz normal ueber diese Zeile in
            // connect_sync_mappings/connect_sync_jobs/connect_sync_runs, wie
            // jeder andere Job auch (Nutzer-Wunsch: eine Job-Tabelle/History
            // statt einer eigenen fuer den Lagerbestand-Abgleich).
            if (($mapping['mapping_kind'] ?? 'default') === 'stock_sync') {
                $this->log("Lagerbestand-Abgleich gestartet für Shop \"{$mapping['shop']}\" (Job " . ($jobId ?? '–') . ')');

                try {
                    $result = (new StockSyncRunner($this->pdo, $this->connectorFactory))->runForShop((string) $mapping['shop'], $runId);
                } catch (\Throwable $e) {
                    // Ausfall zaehlen (3 in Folge = Mail an den Admin), dann wie bisher als Fehler-Lauf
                    (new StockGuard($this->pdo))->recordOutcome((string) $mapping['shop'], 'error', $e->getMessage());
                    throw $e;
                }
                (new StockGuard($this->pdo))->recordOutcome((string) $mapping['shop'], $result['status'], $result['message']);
                $duration = round(microtime(true) - $startedAt, 1);
                $this->log("Fertig in {$duration}s ({$result['status']}): {$result['message']}");

                if (count($result['adjustments']) > 0) {
                    $this->logStockAdjustments((string) $mapping['shop'], $runId, $result['adjustments']);
                }

                // "skipped" ist bei connect_sync_runs.status kein eigener Wert
                // (running|success|partial|error) -- ein uebersprungener Zyklus
                // (z.B. noch keine Daten auf beiden Seiten) ist kein Fehler,
                // deshalb als success gezaehlt, der Skip-Grund steht im Log.
                $status = $result['status'] === 'skipped' ? 'success' : $result['status'];
                $this->finishRun($runId, $status, $result['matched'], $result['written'], $result['failed'], $status === 'error' ? $result['message'] : null);

                return;
            }

            // mapping_kind='stock_back' (Migration 0104): Kassenverkaeufe in Shopify zurueckbuchen
            // (und optional Dummy-Bestellung), siehe StockBackRunner.
            if ($kind === 'stock_back') {
                $this->log("Rückbuchung bei Kassenverkauf gestartet für Shop \"{$mapping['shop']}\" (Job " . ($jobId ?? '–') . ')');

                $result = (new StockBackRunner($this->pdo, $this->connectorFactory))->runForShop((string) $mapping['shop'], $runId);
                $duration = round(microtime(true) - $startedAt, 1);
                $this->log("Fertig in {$duration}s ({$result['status']}): {$result['message']}");

                if (count($result['adjustments']) > 0) {
                    $this->logStockAdjustments((string) $mapping['shop'], $runId, $result['adjustments']);
                }

                $status = $result['status'] === 'skipped' ? 'success' : $result['status'];
                $this->finishRun($runId, $status, $result['matched'], $result['written'], $result['failed'], $status === 'error' ? $result['message'] : null);

                return;
            }

            // mapping_kind='product_visibility' (Migration 0073): ausverkaufte
            // Produkte ausblenden / wieder einblenden, siehe ProductVisibilityRunner.
            if ($kind === 'product_visibility') {
                $this->log("Ausblenden/Einblenden gestartet für Shop \"{$mapping['shop']}\" (Job " . ($jobId ?? '–') . ')');

                $result = (new ProductVisibilityRunner($this->pdo, $this->connectorFactory))->runForShop((string) $mapping['shop'], $runId);
                $duration = round(microtime(true) - $startedAt, 1);
                $this->log("Fertig in {$duration}s ({$result['status']}): {$result['message']}");

                $status = $result['status'] === 'skipped' ? 'success' : $result['status'];
                $this->finishRun($runId, $status, $result['matched'], $result['written'], $result['failed'], $status === 'error' ? $result['message'] : null);

                return;
            }

            // mapping_kind='auto_import_products' (Migration 0059): admin-only
            // automatischer Import fehlender Produkte, siehe
            // Connect\Sync\AutoProductImportRunner -- laeuft, wie stock_sync,
            // eigenstaendig statt ueber die generische Mapping Engine, ABER
            // (anders als stock_sync) MIT gesetztem target_connection_id, daher
            // VOR dem naechsten Zweig geprueft.
            if (($mapping['mapping_kind'] ?? 'default') === 'auto_import_products') {
                $this->log("Automatischer Produktimport gestartet für Shop \"{$mapping['shop']}\" (Job " . ($jobId ?? '–') . ')');

                $result = (new AutoProductImportRunner($this->pdo, $this->connectorFactory))->runForShop((string) $mapping['shop']);
                $duration = round(microtime(true) - $startedAt, 1);
                $this->log("Fertig in {$duration}s ({$result['status']}): {$result['message']}");

                $status = $result['status'] === 'skipped' ? 'success' : $result['status'];
                $this->finishRun($runId, $status, $result['matched'], $result['written'], $result['failed'], $status === 'error' ? $result['message'] : null);

                return;
            }

            if ($mapping['target_connection_id'] !== null) {
                [$read, $written, $failed] = $this->runScheduledTranslationBatch($mapping, $runId);
                $duration = round(microtime(true) - $startedAt, 1);
                $this->log("Fertig: {$written} von {$read} geprüften Datensätzen übersetzt, {$failed} fehlgeschlagen, in {$duration}s.");
                $this->finishRun($runId, $failed > 0 ? 'partial' : 'success', $read, $written, $failed, null);

                return;
            }

            $this->log("Sync gestartet für Mapping \"{$mapping['name']}\" (Job " . ($jobId ?? '–') . ')');

            $connectionId = (int) $mapping['source_connection_id'];
            $this->log("Baue Connector für Connection #{$connectionId} auf ...");
            $connector = $this->connectorFactory->forConnection($connectionId);
            $object = $this->connectorFactory->objectDefinitionById((int) $mapping['source_object_id']);
            $this->log("Object \"{$object->key}\" aufgelöst, starte Cache-Refresh (unbegrenzt) ...");

            $cache = new ObjectCache($this->pdo);
            $refreshResult = $cache->refresh($connector, $connectionId, $object, [], null);
            $count = $refreshResult['processed'];
            $this->log("{$refreshResult['orphaned']} Datensätze als verwaist markiert (in Shopify/Quelle nicht mehr gefunden).");

            // Zusatzliste (Operationstyp "read_list_supplement", z.B. helloCash-Bestand aus der
            // v1-Liste): direkt NACH refresh(), das die Datensaetze komplett ueberschreibt. Scheitert
            // sie, bleibt der Lauf erfolgreich -- die betroffenen Felder sind dann leer (Lagerabgleich
            // ueberspringt "Bestand unbekannt"), Fehler steht im Protokoll.
            $supplementFailed = false;
            if ($connector instanceof \Connect\Connector\Generic\GenericHttpConnector && $connector->supplementConfig($object->key) !== null) {
                try {
                    $merge = $cache->mergeSupplement($connector, $connectionId, $object);
                    $this->log("Zusatzliste: {$merge['rows']} Zeilen gelesen, {$merge['matched']} Datensätzen zugeordnet, {$merge['updated']} aktualisiert.");
                    if ($merge['rows'] > 0 && $merge['matched'] === 0) {
                        $this->log('Achtung: keine Zeile der Zusatzliste passt zu einem Datensatz – Kennung ("match" in _merge) prüfen.');
                        $supplementFailed = true;
                    }
                    // Feldschluessel aus _merge, die es am Object nicht gibt (Tippfehler, Beispielwert
                    // uebernommen) -- die Werte landeten sonst in einem Feld, das niemand liest.
                    $knownKeys = array_map(static fn ($f): string => $f->key, $object->fields);
                    $unknown = array_diff(array_keys((array) $connector->supplementConfig($object->key)['fields']), $knownKeys);
                    if ($unknown !== []) {
                        $this->log('Achtung: Feld(er) ' . implode(', ', $unknown) . ' aus _merge gibt es am Object "' . $object->key . '" nicht – vorhandene Felder: ' . implode(', ', $knownKeys) . '.');
                        $supplementFailed = true;
                    }
                } catch (\Throwable $e) {
                    $this->log('FEHLER Zusatzliste: ' . $e->getMessage());
                    $supplementFailed = true;
                }
            }

            // Detail-Nachladen (z.B. HelloCash "stocks", siehe
            // ObjectCache::hydrateDetails()) nur, wenn am Object explizit
            // eingeschaltet (connect_objects.hydrate_details_after_list,
            // editierbar im Connector Builder -- object_edit.php) -- NICHT
            // automatisch nur weil eine read_one-Operation existiert, die
            // z.B. auch rein fuer Webhook-Einzel-Updates da sein kann, ohne
            // dass ein kompletter Katalog-Umlauf pro Sync gewuenscht ist.
            if ($object->hydrateDetailsAfterList) {
                $jobCursorState = null;
                if ($jobId !== null) {
                    $jobStmt = $this->pdo->prepare('SELECT cursor_state FROM connect_sync_jobs WHERE id = :id');
                    $jobStmt->execute(['id' => $jobId]);
                    $jobCursorState = $jobStmt->fetchColumn();
                    $jobCursorState = $jobCursorState !== false ? $jobCursorState : null;
                }

                $batchSize = $object->hydrateBatchSize ?? self::DEFAULT_HYDRATE_BATCH_SIZE;
                $hydrateResult = $cache->hydrateDetails($connector, $connectionId, $object, $jobCursorState, $batchSize);
                $this->log("Details nachgeladen: {$hydrateResult['hydrated']} Datensätze" . ($hydrateResult['cursorState'] === null ? ' (Katalog-Umlauf komplett, nächster Lauf beginnt wieder von vorne).' : ' (weiter beim nächsten Lauf).'));

                if ($jobId !== null) {
                    $cursorUpdateStmt = $this->pdo->prepare('UPDATE connect_sync_jobs SET cursor_state = :cursor_state WHERE id = :id');
                    $cursorUpdateStmt->execute(['cursor_state' => $hydrateResult['cursorState'], 'id' => $jobId]);
                }
            }

            $duration = round(microtime(true) - $startedAt, 1);
            $this->log("Fertig: {$count} Datensätze verarbeitet in {$duration}s.");

            $this->finishRun($runId, $supplementFailed ? 'partial' : 'success', $count, $count, 0, $supplementFailed ? 'Zusatzliste fehlgeschlagen, siehe Protokoll' : null);
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $startedAt, 1);
            $this->log("FEHLER nach {$duration}s: " . $e->getMessage());
            $this->log('Stacktrace: ' . $e->getTraceAsString());

            $this->finishRun($runId, 'error', 0, 0, 0, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Uebersetzt GENAU EINEN bereits gelesenen Quell-Datensatz sofort --
     * fuer den Webhook-Pfad (siehe webhooks/order_paid.php), der die volle
     * Bestellung per readOne() bereits geholt hat, statt auf den naechsten
     * Scheduler-Durchlauf zu warten. Auch fuer gezielte manuelle Einzel-
     * Ausfuehrungen genutzt (admin/mapping_edit.php "Einmal wirklich
     * ausfuehren", Connect\Sync\ProductImportService fuer den "Importieren"-
     * Button auf der Merchant-Seite "Produkte").
     *
     * Gibt seit Kurzem das Ergebnis zurueck (vorher void) -- bestehende
     * Aufrufer, die es ignorieren, sind davon unberuehrt. ProductImportService
     * braucht es, um dem Merchant eine direkte Erfolg/Fehler-Rueckmeldung zu
     * zeigen statt (wie im Admin-Bereich ueblich) auf die Job-Historie zu
     * verweisen, auf die Merchants keinen Zugriff haben.
     *
     * @param array<string, mixed> $mapping Zeile aus connect_sync_mappings
     * @param array<string, mixed> $sourceRow Bereits gelesener Quell-Datensatz
     * @return array{success: bool, skipped: bool, targetExternalId: ?string, message: string}
     */
    public function runTranslationForRecord(array $mapping, array $sourceRow, ?int $jobId = null): array
    {
        $this->logLines = [];
        self::resetSourceIps();
        $runId = $this->startRun((int) $mapping['id'], $jobId);
        $startedAt = microtime(true);

        $this->log("Übersetzung gestartet für Mapping \"{$mapping['name']}\" (Webhook-getriggert, Job " . ($jobId ?? '–') . ')');

        try {
            $result = $this->translateAndWrite($mapping, $sourceRow, $runId);
            $duration = round(microtime(true) - $startedAt, 1);

            if ($result['skipped']) {
                $this->log("Übersprungen nach {$duration}s: {$result['message']}");
                $this->finishRun($runId, 'success', 1, 0, 0, null);
            } elseif ($result['success']) {
                $suffix = $result['targetExternalId'] !== null ? " (Ziel-ID: {$result['targetExternalId']})" : '';
                $this->log("Fertig in {$duration}s: {$result['message']}{$suffix}");
                $this->finishRun($runId, 'success', 1, 1, 0, null);
            } else {
                $this->log("FEHLER nach {$duration}s: {$result['message']}");
                $this->finishRun($runId, 'error', 1, 0, 1, $result['message']);
            }

            return $result;
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $startedAt, 1);
            $this->log("FEHLER nach {$duration}s: " . $e->getMessage());
            $this->log('Stacktrace: ' . $e->getTraceAsString());

            $this->finishRun($runId, 'error', 1, 0, 1, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Sicherheitsnetz: prueft die zuletzt gecachten Quell-Datensaetze auf
     * noch nicht erfolgreich uebersetzte und holt sie nach. Setzt voraus,
     * dass eine ANDERE (reine Cache-)Sync Mapping denselben Source-
     * Connection+Object regelmaessig cached -- siehe Plan, "Umsetzungs-
     * reihenfolge": eine Mapping-Regel mit Ziel braucht i.d.R. eine zweite,
     * reine Cache-Mapping fuer dasselbe Object daneben.
     *
     * @param array<string, mixed> $mapping
     * @return array{0: int, 1: int, 2: int} geprüft, geschrieben, fehlgeschlagen
     */
    private function runScheduledTranslationBatch(array $mapping, ?int $runId = null): array
    {
        $sourceObject = $this->connectorFactory->objectDefinitionById((int) $mapping['source_object_id']);

        $this->log("Übersetzungs-Nachlauf gestartet für Mapping \"{$mapping['name']}\" (Sicherheitsnetz, max " . self::MAX_BATCH_TRANSLATIONS . ' pro Lauf) ...');

        // Nur Datensaetze ab diesem Zeitpunkt (Schutz vor doppelten Belegen): sonst wuerde der
        // Nachlauf z.B. nach einem Umzug Bestellungen abrechnen, die die alte Welt schon
        // abgerechnet hat (Connect kennt sie nicht). Siehe backfillSince().
        $since = $this->backfillSince($mapping);
        $this->log('Nur Datensätze ab ' . gmdate('Y-m-d H:i', $since) . ' UTC (Einschalten/Umschalten, max. ' . self::BACKFILL_MAX_HOURS . ' h zurück).');

        // Zeitfenster frisch von der Quelle holen (z.B. Shopify-Bestellungen seit $since), damit
        // der Nachlauf nicht von einem eigenen Bestell-Datenjob abhaengt. Nur wenn das Object
        // einen Zeitfilter kennt (Migration 0051, z.B. orderQuery "created_at:>={date}").
        try {
            $filter = $this->pdo->prepare('SELECT age_filter_param, age_filter_format FROM connect_objects WHERE id = :id');
            $filter->execute(['id' => (int) $mapping['source_object_id']]);
            $filterRow = $filter->fetch();
            if ($filterRow !== false && $filterRow['age_filter_param'] !== null && $filterRow['age_filter_format'] !== null) {
                $params = [(string) $filterRow['age_filter_param'] => str_replace('{date}', gmdate('Y-m-d', $since), (string) $filterRow['age_filter_format'])];
                $connector = $this->connectorFactory->forConnection((int) $mapping['source_connection_id']);
                $fresh = (new ObjectCache($this->pdo))->refresh($connector, (int) $mapping['source_connection_id'], $sourceObject, $params, 250, false);
                $this->log("{$fresh['processed']} Datensätze frisch von der Quelle geladen.");
            }
        } catch (\Throwable $e) {
            $this->log('Frisches Laden nicht möglich, arbeite mit dem Zwischenspeicher: ' . $e->getMessage());
        }

        $stmt = $this->pdo->prepare(
            "SELECT data FROM connect_object_records
             WHERE connection_id = :connection_id AND object_key = :object_key AND status = 'active'
             ORDER BY id DESC LIMIT " . self::MAX_BATCH_TRANSLATIONS
        );
        $stmt->execute([
            'connection_id' => (int) $mapping['source_connection_id'],
            'object_key' => $sourceObject->key,
        ]);

        $checked = 0;
        $written = 0;
        $failed = 0;

        foreach ($stmt->fetchAll() as $cachedRecord) {
            $sourceRow = json_decode((string) $cachedRecord['data'], true);
            if (!is_array($sourceRow)) {
                continue;
            }

            // V1-Kurzschluss (siehe Plan "Nicht Teil von v1"): nur
            // Datensaetze uebersetzen, die -- falls das Feld ueberhaupt
            // existiert -- als bezahlt markiert sind. Verhindert, dass der
            // Nachlauf z.B. unbezahlte Bestellungen faelschlich in
            // Auftraege verwandelt. Wird bei "verallgemeinern" durch ein
            // konfigurierbares Filterkriterium (connect_sync_mappings.settings)
            // ersetzt.
            // Shopify liefert displayFinancialStatus in Grossbuchstaben ("PAID") -- vorher wurde
            // gegen "paid" verglichen und dadurch jede Bestellung uebersprungen.
            if (array_key_exists('financial_status', $sourceRow)
                && !in_array(strtolower((string) $sourceRow['financial_status']), ['paid', 'partially_refunded'], true)
            ) {
                continue;
            }

            // Zeitgrenze (siehe oben): ohne verwertbares Datum lieber auslassen als doppelt abrechnen
            $createdAt = isset($sourceRow['created_at']) ? strtotime((string) $sourceRow['created_at']) : false;
            if ($createdAt === false || $createdAt < $since) {
                continue;
            }

            $checked++;
            $result = $this->translateAndWrite($mapping, $sourceRow, $runId);

            if ($result['skipped']) {
                continue;
            }

            if ($result['success']) {
                $written++;
            } else {
                $failed++;
                $this->log('Fehlgeschlagen: ' . $result['message']);
            }
        }

        return [$checked, $written, $failed];
    }

    /**
     * Gemeinsamer Kern fuer beide Ausloeser (Webhook-Einzeldatensatz und
     * Scheduler-Nachlauf): uebersetzt EINEN Quell-Datensatz per MappingEngine
     * (oder, bei mapping_kind='invoice', per InvoiceService) und schreibt ihn
     * ins Zielsystem. Dedup gegen connect_translation_log ist ATOMAR (siehe
     * claimTranslation() unten) -- ein reines "vorher SELECT, nachher INSERT"
     * ist eine Race-Bedingung: zwei nahezu gleichzeitige Aufrufe (Shopifys
     * "at-least-once"-Zustellgarantie, oder zwei parallel registrierte Webhook-
     * Abos fuer dieselbe Adresse -- beides live beobachtet: derselbe Auftrag
     * erzeugte zwei echte HelloCash-Rechnungen) koennnten sonst BEIDE die
     * Pruefung bestehen, bevor einer von beiden fertig geschrieben hat.
     *
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $sourceRow
     * @return array{success: bool, skipped: bool, targetExternalId: ?string, message: string}
     */
    private function translateAndWrite(array $mapping, array $sourceRow, ?int $runId = null): array
    {
        $sourceObject = $this->connectorFactory->objectDefinitionById((int) $mapping['source_object_id']);
        $identifierField = ObjectCache::requireIdentifierField($sourceObject);
        $sourceExternalId = (string) ($sourceRow[$identifierField->key] ?? '');

        if ($sourceExternalId === '') {
            return ['success' => false, 'skipped' => false, 'targetExternalId' => null, 'message' => 'Quell-Datensatz hat keine externe ID, kann nicht übersetzt werden.'];
        }

        $claim = $this->claimTranslation((int) $mapping['id'], $sourceExternalId);
        if ($claim !== null) {
            return $claim; // schon erfolgreich uebersetzt, ODER wird gerade von einem anderen Aufruf bearbeitet
        }

        try {
            // Belegerstellung ueber die Oberflaeche (Migration 0067): Vorlage mit
            // Quelle "Bestellung (aufbereitet)" -> Connect\Sync\Document\DocumentPipeline
            // (Aufbereitung, Kunden-Vorschritt, Kontrollen, Belegprofil). Gilt fuer
            // JEDE Aktivierung mit so einer Vorlage (HelloCash-Rechnung, MGM-Auftrag,
            // ...). Ausnahme: Modus "Nur Lagerbestand anpassen" der Rechnungs-
            // Aktivierung bleibt vorerst im InvoiceService (siehe unten).
            $usesDocumentPipeline = $mapping['mapping_template_id'] !== null
                && DocumentPipeline::templateUsesPreparedOrder($this->pdo, (int) $mapping['mapping_template_id']);
            if ($usesDocumentPipeline && !$this->invoiceSuppressedForShop($mapping)) {
                $result = (new DocumentPipeline($this->pdo, $this->connectorFactory))->run($mapping, $sourceRow, $runId);
                $this->resolveTranslationClaim((int) $mapping['id'], $sourceExternalId, $result['success'], $result['targetExternalId'], $result['success'] ? null : $result['message']);

                return $result;
            }

            // mapping_kind='invoice' (Migration 0054) OHNE Belegvorlage: bisherige,
            // fest programmierte HelloCash-Rechnung (Connect\Sync\InvoiceService) --
            // Rueckfallweg, solange die Vorlage noch nicht eingerichtet ist.
            if (($mapping['mapping_kind'] ?? 'default') === 'invoice') {
                $result = (new InvoiceService($this->pdo, $this->connectorFactory))->createForOrder((string) $mapping['shop'], $sourceRow, $runId);
                $this->resolveTranslationClaim((int) $mapping['id'], $sourceExternalId, $result['success'], $result['targetExternalId'], $result['success'] ? null : $result['message']);

                return $result;
            }

            if ($mapping['mapping_template_id'] === null) {
                // Vorlage wurde geloescht (ON DELETE SET NULL, siehe Migration
                // 0020) -- ohne Vorlage gibt es keine Feld-Zuordnungen mehr,
                // ein leeres Schreiben waere sinnlos/gefaehrlich (leerer
                // Auftrag im Zielsystem).
                $message = 'Keine Mapping-Vorlage (mehr) zugeordnet -- Aktivierung kann nicht ausgefuehrt werden.';
                $this->resolveTranslationClaim((int) $mapping['id'], $sourceExternalId, false, null, $message);

                return ['success' => false, 'skipped' => false, 'targetExternalId' => null, 'message' => $message];
            }

            $targetObject = $this->connectorFactory->objectDefinitionById((int) $mapping['target_object_id']);
            $targetConnector = $this->connectorFactory->forConnection((int) $mapping['target_connection_id']);

            $sourceFieldsById = $this->connectorFactory->fieldsByIdForObject((int) $mapping['source_object_id']);
            $targetFieldsById = $this->connectorFactory->fieldsByIdForObject((int) $mapping['target_object_id']);

            // Feld-Zuordnungen kommen aus der GLOBALEN Mapping-Vorlage (siehe
            // Plan-Erweiterung "Mapping-Vorlagen"), nicht mehr aus dieser
            // (shop-gebundenen) Aktivierungszeile selbst -- dieselbe Vorlage
            // liefert damit fuer jeden Shop, der sie aktiviert, identische
            // Feld-Zuordnungen.
            $fieldMappingsStmt = $this->pdo->prepare('SELECT * FROM connect_field_mappings WHERE mapping_template_id = :mapping_template_id ORDER BY sort_order, id');
            $fieldMappingsStmt->execute(['mapping_template_id' => (int) $mapping['mapping_template_id']]);
            $fieldMappingRows = $fieldMappingsStmt->fetchAll();

            // Pro-Shop-Konstanten dieser Aktivierung (z.B. HelloCash cashier_id) --
            // siehe MappingEngine::translate()-Dokblock. Anders als die Feld-
            // Zuordnungen oben liegen diese NICHT in der globalen Vorlage, weil sie
            // je Shop unterschiedlich sein koennen (z.B. verschiedene Kassierer-IDs).
            $activationSettings = $mapping['settings'] !== null ? (json_decode((string) $mapping['settings'], true) ?: []) : [];

            $data = (new MappingEngine($this->pdo))->translate($sourceRow, $fieldMappingRows, $sourceFieldsById, $targetFieldsById, $activationSettings);

            $writeResult = $targetConnector->write($targetObject->key, 'create', $data);

            // Volles Protokoll (inkl. rohem Request/Response) zusaetzlich zum Dedup-Eintrag
            // (dort nur die kurze Meldung, siehe connect_translation_log) --
            // admin/activity_log.php fasst beides zusammen.
            ActivityLog::log(
                $this->pdo,
                (string) $mapping['shop'],
                null,
                $targetObject->key,
                'translate',
                $writeResult->success,
                $writeResult->message,
                null,
                $writeResult->requestBody,
                $writeResult->responseBody,
                $runId
            );

            $targetExternalId = null;
            if ($writeResult->success) {
                foreach ($targetObject->fields as $field) {
                    if ($field->isIdentifier) {
                        // responseData ist die rohe Antwort des Zielsystems --
                        // bei XML (z.B. MGM GefallAdd-Response) bereits ueber
                        // XmlDatasetParser::parseFlat() flach, bei JSON (z.B.
                        // HelloCash "article.store": {"data":{"id":...}}) aber
                        // NICHT zwangslaeufig -- deshalb ueber denselben
                        // Punkt-Pfad-Mechanismus wie beim Lesen gesucht
                        // (GenericHttpConnector::extractByPath(), oeffentlich seit
                        // genau diesem Bedarf), nicht mehr per flachem Schluessel-
                        // Zugriff (fand z.B. "data.id" nie, weil "id" verschachtelt
                        // unter "data" liegt).
                        $extracted = GenericHttpConnector::extractByPath($writeResult->responseData, $field->path ?? $field->key);
                        $targetExternalId = $extracted !== null ? (string) $extracted : '';
                        break;
                    }
                }
            }

            $this->resolveTranslationClaim((int) $mapping['id'], $sourceExternalId, $writeResult->success, $targetExternalId !== '' ? $targetExternalId : null, $writeResult->success ? null : $writeResult->message);

            return [
                'success' => $writeResult->success,
                'skipped' => false,
                'targetExternalId' => $targetExternalId !== '' ? $targetExternalId : null,
                'message' => $writeResult->message,
            ];
        } catch (\Throwable $e) {
            // Claim aufloesen (als Fehler, damit ein spaeterer Versuch -- z.B. nach einem
            // Bugfix oder wenn das Zielsystem wieder erreichbar ist -- nicht fuer immer an
            // einer haengengebliebenen "pending"-Zeile scheitert), dann normal weiterwerfen --
            // der Aufrufer (runTranslationForRecord()) protokolliert den Fehler wie gewohnt.
            $this->resolveTranslationClaim((int) $mapping['id'], $sourceExternalId, false, null, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Rechnungs-Aktivierung im Modus "Keine Rechnung, nur Lagerbestand anpassen"
     * (connect_shops.invoice_suppress_and_adjust_stock, Migration 0058)? Der Modus
     * ist noch nicht Teil der Belegerstellung ueber die Oberflaeche und laeuft
     * weiter ueber InvoiceService::createForOrder().
     *
     * @param array<string, mixed> $mapping
     */
    private function invoiceSuppressedForShop(array $mapping): bool
    {
        if (($mapping['mapping_kind'] ?? 'default') !== 'invoice') {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT invoice_suppress_and_adjust_stock FROM connect_shops WHERE shop = :shop');
        $stmt->execute(['shop' => (string) $mapping['shop']]);

        return (int) $stmt->fetchColumn() === 1;
    }

    /**
     * Atomarer Claim auf genau diese (sync_mapping_id, source_external_id)-Kombination:
     * legt bei erstem Aufruf eine "pending"-Zeile an (WIR duerfen weitermachen, null
     * zurueckgegeben) -- oder stellt fest, dass bereits erfolgreich uebersetzt wurde
     * ODER dass ein anderer, nahezu gleichzeitiger Aufruf gerade dabei ist (in beiden
     * Faellen: fertiges Ergebnis zurueckgegeben, der Aufrufer darf NICHT schreiben).
     *
     * Nutzt dieselbe MySQL-Eigenheit wie ein "claim" in einer Warteschlange:
     * INSERT ... ON DUPLICATE KEY UPDATE meldet ueber rowCount() genau 1 bei einem
     * frischen INSERT, 2 bei einer Zeile, deren Wert sich durch das UPDATE tatsaechlich
     * AENDERT, und 0, wenn die Zeile zwar existierte, aber unveraendert blieb. Damit laesst
     * sich in einem einzigen, atomaren Statement unterscheiden: frisch (1) oder ein zuvor
     * FEHLGESCHLAGENER Versuch, den WIR jetzt fuer einen Wiederholungsversuch uebernehmen
     * (2, status='error' -> 'pending') -- gegenueber einem noch laufenden ("pending", 0,
     * unveraendert) oder bereits erfolgreichen ("success", 0, unveraendert) Versuch, den ein
     * ANDERER Aufruf haelt. Bewusst weiterhin wiederholbar nach einem Fehler (wie zuvor "KEIN
     * Unique-Verstoss bei einem erneuten Versuch nach einem Fehler") -- nur "pending" und
     * "success" blockieren.
     *
     * @return array{success: bool, skipped: bool, targetExternalId: ?string, message: string}|null null = Claim gewonnen, Aufrufer darf schreiben
     */
    private function claimTranslation(int $syncMappingId, string $sourceExternalId): ?array
    {
        $claimStmt = $this->pdo->prepare(
            "INSERT INTO connect_translation_log (sync_mapping_id, source_external_id, status)
             VALUES (:sync_mapping_id, :source_external_id, 'pending')
             ON DUPLICATE KEY UPDATE status = IF(status = 'error', 'pending', status)"
        );
        $claimStmt->execute(['sync_mapping_id' => $syncMappingId, 'source_external_id' => $sourceExternalId]);

        if ($claimStmt->rowCount() > 0) {
            return null; // frischer Claim (1) oder Wiederholung eines frueheren Fehlers (2) -- wir duerfen schreiben
        }

        $existingStmt = $this->pdo->prepare(
            'SELECT status, target_external_id FROM connect_translation_log WHERE sync_mapping_id = :sync_mapping_id AND source_external_id = :source_external_id'
        );
        $existingStmt->execute(['sync_mapping_id' => $syncMappingId, 'source_external_id' => $sourceExternalId]);
        $existing = $existingStmt->fetch();

        if (($existing['status'] ?? '') === 'success') {
            return ['success' => true, 'skipped' => true, 'targetExternalId' => $existing['target_external_id'], 'message' => "Bereits übersetzt (Quell-ID {$sourceExternalId} → Ziel-ID {$existing['target_external_id']})."];
        }

        return ['success' => true, 'skipped' => true, 'targetExternalId' => null, 'message' => 'Wird bereits von einem nahezu gleichzeitigen Aufruf verarbeitet -- übersprungen, um doppeltes Schreiben zu vermeiden.'];
    }

    private function resolveTranslationClaim(int $syncMappingId, string $sourceExternalId, bool $success, ?string $targetExternalId, ?string $errorMessage): void
    {
        // Reines UPDATE (kein INSERT mehr noetig): claimTranslation() hat die Zeile fuer
        // diese Kombination bereits garantiert angelegt, bevor irgendein Schreibzugriff lief.
        $stmt = $this->pdo->prepare(
            'UPDATE connect_translation_log
             SET target_external_id = :target_external_id, status = :status, error_message = :error_message, created_at = CURRENT_TIMESTAMP
             WHERE sync_mapping_id = :sync_mapping_id AND source_external_id = :source_external_id'
        );
        $stmt->execute([
            'sync_mapping_id' => $syncMappingId,
            'source_external_id' => $sourceExternalId,
            'target_external_id' => $targetExternalId,
            'status' => $success ? 'success' : 'error',
            'error_message' => $errorMessage,
        ]);
    }

    private function shopIsLive(string $shop): bool
    {
        try {
            $stmt = $this->pdo->prepare('SELECT live_in_connect FROM connect_shops WHERE shop = :shop');
            $stmt->execute(['shop' => $shop]);

            return (int) $stmt->fetchColumn() === 1;
        } catch (\Throwable) {
            return true; // Spalte fehlt (Migration 0050 nicht eingespielt): bisheriges Verhalten
        }
    }

    /**
     * Fruehester Zeitpunkt (Unix-Zeit), ab dem der Nachlauf Datensaetze verarbeitet: der
     * spaeteste von
     *   - Einschalten der Aktivierung (settings.backfill_since, gesetzt von
     *     Connect\Setup\ShopActivations bzw. beim Umzug)
     *   - Umschalten des Shops nach Connect (connect_shops.live_changed_at)
     *   - jetzt minus BACKFILL_MAX_HOURS
     * Bestellungen davor hat entweder die alte Welt abgerechnet, oder sie entstanden, als die
     * Rechnungserstellung aus war -- beides darf der Nachlauf nicht nachholen.
     *
     * @param array<string, mixed> $mapping
     */
    private function backfillSince(array $mapping): int
    {
        $since = time() - self::BACKFILL_MAX_HOURS * 3600;

        $settings = isset($mapping['settings']) && $mapping['settings'] !== null ? (json_decode((string) $mapping['settings'], true) ?: []) : [];
        if (isset($settings['backfill_since']) && is_numeric($settings['backfill_since'])) {
            $since = max($since, (int) $settings['backfill_since']);
        }

        try {
            $stmt = $this->pdo->prepare('SELECT UNIX_TIMESTAMP(live_changed_at) FROM connect_shops WHERE shop = :shop');
            $stmt->execute(['shop' => (string) $mapping['shop']]);
            $liveSince = $stmt->fetchColumn();
            if ($liveSince !== false && $liveSince !== null) {
                $since = max($since, (int) $liveSince);
            }
        } catch (\Throwable) {
            // Spalte fehlt (Migration 0050) -- dann nur die anderen Grenzen
        }

        return $since;
    }

    /** Absender-IPs fuer diesen Lauf zuruecksetzen -- ohne neues HttpClient.php (Upload unvollstaendig) einfach nichts tun. */
    private static function resetSourceIps(): void
    {
        if (method_exists(\Connect\Http\HttpClient::class, 'resetUsedSourceIps')) {
            \Connect\Http\HttpClient::resetUsedSourceIps();
        }
    }

    private function log(string $message): void
    {
        $this->logLines[] = '[' . date('H:i:s') . '] ' . $message;
    }

    /**
     * Schreibt die von StockSyncRunner::runForShop() zurueckgelieferten
     * einzelnen Anpassungen in connect_stock_sync_log (Migration 0029) --
     * Grundlage fuer die Merchant-Uebersicht "Lagerbestaende"
     * (connect/public/app/_stock_body.php). StockSyncRunner selbst schreibt
     * hier bewusst nicht rein (siehe dessen Klassenkommentar: kennt weder
     * Job- noch Lauf-Protokollierung), analog zu connect_sync_runs oben.
     *
     * @param array<int, array{match_value: string, label: ?string, direction: string, old_quantity: ?int, new_quantity: int}> $adjustments
     */
    private function logStockAdjustments(string $shop, int $runId, array $adjustments): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO connect_stock_sync_log (shop, run_id, match_value, label, direction, old_quantity, new_quantity)
             VALUES (:shop, :run_id, :match_value, :label, :direction, :old_quantity, :new_quantity)'
        );

        foreach ($adjustments as $adjustment) {
            $stmt->execute([
                'shop' => $shop,
                'run_id' => $runId,
                'match_value' => $adjustment['match_value'],
                'label' => $adjustment['label'],
                'direction' => $adjustment['direction'],
                'old_quantity' => $adjustment['old_quantity'],
                'new_quantity' => $adjustment['new_quantity'],
            ]);
        }
    }

    private function startRun(int $syncMappingId, ?int $jobId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO connect_sync_runs (sync_mapping_id, job_id, status)
             VALUES (:sync_mapping_id, :job_id, \'running\')'
        );
        $stmt->execute(['sync_mapping_id' => $syncMappingId, 'job_id' => $jobId]);

        return (int) $this->pdo->lastInsertId();
    }

    private function finishRun(int $runId, string $status, int $recordsRead, int $recordsWritten, int $recordsFailed, ?string $errorSummary): void
    {
        // Welche Absender-IP je Ziel tatsaechlich benutzt wurde (feste IP je System, Migration 0081).
        // method_exists: ein noch nicht kopiertes HttpClient.php (Upload/Worker-Sync) darf keinen Job abbrechen.
        $sourceIps = method_exists(\Connect\Http\HttpClient::class, 'usedSourceIps') ? \Connect\Http\HttpClient::usedSourceIps() : [];
        if ($sourceIps !== []) {
            $parts = [];
            foreach ($sourceIps as $target => $ip) {
                $parts[] = "{$target} von {$ip}";
            }
            $this->log('Absender-IP: ' . implode(' · ', $parts));
        }

        $stmt = $this->pdo->prepare(
            'UPDATE connect_sync_runs
             SET status = :status, records_read = :records_read, records_written = :records_written,
                 records_failed = :records_failed, error_summary = :error_summary, log = :log, ended_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => $status,
            'records_read' => $recordsRead,
            'records_written' => $recordsWritten,
            'records_failed' => $recordsFailed,
            // Nur die ersten 2000 Zeichen -- error_summary ist fuer die
            // Uebersicht gedacht (siehe admin/jobs.php Laufhistorie), nicht
            // als vollstaendiger Stacktrace-Speicher. Der volle Verlauf inkl.
            // Stacktrace steht ungekuerzt in "log".
            'error_summary' => $errorSummary !== null ? substr($errorSummary, 0, 2000) : null,
            'log' => implode("\n", $this->logLines),
            'id' => $runId,
        ]);
    }
}
