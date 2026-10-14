<?php

declare(strict_types=1);

namespace Connect\Sync;

use Connect\Connector\ConnectorFactory;
use Connect\Connector\Generic\GenericHttpConnector;
use Connect\Connector\ObjectDefinition;
use Connect\Mapping\MappingEngine;
use PDO;

/**
 * "Importieren"-Button auf der Merchant-Seite "Produkte"
 * (connect/public/app/_products_body.php): legt EIN Produkt, das nur auf
 * einer Seite existiert (Status missing_on_shopify/missing_on_target aus
 * Connect\Sync\ProductComparisonService), gezielt auf der fehlenden Seite an.
 *
 * ZWEI GRUNDVERSCHIEDENE WEGE, je nach Richtung:
 *
 *   - Import NACH Shopify (missingOn='shopify', siehe importIntoShopify()):
 *     die ORCHESTRIERUNG (bestehendes Produkt erweitern vs. neu anlegen, die
 *     Zwei-Schritt-Sequenz create_product -> create_variant mit Strategie
 *     REMOVE_STANDALONE_VARIANT, Migration 0031/0039/0040) ist FEST verdrahtet
 *     -- eine einzelne, frei konfigurierbare create-Mutation koennte das nicht
 *     zuverlaessig leisten (Shopify braucht dafuer zwingend diese Reihenfolge).
 *     Die FELD-WERTE (Titel/SKU/Barcode/Preis) dagegen sind admin-
 *     konfigurierbar -- optional, ueber eine ganz normale, GLOBALE
 *     Mapping-Vorlage (admin/mapping_templates.php, dieselbe Maschinerie wie
 *     bei MGM: Quell-Object = Zielsystem [z.B. HelloCash "Artikel"], Ziel-
 *     Object = Shopify "product"). Bewusst OHNE zusaetzlichen Aktivierungs-
 *     Schritt je Shop (anders als bei MGM/admin/mappings.php) -- siehe
 *     resolveConfiguredFieldValues(): sobald die Vorlage existiert, gilt sie
 *     sofort fuer JEDEN Shop mit diesem Connector-Type-Paar, ohne dass ein
 *     Admin sie pro Shop einzeln aktivieren muesste. Existiert (noch) keine
 *     Vorlage, greift automatisch der einfache Standard: die im Connector
 *     Builder markierten Vergleichswert-Rollen (title/sku/barcode/price) --
 *     Import funktioniert also auch OHNE jede Konfiguration ("kommt sofort
 *     lauffaehig"), eine Vorlage ist nur fuer mehr Kontrolle (Transformationen,
 *     feste Werte, Lookup-Tabellen) noetig.
 *     Ohne vom Merchant eingegebenen Varianten-Options-Wert wird Shopifys
 *     eigene Standard-Konvention genutzt (Option "Title" = "Default Title")
 *     -- fuer den Merchant ein ganz normales, varianten-loses Produkt.
 *
 *   - Import NACH einem anderen (nicht-Shopify) Zielsystem
 *     (missingOn='target', siehe importIntoTarget()): nutzt dieselbe Mapping
 *     Engine, die bereits fuer Bestellung->Auftrag/Rechnung existiert
 *     (Connect\Mapping\MappingEngine) -- ABER bewusst NICHT ueber
 *     Connect\Sync\SyncRunner/eine "Aktivierung" (admin/mappings.php): das
 *     ist dieselbe Ueberlegung wie beim Shopify-Weg oben -- auch dieser
 *     Import laeuft ausschliesslich durch einen gezielten Merchant-Klick, nie
 *     automatisch (kein Webhook, kein Zeitplan), eine Aktivierung mit
 *     Connections/Ausloeser waere hier reine Konfigurations-Last ohne
 *     Nutzen. Die Mapping-Vorlage (admin/mapping_templates.php) wird
 *     stattdessen -- genau wie bei importIntoShopify() -- direkt ueber das
 *     Objektpaar aufgeloest, global, ohne Aktivierungsschritt. Anders als
 *     beim Shopify-Weg gibt es hier aber KEINEN Vergleichswert-Rollen-
 *     Fallback: eine Vorlage ist hier ZWINGEND (kein "kommt sofort
 *     lauffaehig") -- ein beliebiges REST-Zielsystem kann eigene
 *     Pflichtfelder verlangen (z.B. HelloCash "tax_rate"), die sich nicht
 *     generisch aus vier festen Rollen ableiten lassen.
 *
 * Fuer beide Wege gilt: die Konfiguration bleibt bewusst folgenlos fuer
 * automatische Laeufe -- diese Klasse wird ausschliesslich durch einen
 * gezielten Merchant-Klick ausgeloest (siehe _products_body.php), nie
 * automatisch im Hintergrund. Der Nutzer wollte ausdruecklich gezielt
 * einzelne Produkte auswaehlen, keinen automatischen Bulk-Import (siehe
 * connect/HANDOFF.md).
 */
final class ProductImportService
{
    /**
     * Registry moeglicher Vorbelegungs-Quellen fuer transform_type=
     * 'manual_input'-Zielfelder (siehe MappingEngine, admin/
     * mapping_template_edit.php Dropdown "Vorbelegung aus Einstellung") --
     * Schluessel = gespeicherter Wert in transform_config.default_source,
     * 'column' = Spalte in connect_shops, 'label' = Anzeigetext im Dropdown.
     * EIN Ort fuer diese Zuordnung, damit Admin-UI und Auswertung
     * (manualInputFieldsForTargetImport()) nicht auseinanderlaufen koennen.
     * Neue Shop-Einstellungen, die sinnvoll vorbelegen koennen (z.B. spaeter
     * eine Standard-Waehrung o.ae.), kommen hier als weiterer Eintrag dazu.
     */
    public const SHOP_SETTING_DEFAULT_SOURCES = [
        'default_tax_rate' => ['column' => 'default_tax_rate', 'label' => 'Standard-Steuersatz (aus Einstellungen)'],
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ConnectorFactory $connectorFactory
    ) {}

