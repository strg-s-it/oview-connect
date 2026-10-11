<?php

declare(strict_types=1);

namespace Connect\Sync\Document;

use Connect\Connector\ConnectorFactory;
use Connect\Connector\ConnectorInterface;
use Connect\Connector\Generic\GenericHttpConnector;
use Connect\Mapping\MappingEngine;
use Connect\Sync\ActivityLog;
use Connect\Sync\InvoiceService;
use Connect\Sync\ObjectCache;
use PDO;

/**
 * Generische Belegerstellung (Rechnung, Auftrag, ...) aus einer bezahlten
 * Shopify-Bestellung -- komplett ueber die Oberflaeche konfiguriert:
 *   - Feldzuordnung: Mapping-Vorlage mit Quelle "Bestellung (aufbereitet)"
 *     (connect_internal/order_prepared, Migration 0067)
 *   - Eigenheiten des Zielsystems: Belegprofil am Ziel-Object
 *     (connect_objects.document_profile, siehe DocumentProfile)
 *
 * Ablauf: Shopify-Bestellung aufbereiten (OrderPreparer) -> optional Kunde im
 * Zielsystem suchen/anlegen -> Steuer- und Betrags-Kontrolle -> Mapping-Engine
 * -> schreiben -> Nach-Kontrolle -> ggf. PDF per Mail. Lieber KEIN Beleg als
 * ein falscher: jede Kontroll-Abweichung bricht ab und informiert den
 * Betreiber.
 *
 * Wird von Connect\Sync\SyncRunner::translateAndWrite() aufgerufen, sobald die
 * Vorlage einer Aktivierung "Bestellung (aufbereitet)" als Quelle hat (Plan
 * "Rechnungen ueber die Oberflaeche", 2026-10-01). Duplikatschutz uebernimmt
 * SyncRunner (connect_translation_log), wie fuer jede andere Aktivierung.
 */
