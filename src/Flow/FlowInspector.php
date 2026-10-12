<?php

declare(strict_types=1);

namespace Connect\Flow;

use Connect\Sync\Document\DocumentProfile;
use PDO;

/**
 * Baut fuer die Ablauf-Ansicht (admin/flows.php) aus der bestehenden
 * Konfiguration eines Shops die Ablaeufe als Diagramm-Daten: Ausloeser,
 * Schritte, Verzweigungen und JEDE Einstellung, die einen Schritt beeinflusst
 * -- egal wo sie gepflegt wird (Shop-Einstellung, Aktivierung, Belegprofil,
 * Vorlage, Object/Feld, Funktion, fest eingebaut).
 *
 * Rein lesend: keine Schreibzugriffe, keine Aufrufe an externe Systeme. Die
 * Ampel je Schritt ergibt sich aus Pruefungen gegen die Konfiguration (z.B.
 * "Operation fehlt", "Vergleichswert-Feld nicht markiert") und spiegelt die
 * Verzweigungen in Connect\Sync\SyncRunner::translateAndWrite(),
 * Connect\Sync\Document\DocumentPipeline, Connect\Sync\InvoiceService,
 * StockSyncRunner und AutoProductImportRunner wider. Aendert sich dort die
 * Logik, muss diese Klasse mitgezogen werden.
 *
 * Rueckgabeformat je Ablauf (siehe forShop()):
 *   ['key', 'label', 'rows', 'nodes' => [key => node], 'edges' => [edge]]
 *   node: lane ('trigger'|'main'), slot (Ausloeser-Position), row, col (0 Hauptweg,
 *         1 Nebenweg), kind, kindLabel, icon, title, sub, status (ok|warn|err|opt),
 *         note, off, what, settings[], open {label, url}
 *   setting: name, value, src (shop|act|prof|tpl|obj|fn|fix), where, url, warn
 *   edge: from, to, type (down|side|back|merge|feed), label, alt, off
 */
final class FlowInspector
{
    private const SRC_SHOP = 'shop';
    private const SRC_ACTIVATION = 'act';
    private const SRC_PROFILE = 'prof';
    private const SRC_TEMPLATE = 'tpl';
    private const SRC_OBJECT = 'obj';
    private const SRC_FUNCTION = 'fn';
    private const SRC_FIXED = 'fix';

    private string $shop = '';
    /** @var array<string, mixed> */
    private array $shopRow = [];
    /** @var array<int, array<string, mixed>> */
    private array $objectCache = [];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forShop(string $shop): array
    {
        $this->shop = $shop;
        $this->shopRow = $this->one('SELECT * FROM connect_shops WHERE shop = :shop', ['shop' => $shop]) ?? [];

        $mappings = $this->all(
            'SELECT m.*, mt.name AS template_name, mt.source_object_id AS template_source_object_id, mt.target_object_id AS template_target_object_id
             FROM connect_sync_mappings m
             LEFT JOIN connect_mapping_templates mt ON mt.id = m.mapping_template_id
             WHERE m.shop = :shop
             ORDER BY m.id',
            ['shop' => $shop]
        );

        $flows = [];
        $cacheJobs = [];
        $hasImportFlow = false;
        $translationNumber = 0;

        foreach ($mappings as $mapping) {
            $kind = (string) ($mapping['mapping_kind'] ?? 'default');
            try {
                if ($kind === 'invoice') {
                    $flows[] = $this->documentFlow($mapping, true);
                } elseif ($kind === 'stock_sync') {
                    $flows[] = $this->stockFlow($mapping);
                } elseif ($kind === 'product_visibility') {
                    $flows[] = $this->visibilityFlow($mapping);
                } elseif ($kind === 'auto_import_products') {
                    $flows[] = $this->importFlow($mapping);
                    $hasImportFlow = true;
                } elseif ($mapping['target_connection_id'] !== null) {
                    $translationNumber++;
                    $flows[] = $this->usesPreparedOrder($mapping['mapping_template_id'])
                        ? $this->documentFlow($mapping, false)
                        : $this->translationFlow($mapping, $translationNumber);
                } else {
                    $cacheJobs[] = $mapping;
                }
            } catch (\Throwable $e) {
                $flows[] = $this->errorFlow((string) $mapping['name'], $e->getMessage());
            }
        }

        // Der "Importieren"-Button auf der Produkte-Seite gibt es auch ohne
        // automatischen Import -- sobald ein Zielsystem verbunden ist.
        if (!$hasImportFlow && ($this->shopRow['target_connection_id'] ?? null) !== null) {
            try {
                $flows[] = $this->importFlow(null);
            } catch (\Throwable $e) {
                $flows[] = $this->errorFlow('Produktimport', $e->getMessage());
            }
        }

        if ($cacheJobs !== []) {
            $flows[] = $this->cacheJobsFlow($cacheJobs);
        }

        return $flows;
    }

    // =========================================================================
    // Beleg-Ablauf: Rechnung (mapping_kind=invoice) oder Uebersetzung mit
    // Vorlage auf Basis "Bestellung (aufbereitet)" (z.B. MGM-Auftrag)
    // =========================================================================