    /**
     * @param string $missingOn 'shopify' | 'target' -- welche Seite das
     *        Produkt NICHT hat (siehe ProductComparisonService-Zeilenstatus
     *        missing_on_shopify/missing_on_target)
     * @param ?string $variantOptionValue Nur fuer den Import NACH Shopify:
     *        Wert der neuen Shopify-Variantenoption (z.B. Groesse/Farbe),
     *        VOM MERCHANT VON HAND eingegeben (Produkte-Seite) -- es gibt
     *        dafuer bewusst KEIN Feld im Connector Builder mehr, da
     *        varianten-lose Systeme wie HelloCash keinen eigenen
     *        strukturierten Options-Wert kennen (genau wie im Altsystem,
     *        siehe manual.php "Varianten Option"-Spalte: frei editierbar,
     *        keine Datenquelle). Leer/null -- Varianten-Zweig greift nicht,
     *        normaler Ablauf (neues, eigenstaendiges Produkt).
     * @return array{success: bool, message: string}
     */
    /**
     * @param array<string, string> $manualFieldValues field_key => vom Merchant
     *        eingegebener Wert (siehe transform_type='manual_input',
     *        Connect\Mapping\MappingEngine) -- nur fuer die Richtung
     *        missingOn='target' relevant (importIntoShopify() hat eine fest
     *        verdrahtete Orchestrierung mit eigenem Feld-Set, siehe dortigen
     *        Klassenkommentar, kein Platz fuer beliebige Zusatzfelder).
     * @param array<string, string> $fieldOverrides comparison_role
     *        ('title'|'sku'|'barcode'|'price') => vom Merchant auf der
     *        Produkte-Seite VOR dem Import direkt angepasster Wert (siehe
     *        _products_body.php, analog zum Altsystem: alle Felder ausser
     *        dem gewaehlten Vergleichswert sind dort editierbar). Gilt fuer
     *        BEIDE Richtungen, hat immer Vorrang vor Mapping-Vorlage/
     *        Vergleichswert-Rollen-Fallback. Whichever role dem aktiven
     *        shop-weiten Vergleichswert entspricht wird von der Seite gar
     *        nicht erst zum Editieren angeboten (das ist der Matching-
     *        Schluessel, darf sich nicht vom Quell-Datensatz unterscheiden).
     */
    public function importMissingProduct(string $shop, string $matchValue, string $missingOn, ?string $variantOptionValue = null, array $manualFieldValues = [], array $fieldOverrides = []): array
    {
        try {
            $context = ProductMatchContext::resolve($this->pdo, $this->connectorFactory, $shop);
        } catch (ProductMatchUnresolvedException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        if ($missingOn === 'shopify') {
            return $this->importIntoShopify($shop, $matchValue, $context, $variantOptionValue, $fieldOverrides);
        }

        if ($missingOn !== 'target') {
            return ['success' => false, 'message' => 'Ungültige Richtung.'];
        }

        return $this->importIntoTarget($shop, $matchValue, $context, $manualFieldValues, $fieldOverrides);
    }

    /**
     * Fuer die Produkte-Seite (app/_products_body.php): welche Zielfelder der
     * fuer Shopify->Zielsystem aufgeloesten Mapping-Vorlage sind als
     * transform_type='manual_input' markiert (siehe MappingEngine) -- fuer
     * jedes davon zeigt die Produkte-Seite bei "Fehlt im Zielsystem"-Zeilen
     * ein zusaetzliches Eingabefeld. Liefert [] ohne Vorlage/Verbindung, statt
     * zu werfen -- rein informativ fuer die Anzeige, kein Fehlerfall.
     *
     * Ein manual_input-Feld bekommt eine Vorbelegung, wenn der Admin beim
     * Einrichten der Mapping-Vorlage (admin/mapping_template_edit.php,
     * Dropdown "Vorbelegung aus Einstellung") explizit eine Quelle aus
     * SHOP_SETTING_DEFAULT_SOURCES gewaehlt hat (transform_config.
     * default_source) -- unabhaengig davon, ob ueberhaupt eine transform_
     * type='derived_price'-Zeile existiert (z.B. auch fuer eine Vorlage OHNE
     * Netto/Brutto-Berechnung nutzbar). Der Merchant kann die Vorbelegung
     * pro Produkt trotzdem ueberschreiben.
     *
     * @return array<int, array{field_key: string, label: string, default_value: ?string}>
     */
    public function manualInputFieldsForTargetImport(string $shop): array
    {
        try {
            $context = ProductMatchContext::resolve($this->pdo, $this->connectorFactory, $shop);
        } catch (ProductMatchUnresolvedException) {
            return [];
        }

        $sourceObjectId = $this->resolveObjectId($context->shopifyConnectionId, $context->shopifyObject->key);
        $targetObjectId = $this->resolveObjectId($context->targetConnectionId, $context->targetObject->key);
        if ($sourceObjectId === null || $targetObjectId === null) {
            return [];
        }

        $templateId = $this->resolveMappingTemplateId($sourceObjectId, $targetObjectId);
        if ($templateId === null) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            "SELECT of.field_key, of.label, fm.transform_config
             FROM connect_field_mappings fm
             JOIN connect_object_fields of ON of.id = fm.target_field_id
             WHERE fm.mapping_template_id = :mapping_template_id AND fm.transform_type = 'manual_input'
             ORDER BY fm.sort_order, fm.id"
        );
        $stmt->execute(['mapping_template_id' => $templateId]);
        $rows = $stmt->fetchAll();

        // Shop-Einstellungen nur einmal laden, wenn ueberhaupt ein Feld eine
        // default_source referenziert -- sonst ganz ohne zusaetzliche Query.
        $shopRow = null;
        foreach ($rows as $row) {
            $config = $row['transform_config'] !== null ? (json_decode((string) $row['transform_config'], true) ?: []) : [];
            if (isset(self::SHOP_SETTING_DEFAULT_SOURCES[$config['default_source'] ?? ''])) {
                $columns = implode(', ', array_map(static fn(array $s): string => $s['column'], self::SHOP_SETTING_DEFAULT_SOURCES));
                $shopSettingsStmt = $this->pdo->prepare("SELECT {$columns} FROM connect_shops WHERE shop = :shop");
                $shopSettingsStmt->execute(['shop' => $shop]);
                $shopRow = $shopSettingsStmt->fetch() ?: [];
                break;
            }
        }

        $fields = [];
        foreach ($rows as $row) {
            $config = $row['transform_config'] !== null ? (json_decode((string) $row['transform_config'], true) ?: []) : [];
            $defaultSource = self::SHOP_SETTING_DEFAULT_SOURCES[$config['default_source'] ?? ''] ?? null;
            $defaultValue = null;
            if ($defaultSource !== null && $shopRow !== null) {
                $rawDefault = $shopRow[$defaultSource['column']] ?? null;
                $defaultValue = $rawDefault !== null ? (string) $rawDefault : null;
            }

            $fields[] = [
                'field_key' => (string) $row['field_key'],
                'label' => (string) ($config['label'] ?? $row['label']),
                'default_value' => $defaultValue,
            ];
        }

        return $fields;
    }