final class DocumentPipeline
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ConnectorFactory $connectorFactory
    ) {
    }

    /**
     * Nutzt die Vorlage die aufbereitete Bestellung als Quelle?
     */
    public static function templateUsesPreparedOrder(PDO $pdo, int $templateId): bool
    {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM connect_mapping_templates t
             JOIN connect_objects o ON o.id = t.source_object_id
             JOIN connect_connector_types ct ON ct.id = o.connector_type_id
             WHERE t.id = :id AND ct.type_key = 'connect_internal' AND o.object_key = 'order_prepared'"
        );
        $stmt->execute(['id' => $templateId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Beleg wirklich anlegen.
     *
     * @param array<string, mixed> $mapping connect_sync_mappings-Zeile (mapping_template_id, target_connection_id, shop, mapping_kind, settings)
     * @param array<string, mixed> $orderRow Shopify-"order"-Datensatz
     * @return array{success: bool, skipped: bool, targetExternalId: ?string, message: string}
     */
    public function run(array $mapping, array $orderRow, ?int $runId = null): array
    {
        $shop = (string) $mapping['shop'];
        $orderNumber = (string) ($orderRow['order_number'] ?? '?');
        $invoiceService = new InvoiceService($this->pdo, $this->connectorFactory);
        $shopRow = $invoiceService->loadShop($shop) ?? [];

        $prepared = $this->prepare($mapping, $orderRow, $shopRow, false);
        $logObjectKey = $prepared['target_object_key'] ?? 'document';

        if ($prepared['skipped'] !== null) {
            ActivityLog::log($this->pdo, $shop, null, $logObjectKey, 'create', true, "Bestellung {$orderNumber}: {$prepared['skipped']}", null, null, null, $runId);

            return self::result(true, true, null, $prepared['skipped']);
        }
        if ($prepared['error'] !== null) {
            $invoiceService->notifyError($shop, $shopRow, $prepared['error'], $orderRow);
            ActivityLog::log($this->pdo, $shop, null, $logObjectKey, 'create', false, "Bestellung {$orderNumber}: {$prepared['error']}", null, null, null, $runId);

            return self::result(false, false, null, $prepared['error']);
        }

        /** @var ConnectorInterface $targetConnector */
        $targetConnector = $prepared['target_connector'];
        /** @var DocumentProfile $profile */
        $profile = $prepared['profile'];
        $notes = $prepared['notes'];

        try {
            $writeResult = $targetConnector->write($logObjectKey, 'create', $prepared['data']);
        } catch (\Throwable $e) {
            $message = 'Beleg konnte nicht angelegt werden: ' . $e->getMessage();
            $invoiceService->notifyError($shop, $shopRow, $message, $orderRow);
            ActivityLog::log($this->pdo, $shop, null, $logObjectKey, 'create', false, "Bestellung {$orderNumber}: {$message}", null, null, null, $runId);

            return self::result(false, false, null, $message);
        }

        $documentNumber = null;
        if ($writeResult->success && $profile->documentNumberPath !== null) {
            $extracted = GenericHttpConnector::extractByPath($writeResult->responseData, $profile->documentNumberPath);
            $documentNumber = $extracted !== null && $extracted !== '' ? (string) $extracted : null;
        }

        ActivityLog::log(
            $this->pdo,
            $shop,
            null,
            $logObjectKey,
            'create',
            $writeResult->success,
            "Bestellung {$orderNumber}: " . $writeResult->message . ($notes !== [] ? ' | Hinweise: ' . implode(' ', $notes) : ''),
            $documentNumber,
            $writeResult->requestBody,
            $writeResult->responseBody,
            $runId
        );

        if (!$writeResult->success) {
            $invoiceService->notifyError($shop, $shopRow, $writeResult->message, $orderRow);

            return self::result(false, false, null, $writeResult->message);
        }

        // Nach-Kontrolle: die vom Zielsystem selbst errechnete Belegsumme gegen
        // Shopifys Gesamtbetrag (z.B. andere Rundung von Rabatten, live #1152).
        // Der Beleg existiert dann schon -- nur Mail + eigener Fehler-Eintrag.
        if ($profile->grossTotalPath !== null && $prepared['record']['total_gross'] !== null) {
            $targetGross = GenericHttpConnector::extractByPath($writeResult->responseData, $profile->grossTotalPath);
            if (is_numeric($targetGross)) {
                $postDiff = abs((float) $targetGross - (float) $prepared['record']['total_gross']);
                if ($postDiff > 0.05) {
                    $postMessage = sprintf(
                        'Nach-Kontrolle fehlgeschlagen: Beleg%s wurde angelegt, Summe laut Zielsystem %.2f EUR weicht aber von Shopifys Gesamtbetrag %.2f EUR ab (Differenz %.2f EUR) -- bitte Beleg prüfen und ggf. stornieren.',
                        $documentNumber !== null ? " Nr. {$documentNumber}" : '',
                        (float) $targetGross,
                        (float) $prepared['record']['total_gross'],
                        $postDiff
                    );
                    $invoiceService->notifyError($shop, $shopRow, $postMessage, $orderRow);
                    ActivityLog::log($this->pdo, $shop, null, $logObjectKey, 'create', false, "Bestellung {$orderNumber}: {$postMessage}", $documentNumber, null, null, $runId);
                    $notes[] = $postMessage;
                }
            }
        }

        // PDF-Versand nur fuer die Rechnungs-Aktivierung des Shops (dort liegen
        // die Mail-Einstellungen, siehe Rechnungs-Karte der Merchant-Seite).
        if ($profile->pdfPath !== null && ($mapping['mapping_kind'] ?? 'default') === 'invoice' && (int) ($shopRow['invoice_mail_enabled'] ?? 0) === 1) {
            $pdfBase64 = GenericHttpConnector::extractByPath($writeResult->responseData, $profile->pdfPath);
            if (is_string($pdfBase64) && $pdfBase64 !== '') {
                $invoiceService->sendInvoiceMail($shop, $shopRow, $orderRow, (string) $documentNumber, (string) base64_decode($pdfBase64, true));
            } else {
                $notes[] = 'Mailversand übersprungen (kein PDF in der Antwort enthalten).';
            }
        }

        $message = 'Beleg erstellt' . ($documentNumber !== null ? " (Nr. {$documentNumber})" : '') . '.';
        if ($notes !== []) {
            $message .= ' Hinweise: ' . implode(' ', $notes);
        }

        return self::result(true, false, $documentNumber, $message);
    }

    /**
     * Vorschau ohne zu schreiben (admin/mapping_edit.php): aufbereitete
     * Bestellung, Ergebnis der Kontrollen und die Daten, die gesendet wuerden.
     * Ein Kunde wird dabei nur GESUCHT, nie angelegt.
     *
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $orderRow
     * @return array{error: ?string, skipped: ?string, notes: list<string>, record: ?array<string, mixed>, data: ?array<string, mixed>, body: ?string}
     */
    public function preview(array $mapping, array $orderRow): array
    {
        $shopRow = (new InvoiceService($this->pdo, $this->connectorFactory))->loadShop((string) $mapping['shop']) ?? [];
        $prepared = $this->prepare($mapping, $orderRow, $shopRow, true);

        $body = null;
        if ($prepared['data'] !== null && $prepared['target_connector'] instanceof GenericHttpConnector) {
            $body = $prepared['target_connector']->buildWriteBodyForPreview((string) $prepared['target_object_key'], 'create', $prepared['data']);
        }

        return [
            'error' => $prepared['error'],
            'skipped' => $prepared['skipped'],
            'notes' => $prepared['notes'],
            'record' => $prepared['record'],
            'data' => $prepared['data'],
            'body' => $body,
        ];
    }

    /**
     * Gemeinsamer Teil von run() und preview(): alles bis einschliesslich
     * Mapping-Engine, ohne den Beleg zu schreiben.
     *
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $orderRow
     * @param array<string, mixed> $shopRow
     * @return array<string, mixed>
     */
    private function prepare(array $mapping, array $orderRow, array $shopRow, bool $dryRun): array
    {
        $out = [
            'error' => null,
            'skipped' => null,
            'notes' => [],
            'record' => null,
            'data' => null,
            'profile' => null,
            'target_connector' => null,
            'target_object_key' => null,
        ];

        $template = $this->loadTemplate((int) ($mapping['mapping_template_id'] ?? 0));
        if ($template === null) {
            $out['error'] = 'Keine Mapping-Vorlage zugeordnet.';
            return $out;
        }
        if (($mapping['target_connection_id'] ?? null) === null) {
            $out['error'] = 'Kein Zielsystem (Connection) zugeordnet.';
            return $out;
        }

        // Sondermodus "Nur Kassenbestellungen" (connect_shops.invoice_pos_only,
        // Migration 0058) -- gilt nur fuer die Rechnungs-Aktivierung des Shops.
        if (($mapping['mapping_kind'] ?? 'default') === 'invoice' && (int) ($shopRow['invoice_pos_only'] ?? 0) === 1) {
            $sourceName = strtolower(trim((string) ($orderRow['source_name'] ?? '')));
            if ($sourceName !== 'pos') {
                $out['skipped'] = 'Übersprungen (Modus "Nur Kassenbestellungen" aktiv, Bestellquelle "'
                    . ($sourceName !== '' ? $sourceName : 'unbekannt') . '" ist keine Kassenbestellung).';
                return $out;
            }
        }

        $targetObjectId = (int) $template['target_object_id'];
        $targetObject = $this->connectorFactory->objectDefinitionById($targetObjectId);
        $out['target_object_key'] = $targetObject->key;
        $profile = DocumentProfile::forObjectId($this->pdo, $targetObjectId) ?? new DocumentProfile();
        $out['profile'] = $profile;

        try {
            $targetConnector = $this->connectorFactory->forConnection((int) $mapping['target_connection_id']);
        } catch (\Throwable $e) {
            $out['error'] = 'Ziel-Connector konnte nicht aufgebaut werden: ' . $e->getMessage();
            return $out;
        }
        $out['target_connector'] = $targetConnector;

        $notes = [];
        $targetConnectionId = (int) $mapping['target_connection_id'];
        $comparisonKey = (string) ($shopRow['comparison_key'] ?? '');
        $articleResolver = $this->articleResolver($targetConnector, $targetConnectionId, $comparisonKey, $notes);
        // NULL (noch nicht festgelegt, Migration 0046) gilt als Brutto.
        $pricesIncludeTax = ($shopRow['shopify_prices_include_tax'] ?? null) === null || (int) $shopRow['shopify_prices_include_tax'] === 1;

        $preparer = new OrderPreparer();
        $preparedOrder = $preparer->prepare($orderRow, $pricesIncludeTax, $comparisonKey, $articleResolver);
        foreach ($preparedOrder['notes'] as $note) {
            $notes[] = $note;
        }
        $renderedLines = $preparer->render($preparedOrder['lines'], $profile);

        $record = $preparer->buildRecord($orderRow, $renderedLines, $profile, [
            'setting_cashier_id' => $shopRow['invoice_cashier_id'] ?? null,
            'setting_payment_method' => $shopRow['invoice_payment_method'] ?? null,
            'setting_signature_mandatory' => $shopRow['invoice_signature_mandatory'] ?? null,
        ]);

        if ($record['lines'] === []) {
            $out['error'] = 'Bestellung hat keine verwertbaren Positionen (Menge <= 0 oder keine Positionen vorhanden), Beleg nicht angelegt.';
            $out['notes'] = $notes;
            return $out;
        }

        // Steuer-Kontrolle: Shopifys eigene Steuerbetraege je Position/Versand gegen
        // Shopifys Steuersumme (Migration 0057).
        if ($record['total_tax'] !== null) {
            $taxDiff = abs($preparedOrder['computed_total_tax'] - (float) $record['total_tax']);
            if ($taxDiff > 0.10) {
                $out['error'] = sprintf(
                    'Steuer-Kontrolle fehlgeschlagen: berechnete Steuersumme %.2f EUR weicht von Shopifys Steuersumme %.2f EUR ab (Differenz %.2f EUR) -- Beleg zur Sicherheit nicht angelegt.',
                    $preparedOrder['computed_total_tax'],
                    (float) $record['total_tax'],
                    $taxDiff
                );
                $out['notes'] = $notes;
                $out['record'] = $record;
                return $out;
            }
        } else {
            $notes[] = 'Steuer-Kontrolle übersprungen (Feld "total_tax" nicht verfügbar).';
        }

        // Betrags-Kontrolle: Summe der Positionen (so wie das Zielsystem sie laut
        // Belegprofil bildet) gegen Shopifys Gesamtbetrag (live #1149).
        if ($record['total_gross'] !== null) {
            $computedGross = array_sum(array_column($record['lines'], 'out_line_total'));
            $grossDiff = abs($computedGross - (float) $record['total_gross']);
            if ($grossDiff > 0.05) {
                $out['error'] = sprintf(
                    'Betrags-Kontrolle fehlgeschlagen: Summe der Positionen %.2f EUR weicht von Shopifys Gesamtbetrag %.2f EUR ab (Differenz %.2f EUR, z.B. nicht erfasster Rabatt, Trinkgeld oder Zusatzgebühr) -- Beleg zur Sicherheit nicht angelegt.',
                    $computedGross,
                    (float) $record['total_gross'],
                    $grossDiff
                );
                $out['notes'] = $notes;
                $out['record'] = $record;
                return $out;
            }
        } else {
            $notes[] = 'Betrags-Kontrolle übersprungen (Feld "total_price" nicht verfügbar).';
        }

        if ($profile->customerObjectKey !== null) {
            try {
                $record['target_customer_id'] = $this->resolveCustomer($targetConnector, $profile, $record, $dryRun, $notes,
                    ($shopRow['invoice_customer_match'] ?? '') === InvoiceService::CUSTOMER_MATCH_EMAIL);
            } catch (\Throwable $e) {
                $notes[] = 'Kunde: ' . $e->getMessage();
            }
        }

        $out['record'] = $record;
        $out['data'] = $this->translate((int) $template['id'], (int) $template['source_object_id'], $targetObjectId, $record, $mapping);
        $out['notes'] = $notes;

        return $out;
    }

    /**
     * Kunden-Vorschritt laut Belegprofil: zuerst per E-Mail suchen, dann per
     * Vor- und Nachname, sonst ueber die Kunden-Vorlage neu anlegen (portiert
     * aus InvoiceService::resolveCustomer(), dort fest fuer HelloCash).
     * $emailOnly (Einstellung "Nur E-Mail-Adresse", Migration 0095): keine Suche per Name,
     * Bestellung ohne E-Mail = kein Kunde (siehe InvoiceService::resolveCustomer()).
     *
     * @param array<string, mixed> $record aufbereitete Bestellung
     * @param list<string> $notes
     */
    private function resolveCustomer(ConnectorInterface $targetConnector, DocumentProfile $profile, array $record, bool $dryRun, array &$notes, bool $emailOnly = false): int|string|null
    {
        $objectKey = (string) $profile->customerObjectKey;
        $email = trim((string) ($record['email'] ?? ''));
        $firstName = trim((string) ($record['customer_first_name'] ?? ''));
        $lastName = trim((string) ($record['customer_last_name'] ?? ''));
        $idField = $profile->customerIdField;
        $normalizeId = static fn (mixed $id): int|string => ctype_digit((string) $id) ? (int) $id : (string) $id;

        if ($emailOnly && $email === '') {
            $notes[] = 'Kein Kunde zugeordnet (Bestellung ohne E-Mail-Adresse, Zuordnung nur per E-Mail eingestellt).';

            return null;
        }

        if ($email !== '' && $profile->customerEmailField !== null) {
            foreach ($targetConnector->read($objectKey, [$profile->customerSearchParam => rawurlencode($email)]) as $row) {
                if (isset($row[$idField], $row[$profile->customerEmailField]) && strcasecmp((string) $row[$profile->customerEmailField], $email) === 0) {
                    return $normalizeId($row[$idField]);
                }
            }
        }

        if (!$emailOnly && $firstName !== '' && $lastName !== '' && $profile->customerFirstNameField !== null && $profile->customerLastNameField !== null) {
            foreach ($targetConnector->read($objectKey, [$profile->customerSearchParam => rawurlencode($firstName . ' ' . $lastName)]) as $row) {
                if (isset($row[$idField])
                    && strcasecmp(trim((string) ($row[$profile->customerFirstNameField] ?? '')), $firstName) === 0
                    && strcasecmp(trim((string) ($row[$profile->customerLastNameField] ?? '')), $lastName) === 0
                ) {
                    return $normalizeId($row[$idField]);
                }
            }
        }

        if ($email === '' && $firstName === '' && $lastName === '') {
            $notes[] = 'Kein Kunde zugeordnet (Bestellung ohne Name und E-Mail).';
            return null;
        }

        $createTemplate = $profile->customerCreateTemplateId !== null ? $this->loadTemplate($profile->customerCreateTemplateId) : null;
        if ($createTemplate === null) {
            $notes[] = 'Kein Kunde zugeordnet (nicht gefunden, keine Kunden-Vorlage für die Neuanlage im Belegprofil).';
            return null;
        }
        $customerData = $this->translate((int) $createTemplate['id'], (int) $createTemplate['source_object_id'], (int) $createTemplate['target_object_id'], $record, []);
        if ($dryRun) {
            // Daten mit ausgeben, damit sich die Kunden-Vorlage pruefen laesst (z.B. country_code)
            $notes[] = 'Kunde nicht gefunden -- würde über die Kunden-Vorlage neu angelegt (Vorschau legt nichts an) mit: '
                . json_encode($customerData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return null;
        }

        $created = $targetConnector->write($objectKey, 'create', $customerData);
        if (!$created->success) {
            $notes[] = 'Kunde konnte nicht angelegt werden: ' . $created->message;
            return null;
        }

        $newId = GenericHttpConnector::extractByPath($created->responseData, $profile->customerCreatedIdPath);

        return $newId !== null && $newId !== '' ? $normalizeId($newId) : null;
    }

    /**
     * Artikelabgleich je Position gegen den Artikel-Cache des Zielsystems
     * (Vergleichswert des Shops, comparison_role/is_identifier im Connector
     * Builder) -- wie bisher in InvoiceService.
     *
     * @param list<string> $notes
     */
    private function articleResolver(ConnectorInterface $targetConnector, int $targetConnectionId, string $comparisonKey, array &$notes): ?callable
    {
        if (!isset(OrderPreparer::LINE_ITEM_MATCH_FIELDS[$comparisonKey])) {
            $notes[] = "Positions-Abgleich übersprungen (unbekannter Vergleichswert \"{$comparisonKey}\").";
            return null;
        }

        $articleMatch = (new InvoiceService($this->pdo, $this->connectorFactory))->findArticleMatchFields($targetConnector, $comparisonKey);
        if ($articleMatch === null) {
            $notes[] = "Positions-Abgleich übersprungen (kein Ziel-Object mit der Vergleichswert-Rolle \"{$comparisonKey}\" und einem ID-Feld gefunden).";
            return null;
        }

        [$targetObject, $matchField, $identifierField] = $articleMatch;
        $objectCache = new ObjectCache($this->pdo);

        return static function (string $matchValue) use ($objectCache, $targetConnectionId, $targetObject, $matchField, $identifierField): ?string {
            $cachedArticle = $objectCache->findOneByFieldValue($targetConnectionId, $targetObject->key, $matchField->key, $matchValue);

            return $cachedArticle !== null && isset($cachedArticle[$identifierField->key]) ? (string) $cachedArticle[$identifierField->key] : null;
        };
    }

    /**
     * Mapping-Engine auf die aufbereitete Bestellung anwenden.
     *
     * @param array<string, mixed> $record
     * @param array<string, mixed> $mapping fuer die Aktivierungs-Einstellungen (settings), leer bei der Kunden-Vorlage
     * @return array<string, mixed>
     */
    private function translate(int $templateId, int $sourceObjectId, int $targetObjectId, array $record, array $mapping): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM connect_field_mappings WHERE mapping_template_id = :id ORDER BY sort_order, id');
        $stmt->execute(['id' => $templateId]);

        $activationSettings = isset($mapping['settings']) && $mapping['settings'] !== null
            ? (json_decode((string) $mapping['settings'], true) ?: [])
            : [];

        $targetFieldsById = $this->connectorFactory->fieldsByIdForObject($targetObjectId);
        $data = (new MappingEngine($this->pdo))->translate(
            $record,
            $stmt->fetchAll(),
            $this->connectorFactory->fieldsByIdForObject($sourceObjectId),
            $targetFieldsById,
            $activationSettings
        );

        // Maximale Laenge laut Connector Builder (max_length, z.B. HelloCash
        // Positionsname 100 Zeichen): bei Belegen kuerzen statt abbrechen -- ein
        // langer Produktname soll keine Rechnung verhindern.
        foreach ($targetFieldsById as $field) {
            if ($field->maxLength === null) {
                continue;
            }
            if ($field->parentKey === null) {
                if (isset($data[$field->key]) && is_string($data[$field->key])) {
                    $data[$field->key] = mb_substr($data[$field->key], 0, $field->maxLength);
                }
                continue;
            }
            foreach ((array) ($data[$field->parentKey] ?? []) as $index => $line) {
                if (is_array($line) && isset($line[$field->key]) && is_string($line[$field->key])) {
                    $data[$field->parentKey][$index][$field->key] = mb_substr($line[$field->key], 0, $field->maxLength);
                }
            }
        }

        return $data;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadTemplate(int $templateId): ?array
    {
        if ($templateId <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM connect_mapping_templates WHERE id = :id');
        $stmt->execute(['id' => $templateId]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * @return array{success: bool, skipped: bool, targetExternalId: ?string, message: string}
     */
    private static function result(bool $success, bool $skipped, ?string $targetExternalId, string $message): array
    {
        return ['success' => $success, 'skipped' => $skipped, 'targetExternalId' => $targetExternalId, 'message' => $message];
    }
}