    /**
     * @param array<string, mixed> $mapping
     * @return array<string, mixed>
     */
    private function documentFlow(array $mapping, bool $isInvoice): array
    {
        $s = $this->shopRow;
        $mappingId = (int) $mapping['id'];
        $activationUrl = $this->url('mapping_edit.php', ['id' => $mappingId]);
        $settingsUrl = $this->url('sync_mappings.php');
        $settingsWhere = $isInvoice ? 'Shop → Jobs/Einstellungen → Rechnungen / Aufträge' : 'Shop → Jobs/Einstellungen';
        $usesPipeline = $this->usesPreparedOrder($mapping['mapping_template_id']);
        $templateTargetObject = $mapping['template_target_object_id'] !== null ? $this->object((int) $mapping['template_target_object_id']) : null;
        $targetConnection = $mapping['target_connection_id'] !== null ? $this->connection((int) $mapping['target_connection_id']) : null;
        $targetTypeId = $targetConnection !== null ? (int) $targetConnection['connector_type_id'] : null;

        $nodes = [];
        $edges = [];

        // ---- Ausloeser -------------------------------------------------------
        $this->addTriggers($nodes, $edges, $mapping, 'gates', $activationUrl);

        // ---- Vorab-Bedingungen ----------------------------------------------
        $enabled = (int) $mapping['enabled'] === 1;
        $gateSettings = [
            $this->setting('Aktivierung', $enabled ? 'aktiv' : 'inaktiv', self::SRC_ACTIVATION, 'Shop → Mappings', $this->url('mappings.php')),
        ];
        $gateStatus = $enabled ? 'ok' : 'warn';
        $gateNote = $enabled ? null : 'Die Aktivierung ist ausgeschaltet, es entsteht nichts.';
        $suppress = false;
        if ($isInvoice) {
            $invoiceOn = (int) ($s['invoice_creation_enabled'] ?? 0) === 1;
            $posOnly = (int) ($s['invoice_pos_only'] ?? 0) === 1;
            $suppress = (int) ($s['invoice_suppress_and_adjust_stock'] ?? 0) === 1;
            $gateSettings[] = $this->setting('Automatische Rechnungsanlage aktivieren', $invoiceOn ? 'an' : 'aus', self::SRC_SHOP, $settingsWhere, $settingsUrl);
            $gateSettings[] = $this->setting('Nur Kassenbestellungen (Shopify POS) berücksichtigen', $posOnly ? 'an – andere Bestellungen werden übersprungen' : 'aus', self::SRC_SHOP, $settingsWhere, $settingsUrl);
            $gateSettings[] = $this->setting('Keine Rechnung erstellen, nur Lagerbestand anpassen', $suppress ? 'an' : 'aus', self::SRC_SHOP, $settingsWhere, $settingsUrl, $suppress);
            $gateSettings[] = $this->setting('Zielsystem (Connection)', $targetConnection !== null ? (string) $targetConnection['label'] : 'keins', self::SRC_SHOP, 'Shop → Jobs/Einstellungen → Vergleichswert & Zielsystem', $settingsUrl);
            if (!$invoiceOn && $enabled) {
                $gateStatus = 'warn';
                $gateNote = 'Rechnungsanlage ist in den Shop-Einstellungen aus.';
            }
            $gateSub = ($posOnly ? 'nur POS-Bestellungen' : 'alle bezahlten Bestellungen') . ($suppress ? ' · nur Lagerbestand' : '');
        } else {
            $gateSub = $enabled ? 'Aktivierung aktiv' : 'Aktivierung inaktiv';
        }
        $gateSettings[] = $this->setting('Doppelt-Schutz', 'jede Bestellung wird nur einmal verarbeitet', self::SRC_FIXED, null);
        if ($targetConnection === null) {
            $gateStatus = 'err';
            $gateNote = 'Kein Zielsystem zugeordnet.';
        }
        $nodes['gates'] = $this->node(1, 0, 'check', 'Vorab-Bedingungen', 'filter', 'Läuft der Ablauf?', $gateSub, $gateStatus,
            'Bevor etwas passiert, prüft Connect die Schalter dieser Aktivierung' . ($isInvoice ? ' und des Shops: Ist die Rechnungsanlage an, zählen nur Kassenbestellungen, oder soll statt einer Rechnung nur der Lagerbestand angepasst werden?' : '.'),
            $gateSettings, ['label' => $isInvoice ? 'Shop-Einstellungen öffnen' : 'Aktivierung öffnen', 'url' => $isInvoice ? $settingsUrl : $activationUrl], $gateNote);

        if ($isInvoice) {
            $adjustOp = $targetTypeId !== null ? $this->one(
                "SELECT op.id, o.id AS object_id, o.label FROM connect_object_operations op JOIN connect_objects o ON o.id = op.object_id
                 WHERE o.connector_type_id = :type_id AND op.operation_type = 'adjust_stock' LIMIT 1",
                ['type_id' => $targetTypeId]
            ) : null;
            $nodes['stockOnly'] = $this->node(1, 1, 'end', 'Nebenweg', 'box', 'Nur Lagerbestand anpassen',
                $suppress ? ($adjustOp !== null ? 'eingeschaltet' : 'Operation adjust_stock fehlt') : 'ausgeschaltet',
                $suppress ? ($adjustOp !== null ? 'ok' : 'err') : 'opt',
                'Statt einer Rechnung zieht Connect die verkauften Mengen im Zielsystem vom Bestand ab. Läuft über den bisherigen, fest programmierten Weg, nicht über die Vorlage.',
                [
                    $this->setting('Schalter', $suppress ? 'an' : 'aus', self::SRC_SHOP, $settingsWhere, $settingsUrl),
                    $this->setting('Operation adjust_stock', $adjustOp !== null ? 'vorhanden am Object „' . $adjustOp['label'] . '“' : 'fehlt im Zielsystem', self::SRC_OBJECT,
                        $adjustOp !== null ? 'Connector Builder → Object „' . $adjustOp['label'] . '“ → Operationen' : 'Connector Builder → Artikel-Object → Operationen',
                        $adjustOp !== null ? $this->url('object_edit.php', ['id' => (int) $adjustOp['object_id']], false) : $this->url('connector_types.php', [], false), $suppress && $adjustOp === null),
                ],
                ['label' => 'Shop-Einstellungen öffnen', 'url' => $settingsUrl],
                $suppress && $adjustOp === null ? 'Ohne diese Operation scheitert jede bezahlte Bestellung.' : null, !$suppress);
            $edges[] = $this->edge('gates', 'stockOnly', 'side', 'nur Lagerbestand', true, !$suppress);
        }

        // ---- Aufbereitung + Kontrollen (fuer Pipeline UND bisherigen Weg) ----
        $nodes['prepare'] = $this->prepareNode($targetTypeId, $isInvoice, $settingsUrl, $settingsWhere);
        $edges[] = $this->edge('gates', 'prepare', 'down', $isInvoice ? 'Rechnung' : null);
        $nodes['checks'] = $this->checksNode($settingsUrl, $settingsWhere);
        $edges[] = $this->edge('prepare', 'checks', 'down');
        $nodes['abort1'] = $this->node(3, 1, 'end', 'Ende', 'stop', 'Abbruch + Fehler-Mail', 'es wird nichts angelegt', 'ok',
            'Connect legt nichts an und schickt eine Mail an die Fehler-Adresse des Shops. Der Fall steht zusätzlich unter Betrieb → Steuer-Kontrolle.',
            [$this->errorMailSetting($settingsUrl, $settingsWhere)], ['label' => 'Steuer-Kontrolle öffnen', 'url' => $this->url('tax_monitoring.php', [], false)]);
        $edges[] = $this->edge('checks', 'abort1', 'side', 'Abweichung', true);

        if ($usesPipeline && $templateTargetObject !== null) {
            $profile = DocumentProfile::forObjectId($this->pdo, (int) $templateTargetObject['id']);
            $objectUrl = $this->url('object_edit.php', ['id' => (int) $templateTargetObject['id']], false);
            $profileWhere = 'Connector Builder → ' . $templateTargetObject['type_name'] . ' → ' . $templateTargetObject['label'] . ' → Belegprofil';

            // ---- Kunde -------------------------------------------------------
            $this->addCustomerNodes($nodes, $edges, $profile, $targetTypeId, $objectUrl, $profileWhere, (string) ($templateTargetObject['type_key'] ?? ''));

            // ---- Feldzuordnung -----------------------------------------------
            $nodes['mapping'] = $this->mappingNode($mapping, $templateTargetObject, $isInvoice, $activationUrl);
            $edges[] = $this->edge('mapping', 'target', 'down');

            // ---- Ziel ----------------------------------------------------------
            $nodes['target'] = $this->documentTargetNode($templateTargetObject, $profile, $objectUrl, $profileWhere);

            // ---- Nach-Kontrolle ------------------------------------------------
            $grossPath = $profile?->grossTotalPath;
            $nodes['post'] = $this->node(7, 0, 'check', 'Prüfung', 'check', 'Nach-Kontrolle',
                $grossPath !== null ? 'Summe im Zielsystem gegen Shopify' : 'ausgeschaltet', $grossPath !== null ? 'ok' : 'opt',
                'Vergleicht die vom Zielsystem errechnete Summe mit Shopifys Gesamtbetrag. Weicht sie mehr als 0,05 € ab, existiert der Beleg schon und muss geprüft werden.',
                [
                    $this->setting('Antwort: Pfad zur Belegsumme', $grossPath ?? 'leer – keine Nach-Kontrolle', self::SRC_PROFILE, $profileWhere, $objectUrl),
                    $this->setting('Toleranz', '0,05 €', self::SRC_FIXED, null),
                ],
                ['label' => 'Belegprofil öffnen', 'url' => $objectUrl], null, $grossPath === null);
            $edges[] = $this->edge('target', 'post', 'down');
            $nodes['abort2'] = $this->node(7, 1, 'end', 'Ende', 'stop', 'Fehler-Mail', 'Beleg prüfen oder stornieren', 'ok',
                'Der Beleg ist schon angelegt, aber die Summe stimmt nicht. Connect meldet das per Mail und im Protokoll.',
                [$this->errorMailSetting($settingsUrl, $settingsWhere)], ['label' => 'Steuer-Kontrolle öffnen', 'url' => $this->url('tax_monitoring.php', [], false)], null, $grossPath === null);
            $edges[] = $this->edge('post', 'abort2', 'side', 'Abweichung', true, $grossPath === null);

            if ($isInvoice) {
                $nodes['mail'] = $this->mailNode($profile?->pdfPath, $settingsUrl, $settingsWhere, 8);
                $edges[] = $this->edge('post', 'mail', 'down', $grossPath !== null ? 'stimmt' : null);
            }
            $rows = $isInvoice ? 9 : 8;
        } else {
            // ---- Bisheriger, fest programmierter Weg (InvoiceService) --------
            $nodes['legacy'] = $this->legacyInvoiceNode($mapping, $activationUrl, $isInvoice);
            $edges[] = $this->edge('checks', 'legacy', 'down', 'in Ordnung');
            if ($isInvoice) {
                $nodes['mail'] = $this->mailNode('output.pdf', $settingsUrl, $settingsWhere, 5);
                $edges[] = $this->edge('legacy', 'mail', 'down');
            }
            $rows = $isInvoice ? 6 : 5;
        }

        $label = $isInvoice
            ? 'Rechnung' . ($targetConnection !== null ? ' (' . $targetConnection['label'] . ')' : '')
            : (string) ($mapping['template_name'] ?? $mapping['name']);

        return ['key' => 'm' . $mappingId, 'label' => $label, 'rows' => $rows, 'nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * @param array<string, array<string, mixed>> $nodes
     * @param list<array<string, mixed>> $edges
     * @param array<string, mixed> $mapping
     */
    private function addTriggers(array &$nodes, array &$edges, array $mapping, string $entryNode, string $activationUrl): void
    {
        $enabled = (int) $mapping['enabled'] === 1;
        $topic = $mapping['trigger_topic'] !== null ? (string) $mapping['trigger_topic'] : null;
        $isWebhook = $mapping['trigger_type'] === 'webhook' && $topic !== null;
        $interval = $mapping['schedule_interval_minutes'] !== null ? (int) $mapping['schedule_interval_minutes'] : null;

        $subscribed = false;
        if ($isWebhook) {
            $subscribed = $this->value(
                'SELECT 1 FROM connect_webhook_subscriptions WHERE connection_id = :connection_id AND topic = :topic',
                ['connection_id' => (int) $mapping['source_connection_id'], 'topic' => $topic]
            ) !== null;
        }
        $webhookStatus = !$isWebhook || !$enabled ? 'opt' : ($subscribed ? 'ok' : 'warn');
        $nodes['tWebhook'] = $this->triggerNode(0, 'Auslöser · automatisch', 'bolt', 'Webhook',
            $isWebhook ? $topic . ' · sofort' : 'ausgeschaltet', $webhookStatus,
            'Das Quellsystem meldet ein Ereignis sofort an Connect (z. B. „Bestellung bezahlt“). Connect liest den Datensatz vollständig nach und startet den Ablauf.',
            [
                $this->setting('Auslöser', $isWebhook ? 'Webhook ' . $topic : 'kein Webhook', self::SRC_ACTIVATION, 'Shop → Mappings → Aktivierung', $activationUrl),
                $this->setting('Webhook-Abo im Quellsystem', $isWebhook ? ($subscribed ? 'angelegt' : 'nicht gefunden – Ereignisse kommen evtl. nicht an') : '–', self::SRC_FIXED, $isWebhook ? 'wird beim Einschalten in den Shop-Einstellungen angelegt' : null, null, $isWebhook && !$subscribed),
            ],
            ['label' => 'Aktivierung öffnen', 'url' => $activationUrl],
            $isWebhook && $enabled && !$subscribed ? 'Für dieses Thema ist kein Webhook-Abo gespeichert.' : null, !$isWebhook || !$enabled);
        $edges[] = $this->edge('tWebhook', $entryNode, 'merge', null, false, !$isWebhook || !$enabled);

        $cacheJob = $this->cacheJobFor((int) $mapping['source_connection_id'], (int) $mapping['source_object_id']);
        // Objects mit Zeitfilter (z.B. Shopify-Bestellung, orderQuery) laedt der Nachlauf selbst frisch
        // (SyncRunner::runScheduledTranslationBatch) -- dann braucht er keinen eigenen Zwischenspeicher-Job.
        $selfLoading = false;
        try {
            $selfLoading = $this->value('SELECT 1 FROM connect_objects WHERE id = :id AND age_filter_param IS NOT NULL AND age_filter_format IS NOT NULL', ['id' => (int) $mapping['source_object_id']]) !== null;
        } catch (\Throwable) {
            $selfLoading = false;
        }
        $scheduleStatus = $interval === null || !$enabled ? 'opt' : ($selfLoading || ($cacheJob !== null && (int) $cacheJob['enabled'] === 1) ? 'ok' : 'warn');
        $nodes['tSchedule'] = $this->triggerNode(1, 'Auslöser · automatisch', 'clock', 'Zeitplan-Nachlauf',
            $interval !== null ? 'alle ' . $interval . ' Minuten' : 'ausgeschaltet', $scheduleStatus,
            'Sicherheitsnetz: geht in festen Abständen die zwischengespeicherten Datensätze durch, die noch nicht verarbeitet wurden (bei Bestellungen nur bezahlte).',
            [
                $this->setting('Intervall', $interval !== null ? $interval . ' Minuten' : 'aus', self::SRC_ACTIVATION, 'Shop → Mappings → Aktivierung → Zeitplan-Nachlauf', $activationUrl),
                $selfLoading
                    ? $this->setting('Datensätze', 'lädt der Nachlauf selbst frisch aus der Quelle (ab Einschalten/Umschalten, max. 48 h zurück)', self::SRC_FIXED, null)
                    : $this->setting('Zwischenspeicher-Job für die Quelle', $cacheJob !== null ? '„' . $cacheJob['name'] . '“ ' . ((int) $cacheJob['enabled'] === 1 ? 'aktiv' : 'inaktiv') : 'keiner', self::SRC_ACTIVATION,
                        'Shop → Jobs', $this->url('sync_mappings.php'), $interval !== null && ($cacheJob === null || (int) $cacheJob['enabled'] !== 1)),
                $this->setting('Höchstens pro Lauf', '50 Datensätze, neueste zuerst', self::SRC_FIXED, null),
            ],
            ['label' => 'Aktivierung öffnen', 'url' => $activationUrl],
            $scheduleStatus === 'warn' ? 'Ohne aktiven Zwischenspeicher-Job findet der Nachlauf keine neuen Datensätze.' : null, $interval === null || !$enabled);
        $edges[] = $this->edge('tSchedule', $entryNode, 'merge', null, false, $interval === null || !$enabled);

        $nodes['tButton'] = $this->triggerNode(2, 'Auslöser · Button', 'hand', 'Einmal wirklich ausführen', 'Admin · Details / Testen', 'ok',
            'Ein Admin wählt einen zwischengespeicherten Datensatz und löst den Ablauf von Hand aus. „Vorschau (schreibt nicht)“ zeigt dasselbe ohne zu schreiben.',
            [$this->setting('Wo', 'Shop → Mappings → Details / Testen → Karte „Testen“', self::SRC_FIXED, null, $activationUrl)],
            ['label' => 'Details / Testen öffnen', 'url' => $activationUrl]);
        $edges[] = $this->edge('tButton', $entryNode, 'merge');
    }

    /**
     * @return array<string, mixed>
     */
    private function prepareNode(?int $targetTypeId, bool $isInvoice, string $settingsUrl, string $settingsWhere): array
    {
        $s = $this->shopRow;
        $comparisonKey = (string) ($s['comparison_key'] ?? 'sku');
        $comparisonLabel = self::comparisonLabel($comparisonKey);
        $roleField = $targetTypeId !== null ? $this->one(
            'SELECT f.label, o.id AS object_id, o.label AS object_label FROM connect_object_fields f JOIN connect_objects o ON o.id = f.object_id
             WHERE o.connector_type_id = :type_id AND f.comparison_role = :role LIMIT 1',
            ['type_id' => $targetTypeId, 'role' => $comparisonKey]
        ) : null;
        $pricesIncludeTax = $s['shopify_prices_include_tax'] ?? null;
        $pricesLabel = $pricesIncludeTax === null ? 'Brutto (noch nicht festgelegt, gilt als Brutto)' : ((int) $pricesIncludeTax === 1 ? 'Brutto (Preis inkl. MwSt.)' : 'Netto (Preis ohne MwSt.)');

        $settings = [
            $this->setting('Preise in Shopify', $pricesLabel, self::SRC_SHOP, 'Shop → Jobs/Einstellungen → Preise (Netto/Brutto)', $settingsUrl),
            $this->setting('Vergleichswert für den Artikelabgleich', $comparisonLabel, self::SRC_SHOP, 'Shop → Jobs/Einstellungen → Vergleichswert', $settingsUrl),
            $this->setting('Feld mit dieser Rolle im Zielsystem', $roleField !== null ? '„' . $roleField['label'] . '“ am Object „' . $roleField['object_label'] . '“' : 'keins markiert – Positionen ohne Artikel-ID',
                self::SRC_OBJECT, 'Connector Builder → Object → Felder → Spalte „Vergleichswert“',
                $roleField !== null ? $this->url('object_edit.php', ['id' => (int) $roleField['object_id']], false) : $this->url('connector_types.php', [], false), $roleField === null),
        ];
        if ($isInvoice) {
            $settings[] = $this->setting('Kassierer', ($s['invoice_cashier_id'] ?? null) !== null ? (string) $s['invoice_cashier_id'] : 'keiner', self::SRC_SHOP, $settingsWhere, $settingsUrl);
            $settings[] = $this->setting('Zahlungsart', (string) ($s['invoice_payment_method'] ?? '') !== '' ? (string) $s['invoice_payment_method'] : 'keine', self::SRC_SHOP, $settingsWhere, $settingsUrl);
        }

        return $this->node(2, 0, 'step', 'Schritt', 'gear', 'Bestellung aufbereiten',
            'Rabatte, Steuern, Versand · Abgleich per ' . $comparisonLabel, $roleField !== null ? 'ok' : 'warn',
            'Rabatte, Steuersätze, Netto/Brutto und Versand werden aus der Shopify-Bestellung aufgelöst, die Positionen werden mit den Artikeln im Zielsystem abgeglichen'
            . ($isInvoice ? ', Kassierer und Zahlungsart werden als Felder bereitgestellt.' : '.'),
            $settings, ['label' => 'Shop-Einstellungen öffnen', 'url' => $settingsUrl],
            $roleField === null ? 'Im Zielsystem ist kein Feld mit der Rolle „' . $comparisonLabel . '“ markiert, Positionen bekommen keine Artikel-ID.' : null);
    }

    /**
     * @return array<string, mixed>
     */
    private function checksNode(string $settingsUrl, string $settingsWhere): array
    {
        return $this->node(3, 0, 'check', 'Prüfung', 'check', 'Steuer- und Betragskontrolle', 'vor dem Anlegen', 'ok',
            'Shopifys Steuerbeträge je Position werden gegen Shopifys Steuersumme geprüft, die Summe der Positionen gegen den Gesamtbetrag. Bei einer Abweichung wird nichts angelegt.',
            [
                $this->setting('Toleranz Steuer', '0,10 €', self::SRC_FIXED, null),
                $this->setting('Toleranz Betrag', '0,05 €', self::SRC_FIXED, null),
                $this->errorMailSetting($settingsUrl, $settingsWhere),
            ],
            ['label' => 'Steuer-Kontrolle öffnen', 'url' => $this->url('tax_monitoring.php', [], false)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function errorMailSetting(string $settingsUrl, string $settingsWhere): array
    {
        $email = (string) ($this->shopRow['invoice_error_notification_email'] ?? '');

        return $this->setting('Fehler-Benachrichtigung an', $email !== '' ? $email : 'Standardadresse aus der Server-Konfiguration', self::SRC_SHOP, $settingsWhere . ' → „Fehler-Benachrichtigung an“', $settingsUrl);
    }

    /**
     * @param array<string, array<string, mixed>> $nodes
     * @param list<array<string, mixed>> $edges
     */
    private function addCustomerNodes(array &$nodes, array &$edges, ?DocumentProfile $profile, ?int $targetTypeId, string $objectUrl, string $profileWhere, string $targetTypeKey): void
    {
        $edges[] = $this->edge('checks', 'customer', 'down', 'in Ordnung');
        $customerKey = $profile?->customerObjectKey;
        if ($profile === null || $customerKey === null) {
            $nodes['customer'] = $this->node(4, 0, 'step', 'Schritt · optional', 'search', 'Kunde zuordnen', 'ausgeschaltet – ohne Kunden-ID', 'opt',
                'Optionaler Vorschritt: den Kunden im Zielsystem suchen und sonst neu anlegen. Ist er aus, entsteht der Beleg ohne Kunden-ID.',
                [$this->setting('Kunden-Object', 'kein Kunden-Vorschritt', self::SRC_PROFILE, $profileWhere, $objectUrl)],
                ['label' => 'Belegprofil öffnen', 'url' => $objectUrl], null, true);
            $edges[] = $this->edge('customer', 'mapping', 'down');

            return;
        }

        $customerObject = $targetTypeId !== null ? $this->one(
            'SELECT id, label FROM connect_objects WHERE connector_type_id = :type_id AND object_key = :key',
            ['type_id' => $targetTypeId, 'key' => $customerKey]
        ) : null;
        $readOp = $customerObject !== null ? $this->one(
            "SELECT path_template, body_template FROM connect_object_operations WHERE object_id = :id AND operation_type = 'read_list' LIMIT 1",
            ['id' => (int) $customerObject['id']]
        ) : null;
        $placeholder = '{{' . $profile->customerSearchParam . '}}';
        $hasPlaceholder = $readOp !== null && (str_contains((string) $readOp['path_template'], $placeholder) || str_contains((string) ($readOp['body_template'] ?? ''), $placeholder));
        $searchFields = array_values(array_filter([
            $profile->customerEmailField !== null ? 'E-Mail: ' . $profile->customerEmailField : null,
            $profile->customerFirstNameField !== null && $profile->customerLastNameField !== null ? 'Name: ' . $profile->customerFirstNameField . ' + ' . $profile->customerLastNameField : null,
        ]));

        $status = 'ok';
        $note = null;
        if ($customerObject === null) {
            $status = 'err';
            $note = 'Das Kunden-Object „' . $customerKey . '“ gibt es im Zielsystem nicht.';
        } elseif (!$hasPlaceholder) {
            $status = 'err';
            $note = 'Die Operation „Lesen (Liste)“ des Kunden-Objects enthält den Platzhalter ' . $placeholder . ' nicht, die Suche findet nie etwas.';
        } elseif ($searchFields === []) {
            $status = 'warn';
            $note = 'Weder E-Mail- noch Namensfelder sind eingetragen, es wird nie gesucht.';
        }
        $customerObjectUrl = $customerObject !== null ? $this->url('object_edit.php', ['id' => (int) $customerObject['id']], false) : $objectUrl;

        $nodes['customer'] = $this->node(4, 0, 'step', 'Schritt', 'search', 'Kunde suchen', $searchFields !== [] ? implode(', dann ', array_map(static fn ($f) => explode(':', $f)[0], $searchFields)) : 'keine Suchfelder', $status,
            'Sucht den Kunden im Zielsystem zuerst per E-Mail, dann per Vor- und Nachname. Die gefundene ID steht der Vorlage als „Kunden-ID im Zielsystem“ zur Verfügung.',
            [
                $this->setting('Kunden-Object', $customerObject !== null ? $customerObject['label'] . ' (' . $customerKey . ')' : $customerKey . ' – nicht gefunden', self::SRC_PROFILE, $profileWhere, $objectUrl, $customerObject === null),
                $this->setting('Platzhalter für den Suchbegriff', $profile->customerSearchParam, self::SRC_PROFILE, $profileWhere, $objectUrl),
                $this->setting('Suchfelder', $searchFields !== [] ? implode(' · ', $searchFields) : 'keine', self::SRC_PROFILE, $profileWhere, $objectUrl, $searchFields === []),
                $this->setting('Feld mit der Kunden-ID', $profile->customerIdField, self::SRC_PROFILE, $profileWhere, $objectUrl),
                $this->setting('Suche (Operation „Lesen (Liste)“)', $readOp !== null ? (string) $readOp['path_template'] : 'fehlt', self::SRC_OBJECT, 'Connector Builder → Kunden-Object → Operationen', $customerObjectUrl, !$hasPlaceholder),
            ],
            ['label' => 'Belegprofil öffnen', 'url' => $objectUrl], $note);
        $edges[] = $this->edge('customer', 'mapping', 'down', 'gefunden');

        if ($profile->customerCreateTemplateId === null) {
            return;
        }

        $template = $this->one('SELECT id, name, target_object_id FROM connect_mapping_templates WHERE id = :id', ['id' => $profile->customerCreateTemplateId]);
        $createOp = $customerObject !== null ? $this->value(
            "SELECT 1 FROM connect_object_operations WHERE object_id = :id AND operation_type = 'create'",
            ['id' => (int) $customerObject['id']]
        ) : null;
        $settings = [
            $this->setting('Vorlage für die Neuanlage', $template !== null ? $template['name'] . ' · ' . $this->fieldMappingCount((int) $template['id']) . ' Felder' : 'gelöscht', self::SRC_PROFILE, $profileWhere,
                $template !== null ? $this->url('mapping_template_edit.php', ['id' => (int) $template['id']], false) : $objectUrl, $template === null),
            $this->setting('Antwort: Pfad zur ID des neuen Kunden', $profile->customerCreatedIdPath, self::SRC_PROFILE, $profileWhere, $objectUrl),
            $this->setting('Operation „Anlegen“ am Kunden-Object', $createOp !== null ? 'vorhanden' : 'fehlt', self::SRC_OBJECT, 'Connector Builder → Kunden-Object → Operationen', $customerObjectUrl, $createOp === null),
        ];
        $lookups = $template !== null ? $this->lookupsOfTemplate((int) $template['id']) : [];
        foreach ($lookups as $lookup) {
            $settings[] = $this->setting('Lookup-Tabelle', $lookup['name'] . ' · ' . $lookup['entries'] . ' Einträge', self::SRC_TEMPLATE, 'Konfiguration → Lookup-Tabellen', $this->url('lookup_table_edit.php', ['id' => (int) $lookup['id']], false));
        }
        $status = $template === null || $createOp === null ? 'err' : ($targetTypeKey === 'hellocash' ? 'warn' : 'ok');
        $note = $template === null ? 'Die Kunden-Vorlage wurde gelöscht.' : ($createOp === null ? 'Das Kunden-Object kann nichts anlegen.' : ($targetTypeKey === 'hellocash' ? 'HelloCash akzeptiert neue Kunden nur aus 11 Ländern, sonst entsteht der Beleg ohne Kunde.' : null));

        $nodes['createCustomer'] = $this->node(4, 1, 'step', 'Nebenweg', 'userplus', 'Kunde anlegen',
            $template !== null ? 'Vorlage „' . $template['name'] . '“' : 'Vorlage fehlt', $status,
            'Gibt es den Kunden noch nicht, legt Connect ihn über die Kunden-Vorlage an und nimmt die neue ID für den Beleg.',
            $settings, ['label' => 'Kunden-Vorlage öffnen', 'url' => $template !== null ? $this->url('mapping_template_edit.php', ['id' => (int) $template['id']], false) : $objectUrl], $note);
        $edges[] = $this->edge('customer', 'createCustomer', 'side', 'nicht gefunden', true);
        $edges[] = $this->edge('createCustomer', 'mapping', 'back', 'neue Kunden-ID', true);
    }

    /**
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $targetObject
     * @return array<string, mixed>
     */
    private function mappingNode(array $mapping, array $targetObject, bool $isInvoice, string $activationUrl): array
    {
        $templateId = (int) $mapping['mapping_template_id'];
        $templateUrl = $this->url('mapping_template_edit.php', ['id' => $templateId], false);
        $rows = $this->all('SELECT transform_type, transform_config FROM connect_field_mappings WHERE mapping_template_id = :id', ['id' => $templateId]);
        $omitCount = 0;
        foreach ($rows as $row) {
            $config = $row['transform_config'] !== null ? (json_decode((string) $row['transform_config'], true) ?: []) : [];
            if (!empty($config['omit_if_empty'])) {
                $omitCount++;
            }
        }
        $fieldCount = (int) $this->value('SELECT COUNT(*) FROM connect_object_fields WHERE object_id = :id AND is_repeating_group = 0', ['id' => (int) $targetObject['id']]);
        $activationSettings = $mapping['settings'] !== null ? (json_decode((string) $mapping['settings'], true) ?: []) : [];
        $settingsText = $activationSettings === [] ? 'keine' : implode(', ', array_map(static fn ($k, $v) => $k . ' = ' . (is_scalar($v) ? (string) $v : json_encode($v)), array_keys($activationSettings), $activationSettings));

        $settings = [
            $this->setting('Vorlage', (string) $mapping['template_name'], self::SRC_ACTIVATION, 'Shop → Mappings → Details / Testen → „Mapping-Vorlage“', $activationUrl),
            $this->setting('Feldzuordnungen', count($rows) . ' von ' . $fieldCount . ' Zielfeldern · ' . $omitCount . ' „nur wenn vorhanden“', self::SRC_TEMPLATE, 'Konfiguration → Mapping-Vorlagen', $templateUrl, count($rows) === 0),
            $this->setting('Einstellungen für diesen Shop', $settingsText, self::SRC_ACTIVATION, 'Details / Testen → „Einstellungen für diesen Shop“', $activationUrl),
        ];
        $status = count($rows) === 0 ? 'err' : 'ok';
        $note = count($rows) === 0 ? 'Die Vorlage hat keine Feldzuordnungen.' : null;

        if ($isInvoice) {
            // Signaturpflicht (Migration 0072): Shop verlangt sie, die Vorlage fuellt das Zielfeld aber nicht
            $signatureOn = (int) ($this->shopRow['invoice_signature_mandatory'] ?? 0) === 1;
            $signatureMapped = $this->value(
                "SELECT 1 FROM connect_field_mappings fm JOIN connect_object_fields f ON f.id = fm.target_field_id
                 WHERE fm.mapping_template_id = :id AND f.field_key = 'signature_mandatory'",
                ['id' => $templateId]
            ) !== null;
            $settings[] = $this->setting('Signatur erforderlich (RKSV)', ($signatureOn ? 'an' : 'aus') . ' · Zielfeld signature_mandatory ' . ($signatureMapped ? 'zugeordnet' : 'nicht in der Vorlage'),
                'shop', 'Shop → Jobs/Einstellungen → Rechnungen / Aufträge', $this->url('sync_mappings.php'), $signatureOn && !$signatureMapped);
            if ($signatureOn && !$signatureMapped && $status === 'ok') {
                $status = 'warn';
                $note = 'Der Shop verlangt eine Signatur, die Vorlage sendet signature_mandatory aber nicht – HelloCash legt die Rechnung dann ohne Signaturpflicht an.';
            }

            // Rechnungstext = Zielfeld "text" der Vorlage (z.B. "Text mit Platzhaltern")
            $textMapping = $this->one(
                "SELECT fm.transform_type, fm.transform_config, sf.label AS source_label
                 FROM connect_field_mappings fm
                 JOIN connect_object_fields f ON f.id = fm.target_field_id
                 LEFT JOIN connect_object_fields sf ON sf.id = fm.source_field_id
                 WHERE fm.mapping_template_id = :id AND f.field_key = 'text' AND f.parent_field_id IS NULL LIMIT 1",
                ['id' => $templateId]
            );
            if ($textMapping !== null) {
                $textConfig = $textMapping['transform_config'] !== null ? (json_decode((string) $textMapping['transform_config'], true) ?: []) : [];
                $textValue = match ($textMapping['transform_type']) {
                    'text_template' => '„' . ($textConfig['template'] ?? '') . '“',
                    'static' => 'fest „' . ($textConfig['value'] ?? '') . '“',
                    default => 'aus Feld „' . ($textMapping['source_label'] ?? '?') . '“',
                };
                $settings[] = $this->setting('Rechnungstext', $textValue, self::SRC_TEMPLATE, 'Konfiguration → Mapping-Vorlagen → Zielfeld „text“', $templateUrl);
            }

            $headerFunction = $this->one(
                "SELECT f.label, t.name FROM connect_functions f JOIN connect_mapping_templates t ON t.id = f.mapping_template_id WHERE f.function_kind = 'invoice_create' LIMIT 1"
            );
            if ($headerFunction !== null && $textMapping === null) {
                $settings[] = $this->setting('Funktion „' . $headerFunction['label'] . '“', 'wird in diesem Ablauf nicht verwendet, nur beim bisherigen Weg ohne Vorlage', self::SRC_FUNCTION, 'Konfiguration → Funktionen', $this->url('functions.php', [], false), true);
                if ($status === 'ok') {
                    $status = 'warn';
                    $note = 'Die Kopftext-Funktion „' . $headerFunction['label'] . '“ wirkt hier nicht. Der Rechnungstext kommt aus der Feldzuordnung der Vorlage.';
                }
            }
        }

        return $this->node(5, 0, 'step', 'Schritt', 'columns', 'Felder zuordnen', 'Vorlage „' . $mapping['template_name'] . '“ · ' . count($rows) . ' Felder', $status,
            'Baut aus der aufbereiteten Bestellung die Anfrage an das Zielsystem. Einstellungen der Aktivierung werden darunter gemischt, eine Feldzuordnung gewinnt.',
            $settings, ['label' => 'Vorlage öffnen', 'url' => $templateUrl], $note);
    }

    /**
     * @param array<string, mixed> $targetObject
     * @return array<string, mixed>
     */
    private function documentTargetNode(array $targetObject, ?DocumentProfile $profile, string $objectUrl, string $profileWhere): array
    {
        $createOp = $this->one(
            "SELECT http_method, path_template FROM connect_object_operations WHERE object_id = :id AND operation_type = 'create' LIMIT 1",
            ['id' => (int) $targetObject['id']]
        );
        $discountLabels = ['none' => 'Kein Rabattfeld (rabattierter Preis)', 'percent' => 'Nur Prozent', 'absolute' => 'Nur Betrag', 'auto' => 'Prozent wenn glatt, sonst Betrag'];
        $settings = [
            $this->setting('Operation „Anlegen“', $createOp !== null ? $createOp['http_method'] . ' ' . $createOp['path_template'] : 'fehlt', self::SRC_OBJECT, 'Connector Builder → ' . $targetObject['label'] . ' → Operationen', $objectUrl, $createOp === null),
            $this->setting('Belegprofil', $profile !== null ? 'gesetzt' : 'nicht gesetzt – Standardwerte (kein Rabattfeld, keine Antwortpfade)', self::SRC_PROFILE, $profileWhere, $objectUrl, $profile === null),
        ];
        if ($profile !== null) {
            $settings[] = $this->setting('Rabatt an der Position', $discountLabels[$profile->discountMode] ?? $profile->discountMode, self::SRC_PROFILE, $profileWhere, $objectUrl);
            $settings[] = $this->setting('Betragsrabatt gilt …', $profile->absoluteDiscountPerUnit ? 'pro Stück' : 'für die ganze Position', self::SRC_PROFILE, $profileWhere, $objectUrl);
            $settings[] = $this->setting('Nachkommastellen beim Prozentrabatt', (string) $profile->percentDecimals, self::SRC_PROFILE, $profileWhere, $objectUrl);
            $settings[] = $this->setting('Versand-Artikel', $profile->shippingArticleId ?? 'keiner', self::SRC_PROFILE, $profileWhere, $objectUrl);
            $settings[] = $this->setting('Antwort: Pfad zur Belegnummer', $profile->documentNumberPath ?? 'leer', self::SRC_PROFILE, $profileWhere, $objectUrl);
        }
        foreach ($this->all('SELECT field_key, max_length FROM connect_object_fields WHERE object_id = :id AND max_length IS NOT NULL', ['id' => (int) $targetObject['id']]) as $field) {
            $settings[] = $this->setting('Max. Länge ' . $field['field_key'], $field['max_length'] . ' Zeichen (längere Werte werden gekürzt)', self::SRC_OBJECT, 'Connector Builder → ' . $targetObject['label'] . ' → Felder', $objectUrl);
        }

        return $this->node(6, 0, 'target', 'Ziel', 'doc', $targetObject['label'] . ' anlegen', $targetObject['type_name'] . ($createOp !== null ? ' · ' . $createOp['http_method'] . ' ' . $createOp['path_template'] : ''),
            $createOp === null ? 'err' : ($profile === null ? 'warn' : 'ok'),
            'Schickt den Beleg an das Zielsystem. Wie Rabatte dargestellt werden und wo Belegnummer, Summe und PDF in der Antwort stehen, legt das Belegprofil fest.',
            $settings, ['label' => 'Object „' . $targetObject['label'] . '“ öffnen', 'url' => $objectUrl],
            $createOp === null ? 'Das Ziel-Object hat keine Operation „Anlegen“.' : ($profile === null ? 'Ohne Belegprofil gibt es keine Rabattfelder, keine Nach-Kontrolle und keinen PDF-Versand.' : null));
    }

    /**
     * @return array<string, mixed>
     */
    private function mailNode(?string $pdfPath, string $settingsUrl, string $settingsWhere, int $row): array
    {
        $s = $this->shopRow;
        $on = (int) ($s['invoice_mail_enabled'] ?? 0) === 1;
        $smtpHost = (string) ($s['invoice_mail_smtp_host'] ?? '');
        $smtpText = $smtpHost !== ''
            ? $smtpHost . ':' . ($s['invoice_mail_smtp_port'] ?? '?') . ' · ' . strtoupper((string) ($s['invoice_mail_smtp_encryption'] ?? 'tls')) . ' · Benutzer ' . (($s['invoice_mail_smtp_username'] ?? '') !== '' ? $s['invoice_mail_smtp_username'] : '–') . ' · Passwort ' . (($s['invoice_mail_smtp_password'] ?? '') !== '' ? 'gesetzt' : 'fehlt')
            : 'nicht eingerichtet – Systemversand';
        $status = !$on ? 'opt' : ($pdfPath === null ? 'err' : 'ok');

        return $this->node($row, 0, 'end', 'Ende · optional', 'mail', 'PDF-Mail an Kunden', !$on ? 'ausgeschaltet' : ($smtpHost !== '' ? 'eigenes SMTP' : 'Systemversand'), $status,
            'Schickt das PDF aus der Antwort des Zielsystems an die E-Mail-Adresse der Bestellung.',
            [
                $this->setting('Rechnung automatisch per E-Mail an den Kunden senden', $on ? 'an' : 'aus', self::SRC_SHOP, $settingsWhere, $settingsUrl),
                $this->setting('Absendername', (string) ($s['invoice_mail_sender_name'] ?? '') !== '' ? (string) $s['invoice_mail_sender_name'] : 'Standard', self::SRC_SHOP, $settingsWhere, $settingsUrl),
                $this->setting('Absender-/Antwortadresse', (string) ($s['invoice_mail_sender_address'] ?? '') !== '' ? (string) $s['invoice_mail_sender_address'] : 'Standard', self::SRC_SHOP, $settingsWhere, $settingsUrl),
                $this->setting('Betreff', (string) ($s['invoice_mail_subject'] ?? '') !== '' ? (string) $s['invoice_mail_subject'] : 'Standardbetreff', self::SRC_SHOP, $settingsWhere, $settingsUrl),
                $this->setting('Nachricht', (string) ($s['invoice_mail_message'] ?? '') !== '' ? mb_strimwidth((string) $s['invoice_mail_message'], 0, 120, '…') : 'Standardtext', self::SRC_SHOP, $settingsWhere, $settingsUrl),
                $this->setting('Eigenes SMTP-Konto', $smtpText, self::SRC_SHOP, $settingsWhere . ' → „Eigenes SMTP-Konto verwenden“', $settingsUrl),
                $this->setting('Antwort: Pfad zum PDF', $pdfPath ?? 'leer – kein PDF-Versand möglich', self::SRC_PROFILE, 'Belegprofil des Ziel-Objects', null, $on && $pdfPath === null),
            ],
            ['label' => 'Shop-Einstellungen öffnen', 'url' => $settingsUrl],
            $on && $pdfPath === null ? 'Im Belegprofil fehlt der Pfad zum PDF, die Mail kann nicht verschickt werden.' : null, !$on);
    }

    /**
     * Rechnungs-Aktivierung ohne Beleg-Vorlage: bisheriger, fest programmierter
     * Weg ueber Connect\Sync\InvoiceService (Kunde, Rabatte, Kopftext im Code).
     *
     * @param array<string, mixed> $mapping
     * @return array<string, mixed>
     */
    private function legacyInvoiceNode(array $mapping, string $activationUrl, bool $isInvoice): array
    {
        $headerFunction = $this->one(
            "SELECT f.label, t.id, t.name FROM connect_functions f JOIN connect_mapping_templates t ON t.id = f.mapping_template_id WHERE f.function_kind = 'invoice_create' LIMIT 1"
        );

        return $this->node(4, 0, 'target', 'Ziel · bisheriger Weg', 'doc', 'Rechnung anlegen (fest programmiert)', 'ohne Vorlage · HelloCash', 'warn',
            'Diese Aktivierung hat keine Beleg-Vorlage. Kunde, Rabatte und Antwort werden im Code für HelloCash behandelt. Mit einer Vorlage „Bestellung (aufbereitet) → …“ wird alles einstellbar.',
            [
                $this->setting('Mapping-Vorlage', $isInvoice ? 'keine (bisherige HelloCash-Logik)' : 'keine', self::SRC_ACTIVATION, 'Shop → Mappings → Details / Testen', $activationUrl, true),
                $this->setting('Rechnungstext', $headerFunction !== null ? 'Funktion „' . $headerFunction['label'] . '“ (Vorlage „' . $headerFunction['name'] . '“)' : 'fest „Shopify #Bestellnummer“', self::SRC_FUNCTION, 'Konfiguration → Funktionen', $this->url('functions.php', [], false)),
                $this->setting('Kunde', 'fest: HelloCash-Object customer, Suche per E-Mail, dann Name', self::SRC_FIXED, null),
                $this->setting('Rabatte', 'fest: Prozent wenn glatt, sonst Betrag', self::SRC_FIXED, null),
                $this->setting('Nach-Kontrolle', 'fest: data.gross_total', self::SRC_FIXED, null),
            ],
            ['label' => 'Aktivierung öffnen', 'url' => $activationUrl],
            'Bisheriger Weg ohne Vorlage. Einstellungen wie Belegprofil oder Kunden-Vorlage wirken hier nicht.');
    }

    // =========================================================================
    // Lagerbestand-Abgleich (mapping_kind=stock_sync)
    // =========================================================================

    /**
     * @param array<string, mixed> $mapping
     * @return array<string, mixed>
     */
    private function stockFlow(array $mapping): array
    {
        $s = $this->shopRow;
        $settingsUrl = $this->url('sync_mappings.php');
        $where = 'Shop → Jobs/Einstellungen → Lagerbestand';
        $enabled = (int) $mapping['enabled'] === 1 && (int) ($s['stock_sync_enabled'] ?? 0) === 1;
        $interval = $mapping['schedule_interval_minutes'] !== null ? (int) $mapping['schedule_interval_minutes'] : null;
        $targetConnection = ($s['target_connection_id'] ?? null) !== null ? $this->connection((int) $s['target_connection_id']) : null;
        $targetTypeId = $targetConnection !== null ? (int) $targetConnection['connector_type_id'] : null;
        $shopifyTypeId = $this->connection((int) $mapping['source_connection_id'])['connector_type_id'] ?? null;

        $nodes = [];
        $edges = [];
        $nodes['tSchedule'] = $this->triggerNode(0, 'Auslöser · automatisch', 'clock', 'Zeitplan', $interval !== null ? 'alle ' . $interval . ' Minuten' : 'ohne Zeitplan', $interval !== null && $enabled ? 'ok' : 'opt',
            'Der Abgleich läuft in festen Abständen über den Scheduler.',
            [
                $this->setting('Intervall', $interval !== null ? $interval . ' Minuten' : 'keins', self::SRC_ACTIVATION, 'Shop → Jobs → „' . $mapping['name'] . '“', $settingsUrl),
                $this->setting('Ausgeführt von', 'Connect-Scheduler, ein Job pro Aufruf', self::SRC_FIXED, null),
            ], ['label' => 'Jobs öffnen', 'url' => $settingsUrl], null, $interval === null || !$enabled);
        $edges[] = $this->edge('tSchedule', 'gates', 'merge', null, false, $interval === null || !$enabled);
        $nodes['tButton'] = $this->triggerNode(1, 'Auslöser · Button', 'hand', 'Jetzt ausführen', 'Shop → Jobs', 'ok',
            'Startet denselben Abgleich sofort von Hand.',
            [$this->setting('Wo', 'Shop → Jobs → Zeile „' . $mapping['name'] . '“ → „Jetzt ausführen“', self::SRC_FIXED, null, $settingsUrl)],
            ['label' => 'Jobs öffnen', 'url' => $settingsUrl]);
        $edges[] = $this->edge('tButton', 'gates', 'merge');
        $productsSubscribed = $this->value(
            "SELECT 1 FROM connect_webhook_subscriptions WHERE connection_id = :id AND topic = 'products/update'",
            ['id' => (int) $mapping['source_connection_id']]
        ) !== null;
        $nodes['tWebhook'] = $this->triggerNode(2, 'Zuträger · automatisch', 'bolt', 'Webhook products/update', 'hält nur Shopify-Daten aktuell', $productsSubscribed ? 'ok' : 'warn',
            'Ändert sich ein Produkt in Shopify, aktualisiert Connect sofort den Zwischenspeicher. Den Abgleich startet das nicht, es liefert nur frische Daten für den nächsten Lauf.',
            [$this->setting('Webhook-Abo', $productsSubscribed ? 'angelegt' : 'nicht gefunden', self::SRC_FIXED, 'wird mit der Shopify-Connection angelegt', $this->url('connections.php'), !$productsSubscribed)],
            ['label' => 'Connections öffnen', 'url' => $this->url('connections.php')], $productsSubscribed ? null : 'Ohne Abo werden Shopify-Änderungen erst beim nächsten Listen-Abruf sichtbar.');
        $edges[] = $this->edge('tWebhook', 'read', 'feed', 'frische Daten', true);

        $nodes['gates'] = $this->node(1, 0, 'check', 'Vorab-Bedingungen', 'filter', 'Abgleich eingeschaltet?', $enabled ? 'eingeschaltet' : 'ausgeschaltet', $enabled ? 'ok' : 'warn',
            'Ohne diesen Schalter läuft kein Abgleich.',
            [
                $this->setting('Automatischen Lagerbestandsabgleich aktivieren', (int) ($s['stock_sync_enabled'] ?? 0) === 1 ? 'an' : 'aus', self::SRC_SHOP, $where, $settingsUrl),
                $this->setting('Job aktiv', (int) $mapping['enabled'] === 1 ? 'ja' : 'nein', self::SRC_ACTIVATION, 'Shop → Jobs', $settingsUrl),
                $this->setting('Zielsystem (Connection)', $targetConnection !== null ? (string) $targetConnection['label'] : 'keins', self::SRC_SHOP, 'Shop → Jobs/Einstellungen → Vergleichswert & Zielsystem', $settingsUrl, $targetConnection === null),
            ], ['label' => 'Shop-Einstellungen öffnen', 'url' => $settingsUrl], $enabled ? null : 'Der Abgleich ist ausgeschaltet.');

        $locationId = (string) ($s['stock_primary_location_id'] ?? '');
        $shopifyStockField = $shopifyTypeId !== null ? $this->one(
            'SELECT f.label, o.id AS object_id FROM connect_object_fields f JOIN connect_objects o ON o.id = f.object_id WHERE o.connector_type_id = :type_id AND f.is_stock_quantity = 1 LIMIT 1',
            ['type_id' => (int) $shopifyTypeId]
        ) : null;
        $targetStockObject = $targetTypeId !== null ? $this->one(
            'SELECT o.id, o.label, o.hydrate_details_after_list, o.hydrate_batch_size, f.label AS field_label FROM connect_object_fields f JOIN connect_objects o ON o.id = f.object_id
             WHERE o.connector_type_id = :type_id AND f.is_stock_quantity = 1 LIMIT 1',
            ['type_id' => $targetTypeId]
        ) : null;
        $readStatus = $shopifyStockField !== null && $targetStockObject !== null ? 'ok' : 'err';
        $nodes['read'] = $this->node(2, 0, 'step', 'Schritt', 'db', 'Bestände bereitstellen', $locationId !== '' ? 'Shopify-Standort ' . $locationId : 'Shopify: alle Standorte', $readStatus,
            'Connect nutzt die Zwischenspeicher beider Systeme. Das Feld mit der Rolle „Lagerbestand“ liefert je System die Menge.',
            [
                $this->setting('Primärer Standort (Shopify)', $locationId !== '' ? $locationId : 'keiner – Summe aller Standorte', self::SRC_SHOP, $where . ' → „Primärer Standort“', $settingsUrl),
                $this->setting('Lagerbestand-Feld Shopify', $shopifyStockField !== null ? (string) $shopifyStockField['label'] : 'keins markiert', self::SRC_OBJECT, 'Connector Builder → Shopify → Felder → Spalte „Lagerbestand“',
                    $shopifyStockField !== null ? $this->url('object_edit.php', ['id' => (int) $shopifyStockField['object_id']], false) : $this->url('connector_types.php', [], false), $shopifyStockField === null),
                $this->setting('Lagerbestand-Feld Zielsystem', $targetStockObject !== null ? $targetStockObject['field_label'] . ' am Object „' . $targetStockObject['label'] . '“' : 'keins markiert', self::SRC_OBJECT, 'Connector Builder → Zielsystem → Felder → Spalte „Lagerbestand“',
                    $targetStockObject !== null ? $this->url('object_edit.php', ['id' => (int) $targetStockObject['id']], false) : $this->url('connector_types.php', [], false), $targetStockObject === null),
                $this->setting('Details pro Datensatz nachladen (Zielsystem)', $targetStockObject !== null ? ((int) $targetStockObject['hydrate_details_after_list'] === 1 ? 'an · Batch-Größe ' . ($targetStockObject['hydrate_batch_size'] ?? 200) : 'aus') : '–', self::SRC_OBJECT, 'Connector Builder → Object → Stammdaten',
                    $targetStockObject !== null ? $this->url('object_edit.php', ['id' => (int) $targetStockObject['id']], false) : null),
            ], ['label' => 'Shop-Einstellungen öffnen', 'url' => $settingsUrl], $readStatus === 'err' ? 'Auf mindestens einer Seite ist kein Feld als „Lagerbestand“ markiert.' : null);
        $edges[] = $this->edge('gates', 'read', 'down');

        $comparisonKey = (string) ($s['comparison_key'] ?? 'sku');
        $sourceRole = $shopifyTypeId !== null ? $this->value('SELECT 1 FROM connect_object_fields f JOIN connect_objects o ON o.id = f.object_id WHERE o.connector_type_id = :t AND f.comparison_role = :r', ['t' => (int) $shopifyTypeId, 'r' => $comparisonKey]) : null;
        $targetRole = $targetTypeId !== null ? $this->value('SELECT 1 FROM connect_object_fields f JOIN connect_objects o ON o.id = f.object_id WHERE o.connector_type_id = :t AND f.comparison_role = :r', ['t' => $targetTypeId, 'r' => $comparisonKey]) : null;
        $matchOk = $sourceRole !== null && $targetRole !== null;
        $nodes['match'] = $this->node(3, 0, 'step', 'Schritt', 'search', 'Artikel zuordnen', 'per ' . self::comparisonLabel($comparisonKey), $matchOk ? 'ok' : 'err',
            'Paare werden über den Vergleichswert des Shops gebildet. Artikel ohne Partner werden übersprungen und auf der Produkte-Seite angezeigt.',
            [
                $this->setting('Vergleichswert', self::comparisonLabel($comparisonKey), self::SRC_SHOP, 'Shop → Jobs/Einstellungen → Vergleichswert', $settingsUrl),
                $this->setting('Feld mit dieser Rolle', ($sourceRole !== null ? 'Shopify ✓' : 'Shopify fehlt') . ' · ' . ($targetRole !== null ? 'Zielsystem ✓' : 'Zielsystem fehlt'), self::SRC_OBJECT, 'Connector Builder → Felder → Spalte „Vergleichswert“', $this->url('connector_types.php', [], false), !$matchOk),
            ], ['label' => 'Produkte-Seite öffnen', 'url' => $this->url('products.php')], $matchOk ? null : 'Ohne Feld mit der Vergleichswert-Rolle auf beiden Seiten findet der Abgleich keine Paare.');
        $edges[] = $this->edge('read', 'match', 'down');

        $direction = (string) ($s['stock_sync_direction'] ?? 'shopify_to_target');
        $nodes['write'] = $this->node(4, 0, 'target', 'Ziel', 'swap', 'Bestand schreiben', $direction === 'shopify_to_target' ? 'Shopify führt → Zielsystem' : 'Zielsystem führt → Shopify', 'ok',
            'Das führende System gibt den Bestand vor, das andere wird angeglichen. Jede Änderung steht unter Betrieb → Lagerbestand.',
            [$this->setting('Richtung', $direction === 'shopify_to_target' ? '„Ich bearbeite meinen Lagerbestand in Shopify“' : '„Ich bearbeite meinen Lagerbestand im Zielsystem“', self::SRC_SHOP, $where, $settingsUrl)],
            ['label' => 'Lagerbestand-Protokoll öffnen', 'url' => $this->url('stock_overview.php', [], false)]);
        $edges[] = $this->edge('match', 'write', 'down');

        return ['key' => 'm' . (int) $mapping['id'], 'label' => 'Lagerbestand', 'rows' => 5, 'nodes' => $nodes, 'edges' => $edges];
    }

    // =========================================================================
    // Produktimport (mapping_kind=auto_import_products und/oder Button)
    // =========================================================================

    /**
     * @param array<string, mixed>|null $mapping
     * @return array<string, mixed>
     */
    private function importFlow(?array $mapping): array
    {
        $s = $this->shopRow;
        $settingsUrl = $this->url('sync_mappings.php');
        $where = 'Shop → Jobs/Einstellungen → Automatischer Produktimport (Admin)';
        $autoOn = $mapping !== null && (int) $mapping['enabled'] === 1 && (int) ($s['auto_import_missing_to_target'] ?? 0) === 1;
        $interval = $mapping !== null && $mapping['schedule_interval_minutes'] !== null ? (int) $mapping['schedule_interval_minutes'] : null;

        $targetConnection = ($s['target_connection_id'] ?? null) !== null ? $this->connection((int) $s['target_connection_id']) : null;
        $targetTypeId = $targetConnection !== null ? (int) $targetConnection['connector_type_id'] : null;
        $sourceProduct = $this->one("SELECT o.id FROM connect_objects o JOIN connect_connector_types t ON t.id = o.connector_type_id WHERE t.type_key = 'shopify' AND o.object_key = 'product' LIMIT 1");
        $template = null;
        $viaFunction = false;
        if ($sourceProduct !== null && $targetTypeId !== null) {
            $template = $this->one(
                "SELECT t.id, t.name, t.target_object_id, f.label AS function_label FROM connect_functions f JOIN connect_mapping_templates t ON t.id = f.mapping_template_id
                 WHERE f.function_kind = 'import_missing' AND t.source_object_id = :source AND t.target_connector_type_id = :target_type LIMIT 1",
                ['source' => (int) $sourceProduct['id'], 'target_type' => $targetTypeId]
            );
            $viaFunction = $template !== null;
            $template ??= $this->one(
                'SELECT id, name, target_object_id, NULL AS function_label FROM connect_mapping_templates WHERE source_object_id = :source AND target_connector_type_id = :target_type ORDER BY id DESC LIMIT 1',
                ['source' => (int) $sourceProduct['id'], 'target_type' => $targetTypeId]
            );
        }

        $nodes = [];
        $edges = [];
        $nodes['tSchedule'] = $this->triggerNode(0, 'Auslöser · automatisch', 'clock', 'Zeitplan', $autoOn ? 'alle ' . ($interval ?? 60) . ' Minuten · Admin' : 'ausgeschaltet', $autoOn ? 'ok' : 'opt',
            'Legt fehlende Produkte automatisch im Zielsystem an. Nur Admins können das einschalten.',
            [
                $this->setting('Fehlende Produkte automatisch im Zielsystem anlegen', (int) ($s['auto_import_missing_to_target'] ?? 0) === 1 ? 'an' : 'aus', self::SRC_SHOP, $where, $settingsUrl),
                $this->setting('Intervall', $interval !== null ? $interval . ' Minuten' : '–', self::SRC_ACTIVATION, 'Shop → Jobs', $settingsUrl),
            ], ['label' => 'Shop-Einstellungen öffnen', 'url' => $settingsUrl], null, !$autoOn);
        $edges[] = $this->edge('tSchedule', 'gates', 'merge', null, false, !$autoOn);
        $nodes['tButton'] = $this->triggerNode(1, 'Auslöser · Button (Funktion)', 'hand', 'Importieren', 'Produkte-Seite', $template !== null ? ($viaFunction ? 'ok' : 'warn') : 'err',
            'Auf der Produkte-Seite wählt man fehlende Produkte aus und klickt „Importieren“. Welche Vorlage verwendet wird, legt die Funktion „Fehlendes Produkt anlegen (Importieren)“ fest.',
            [
                $this->setting('Funktion', $viaFunction ? '„' . $template['function_label'] . '“ → Vorlage „' . $template['name'] . '“' : ($template !== null ? 'keine – es gilt die neueste passende Vorlage „' . $template['name'] . '“' : 'keine Vorlage'), self::SRC_FUNCTION, 'Konfiguration → Funktionen', $this->url('functions.php', [], false), !$viaFunction),
                $this->setting('Wo', 'Shop → Produkte → „Fehlt im Zielsystem“ → Importieren', self::SRC_FIXED, null, $this->url('products.php')),
            ], ['label' => 'Funktionen öffnen', 'url' => $this->url('functions.php', [], false)],
            $template === null ? 'Es gibt keine Vorlage Shopify-Produkt → Zielsystem.' : (!$viaFunction ? 'Keine Funktion festgelegt, Connect nimmt die neueste passende Vorlage.' : null));
        $edges[] = $this->edge('tButton', 'find', 'feed', 'ausgewählte Produkte', true);

        $filterKey = (string) ($s['auto_import_filter_field_key'] ?? '');
        $filterValue = (string) ($s['auto_import_filter_value'] ?? '');
        $nodes['gates'] = $this->node(1, 0, 'check', 'Vorab-Bedingungen', 'filter', 'Welche Produkte?', $filterKey !== '' ? 'Filter: ' . $filterKey . ' enthält „' . $filterValue . '“' : 'kein Filter', 'ok',
            'Beim automatischen Import kann ein Filter die Produkte eingrenzen. Der Button auf der Produkte-Seite nimmt immer die ausgewählten Produkte.',
            [$this->setting('Filter (optional)', $filterKey !== '' ? $filterKey . ' enthält „' . $filterValue . '“' : 'kein Filter', self::SRC_SHOP, $where . ' → „Filter (optional)“', $settingsUrl)],
            ['label' => 'Shop-Einstellungen öffnen', 'url' => $settingsUrl], null, !$autoOn);

        $variantKey = $s['variant_comparison_key'] ?? null;
        $nodes['find'] = $this->node(2, 0, 'step', 'Schritt', 'search', 'Fehlende Produkte finden', 'per ' . self::comparisonLabel((string) ($s['comparison_key'] ?? 'sku')), 'ok',
            'Produkte ohne Partner im Zielsystem gelten als fehlend. Varianten werden über einen eigenen Vergleichswert zugeordnet.',
            [
                $this->setting('Vergleichswert', self::comparisonLabel((string) ($s['comparison_key'] ?? 'sku')), self::SRC_SHOP, 'Shop → Jobs/Einstellungen → Vergleichswert', $settingsUrl),
                $this->setting('Varianten-Vergleichswert', $variantKey !== null ? self::comparisonLabel((string) $variantKey) : 'keiner', self::SRC_SHOP, 'Shop → Jobs/Einstellungen → Varianten-Import', $settingsUrl),
                $this->setting('Varianten-Option', (string) ($s['variant_option_name'] ?? 'Option'), self::SRC_SHOP, 'Shop → Jobs/Einstellungen → Varianten-Import', $settingsUrl),
            ], ['label' => 'Produkte-Seite öffnen', 'url' => $this->url('products.php')]);
        $edges[] = $this->edge('gates', 'find', 'down', null, false, !$autoOn);

        $mappingSettings = [];
        $manualCount = 0;
        $mappingCount = 0;
        if ($template !== null) {
            $rows = $this->all('SELECT transform_type, transform_config FROM connect_field_mappings WHERE mapping_template_id = :id', ['id' => (int) $template['id']]);
            $mappingCount = count($rows);
            $mappingSettings[] = $this->setting('Vorlage', $template['name'] . ' · ' . $mappingCount . ' Felder', $viaFunction ? self::SRC_FUNCTION : self::SRC_TEMPLATE, 'Konfiguration → Mapping-Vorlagen', $this->url('mapping_template_edit.php', ['id' => (int) $template['id']], false));
            foreach ($rows as $row) {
                if ($row['transform_type'] !== 'manual_input') {
                    continue;
                }
                $manualCount++;
                $config = $row['transform_config'] !== null ? (json_decode((string) $row['transform_config'], true) ?: []) : [];
                $mappingSettings[] = $this->setting('Eingabe „' . ($config['label'] ?? 'manuell') . '“', ($config['default_source'] ?? '') === 'default_tax_rate' ? 'vorbelegt mit Standard-Steuersatz' : 'ohne Vorbelegung',
                    self::SRC_TEMPLATE, 'Mapping-Vorlage → Umrechnung „Manuelle Eingabe beim Import“', $this->url('mapping_template_edit.php', ['id' => (int) $template['id']], false), ($config['default_source'] ?? '') === '' && $autoOn);
            }
        }
        $taxRate = $s['default_tax_rate'] ?? null;
        $mappingSettings[] = $this->setting('Standard-Steuersatz', $taxRate !== null ? rtrim(rtrim((string) $taxRate, '0'), '.') . ' %' : 'nicht gesetzt', self::SRC_SHOP, 'Shop → Jobs/Einstellungen → Preise (Netto/Brutto)', $settingsUrl, $taxRate === null && $manualCount > 0);
        $mappingSettings[] = $this->setting('Preise in Shopify', ($s['shopify_prices_include_tax'] ?? null) === null || (int) $s['shopify_prices_include_tax'] === 1 ? 'Brutto' : 'Netto', self::SRC_SHOP, 'Shop → Jobs/Einstellungen → Preise (Netto/Brutto)', $settingsUrl);
        $nodes['mapping'] = $this->node(3, 0, 'step', 'Schritt', 'columns', 'Felder zuordnen', $template !== null ? 'Vorlage „' . $template['name'] . '“' : 'keine Vorlage', $template === null ? 'err' : ($mappingCount === 0 ? 'err' : 'ok'),
            'Baut den neuen Artikel im Zielsystem. Werte, die Shopify nicht kennt (z. B. Steuersatz), kommen aus einer Eingabe, optional vorbelegt aus den Shop-Einstellungen.',
            $mappingSettings, ['label' => 'Vorlage öffnen', 'url' => $template !== null ? $this->url('mapping_template_edit.php', ['id' => (int) $template['id']], false) : $this->url('mapping_templates.php', [], false)],
            $template === null ? 'Keine Vorlage Shopify-Produkt → Zielsystem vorhanden.' : null);
        $edges[] = $this->edge('find', 'mapping', 'down');

        $targetObject = $template !== null ? $this->object((int) $template['target_object_id']) : null;
        $createOp = $targetObject !== null ? $this->value("SELECT 1 FROM connect_object_operations WHERE object_id = :id AND operation_type = 'create'", ['id' => (int) $targetObject['id']]) : null;
        $writeSettings = [
            $this->setting('Operation „Anlegen“', $createOp !== null ? 'vorhanden' : 'fehlt', self::SRC_OBJECT, 'Connector Builder → Ziel-Object → Operationen',
                $targetObject !== null ? $this->url('object_edit.php', ['id' => (int) $targetObject['id']], false) : null, $createOp === null),
        ];
        if ($targetObject !== null) {
            foreach ($this->all('SELECT field_key, max_length FROM connect_object_fields WHERE object_id = :id AND max_length IS NOT NULL', ['id' => (int) $targetObject['id']]) as $field) {
                $writeSettings[] = $this->setting('Max. Länge ' . $field['field_key'], $field['max_length'] . ' Zeichen (zu lang = Import bricht ab)', self::SRC_OBJECT, 'Connector Builder → ' . $targetObject['label'] . ' → Felder', $this->url('object_edit.php', ['id' => (int) $targetObject['id']], false));
            }
        }
        $nodes['write'] = $this->node(4, 0, 'target', 'Ziel', 'doc', ($targetObject['label'] ?? 'Artikel') . ' anlegen', $targetObject !== null ? (string) $targetObject['type_name'] : 'kein Ziel-Object', $createOp !== null ? 'ok' : 'err',
            'Legt den Artikel an und trägt ihn sofort im Zwischenspeicher ein, damit er auf der Produkte-Seite nicht mehr als fehlend erscheint.',
            $writeSettings, $targetObject !== null ? ['label' => 'Object „' . $targetObject['label'] . '“ öffnen', 'url' => $this->url('object_edit.php', ['id' => (int) $targetObject['id']], false)] : null,
            $createOp === null ? 'Das Ziel-Object hat keine Operation „Anlegen“.' : null);
        $edges[] = $this->edge('mapping', 'write', 'down');

        return ['key' => $mapping !== null ? 'm' . (int) $mapping['id'] : 'import', 'label' => 'Produktimport', 'rows' => 5, 'nodes' => $nodes, 'edges' => $edges];
    }

    // =========================================================================
    // Ausverkaufte Produkte ausblenden (mapping_kind=product_visibility)
    // Spiegelt Connect\Sync\ProductVisibilityRunner -- bei Logikaenderungen dort mitziehen.
    // =========================================================================

    /**
     * @param array<string, mixed> $mapping
     * @return array<string, mixed>
     */
    private function visibilityFlow(array $mapping): array
    {
        $s = $this->shopRow;
        $settingsUrl = $this->url('sync_mappings.php');
        $where = 'Shop → Jobs/Einstellungen → Ausverkaufte Produkte ausblenden (Admin)';
        $on = (int) $mapping['enabled'] === 1 && (int) ($s['visibility_sync_enabled'] ?? 0) === 1;
        $interval = $mapping['schedule_interval_minutes'] !== null ? (int) $mapping['schedule_interval_minutes'] : 60;
        $mode = (string) ($s['visibility_reactivate_mode'] ?? 'own');
        $modeLabels = ['own' => 'nur von Connect ausgeblendete', 'all' => 'alle Entwürfe mit Bestand', 'never' => 'nie'];
        $yesNo = static fn (string $key): string => (int) ($s[$key] ?? 0) === 1 ? 'ja' : 'nein';

        $product = $this->one("SELECT o.id FROM connect_objects o JOIN connect_connector_types t ON t.id = o.connector_type_id WHERE t.type_key = 'shopify' AND o.object_key = 'product' LIMIT 1");
        $productUrl = $product !== null ? $this->url('object_edit.php', ['id' => (int) $product['id']], false) : null;
        $setStatusOp = $product !== null ? $this->value("SELECT 1 FROM connect_object_operations WHERE object_id = :id AND operation_type = 'set_status'", ['id' => (int) $product['id']]) : null;
        $descriptionField = $product !== null ? $this->value("SELECT 1 FROM connect_object_fields WHERE object_id = :id AND field_key = 'product_description_start'", ['id' => (int) $product['id']]) : null;
        $needsDescription = (int) ($s['visibility_require_description'] ?? 0) === 1 && $mode !== 'never';

        $nodes = [];
        $edges = [];
        $nodes['tSchedule'] = $this->triggerNode(0, 'Auslöser · automatisch', 'clock', 'Zeitplan', $on ? 'alle ' . $interval . ' Minuten · Admin' : 'ausgeschaltet', $on ? 'ok' : 'opt',
            'Prüft den Lagerbestand der Shopify-Produkte im Zwischenspeicher. Nur Admins können das einschalten, schreibt nur bei Shops, die in Connect live sind.',
            [
                $this->setting('Ausverkaufte Produkte ausblenden / wieder einblenden', $on ? 'an' : 'aus', self::SRC_SHOP, $where, $settingsUrl),
                $this->setting('Intervall', $interval . ' Minuten', self::SRC_ACTIVATION, 'Shop → Jobs', $settingsUrl),
            ], ['label' => 'Shop-Einstellungen öffnen', 'url' => $settingsUrl], null, !$on);

        $nodes['gates'] = $this->node(1, 0, 'check', 'Bedingungen', 'filter', 'Welche Produkte?', 'ausverkauft = alle Varianten ≤ 0', 'ok',
            'Ausgeblendet wird ein aktives Produkt, dessen Varianten alle keinen Bestand haben. Aktiviert wird ein Entwurf, sobald eine Variante Bestand hat. Archivierte Produkte bleiben unberührt.',
            [
                $this->setting('Nur im Zielsystem zugeordnete Produkte', $yesNo('visibility_only_matched'), self::SRC_SHOP, $where, $settingsUrl),
                $this->setting('Nur Produkte mit einer Variante ausblenden', $yesNo('visibility_single_variant_only'), self::SRC_SHOP, $where, $settingsUrl),
                $this->setting('Nur mit Beschreibung wieder aktivieren', $yesNo('visibility_require_description'), self::SRC_SHOP, $where, $settingsUrl),
                $this->setting('Wieder aktivieren', $modeLabels[$mode] ?? $mode, self::SRC_SHOP, $where, $settingsUrl),
                $this->setting('Feld „Beschreibung (Anfang)“', $descriptionField !== null ? 'vorhanden' : 'fehlt', self::SRC_OBJECT, 'Connector Builder → Shopify Produkt → Felder', $productUrl, $needsDescription && $descriptionField === null),
            ], ['label' => 'Shop-Einstellungen öffnen', 'url' => $settingsUrl],
            $needsDescription && $descriptionField === null ? 'Ohne das Feld „Beschreibung (Anfang)“ kann „nur mit Beschreibung aktivieren“ nicht geprüft werden.' : null, !$on);
        $edges[] = $this->edge('tSchedule', 'gates', 'down', null, false, !$on);

        $nodes['write'] = $this->node(2, 0, 'target', 'Ziel', 'doc', 'Shopify-Status setzen', 'Entwurf ↔ aktiv · max. 20 je Richtung', $setStatusOp !== null ? 'ok' : 'err',
            'Setzt den Produktstatus per productUpdate und trägt die Änderung sofort im Zwischenspeicher und im Protokoll ein.',
            [$this->setting('Operation „set_status“', $setStatusOp !== null ? 'vorhanden' : 'fehlt (Migration 0073)', self::SRC_OBJECT, 'Connector Builder → Shopify Produkt → Operationen', $productUrl, $setStatusOp === null)],
            $productUrl !== null ? ['label' => 'Object „Produkt“ öffnen', 'url' => $productUrl] : null,
            $setStatusOp === null ? 'Dem Shopify-Produkt fehlt die Operation „set_status“.' : null, !$on);
        $edges[] = $this->edge('gates', 'write', 'down', null, false, !$on);

        return ['key' => 'm' . (int) $mapping['id'], 'label' => 'Produkte ausblenden', 'rows' => 3, 'nodes' => $nodes, 'edges' => $edges];
    }

    // =========================================================================
    // Allgemeine Uebersetzung (Vorlage ohne "Bestellung (aufbereitet)")
    // =========================================================================

    /**
     * @param array<string, mixed> $mapping
     * @return array<string, mixed>
     */
    private function translationFlow(array $mapping, int $number): array
    {
        $activationUrl = $this->url('mapping_edit.php', ['id' => (int) $mapping['id']]);
        $nodes = [];
        $edges = [];
        $this->addTriggers($nodes, $edges, $mapping, 'gates', $activationUrl);
        $enabled = (int) $mapping['enabled'] === 1;
        $sourceObject = $this->object((int) $mapping['source_object_id']);
        $nodes['gates'] = $this->node(1, 0, 'check', 'Vorab-Bedingungen', 'filter', 'Läuft der Ablauf?', $enabled ? 'Aktivierung aktiv' : 'Aktivierung inaktiv', $enabled ? 'ok' : 'warn',
            'Ist die Aktivierung aus, passiert nichts. Beim Zeitplan-Nachlauf werden Bestellungen nur verarbeitet, wenn sie bezahlt sind.',
            [
                $this->setting('Aktivierung', $enabled ? 'aktiv' : 'inaktiv', self::SRC_ACTIVATION, 'Shop → Mappings', $this->url('mappings.php')),
                $this->setting('Quelle', ($sourceObject['type_name'] ?? '?') . ' · ' . ($sourceObject['label'] ?? '?'), self::SRC_ACTIVATION, 'Shop → Mappings', $this->url('mappings.php')),
                $this->setting('Doppelt-Schutz', 'jeder Datensatz wird nur einmal übersetzt', self::SRC_FIXED, null),
            ], ['label' => 'Aktivierung öffnen', 'url' => $activationUrl], $enabled ? null : 'Die Aktivierung ist ausgeschaltet.');

        $hasTemplate = $mapping['mapping_template_id'] !== null;
        if ($hasTemplate) {
            $targetObject = $this->object((int) $mapping['template_target_object_id']);
            $nodes['mapping'] = $this->mappingNode($mapping, $targetObject, false, $activationUrl);
            $nodes['mapping']['row'] = 2;
            $createOp = $this->one("SELECT http_method, path_template FROM connect_object_operations WHERE object_id = :id AND operation_type = 'create' LIMIT 1", ['id' => (int) $targetObject['id']]);
            $objectUrl = $this->url('object_edit.php', ['id' => (int) $targetObject['id']], false);
            $nodes['target'] = $this->node(3, 0, 'target', 'Ziel', 'doc', $targetObject['label'] . ' anlegen', $targetObject['type_name'] . ($createOp !== null ? ' · ' . $createOp['http_method'] . ' ' . $createOp['path_template'] : ''), $createOp !== null ? 'ok' : 'err',
                'Schreibt den übersetzten Datensatz ins Zielsystem. Rabatte, Steuern und Kontrollen gibt es hier nicht – dafür eine Vorlage mit Quelle „Bestellung (aufbereitet)“ verwenden.',
                [$this->setting('Operation „Anlegen“', $createOp !== null ? $createOp['http_method'] . ' ' . $createOp['path_template'] : 'fehlt', self::SRC_OBJECT, 'Connector Builder → ' . $targetObject['label'] . ' → Operationen', $objectUrl, $createOp === null)],
                ['label' => 'Object öffnen', 'url' => $objectUrl], $createOp === null ? 'Das Ziel-Object hat keine Operation „Anlegen“.' : null);
            $edges[] = $this->edge('gates', 'mapping', 'down');
            $edges[] = $this->edge('mapping', 'target', 'down');
        } else {
            $nodes['mapping'] = $this->node(2, 0, 'step', 'Schritt', 'columns', 'Felder zuordnen', 'keine Vorlage', 'err',
                'Ohne Vorlage gibt es keine Feldzuordnungen, die Aktivierung kann nicht laufen.',
                [$this->setting('Vorlage', 'gelöscht', self::SRC_ACTIVATION, 'Shop → Mappings → Details / Testen', $activationUrl, true)],
                ['label' => 'Aktivierung öffnen', 'url' => $activationUrl], 'Die Vorlage dieser Aktivierung wurde gelöscht.');
            $edges[] = $this->edge('gates', 'mapping', 'down');
        }

        return ['key' => 'm' . (int) $mapping['id'], 'label' => (string) ($mapping['template_name'] ?? $mapping['name'] ?? 'Übersetzung ' . $number), 'rows' => 4, 'nodes' => $nodes, 'edges' => $edges];
    }

    // =========================================================================
    // Reine Zwischenspeicher-Jobs (kein Ziel)
    // =========================================================================

    /**
     * @param list<array<string, mixed>> $jobs
     * @return array<string, mixed>
     */
    private function cacheJobsFlow(array $jobs): array
    {
        $nodes = [];
        $edges = [];
        $settingsUrl = $this->url('sync_mappings.php');
        foreach (array_values($jobs) as $i => $job) {
            $object = $this->object((int) $job['source_object_id']);
            $connection = $this->connection((int) $job['source_connection_id']);
            $enabled = (int) $job['enabled'] === 1;
            $interval = $job['schedule_interval_minutes'] !== null ? (int) $job['schedule_interval_minutes'] : null;
            $objectUrl = $this->url('object_edit.php', ['id' => (int) $object['id']], false);
            $readOp = $this->value("SELECT 1 FROM connect_object_operations WHERE object_id = :id AND operation_type = 'read_list'", ['id' => (int) $object['id']]);

            $nodes['t' . $i] = $this->node($i, 0, 'trigger', 'Auslöser', 'clock', $interval !== null ? 'Alle ' . $interval . ' Minuten' : 'Nur per Button', $enabled ? 'aktiv · auch „Jetzt ausführen“' : 'inaktiv', $enabled ? 'ok' : 'opt',
                'Der Scheduler startet den Job im eingestellten Abstand. „Jetzt ausführen“ in Shop → Jobs startet ihn sofort.',
                [
                    $this->setting('Job', (string) $job['name'], self::SRC_ACTIVATION, 'Shop → Jobs', $settingsUrl),
                    $this->setting('Intervall', $interval !== null ? $interval . ' Minuten' : 'keins', self::SRC_ACTIVATION, 'Shop → Jobs', $settingsUrl),
                    $this->setting('Aktiv', $enabled ? 'ja' : 'nein', self::SRC_ACTIVATION, 'Shop → Jobs', $settingsUrl),
                ], ['label' => 'Jobs öffnen', 'url' => $settingsUrl], null, !$enabled);

            $settings = [
                $this->setting('Connection', ($connection['label'] ?? '?'), self::SRC_ACTIVATION, 'Shop → Connections', $this->url('connections.php')),
                $this->setting('Operation „Lesen (Liste)“', $readOp !== null ? 'vorhanden' : 'fehlt', self::SRC_OBJECT, 'Connector Builder → ' . $object['label'] . ' → Operationen', $objectUrl, $readOp === null),
                $this->setting('Details pro Datensatz nachladen', (int) $object['hydrate_details_after_list'] === 1 ? 'an · Batch-Größe ' . ($object['hydrate_batch_size'] ?? 200) : 'aus', self::SRC_OBJECT, 'Connector Builder → ' . $object['label'] . ' → Stammdaten', $objectUrl),
            ];
            if (($object['max_age_days'] ?? null) !== null) {
                $settings[] = $this->setting('Zeitfenster', 'letzte ' . $object['max_age_days'] . ' Tage', self::SRC_OBJECT, 'nur per Migration änderbar', null);
            }
            if (($this->shopRow['stock_primary_location_id'] ?? null) !== null && ($object['type_key'] ?? '') === 'shopify') {
                $settings[] = $this->setting('Standort-Filter', (string) $this->shopRow['stock_primary_location_id'], self::SRC_SHOP, 'Shop → Jobs/Einstellungen → Lagerbestand → „Primärer Standort“', $settingsUrl);
            }
            $nodes['o' . $i] = $this->node($i, 1, 'step', 'Zwischenspeicher', 'db', $object['type_name'] . ' · ' . $object['label'], 'Liste lesen' . ((int) $object['hydrate_details_after_list'] === 1 ? ' + Details' : ''), $readOp !== null ? 'ok' : 'err',
                'Liest die Datensätze und legt sie im Zwischenspeicher ab. Andere Abläufe (Nachlauf, Produkte-Seite, Lagerbestand) arbeiten mit diesen Daten.',
                $settings, ['label' => 'Object öffnen', 'url' => $objectUrl], $readOp === null ? 'Das Object hat keine Operation „Lesen (Liste)“.' : null, !$enabled);
            $edges[] = $this->edge('t' . $i, 'o' . $i, 'side', null, false, !$enabled);
        }

        return ['key' => 'cache', 'label' => 'Daten-Jobs', 'rows' => count($jobs), 'compact' => true, 'nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * @return array<string, mixed>
     */
    private function errorFlow(string $label, string $message): array
    {
        return ['key' => 'err' . md5($label), 'label' => $label, 'rows' => 1, 'nodes' => [
            'error' => $this->node(0, 0, 'end', 'Fehler', 'stop', 'Ablauf konnte nicht gelesen werden', $message, 'err', 'Beim Zusammenstellen dieses Ablaufs ist ein Fehler aufgetreten: ' . $message, [], null, $message),
        ], 'edges' => []];
    }

    // =========================================================================
    // Hilfsfunktionen
    // =========================================================================

    private function usesPreparedOrder(mixed $templateId): bool
    {
        if ($templateId === null) {
            return false;
        }

        return $this->value(
            "SELECT 1 FROM connect_mapping_templates t JOIN connect_objects o ON o.id = t.source_object_id JOIN connect_connector_types ct ON ct.id = o.connector_type_id
             WHERE t.id = :id AND ct.type_key = 'connect_internal' AND o.object_key = 'order_prepared'",
            ['id' => (int) $templateId]
        ) !== null;
    }

    /**
     * Reiner Zwischenspeicher-Job (kein Ziel) fuer dieselbe Quelle -- Voraussetzung
     * fuer den Zeitplan-Nachlauf, siehe SyncRunner::runScheduledTranslationBatch().
     *
     * @return array<string, mixed>|null
     */
    private function cacheJobFor(int $connectionId, int $objectId): ?array
    {
        return $this->one(
            "SELECT id, name, enabled FROM connect_sync_mappings
             WHERE shop = :shop AND source_connection_id = :connection_id AND source_object_id = :object_id
               AND target_connection_id IS NULL AND mapping_kind = 'default'
             ORDER BY enabled DESC LIMIT 1",
            ['shop' => $this->shop, 'connection_id' => $connectionId, 'object_id' => $objectId]
        );
    }

    private function fieldMappingCount(int $templateId): int
    {
        return (int) $this->value('SELECT COUNT(*) FROM connect_field_mappings WHERE mapping_template_id = :id', ['id' => $templateId]);
    }

    /**
     * @return list<array{id: int, name: string, entries: int}>
     */
    private function lookupsOfTemplate(int $templateId): array
    {
        $ids = [];
        foreach ($this->all("SELECT transform_config FROM connect_field_mappings WHERE mapping_template_id = :id AND transform_type = 'lookup'", ['id' => $templateId]) as $row) {
            $config = $row['transform_config'] !== null ? (json_decode((string) $row['transform_config'], true) ?: []) : [];
            if ((int) ($config['lookup_table_id'] ?? 0) > 0) {
                $ids[(int) $config['lookup_table_id']] = true;
            }
        }
        $result = [];
        foreach (array_keys($ids) as $id) {
            $table = $this->one('SELECT id, name, (SELECT COUNT(*) FROM connect_lookup_table_entries e WHERE e.lookup_table_id = l.id) AS entries FROM connect_lookup_tables l WHERE l.id = :id', ['id' => $id]);
            if ($table !== null) {
                $result[] = ['id' => (int) $table['id'], 'name' => (string) $table['name'], 'entries' => (int) $table['entries']];
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function object(int $id): array
    {
        if (!isset($this->objectCache[$id])) {
            $this->objectCache[$id] = $this->one(
                'SELECT o.*, t.type_key, t.name AS type_name FROM connect_objects o JOIN connect_connector_types t ON t.id = o.connector_type_id WHERE o.id = :id',
                ['id' => $id]
            ) ?? ['id' => $id, 'label' => 'Object #' . $id, 'type_name' => '?', 'type_key' => '', 'hydrate_details_after_list' => 0, 'hydrate_batch_size' => null];
        }

        return $this->objectCache[$id];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function connection(int $id): ?array
    {
        return $this->one('SELECT id, label, connector_type_id FROM connect_connections WHERE id = :id AND shop = :shop', ['id' => $id, 'shop' => $this->shop]);
    }

    private static function comparisonLabel(string $key): string
    {
        return ['sku' => 'SKU', 'title' => 'Produktname', 'barcode' => 'Barcode'][$key] ?? $key;
    }

    /**
     * Seiten unter admin/ mit Shop-Bezug bekommen ?shop=..., allgemeine
     * Konfigurationsseiten (Connector Builder, Vorlagen, Funktionen) nicht.
     *
     * @param array<string, int|string> $params
     */
    private function url(string $page, array $params = [], bool $withShop = true): string
    {
        if ($withShop) {
            $params['shop'] = $this->shop;
        }

        return $page . ($params !== [] ? '?' . http_build_query($params) : '');
    }

    /**
     * @return array<string, mixed>
     */
    private function setting(string $name, string $value, string $src, ?string $where, ?string $url = null, bool $warn = false): array
    {
        return ['name' => $name, 'value' => $value, 'src' => $src, 'where' => $where, 'url' => $url, 'warn' => $warn];
    }

    /**
     * @param list<array<string, mixed>> $settings
     * @param array{label: string, url: ?string}|null $open
     * @return array<string, mixed>
     */
    private function node(int $row, int $col, string $kind, string $kindLabel, string $icon, string $title, string $sub, string $status, string $what, array $settings, ?array $open, ?string $note = null, bool $off = false): array
    {
        return ['lane' => 'main', 'slot' => 0, 'row' => $row, 'col' => $col, 'kind' => $kind, 'kindLabel' => $kindLabel, 'icon' => $icon, 'title' => $title, 'sub' => $sub,
            'status' => $status, 'note' => $note, 'off' => $off, 'what' => $what, 'settings' => $settings, 'open' => $open];
    }

    /**
     * @param list<array<string, mixed>> $settings
     * @param array{label: string, url: ?string}|null $open
     * @return array<string, mixed>
     */
    private function triggerNode(int $slot, string $kindLabel, string $icon, string $title, string $sub, string $status, string $what, array $settings, ?array $open, ?string $note = null, bool $off = false): array
    {
        $node = $this->node(0, 0, 'trigger', $kindLabel, $icon, $title, $sub, $status, $what, $settings, $open, $note, $off);
        $node['lane'] = 'trigger';
        $node['slot'] = $slot;

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private function edge(string $from, string $to, string $type, ?string $label = null, bool $alt = false, bool $off = false): array
    {
        return ['from' => $from, 'to' => $to, 'type' => $type, 'label' => $label, 'alt' => $alt, 'off' => $off];
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function all(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function one(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function value(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();

        return $value !== false ? $value : null;
    }
}