    /**
     * Import NACH einem anderen (nicht-Shopify) Zielsystem (siehe
     * Klassenkommentar) -- Gegenstueck zu importIntoShopify(), gleiches
     * Grundmuster (Mapping-Vorlage direkt ueber das Objektpaar aufgeloest,
     * kein Aktivierungsschritt, Cache sofort garantiert nachgezogen), aber
     * OHNE Rollen-Fallback: ohne passende Vorlage ist das ein echter Fehler,
     * kein stilles "kommt trotzdem lauffaehig" (siehe Klassenkommentar,
     * Grund: beliebige REST-Zielsysteme koennen eigene Pflichtfelder haben).
     *
     * @param array<string, string> $manualFieldValues siehe importMissingProduct()
     * @param array<string, string> $fieldOverrides siehe importMissingProduct()
     * @return array{success: bool, message: string}
     */
    private function importIntoTarget(string $shop, string $matchValue, ProductMatchContext $context, array $manualFieldValues = [], array $fieldOverrides = []): array
    {
        $sourceObject = $context->shopifyObject;
        $objectCache = new ObjectCache($this->pdo);
        $sourceRecord = $objectCache->findOneByFieldValue($context->shopifyConnectionId, $sourceObject->key, $context->shopifyMatchField->key, $matchValue);
        if ($sourceRecord === null) {
            return ['success' => false, 'message' => "Quell-Datensatz für \"{$matchValue}\" nicht (mehr) im Cache gefunden."];
        }

        // Merchant-Bearbeitung auf der Produkte-Seite (siehe
        // importMissingProduct()) UEBERSCHREIBT direkt den Quell-Datensatz,
        // BEVOR er in die Mapping Engine geht -- dadurch wirkt sich die
        // Aenderung automatisch ueber JEDEN Transform aus, der dieses Feld
        // liest (direkt, Lookup, Verkettung, sogar 'derived_price' als
        // Basispreis), ohne Sonderfaelle je Transform-Art.
        foreach (['title', 'sku', 'barcode', 'price'] as $overrideRole) {
            if (!array_key_exists($overrideRole, $fieldOverrides)) {
                continue;
            }
            $overrideValue = trim((string) $fieldOverrides[$overrideRole]);
            // Leer = bewusst geleert: nur bei SKU/Barcode zulaessig (sonst
            // wuerde z.B. die Shopify-SKU trotz leerem Feld als Artikelcode gesendet).
            if ($overrideValue === '' && !in_array($overrideRole, ['sku', 'barcode'], true)) {
                continue;
            }
            $overrideField = ProductMatchContext::fieldWithComparisonRole($sourceObject, $overrideRole);
            if ($overrideField !== null) {
                $sourceRecord[$overrideField->key] = $overrideValue;
            }
        }

        $sourceObjectId = $this->resolveObjectId($context->shopifyConnectionId, $sourceObject->key);
        $targetObjectId = $this->resolveObjectId($context->targetConnectionId, $context->targetObject->key);
        if ($sourceObjectId === null || $targetObjectId === null) {
            return ['success' => false, 'message' => 'Objekt-Definition konnte nicht aufgelöst werden.'];
        }

        $templateId = $this->resolveMappingTemplateId($sourceObjectId, $targetObjectId);

        if ($templateId === null) {
            return [
                'success' => false,
                'message' => "Für diese Richtung ist noch keine Mapping-Vorlage vorhanden -- bitte unter \"Mapping-Vorlagen\" eine mit Quelle \"Shopify — {$sourceObject->label}\" und Ziel \"{$context->targetObject->label}\" anlegen (kein Aktivierungsschritt nötig, gilt automatisch für jeden Shop mit dieser Systemkombination).",
            ];
        }

        $fieldMappingsStmt = $this->pdo->prepare('SELECT * FROM connect_field_mappings WHERE mapping_template_id = :mapping_template_id ORDER BY sort_order, id');
        $fieldMappingsStmt->execute(['mapping_template_id' => $templateId]);
        $fieldMappingRows = $fieldMappingsStmt->fetchAll();

        if (count($fieldMappingRows) === 0) {
            $templateNameStmt = $this->pdo->prepare('SELECT name FROM connect_mapping_templates WHERE id = :id');
            $templateNameStmt->execute(['id' => $templateId]);
            $templateName = (string) $templateNameStmt->fetchColumn();
            return ['success' => false, 'message' => "Die Mapping-Vorlage \"{$templateName}\" hat noch keine Feld-Zuordnungen -- bitte unter \"Mapping-Vorlagen\" Felder zuordnen."];
        }

        $sourceFieldsById = $this->connectorFactory->fieldsByIdForObject($sourceObjectId);
        $targetFieldsById = $this->connectorFactory->fieldsByIdForObject($targetObjectId);

        // Werte fuer transform_type='manual_input'-Zielfelder (z.B. HelloCash
        // "Steuersatz") kommen vom Merchant, nicht aus $sourceRecord -- ohne
        // eingegebenen Wert lieber hier klar abbrechen statt eine kryptische
        // 422-Antwort des Zielsystems zu provozieren (siehe MappingEngine).
        $manualValuesById = [];
        foreach ($fieldMappingRows as $fieldMapping) {
            if (($fieldMapping['transform_type'] ?? '') !== 'manual_input') {
                continue;
            }
            $manualTargetField = $targetFieldsById[(int) $fieldMapping['target_field_id']] ?? null;
            if ($manualTargetField === null) {
                continue;
            }
            $manualValue = trim((string) ($manualFieldValues[$manualTargetField->key] ?? ''));
            if ($manualValue === '') {
                $manualConfig = $fieldMapping['transform_config'] !== null ? (json_decode((string) $fieldMapping['transform_config'], true) ?: []) : [];
                $manualLabel = (string) ($manualConfig['label'] ?? $manualTargetField->label);
                return ['success' => false, 'message' => "Bitte einen Wert für \"{$manualLabel}\" angeben."];
            }
            $manualValuesById[(int) $fieldMapping['target_field_id']] = $manualValue;
        }

        // Zielfelder mit transform_type='derived_price' (z.B. HelloCash
        // "net_selling_price"/"gross_selling_price") brauchen zusaetzlich zum
        // gemappten Shopify-Preis die Shop-Einstellung, ob dieser Preis Netto
        // oder Brutto ist (connect_shops.shopify_prices_include_tax, siehe
        // Settings-Seite) -- ohne die kann nicht sinnvoll gerechnet werden,
        // also lieber hier klar abbrechen statt einen falschen Preis zu
        // schreiben. $priceInfo wird zusaetzlich in der Erfolgsmeldung
        // angezeigt, damit fuer Admin/Merchant transparent ist, welche
        // Annahme beim Rechnen verwendet wurde.
        $priceContext = [];
        $priceInfo = null;
        $usesDerivedPrice = false;
        foreach ($fieldMappingRows as $fieldMapping) {
            if (($fieldMapping['transform_type'] ?? '') === 'derived_price') {
                $usesDerivedPrice = true;
                break;
            }
        }
        if ($usesDerivedPrice) {
            $taxModeStmt = $this->pdo->prepare('SELECT shopify_prices_include_tax FROM connect_shops WHERE shop = :shop');
            $taxModeStmt->execute(['shop' => $shop]);
            $taxMode = $taxModeStmt->fetchColumn();
            if ($taxMode === false || $taxMode === null) {
                return ['success' => false, 'message' => 'Bitte unter Einstellungen festlegen, ob Shopify-Preise netto oder brutto (inkl. MwSt.) sind, bevor Preise berechnet werden können.'];
            }
            $taxesIncluded = (bool) $taxMode;
            $priceContext = ['taxes_included' => $taxesIncluded];
            $priceInfo = 'Preis als ' . ($taxesIncluded ? 'Brutto (inkl. MwSt.)' : 'Netto') . ' interpretiert (siehe Einstellungen).';
        }

        $data = (new MappingEngine($this->pdo))->translate($sourceRecord, $fieldMappingRows, $sourceFieldsById, $targetFieldsById, [], $manualValuesById, $priceContext);

        // Feld-Laengenlimit (connect_object_fields.max_length, Migration 0049,
        // im Connector Builder gesetzt): klar abbrechen statt eine kryptische
        // 422-Antwort des Zielsystems zu provozieren.
        foreach ($targetFieldsById as $lengthField) {
            if ($lengthField->maxLength === null || !isset($data[$lengthField->key]) || is_array($data[$lengthField->key])) {
                continue;
            }
            $lengthValue = (string) $data[$lengthField->key];
            if (mb_strlen($lengthValue) > $lengthField->maxLength) {
                return [
                    'success' => false,
                    'message' => "\"{$lengthField->label}\" ist zu lang (" . mb_strlen($lengthValue) . " statt maximal {$lengthField->maxLength} Zeichen): \"{$lengthValue}\" -- bitte den Wert kuerzen (in Shopify, oder auf der Produkte-Seite direkt in der Zeile, falls das Feld dort editierbar ist).",
                ];
            }
        }

        try {
            $writeResult = $context->targetConnector->write($context->targetObject->key, 'create', $data);
        } catch (\Throwable $e) {
            ActivityLog::log($this->pdo, $shop, null, $context->targetObject->key, 'create', false, $e->getMessage());

            return ['success' => false, 'message' => 'Import fehlgeschlagen: ' . $e->getMessage()];
        }

        if (!$writeResult->success) {
            ActivityLog::log($this->pdo, $shop, null, $context->targetObject->key, 'create', false, $writeResult->message, null, $writeResult->requestBody, $writeResult->responseBody);

            return ['success' => false, 'message' => $writeResult->message];
        }

        // Ziel-ID aus der (bei JSON ggf. verschachtelten, siehe
        // GenericHttpConnector::write()) Antwort ziehen -- ueber denselben
        // Punkt-Pfad-Mechanismus wie beim Lesen, nicht per flachem
        // Schluessel-Zugriff (fand z.B. HelloCash "data.id" nie).
        $targetIdentifierField = ObjectCache::requireIdentifierField($context->targetObject);
        $extracted = GenericHttpConnector::extractByPath($writeResult->responseData, $targetIdentifierField->path ?? $targetIdentifierField->key);
        $targetExternalId = $extracted !== null && $extracted !== '' ? (string) $extracted : null;

        ActivityLog::log(
            $this->pdo,
            $shop,
            null,
            $context->targetObject->key,
            'create',
            true,
            "Neuer Datensatz für \"{$matchValue}\" angelegt." . ($priceInfo !== null ? ' ' . $priceInfo : ''),
            $targetExternalId,
            $writeResult->requestBody,
            $writeResult->responseBody
        );

        if ($targetExternalId !== null) {
            // Cache SOFORT garantiert nachziehen (gleiches Muster wie
            // importIntoShopify()) -- $data ist bereits mit den Ziel-
            // field_keys als Schluessel (MappingEngine::translate()), kann
            // also direkt als Basis der Seed-Zeile dienen.
            $seedRow = [$targetIdentifierField->key => $targetExternalId] + $data;
            $objectCache->seedRecord($context->targetConnectionId, $context->targetObject->key, $context->targetObject, $seedRow);

            try {
                $objectCache->refreshOne($context->targetConnector, $context->targetConnectionId, $context->targetObject, [$targetIdentifierField->key => $targetExternalId]);
            } catch (\Throwable) {
                // Seed-Zeile oben deckt den fuer die "Produkte"-Seite
                // wichtigen Fall bereits ab.
            }

            // Siehe importIntoShopify() -- Live-Refresh kann eine
            // unvollstaendige Zeile zurueckliefern und die Seed-Zeile
            // ueberschreiben, deshalb hier nochmal per Merge erzwungen.
            $objectCache->updateCachedFields($context->targetConnectionId, $context->targetObject->key, $targetExternalId, $seedRow);
        }

        // Anfangsbestand: ein neu angelegter Artikel startet im Zielsystem mit 0 (helloCash kennt
        // beim Anlegen keinen Bestand, nur Delta ueber change-stock). Nach dem Cache-Nachziehen,
        // damit der Live-Refresh oben den neuen Bestand nicht ueberschreibt.
        $stockInfo = null;
        if ($targetExternalId !== null) {
            $stockInfo = $this->writeInitialStock($shop, $context, $objectCache, $sourceRecord, $matchValue, $targetExternalId);
            if ($stockInfo !== null && $stockInfo['written']) {
                $objectCache->updateCachedFields($context->targetConnectionId, $context->targetObject->key, $targetExternalId, [$context->targetStockField->key => $stockInfo['quantity']]);
            }
        }

        $idSuffix = $targetExternalId !== null ? " (Ziel-ID: {$targetExternalId})" : '';

        return ['success' => true, 'message' => "Neu angelegt{$idSuffix}." . ($priceInfo !== null ? ' ' . $priceInfo : '') . ($stockInfo !== null ? ' ' . $stockInfo['message'] : '')];
    }

    /**
     * Schreibt den Shopify-Bestand als Anfangsbestand in einen frisch angelegten Ziel-Artikel --
     * in BEIDE Richtungen des Lagerabgleichs: ein neuer Artikel startet mit 0, und wuerde der
     * Abgleich (fuehrt das Kassensystem) das naechste Mal laufen, setzte er den Shopify-Bestand
     * auf 0. Der Anfangsbestand ist also die einmalige Uebernahme aus Shopify. Ein neuer Artikel
     * hat Bestand 0, das Delta ist daher der Shopify-Bestand selbst. Fuehrt das Kassensystem mit
     * Sicherheitsbestand, kommt dieser dazu (Shopify zeigt Kassenbestand minus Puffer, der
     * Shopify-Bestand bliebe sonst nicht erhalten).
     *
     * Der Shopify-Bestand ist standortbezogen. Stammt der Datensatz aus der Zeit vor der
     * Standortwahl (_location_id fehlt/anders), wird er hier einmal live nachgeladen. Kann nichts
     * uebernommen werden, sagt die Rueckgabe warum (statt stillschweigend nichts zu tun).
     * Scheitert das Schreiben, bleibt der Import gelungen, der Lagerabgleich holt es nach.
     *
     * @param array<string, mixed> $sourceRecord Shopify-Datensatz
     * @return array{written: bool, quantity: int, message: string}|null null = nichts zu tun (Bestand 0)
     */
    private function writeInitialStock(string $shop, ProductMatchContext $context, ObjectCache $objectCache, array $sourceRecord, string $matchValue, string $targetExternalId): ?array
    {
        $skipped = static fn (string $reason): array => ['written' => false, 'quantity' => 0, 'message' => 'Bestand nicht übernommen: ' . $reason];

        if ($context->locationId === null || $context->locationId === '') {
            return $skipped('kein Shopify-Standort gewählt (Einstellungen → Lagerbestand).');
        }

        $stockKey = $context->shopifyStockField->key;
        $isStale = static fn (array $record): bool => $stockKey === 'location_stock_quantity'
            && (string) ($record['_location_id'] ?? '') !== (string) $context->locationId;

        if ($isStale($sourceRecord)) {
            // Datensatz noch ohne/mit anderem Standort geladen: genau dieses Produkt live nachladen
            try {
                $identifierKey = ObjectCache::requireIdentifierField($context->shopifyObject)->key;
                $externalId = (string) ($sourceRecord[$identifierKey] ?? '');
                if ($externalId !== '' && $objectCache->refreshOne($context->shopifyConnector, $context->shopifyConnectionId, $context->shopifyObject, [$identifierKey => $externalId, 'id' => $externalId])) {
                    $sourceRecord = $objectCache->getCachedOne($context->shopifyConnectionId, $context->shopifyObject->key, $externalId) ?? $sourceRecord;
                }
            } catch (\Throwable $e) {
                error_log('[ProductImportService] Shopify-Bestand nicht nachladbar: ' . $e->getMessage());
            }
            if ($isStale($sourceRecord)) {
                return $skipped('der Shopify-Bestand konnte nicht für den gewählten Standort gelesen werden – folgt mit dem nächsten Lagerabgleich.');
            }
        }

        if (!array_key_exists($stockKey, $sourceRecord) || $sourceRecord[$stockKey] === null || $sourceRecord[$stockKey] === '') {
            return $skipped('Shopify führt dieses Produkt am gewählten Standort nicht (oder der Bestand ist unbekannt).');
        }

        $quantity = (int) $sourceRecord[$stockKey];
        if ($quantity <= 0) {
            return null;
        }
        if ($context->direction === 'target_to_shopify') {
            $quantity += ShopStockRules::forShop($this->pdo, $shop)['buffer'];
        }

        $targetIdentifierField = ObjectCache::requireIdentifierField($context->targetObject);

        try {
            $result = $context->targetConnector->write($context->targetObject->key, 'update', [
                $targetIdentifierField->key => $targetExternalId,
                'stock_change' => $quantity,
            ]);
        } catch (\Throwable $e) {
            ActivityLog::log($this->pdo, $shop, null, $context->targetObject->key, 'stock_update', false, $e->getMessage(), $targetExternalId);

            return ['written' => false, 'quantity' => $quantity, 'message' => 'Anfangsbestand konnte nicht gesetzt werden (' . $e->getMessage() . ') -- folgt mit dem nächsten Lagerabgleich.'];
        }

        ActivityLog::log(
            $this->pdo,
            $shop,
            null,
            $context->targetObject->key,
            'stock_update',
            $result->success,
            $result->success ? "Anfangsbestand für \"{$matchValue}\": 0 -> {$quantity}." : $result->message,
            $targetExternalId,
            $result->requestBody,
            $result->responseBody
        );

        if (!$result->success) {
            return ['written' => false, 'quantity' => $quantity, 'message' => 'Anfangsbestand konnte nicht gesetzt werden (' . $result->message . ') -- folgt mit dem nächsten Lagerabgleich.'];
        }

        return ['written' => true, 'quantity' => $quantity, 'message' => "Bestand {$quantity} übernommen."];
    }

    /**
     * Import NACH Shopify (siehe Klassenkommentar + Aufrufstelle in
     * importMissingProduct()) -- laeuft IMMER ueber create_product/
     * create_variant, es gibt hier keinen zweiten, generischen Pfad mehr:
     *
     *   - Merchant hat einen Options-Wert eingegeben ("echte" Variante):
     *     Gruppierung wird erwartet (Varianten-Vergleichswert konfiguriert,
     *     passendes Feld im Connector Builder markiert, Feld beim
     *     Datensatz nicht leer) -- jede Luecke dabei ist ein ECHTER Fehler
     *     mit konkretem Grund, kein stilles Zurueckfallen, damit der
     *     Merchant erfaehrt, warum sein Varianten-Wunsch nicht umgesetzt
     *     werden konnte.
     *   - Kein Options-Wert eingegeben: Gruppierung wird uebersprungen,
     *     Shopifys eigene Standard-Konvention fuer ein varianten-loses
     *     Produkt wird genutzt (Option "Title" = "Default Title").
     *
     * In BEIDEN Faellen: existiert bereits ein Geschwister-Datensatz mit
     * demselben Gruppierungswert in Shopify, wird die neue Variante daran
     * angehaengt -- sonst wird ein neues Produkt angelegt. AUSNAHME (live
     * beobachtet, siehe Fehlermeldung unten): war das Geschwister-Produkt
     * selbst OHNE Varianten-Wert angelegt (traegt noch Shopifys Platzhalter
     * "Title"/"Default Title"), wird das Anhaengen VERWEIGERT statt
     * durchgefuehrt -- Shopify entfernt beim Anhaengen einer echten Variante
     * an so ein Produkt naemlich selbststaendig die bisherige Platzhalter-
     * Variante (kein Verhalten unsererseits), das bereits importierte
     * Erstprodukt wuerde sonst ersatzlos verschwinden. Empfehlung an den
     * Merchant: das erste Produkt einer geplanten Gruppe immer gleich MIT
     * einem Varianten-Wert importieren.
     *
     * Preis: nur gesetzt, wenn im Zielsystem ein Feld mit comparison_role=
     * 'price' markiert ist (Migration 0041, optional, im Connector Builder
     * einzurichten) -- ohne Markierung bleibt der Preis auf Shopifys
     * Standard (0), vom Merchant im DRAFT-Produkt manuell nachzutragen.
     *
     * Cache: der frisch importierte Datensatz wird nach einem erfolgreichen
     * Schreibzugriff GARANTIERT (nicht nur best-effort) sofort in
     * connect_object_records nachgezogen (ObjectCache::seedRecord()) --
     * ohne das wuerde er bis zum naechsten regulaeren Sync auf der
     * "Produkte"-Seite faelschlich weiter als "fehlt"/erneut importierbar
     * erscheinen.
     *
     * @param array<string, string> $fieldOverrides siehe importMissingProduct()
     * @return array{success: bool, message: string}
     */
    private function importIntoShopify(string $shop, string $matchValue, ProductMatchContext $context, ?string $variantOptionValue, array $fieldOverrides = []): array
    {
        // KEIN Fruehausstieg mehr ueber shopifySupportsVariants -- diese
        // Methode ist ohnehin unabhaengig davon immer Shopify-spezifisch
        // (ProductMatchContext::findConnectionId() sucht die Connection
        // bereits fest ueber type_key='shopify'), und ein Import OHNE
        // Varianten-Options-Wert (der Standardfall) hat mit diesem Flag
        // inhaltlich nichts zu tun. Eine fruehere Version hatte das
        // faelschlich als Voraussetzung fuer JEDEN Import erzwungen.

        // targetObject/targetConnectionId ist hier das varianten-lose
        // Quellsystem (z.B. HelloCash) -- siehe missingOn==='shopify'-Zweig
        // in importMissingProduct().
        $flatObject = $context->targetObject;
        $objectCache = new ObjectCache($this->pdo);
        $sourceRecord = $objectCache->findOneByFieldValue($context->targetConnectionId, $flatObject->key, $context->targetMatchField->key, $matchValue);
        if ($sourceRecord === null) {
            return ['success' => false, 'message' => "Quell-Datensatz für \"{$matchValue}\" nicht (mehr) im Cache gefunden."];
        }

        // Felder, die nur der Einzelabruf liefert (helloCash: Kategorie, Hersteller), fuer GENAU
        // diesen Artikel frisch holen -- statt den ganzen Katalog regelmaessig nachzuladen
        // ("Details nachladen" kann dann aus bleiben, der Bestand kommt aus der v1-Zusatzliste).
        // Scheitert der Abruf, wird mit dem gecachten Stand angelegt (wie bisher).
        $sourceRecord = $this->withFreshDetails($objectCache, $context, $flatObject, $sourceRecord);

        // Optional vom Admin konfigurierte Feld-Zuordnung (siehe Klassenkommentar)
        // -- liefert [] (kein Override), wenn fuer dieses Objektpaar (noch)
        // keine Mapping-Vorlage existiert. In dem Fall greifen unten die
        // bisherigen Vergleichswert-Rollen-Fallbacks unveraendert.
        $configuredFieldValues = $this->resolveConfiguredFieldValues($context, $flatObject, $sourceRecord);

        // Merchant-Bearbeitung auf der Produkte-Seite (siehe
        // importMissingProduct()) hat IMMER Vorrang -- auch vor einer
        // Mapping-Vorlage. Shopifys eigene Feld-Keys sind hier bewusst
        // identisch zu den Rollen-Namen (title/sku/barcode/price, siehe
        // Migration 0004/0030), deshalb ohne Umweg direkt uebernehmbar.
        foreach (['title', 'sku', 'barcode', 'price'] as $overrideRole) {
            $overrideValue = trim((string) ($fieldOverrides[$overrideRole] ?? ''));
            if ($overrideValue !== '') {
                $configuredFieldValues[$overrideRole] = $overrideValue;
            }
        }

        $variantOptionValue = $variantOptionValue !== null ? trim($variantOptionValue) : '';
        $hasRealOption = $variantOptionValue !== '';

        $existingProductId = null;

        if ($hasRealOption) {
            // Nur wenn der Merchant WIRKLICH eine Variante wollte, ist eine
            // fehlende Gruppierungs-Konfiguration ein ECHTER Fehler --
            // siehe Methodenkommentar.
            if ($context->variantComparisonKey === null) {
                return ['success' => false, 'message' => 'Kein Varianten-Vergleichswert konfiguriert -- bitte unter Einstellungen ("Varianten-Import") einrichten.'];
            }

            $groupField = ProductMatchContext::fieldWithComparisonRole($flatObject, $context->variantComparisonKey);
            if ($groupField === null) {
                return ['success' => false, 'message' => "Kein Feld im Zielsystem mit der Vergleichswert-Rolle \"{$context->variantComparisonKey}\" markiert (Connector Builder)."];
            }

            $groupValue = $sourceRecord[$groupField->key] ?? null;
            if ($groupValue === null || $groupValue === '') {
                return ['success' => false, 'message' => "Feld \"{$groupField->label}\" ist bei diesem Produkt leer -- keine Gruppierung möglich."];
            }

            $existingProductId = $this->findExistingProductId($context, (string) $groupValue);
        }

        // Ohne echten Options-Wert (Standard-Fall: eigenstaendiges,
        // varianten-loses Produkt) nutzt Shopify selbst diese Konvention
        // fuer die einzige Variante eines Produkts.
        $optionName = $hasRealOption ? $this->configuredOptionName($shop) : 'Title';
        $optionValue = $hasRealOption ? $variantOptionValue : 'Default Title';

        $isNewProduct = false;

        if ($existingProductId !== null) {
            // Den konfigurierten Options-Namen nur als Fallback nutzen -- der
            // TATSAECHLICHE Options-Name des Ziel-Produkts kann davon abweichen,
            // z.B. wenn der Merchant ihn in Shopify manuell umbenannt hat (z.B.
            // "Option" -> "Größe"). Live-Abfrage direkt vor dem Schreiben ist die
            // einzig verlaessliche Quelle (Altsystem-Erfahrung, siehe
            // manual.php GetProductOptions) -- ein falscher Options-Name wuerde
            // sonst fehlschlagen oder faelschlich eine zweite Option anlegen.
            // Fehlschlag der Abfrage selbst (z.B. Netzwerkfehler) ist kein Grund,
            // den Import abzubrechen -- dann greift einfach der konfigurierte
            // Fallback, wie bisher.
            try {
                $optionsLookup = $context->shopifyConnector->write($context->shopifyObject->key, 'lookup_options', ['id' => $existingProductId]);
                $liveOptionName = $optionsLookup->responseData['data']['product']['options'][0]['name'] ?? null;
                if ($optionsLookup->success && $liveOptionName !== null && $liveOptionName !== '') {
                    $optionName = (string) $liveOptionName;
                }
            } catch (\Throwable) {
                // Fallback (konfigurierter Name) bleibt stehen.
            }

            // Sicherheitsnetz gegen stillen Datenverlust (live beobachtet):
            // "Title"/"Default Title" ist Shopifys eigene interne Kennzeichnung
            // "dieses Produkt hat keine echten Varianten" (siehe optionName/
            // optionValue oben, gesetzt wenn OHNE Varianten-Wert importiert).
            // Haengt man jetzt trotzdem eine ECHTE Variante an so ein Produkt
            // an, entfernt SHOPIFY SELBST die bisherige Platzhalter-Variante
            // automatisch (kein Verhalten unsererseits, kein Strategy-Flag
            // noetig, um das auszuloesen) -- das urspruenglich importierte
            // Erstprodukt (samt SKU/Barcode/Preis) verschwindet dabei
            // ersatzlos. Deshalb hier ECHTER Fehler statt stillem Anhaengen:
            // der Admin muss dem Erstprodukt zuerst selbst einen echten
            // Varianten-Namen geben (in Shopify direkt), oder es beim naechsten
            // Mal gleich MIT einem Varianten-Wert importieren.
            if ($optionName === 'Title') {
                return [
                    'success' => false,
                    'message' => "Das gefundene Geschwister-Produkt wurde ohne Varianten-Wert angelegt (Shopify-Platzhalter \"Default Title\") -- eine weitere Variante kann nicht automatisch angehaengt werden, OHNE dass Shopify dabei das bereits importierte Erstprodukt entfernt. Bitte zuerst in Shopify direkt einen echten Varianten-Namen fuer dieses Produkt vergeben, oder das erste Produkt dieser Gruppe kuenftig gleich MIT einem Varianten-Wert importieren.",
                ];
            }
        } else {
            // Kein Geschwister-Datensatz mit demselben Gruppierungswert bisher
            // in Shopify bekannt (oder gar keine Variante gewuenscht) -- das
            // hier ist die ALLERERSTE Variante dieser Produktgruppe bzw. ein
            // komplett eigenstaendiges Produkt. Neues Produkt anlegen (Status
            // DRAFT, direkt mit $optionName/$optionValue von oben) -- kein
            // Live-Abgleich noetig, wir legen das Produkt gerade erst selbst
            // an, es kann also noch keinen abweichenden Options-Namen geben.
            $newProductResult = $this->createNewProduct($context, $sourceRecord, $optionName, $optionValue, $configuredFieldValues);
            ActivityLog::log(
                $this->pdo,
                $shop,
                'shopify',
                $context->shopifyObject->key,
                'create_product',
                $newProductResult['productId'] !== null,
                $newProductResult['productId'] !== null ? "Neues Produkt fuer \"{$matchValue}\" angelegt." : (string) $newProductResult['error'],
                $newProductResult['productId'],
                $newProductResult['requestBody'],
                $newProductResult['responseBody']
            );
            if ($newProductResult['productId'] === null) {
                return ['success' => false, 'message' => 'Neues Shopify-Produkt konnte nicht angelegt werden: ' . $newProductResult['error']];
            }
            $existingProductId = $newProductResult['productId'];
            $isNewProduct = true;
        }

        $skuField = ProductMatchContext::fieldWithComparisonRole($flatObject, 'sku');
        $barcodeField = ProductMatchContext::fieldWithComparisonRole($flatObject, 'barcode');
        $priceField = ProductMatchContext::fieldWithComparisonRole($flatObject, 'price');

        // $configuredFieldValues (aus einer optional aktivierten Mapping-
        // Vorlage, siehe Klassenkommentar + resolveConfiguredFieldValues())
        // hat immer Vorrang vor dem Vergleichswert-Rollen-Fallback.
        $writeData = [
            'productId' => $existingProductId,
            'sku' => (string) ($configuredFieldValues['sku'] ?? ($skuField !== null ? ($sourceRecord[$skuField->key] ?? '') : '')),
            'barcode' => (string) ($configuredFieldValues['barcode'] ?? ($barcodeField !== null ? ($sourceRecord[$barcodeField->key] ?? '') : '')),
            'optionName' => $optionName,
            'optionValue' => (string) $optionValue,
        ];
        // Preis nur mitschicken, wenn ueberhaupt ein Wert bekannt ist (Mapping-
        // Vorlage ODER Rolle "Preis", Migration 0041) -- sonst bleibt $price
        // als GraphQL-Variable einfach unbelegt, Shopify laesst den Preis dann
        // auf seinem Standard (0).
        $priceValue = $configuredFieldValues['price']
            ?? ($priceField !== null && isset($sourceRecord[$priceField->key]) && $sourceRecord[$priceField->key] !== '' ? $sourceRecord[$priceField->key] : null);
        if ($priceValue !== null && $priceValue !== '') {
            $writeData['price'] = (string) $priceValue;
        }
        if ($isNewProduct) {
            // Entfernt die beim Anlegen automatisch erzeugte Standalone-
            // Variante und ersetzt sie durch die hier gebaute -- nur beim
            // allerersten "create_variant"-Aufruf eines neuen Produkts noetig,
            // ein bereits bestehendes Produkt hat keine Standalone-Variante
            // mehr, siehe Klassenkommentar zu Migration 0040.
            $writeData['strategy'] = 'REMOVE_STANDALONE_VARIANT';
        }

        try {
            $writeResult = $context->shopifyConnector->write($context->shopifyObject->key, 'create_variant', $writeData);
        } catch (\Throwable $e) {
            ActivityLog::log($this->pdo, $shop, 'shopify', $context->shopifyObject->key, 'create_variant', false, $e->getMessage());

            return ['success' => false, 'message' => 'Import als Variante fehlgeschlagen: ' . $e->getMessage()];
        }

        if (!$writeResult->success) {
            ActivityLog::log($this->pdo, $shop, 'shopify', $context->shopifyObject->key, 'create_variant', false, $writeResult->message, null, $writeResult->requestBody, $writeResult->responseBody);

            return ['success' => false, 'message' => $writeResult->message];
        }

        $newVariantId = (string) ($writeResult->responseData['data']['result']['productVariants'][0]['id'] ?? '');
        ActivityLog::log(
            $this->pdo,
            $shop,
            'shopify',
            $context->shopifyObject->key,
            'create_variant',
            true,
            ($isNewProduct ? 'Neue Variante am neuen Produkt' : 'Neue Variante am bestehenden Produkt') . " fuer \"{$matchValue}\" angelegt.",
            $newVariantId !== '' ? $newVariantId : null,
            $writeResult->requestBody,
            $writeResult->responseBody
        );

        if ($newVariantId !== '') {
            $destIdentifierField = ObjectCache::requireIdentifierField($context->shopifyObject);

            // Cache-Zeile SOFORT aus den bereits bekannten/gerade geschriebenen
            // Werten aufbauen -- ohne Netzwerk-Aufruf, kann also (anders als
            // der anschliessende refreshOne()-Versuch) nicht an einem
            // API-Fehler scheitern. Ohne das blieb der frisch importierte
            // Datensatz auf der "Produkte"-Seite bis zum naechsten regulaeren
            // Sync faelschlich als "fehlt"/erneut importierbar sichtbar, wenn
            // der Live-Refresh aus irgendeinem Grund fehlschlug.
            $seedRow = [$destIdentifierField->key => $newVariantId, 'product_id' => $existingProductId];
            $shopifyTitleField = ProductMatchContext::fieldWithComparisonRole($context->shopifyObject, 'title');
            $shopifySkuField = ProductMatchContext::fieldWithComparisonRole($context->shopifyObject, 'sku');
            $shopifyBarcodeField = ProductMatchContext::fieldWithComparisonRole($context->shopifyObject, 'barcode');
            $shopifyPriceField = ProductMatchContext::fieldWithComparisonRole($context->shopifyObject, 'price');
            $sourceTitleField = ProductMatchContext::titleField($flatObject);
            $titleForSeed = $configuredFieldValues['title'] ?? ($sourceTitleField !== null ? ($sourceRecord[$sourceTitleField->key] ?? null) : null);
            if ($shopifyTitleField !== null && $titleForSeed !== null) {
                $seedRow[$shopifyTitleField->key] = (string) $titleForSeed;
            }
            if ($shopifySkuField !== null) {
                $seedRow[$shopifySkuField->key] = $writeData['sku'];
            }
            if ($shopifyBarcodeField !== null) {
                $seedRow[$shopifyBarcodeField->key] = $writeData['barcode'];
            }
            if ($shopifyPriceField !== null && isset($writeData['price'])) {
                $seedRow[$shopifyPriceField->key] = $writeData['price'];
            }
            $objectCache->seedRecord($context->shopifyConnectionId, $context->shopifyObject->key, $context->shopifyObject, $seedRow);

            try {
                // Best-effort-Anreicherung um die hier nicht bekannten Felder
                // (Status/Lagerbestand/Handle/...) -- scheitert dieser
                // zusaetzliche Abruf, bleibt die oben garantiert gesetzte
                // Zeile trotzdem bestehen.
                $objectCache->refreshOne($context->shopifyConnector, $context->shopifyConnectionId, $context->shopifyObject, [$destIdentifierField->key => $newVariantId]);
            } catch (\Throwable) {
                // Seed-Zeile oben deckt den fuer die "Produkte"-Seite
                // wichtigen Fall (Vergleichswert korrekt gesetzt) bereits ab.
            }

            // Die Seed-Werte (insbesondere product_id -- kritisch fuer
            // findExistingProductId() bei der NAECHSTEN Variante derselben
            // Gruppe) NACH dem Live-Refresh nochmal per Merge erzwingen. Grund:
            // Shopifys GraphQL-Admin-API liest unmittelbar nach einem Schreib-
            // zugriff nicht immer sofort konsistent (kurze Verzoegerung, bis
            // ein frisch angelegtes Produkt/Variante ueber eine Lese-Query voll
            // sichtbar ist) -- ein in diesem Fenster ausgefuehrter refreshOne()
            // kann eine unvollstaendige Zeile (z.B. product_id fehlt/leer)
            // zurueckliefern und damit die oben garantiert korrekte Seed-Zeile
            // versehentlich UEBERSCHREIBEN (upsertRecord() ersetzt die Zeile
            // komplett, kein Merge). updateCachedFields() MISCHT stattdessen
            // nur die uebergebenen Felder in die bereits gespeicherten Daten,
            // ueberschreibt also gezielt genau diese wieder korrekt, ohne die
            // eventuell zusaetzlich vom Live-Refresh gewonnenen Felder zu
            // verlieren.
            $objectCache->updateCachedFields($context->shopifyConnectionId, $context->shopifyObject->key, $newVariantId, $seedRow);
        }

        // Anfangsbestand: das Produkt hat im Zielsystem (z.B. helloCash) schon Bestand und soll ihn
        // mitnehmen -- sonst startet die neue Shopify-Variante mit 0, und der naechste Lagerabgleich
        // (fuehrt Shopify) schriebe diese 0 zurueck ins Zielsystem.
        $stockInfo = null;
        if ($newVariantId !== '') {
            $inventoryItemId = (string) ($writeResult->responseData['data']['result']['productVariants'][0]['inventoryItem']['id'] ?? '');
            $stockInfo = $this->writeInitialStockToShopify($shop, $context, $objectCache, $sourceRecord, $matchValue, $newVariantId, $inventoryItemId);
        }

        $idSuffix = $newVariantId !== '' ? " (Varianten-ID: {$newVariantId})" : '';
        if ($isNewProduct) {
            $successMessage = $hasRealOption
                ? "Neues Shopify-Produkt mit erster Variante angelegt{$idSuffix}."
                : "Neues Shopify-Produkt angelegt{$idSuffix}.";
        } else {
            $successMessage = "Als neue Variante des bestehenden Produkts importiert{$idSuffix}.";
        }

        return ['success' => true, 'message' => $successMessage . ($stockInfo !== null ? ' ' . $stockInfo['message'] : '')];
    }

    /**
     * Setzt den Bestand des Zielsystems (z.B. helloCash) als Anfangsbestand der frisch angelegten
     * Shopify-Variante -- Gegenstueck zu writeInitialStock(). Reihenfolge: Inventar verfolgen
     * (track_inventory) und am gewaehlten Standort aktivieren (activate_inventory), beides
     * idempotent und nur wenn die Operationen vorhanden sind (Migration 0100), danach der
     * bewaehrte Bestands-Schreibweg des Lagerabgleichs ("update", inventorySetQuantities, von 0 aus).
     * Mit Sicherheitsbestand (fuehrt das Kassensystem) zeigt Shopify Kassenbestand minus Puffer.
     * Kann nichts uebernommen werden, sagt die Rueckgabe warum. Der Import selbst bleibt gelungen.
     *
     * @param array<string, mixed> $sourceRecord Datensatz des Zielsystems (Bestandsfeld per Detail-Abruf frisch)
     * @return array{written: bool, quantity: int, message: string}|null null = nichts zu tun (Bestand 0)
     */
    private function writeInitialStockToShopify(string $shop, ProductMatchContext $context, ObjectCache $objectCache, array $sourceRecord, string $matchValue, string $variantId, string $inventoryItemId): ?array
    {
        $skipped = static fn (string $reason): array => ['written' => false, 'quantity' => 0, 'message' => 'Bestand nicht übernommen: ' . $reason];

        if ($context->locationId === null || $context->locationId === '') {
            return $skipped('kein Shopify-Standort gewählt (Einstellungen → Lagerbestand).');
        }
        if ($inventoryItemId === '') {
            return $skipped('Shopify hat keine Inventar-ID zur neuen Variante geliefert.');
        }

        $stockKey = $context->targetStockField->key;
        if (!array_key_exists($stockKey, $sourceRecord) || $sourceRecord[$stockKey] === null || $sourceRecord[$stockKey] === '') {
            return $skipped('der Bestand im Zielsystem ist unbekannt.');
        }
        $quantity = ShopStockRules::shopifyQuantity((int) $sourceRecord[$stockKey], ShopStockRules::forShop($this->pdo, $shop)['buffer'], $context->direction);
        if ($quantity <= 0) {
            return null;
        }

        $connector = $context->shopifyConnector;
        $objectKey = $context->shopifyObject->key;

        // Vorbereitung: Inventar verfolgen, am Standort aktivieren (fehlende Operation = Schritt entfaellt)
        foreach ([
            'track_inventory' => ['inventoryItemId' => $inventoryItemId],
            'activate_inventory' => ['inventoryItemId' => $inventoryItemId, 'locationId' => $context->locationId],
        ] as $operation => $data) {
            if (!$connector instanceof GenericHttpConnector || !$connector->hasOperation($objectKey, $operation)) {
                continue;
            }
            try {
                $prepared = $connector->write($objectKey, $operation, $data);
            } catch (\Throwable $e) {
                ActivityLog::log($this->pdo, $shop, 'shopify', $objectKey, $operation, false, $e->getMessage(), $variantId);

                return $skipped('Vorbereitung in Shopify fehlgeschlagen (' . $e->getMessage() . ').');
            }
            ActivityLog::log(
                $this->pdo,
                $shop,
                'shopify',
                $objectKey,
                $operation,
                $prepared->success,
                $prepared->success ? ($operation === 'track_inventory' ? "Bestandsführung für \"{$matchValue}\" eingeschaltet." : "Am gewählten Standort für \"{$matchValue}\" aktiviert.") : $prepared->message,
                $variantId,
                $prepared->requestBody,
                $prepared->responseBody
            );
            if (!$prepared->success) {
                return $skipped('Vorbereitung in Shopify fehlgeschlagen (' . $prepared->message . ').');
            }
        }

        try {
            $result = $connector->write($objectKey, 'update', [
                'inventoryItemId' => $inventoryItemId,
                'locationId' => $context->locationId,
                'quantity' => $quantity,
                'changeFromQuantity' => 0,
            ]);
        } catch (\Throwable $e) {
            ActivityLog::log($this->pdo, $shop, 'shopify', $objectKey, 'stock_update', false, $e->getMessage(), $variantId);

            return $skipped('Schreiben nach Shopify fehlgeschlagen (' . $e->getMessage() . ') – folgt mit dem nächsten Lagerabgleich.');
        }
        ActivityLog::log(
            $this->pdo,
            $shop,
            'shopify',
            $objectKey,
            'stock_update',
            $result->success,
            $result->success ? "Anfangsbestand für \"{$matchValue}\": 0 -> {$quantity}." : $result->message,
            $variantId,
            $result->requestBody,
            $result->responseBody
        );
        if (!$result->success) {
            return $skipped('Schreiben nach Shopify fehlgeschlagen (' . $result->message . ') – folgt mit dem nächsten Lagerabgleich.');
        }

        // Zwischenspeicher mit dem neuen Bestand und dem Standort-Stempel nachziehen
        $objectCache->updateCachedFields($context->shopifyConnectionId, $objectKey, $variantId, [
            'inventory_item_id' => $inventoryItemId,
            $context->shopifyStockField->key => $quantity,
            '_location_id' => (string) $context->locationId,
        ]);

        return ['written' => true, 'quantity' => $quantity, 'message' => "Bestand {$quantity} übernommen."];
    }

    /**
     * Quell-Datensatz per Einzelabruf (read_one) aktualisieren, falls das Object einen hat.
     *
     * @param array<string, mixed> $sourceRecord
     * @return array<string, mixed>
     */
    private function withFreshDetails(ObjectCache $objectCache, ProductMatchContext $context, ObjectDefinition $flatObject, array $sourceRecord): array
    {
        $connector = $context->targetConnector;
        if (!$connector instanceof \Connect\Connector\Generic\GenericHttpConnector || !$connector->hasOperation($flatObject->key, 'read_one')) {
            return $sourceRecord;
        }
        try {
            $identifierKey = ObjectCache::requireIdentifierField($flatObject)->key;
            $externalId = (string) ($sourceRecord[$identifierKey] ?? '');
            if ($externalId === '') {
                return $sourceRecord;
            }
            if ($objectCache->refreshOne($connector, $context->targetConnectionId, $flatObject, [$identifierKey => $externalId, 'id' => $externalId])) {
                return $objectCache->getCachedOne($context->targetConnectionId, $flatObject->key, $externalId) ?? $sourceRecord;
            }
        } catch (\Throwable $e) {
            error_log('[ProductImportService] Details für ' . $flatObject->key . ' nicht nachladbar: ' . $e->getMessage());
        }

        return $sourceRecord;
    }

    private function configuredOptionName(string $shop): string
    {
        $stmt = $this->pdo->prepare('SELECT variant_option_name FROM connect_shops WHERE shop = :shop');
        $stmt->execute(['shop' => $shop]);

        return (string) ($stmt->fetchColumn() ?: 'Option');
    }

    /**
     * Legt ein neues Shopify-Produkt an -- entweder mit einer echten
     * Variantenoption (Merchant-Eingabe) oder, wenn keine gewuenscht war,
     * mit Shopifys Standard-Konvention "Title"/"Default Title" (siehe
     * Aufrufstelle in importIntoShopify()). Operation "create_product"
     * (Migration 0040), als DRAFT (Merchant kann vor Veroeffentlichung
     * pruefen). Setzt noch keine SKU/Barcode/Preis -- das macht der
     * Aufrufer im naechsten Schritt ueber "create_variant" mit
     * strategy=REMOVE_STANDALONE_VARIANT (ersetzt die beim Anlegen
     * automatisch erzeugte Standard-Variante).
     *
     * @param array<string, mixed> $configuredFieldValues optionale Mapping-
     *        Vorlagen-Werte (siehe resolveConfiguredFieldValues()) -- 'title'
     *        hat Vorrang vor der Titel-Vergleichswert-Rolle.
     * @return array{productId: ?string, error: ?string, requestBody: ?string, responseBody: ?string}
     */
    private function createNewProduct(ProductMatchContext $context, array $sourceRecord, string $optionName, string $optionValue, array $configuredFieldValues = []): array
    {
        $titleField = ProductMatchContext::titleField($context->targetObject);
        $title = (string) ($configuredFieldValues['title'] ?? ($titleField !== null ? ($sourceRecord[$titleField->key] ?? '') : ''));
        if ($title === '') {
            // Sollte praktisch nie eintreten (Titel-Rolle ist ueblicherweise
            // gesetzt) -- Notfall-Fallback, damit productCreate nicht an
            // einem leeren Pflichtfeld scheitert.
            $title = $optionValue;
        }

        try {
            $result = $context->shopifyConnector->write($context->shopifyObject->key, 'create_product', [
                'title' => $title,
                'optionName' => $optionName,
                'optionValue' => $optionValue,
            ]);
        } catch (\Throwable $e) {
            return ['productId' => null, 'error' => $e->getMessage(), 'requestBody' => null, 'responseBody' => null];
        }

        if (!$result->success) {
            return ['productId' => null, 'error' => $result->message, 'requestBody' => $result->requestBody, 'responseBody' => $result->responseBody];
        }

        $productId = $result->responseData['data']['result']['product']['id'] ?? null;
        if ($productId === null || $productId === '') {
            return ['productId' => null, 'error' => 'Antwort enthielt keine Produkt-ID.', 'requestBody' => $result->requestBody, 'responseBody' => $result->responseBody];
        }

        return ['productId' => (string) $productId, 'error' => null, 'requestBody' => $result->requestBody, 'responseBody' => $result->responseBody];
    }

    /**
     * Optionale Admin-Konfiguration "welches Quell-Feld fuellt welches
     * Shopify-Schreib-Feld beim Import" -- OHNE dass dafuer eine eigene,
     * neue Admin-Oberflaeche noetig ist: nutzt die bereits bestehende, vom
     * Admin bekannte Mapping-Vorlagen-Verwaltung (admin/mapping_templates.php)
     * -- inkl. fester Werte, Verkettung, Lookup-Tabellen,
     * Connect\Mapping\MappingEngine, dieselbe Maschinerie wie beim
     * MGM-Auftrag. Ziel-Object der Vorlage ist ganz regulaer das
     * Shopify-Produkt-Object -- seine (fuer den Lese-/Vergleichspfad
     * ohnehin vorhandenen) Felder title/sku/barcode/price dienen hier
     * zusaetzlich als Mapping-Ziele.
     *
     * BEWUSST OHNE Aktivierung/"Mappings"-Schritt je Shop: eine Mapping-
     * Vorlage ist per Definition schon global (Connector-Type+Object ->
     * Connector-Type+Object, siehe Migration 0020 -- "einmal pflegen, von
     * beliebig vielen Shops nutzen"), und die konkreten Connections fuer den
     * Schreibzugriff sind hier ohnehin schon ueber $context bekannt (pro Shop
     * aufgeloest durch ProductMatchContext::resolve()) -- eine zusaetzliche,
     * separat je Shop zu pflegende Aktivierungszeile (wie sie SyncRunner fuer
     * automatische Laeufe braucht) waere hier nur unnoetiger Konfigurations-
     * Aufwand. Sobald eine Vorlage fuer dieses Objektpaar existiert, gilt sie
     * SOFORT fuer JEDEN Shop mit diesem Connector-Type-Paar (z.B. jeder Shop
     * mit HelloCash+Shopify) -- keine Aktivierung noetig.
     *
     * Liefert [] (kein Override), wenn fuer dieses Objektpaar keine Vorlage
     * existiert -- der Aufrufer faellt dann auf die im Connector Builder
     * markierten Vergleichswert-Rollen zurueck, der Import funktioniert also
     * auch ganz ohne diese Konfiguration (und genauso automatisch fuer jeden
     * Shop, siehe Klassenkommentar).
     *
     * @param array<string, mixed> $sourceRecord
     * @return array<string, mixed> shopify_field_key => Wert (nur die vom
     *         Admin tatsaechlich zugeordneten Felder, i.d.R. eine Teilmenge
     *         von title/sku/barcode/price)
     */
    private function resolveConfiguredFieldValues(ProductMatchContext $context, ObjectDefinition $flatObject, array $sourceRecord): array
    {
        $sourceObjectId = $this->resolveObjectId($context->targetConnectionId, $flatObject->key);
        $shopifyObjectId = $this->resolveObjectId($context->shopifyConnectionId, $context->shopifyObject->key);
        if ($sourceObjectId === null || $shopifyObjectId === null) {
            return [];
        }

        $templateId = $this->resolveMappingTemplateId($sourceObjectId, $shopifyObjectId);

        if ($templateId === null) {
            return [];
        }

        $fieldMappingsStmt = $this->pdo->prepare('SELECT * FROM connect_field_mappings WHERE mapping_template_id = :mapping_template_id ORDER BY sort_order, id');
        $fieldMappingsStmt->execute(['mapping_template_id' => $templateId]);
        $fieldMappingRows = $fieldMappingsStmt->fetchAll();

        if (count($fieldMappingRows) === 0) {
            return [];
        }

        $sourceFieldsById = $this->connectorFactory->fieldsByIdForObject($sourceObjectId);
        $targetFieldsById = $this->connectorFactory->fieldsByIdForObject($shopifyObjectId);

        return (new MappingEngine($this->pdo))->translate($sourceRecord, $fieldMappingRows, $sourceFieldsById, $targetFieldsById);
    }

    /**
     * Leitet aus den bereits ueber den Vergleichswert gematchten Paaren
     * (Shopify <-> flaches Quellsystem) ab, ob zum gegebenen Gruppierungswert
     * (Feld mit comparison_role=variantComparisonKey, z.B. SKU) bereits ein
     * Shopify-Produkt existiert -- OHNE ein eigenes Shopify-seitiges
     * Gruppierungs-Feld zu brauchen: fuer jeden bereits gematchten
     * Datensatz wird der Gruppierungswert (Quellseite) mit der product_id
     * des gematchten Shopify-Datensatzes verknuepft.
     */
    private function findExistingProductId(ProductMatchContext $context, string $groupValue): ?string
    {
        if ($context->variantComparisonKey === null) {
            return null;
        }

        $groupField = ProductMatchContext::fieldWithComparisonRole($context->targetObject, $context->variantComparisonKey);
        if ($groupField === null) {
            return null;
        }

        $objectCache = new ObjectCache($this->pdo);

        $shopifyIndex = [];
        foreach ($objectCache->getCached($context->shopifyConnectionId, $context->shopifyObject->key) as $record) {
            if (($record['_status'] ?? 'active') === 'orphaned') {
                continue;
            }
            $value = $record[$context->shopifyMatchField->key] ?? null;
            if ($value !== null && $value !== '') {
                $shopifyIndex[(string) $value] = $record;
            }
        }

        foreach ($objectCache->getCached($context->targetConnectionId, $context->targetObject->key) as $record) {
            $matchValue = $record[$context->targetMatchField->key] ?? null;
            $recordGroupValue = $record[$groupField->key] ?? null;
            if ($matchValue === null || $matchValue === '' || $recordGroupValue === null || (string) $recordGroupValue !== $groupValue) {
                continue;
            }

            $shopifyMatch = $shopifyIndex[(string) $matchValue] ?? null;
            $productId = $shopifyMatch['product_id'] ?? null;
            if ($productId !== null && $productId !== '') {
                return (string) $productId;
            }
        }

        return null;
    }

    private function resolveObjectId(int $connectionId, string $objectKey): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.id FROM connect_objects o
             JOIN connect_connections c ON c.connector_type_id = o.connector_type_id
             WHERE c.id = :connection_id AND o.object_key = :object_key'
        );
        $stmt->execute(['connection_id' => $connectionId, 'object_key' => $objectKey]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    /**
     * Loest die Mapping-Vorlage fuer ein Objekt-Paar auf -- bevorzugt eine
     * explizit vom Admin hinterlegte Funktion (connect_functions,
     * function_kind='import_missing', admin/functions.php), damit nicht
     * mehr implizit "die zuletzt angelegte Vorlage" gewinnt, sobald fuer
     * dasselbe Objekt-Paar mehrere Vorlagen existieren. Ohne hinterlegte
     * Funktion greift wie bisher der einfache Standard (neueste Vorlage) --
     * Import funktioniert also weiterhin auch ganz ohne diese Konfiguration.
     */
    private function resolveMappingTemplateId(int $sourceObjectId, int $targetObjectId): ?int
    {
        $fnStmt = $this->pdo->prepare(
            "SELECT f.mapping_template_id FROM connect_functions f
             JOIN connect_mapping_templates t ON t.id = f.mapping_template_id
             WHERE f.function_kind = 'import_missing'
               AND t.source_object_id = :source_object_id AND t.target_object_id = :target_object_id
             LIMIT 1"
        );
        $fnStmt->execute(['source_object_id' => $sourceObjectId, 'target_object_id' => $targetObjectId]);
        $pinned = $fnStmt->fetchColumn();
        if ($pinned !== false) {
            return (int) $pinned;
        }

        $templateStmt = $this->pdo->prepare(
            'SELECT id FROM connect_mapping_templates
             WHERE source_object_id = :source_object_id AND target_object_id = :target_object_id
             ORDER BY id DESC LIMIT 1'
        );
        $templateStmt->execute(['source_object_id' => $sourceObjectId, 'target_object_id' => $targetObjectId]);
        $id = $templateStmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }
}
