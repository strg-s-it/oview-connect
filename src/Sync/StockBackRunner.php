<?php

declare(strict_types=1);

namespace Connect\Sync;

use Connect\Connector\ConnectorFactory;
use Connect\Connector\ConnectorInterface;
use Connect\Connector\Generic\GenericHttpConnector;
use Connect\Connector\ObjectDefinition;
use PDO;

/**
 * Rueckbuchung bei Kassenverkauf (connect_sync_mappings.mapping_kind='stock_back', Migration 0104),
 * portiert aus der alten Welt (jobs/cron_sync_stock_back.php, "Zurueck beim Verkauf").
 *
 * Warum: Bei Richtung "Shopify fuehrt" schreibt StockSyncRunner den Shopify-Bestand ins Kassensystem.
 * Ein Kassenverkauf (Kasse 10 -> 9, Shopify bleibt 10) wuerde dabei beim naechsten Abgleich wieder
 * auf 10 zurueckgesetzt. Dieser Job erkennt neue Kassenrechnungen, liest den AKTUELLEN Bestand der
 * verkauften Artikel im Kassensystem und setzt Shopify darauf (absolute Setzung mit
 * changeFromQuantity, wie StockSyncRunner). Eine Stornorechnung wirkt damit von selbst richtig.
 *
 * Ablauf je Lauf:
 *   1. Neue Rechnungen aus der Liste des Zielsystems (Object "invoice_read", read_list),
 *      nur nach stock_back_since und hoechstens LOOKBACK_DAYS zurueck.
 *   2. Je Rechnung atomarer Claim in connect_stock_back_log (UNIQUE shop+invoice_id): eine
 *      Rechnung wird genau einmal verarbeitet, auch bei mehreren Workern.
 *   3. Detail der Rechnung (read_one): Positionen "items" (article_id, quantity, name).
 *      Rechnungen aus Connect selbst (Shopify-Bestellungen) werden uebersprungen: Text enthaelt
 *      "Shopify #" ODER die Rechnungsnummer steht als Erfolg im Aktivitaetsprotokoll.
 *   4. Je Artikel: Artikel live lesen (Bestand + Vergleichswert), passende Shopify-Variante
 *      ueber den Vergleichswert im Zwischenspeicher finden, live nachladen, Bestand setzen.
 *   5. Optional (stock_back_dummy_order): Bestellung in Shopify anlegen (Notiz "POS helloCash #Nr",
 *      Bestandsverhalten "bypass" -- der Bestand wird oben schon gesetzt). Idempotenz doppelt:
 *      eigener Claim UND Pruefung live gegen Shopify (Notiz in den Bestellungen der letzten 2 Stunden).
 *      Ohne financialStatus bleibt die Bestellung unbezahlt -- orders/paid loest also keine
 *      Rechnung in Connect aus (wie in der alten Welt).
 *
 * Nur Richtung shopify_to_target. Bei "Kassensystem fuehrt" kommt der Kassenbestand ohnehin
 * zu Shopify (StockSyncRunner).
 *
 * Voraussetzung im Connector Builder (helloCash, Object "invoice_read"), siehe HANDOFF.md:
 *   Felder: id (Kennung), invoice_number, type, status, note, created_at und die wiederholende
 *   Gruppe "items" mit article_id, quantity, name.
 *   Operationen: read_list (GET invoices mit from/status/limit/offset) und read_one (GET invoices/{{id}}).
 */
final class StockBackRunner
{
    public const INVOICE_OBJECT = 'invoice_read';

    /** Hoechstens so viele Rechnungen je Lauf, der Rest folgt im naechsten Lauf. */
    private const MAX_INVOICES_PER_RUN = 30;

    /** Wie weit die Liste zurueckgreift, unabhaengig von stock_back_since. */
    private const LOOKBACK_DAYS = 3;

    private const MAX_ATTEMPTS = 5;

    /** Haengende "pending"-Claims (Abbruch mitten im Lauf) werden nach dieser Zeit freigegeben. */
    private const STALE_CLAIM_MINUTES = 30;

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
        try {
            $stmt = $this->pdo->prepare(
                'SELECT stock_back_enabled, stock_back_dummy_order, stock_back_since, stock_sync_direction
                 FROM connect_shops WHERE shop = :shop'
            );
            $stmt->execute(['shop' => $shop]);
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return self::result('skipped', 0, 0, 0, 'Migration 0104 fehlt (php bin/install.php).', []);
        }

        if ($settings === false) {
            return self::result('skipped', 0, 0, 0, 'Shop-Einstellungen (connect_shops) nicht gefunden.', []);
        }
        if ((int) $settings['stock_back_enabled'] !== 1) {
            return self::result('skipped', 0, 0, 0, 'Rückbuchung bei Kassenverkauf ist für diesen Shop nicht aktiviert.', []);
        }
        if ((string) $settings['stock_sync_direction'] !== 'shopify_to_target') {
            return self::result('skipped', 0, 0, 0, 'Rückbuchung gilt nur bei Richtung „Shopify führt“ – bei „Kassensystem führt“ kommt der Kassenbestand ohnehin zu Shopify.', []);
        }

        try {
            $context = ProductMatchContext::resolve($this->pdo, $this->connectorFactory, $shop);
        } catch (ProductMatchUnresolvedException $e) {
            return self::result($e->status, 0, 0, 0, $e->getMessage(), []);
        }
        if ($context->locationId === null || $context->locationId === '') {
            return self::result('skipped', 0, 0, 0, 'Kein Shopify-Standort gewählt – Rückbuchung pausiert.', []);
        }

        $targetConnector = $context->targetConnector;
        $invoiceObject = self::invoiceObject($targetConnector);
        if ($invoiceObject === null || !$targetConnector instanceof GenericHttpConnector
            || !$targetConnector->hasOperation(self::INVOICE_OBJECT, 'read_list')
            || !$targetConnector->hasOperation(self::INVOICE_OBJECT, 'read_one')
            || !$targetConnector->hasOperation($context->targetObject->key, 'read_one')
        ) {
            return self::result('skipped', 0, 0, 0, 'Im Connector Builder fehlt das Object „' . self::INVOICE_OBJECT . '“ (read_list + read_one für GET /invoices) bzw. read_one am Artikel-Object – Rückbuchung pausiert.', []);
        }

        // Ohne Startzeitpunkt wird nichts nachtraeglich verarbeitet: ab jetzt zaehlen neue Rechnungen.
        $sinceValue = (string) ($settings['stock_back_since'] ?? '');
        $sinceTs = $sinceValue !== '' ? strtotime($sinceValue) : false;
        if ($sinceTs === false) {
            $this->pdo->prepare('UPDATE connect_shops SET stock_back_since = :since WHERE shop = :shop')
                ->execute(['since' => date('Y-m-d H:i:s'), 'shop' => $shop]);

            return self::result('skipped', 0, 0, 0, 'Startzeitpunkt der Rückbuchung gesetzt – ab jetzt werden neue Kassenrechnungen verarbeitet.', []);
        }

        // Neue Rechnungen einsammeln (Liste: nur Kopfdaten), aelteste zuerst
        $fromTs = max($sinceTs, time() - self::LOOKBACK_DAYS * 86400);
        $candidates = [];
        try {
            foreach ($targetConnector->read(self::INVOICE_OBJECT, ['from' => date('Y-m-d', $fromTs), 'status' => 'completed']) as $row) {
                $invoiceId = trim((string) ($row['id'] ?? ''));
                $createdTs = strtotime((string) ($row['created_at'] ?? ''));
                if ($invoiceId === '' || $createdTs === false || $createdTs <= $sinceTs) {
                    continue;
                }
                $candidates[$invoiceId] = ['ts' => $createdTs, 'number' => trim((string) ($row['invoice_number'] ?? ''))];
            }
        } catch (\Throwable $e) {
            return self::result('error', 0, 0, 1, 'Rechnungsliste nicht abrufbar: ' . $e->getMessage(), []);
        }
        uasort($candidates, static fn (array $a, array $b): int => $a['ts'] <=> $b['ts']);

        $objectCache = new ObjectCache($this->pdo);
        $shopifyIndex = null; // lazy, erst bei der ersten verwertbaren Rechnung
        $dummyOrders = (int) $settings['stock_back_dummy_order'] === 1;

        $processed = 0;
        $matched = 0;
        $written = 0;
        $failed = 0;
        $skipped = 0;
        $deferred = 0;
        $adjustments = [];
        $errorMessages = [];

        foreach ($candidates as $invoiceId => $candidate) {
            // Kennung gehoert zu keiner offenen Arbeit? Dann ohne Claim gar nicht erst anfassen.
            if ($processed >= self::MAX_INVOICES_PER_RUN) {
                $deferred++;
                continue;
            }

            $claim = $this->claim($shop, (string) $invoiceId, $candidate['number']);
            if ($claim === null) {
                continue; // bereits verarbeitet oder in Arbeit
            }
            $processed++;

            try {
                if ($shopifyIndex === null) {
                    $shopifyIndex = $this->shopifyIndex($objectCache, $context);
                }
                $outcome = $this->processInvoice($shop, $runId, (string) $invoiceId, $claim, $context, $objectCache, $shopifyIndex, $dummyOrders);
            } catch (\Throwable $e) {
                $outcome = ['status' => 'error', 'stock' => 'error', 'dummy' => null, 'message' => $e->getMessage(), 'matched' => 0, 'written' => 0, 'failed' => 1, 'adjustments' => []];
            }

            $this->finish($shop, (string) $invoiceId, $outcome);
            $matched += $outcome['matched'];
            $written += $outcome['written'];
            $failed += $outcome['failed'];
            $skipped += $outcome['status'] === 'skipped' ? 1 : 0;
            array_push($adjustments, ...$outcome['adjustments']);
            if ($outcome['status'] === 'error') {
                $errorMessages[] = 'Rechnung ' . ($candidate['number'] !== '' ? $candidate['number'] : $invoiceId) . ': ' . $outcome['message'];
            }
        }

        $status = $failed > 0 ? ($written > 0 ? 'partial' : 'error') : 'success';
        $message = "{$processed} neue Kassenrechnung(en) verarbeitet ({$skipped} übersprungen), {$matched} Artikel zugeordnet, {$written} Bestände in Shopify gesetzt, {$failed} fehlgeschlagen.";
        if ($deferred > 0) {
            $message .= " {$deferred} weitere folgen im nächsten Lauf.";
        }
        if ($errorMessages !== []) {
            $message .= ' Fehler: ' . implode(' | ', array_slice($errorMessages, 0, 10));
        }

        return self::result($status, $matched, $written, $failed, $message, $adjustments);
    }

    /**
     * Eine Rechnung verarbeiten (Claim ist schon gesetzt).
     *
     * @param array{stock_done: bool, dummy_done: bool, attempts: int} $claim
     * @param array{records: array<string, array<string, mixed>>, duplicates: array<string, mixed>} $shopifyIndex
     * @return array{status: string, stock: ?string, dummy: ?string, message: string, matched: int, written: int, failed: int, adjustments: array<int, array{match_value: string, label: ?string, direction: string, old_quantity: ?int, new_quantity: int}>}
     */
    private function processInvoice(string $shop, ?int $runId, string $invoiceId, array $claim, ProductMatchContext $context, ObjectCache $cache, array $shopifyIndex, bool $dummyOrders): array
    {
        $detail = $context->targetConnector->readOne(self::INVOICE_OBJECT, ['id' => $invoiceId]);
        if ($detail === null) {
            return self::outcome('skipped', null, null, 'Rechnung im Kassensystem nicht (mehr) gefunden.');
        }

        $number = trim((string) ($detail['invoice_number'] ?? ''));
        $text = (string) ($detail['note'] ?? '');

        // Rechnungen, die Connect selbst fuer eine Shopify-Bestellung angelegt hat: dort ist der
        // Shopify-Bestand schon richtig, und eine Dummy-Bestellung waere ein Duplikat der echten.
        if (stripos($text, 'Shopify #') !== false || ($number !== '' && $this->createdByConnect($shop, $number))) {
            return self::outcome('skipped', null, null, 'Rechnung stammt aus einer Shopify-Bestellung (von Connect angelegt).');
        }
        if (strtolower((string) ($detail['type'] ?? '')) === 'cancellation') {
            $detail['_is_cancellation'] = true;
        }

        $items = is_array($detail['items'] ?? null) ? $detail['items'] : [];
        $stockStatus = $claim['stock_done'] ? 'success' : null;
        $matched = 0;
        $written = 0;
        $failed = 0;
        $adjustments = [];
        $notes = [];
        $dummyLines = []; // variantId => Menge

        if (!$claim['stock_done'] || ($dummyOrders && !$claim['dummy_done'])) {
            $seenArticles = [];
            foreach ($items as $item) {
                $articleId = is_array($item) ? ($item['article_id'] ?? null) : null;
                if ($articleId === null || $articleId === '' || isset($seenArticles[(string) $articleId])) {
                    continue; // Position ohne Artikel (Freitext, Rabatt) oder derselbe Artikel nochmal
                }
                $seenArticles[(string) $articleId] = true;
                $itemName = (string) ($item['name'] ?? $articleId);

                // Verkaufte Menge je Artikel (mehrere Positionen desselben Artikels zusammenfassen)
                $quantity = 0.0;
                foreach ($items as $other) {
                    if (is_array($other) && (string) ($other['article_id'] ?? '') === (string) $articleId) {
                        $quantity += (float) ($other['quantity'] ?? 0);
                    }
                }

                // Artikel live: aktueller Kassenbestand + Vergleichswert
                $targetKey = ObjectCache::requireIdentifierField($context->targetObject)->key;
                if (!$cache->refreshOne($context->targetConnector, $context->targetConnectionId, $context->targetObject, [$targetKey => (string) $articleId, 'id' => (string) $articleId])) {
                    $notes[] = "„{$itemName}“: Artikel im Kassensystem nicht gefunden.";
                    continue;
                }
                $article = $cache->getCachedOne($context->targetConnectionId, $context->targetObject->key, (string) $articleId);
                if ($article === null) {
                    $notes[] = "„{$itemName}“: Artikel im Kassensystem nicht gefunden.";
                    continue;
                }
                $matchValue = trim((string) ($article[$context->targetMatchField->key] ?? ''));
                if ($matchValue === '') {
                    $notes[] = "„{$itemName}“: Artikel ohne Vergleichswert ({$context->comparisonKey}) – nicht zugeordnet.";
                    continue;
                }
                $shopifyRecord = $shopifyIndex['records'][$matchValue] ?? null;
                if ($shopifyRecord === null || isset($shopifyIndex['duplicates'][$matchValue])) {
                    $notes[] = "„{$itemName}“ ({$matchValue}): in Shopify nicht (eindeutig) gefunden.";
                    continue;
                }
                $matched++;

                if ($quantity > 0 && !isset($detail['_is_cancellation'])) {
                    $variantId = (string) ($shopifyRecord[ObjectCache::requireIdentifierField($context->shopifyObject)->key] ?? '');
                    if ($variantId !== '') {
                        $dummyLines[$variantId] = ($dummyLines[$variantId] ?? 0) + (int) round($quantity);
                    }
                }
                if ($claim['stock_done']) {
                    continue;
                }

                if (!array_key_exists($context->targetStockField->key, $article) || $article[$context->targetStockField->key] === null) {
                    $failed++;
                    $notes[] = "„{$itemName}“ ({$matchValue}): Kassenbestand unbekannt – nicht gesetzt.";
                    continue;
                }
                $newQuantity = (int) $article[$context->targetStockField->key];

                // Shopify-Variante live nachladen (changeFromQuantity + aktueller Standort-Bestand)
                $shopifyKey = ObjectCache::requireIdentifierField($context->shopifyObject)->key;
                $shopifyId = (string) ($shopifyRecord[$shopifyKey] ?? '');
                if (!$cache->refreshOne($context->shopifyConnector, $context->shopifyConnectionId, $context->shopifyObject, [$shopifyKey => $shopifyId, 'id' => $shopifyId])) {
                    $notes[] = "„{$itemName}“ ({$matchValue}): in Shopify nicht mehr gefunden.";
                    continue;
                }
                $live = $cache->getCachedOne($context->shopifyConnectionId, $context->shopifyObject->key, $shopifyId);
                if ($live === null || !array_key_exists($context->shopifyStockField->key, $live) || $live[$context->shopifyStockField->key] === null) {
                    $notes[] = "„{$itemName}“ ({$matchValue}): Produkt wird am gewählten Standort nicht geführt.";
                    continue;
                }
                $oldQuantity = (int) $live[$context->shopifyStockField->key];
                if ($oldQuantity === $newQuantity) {
                    continue; // Shopify ist schon richtig
                }
                $inventoryItemId = $live['inventory_item_id'] ?? null;
                if ($inventoryItemId === null || $inventoryItemId === '') {
                    $failed++;
                    $notes[] = "„{$itemName}“ ({$matchValue}): ohne inventory_item_id.";
                    continue;
                }

                $result = $context->shopifyConnector->write($context->shopifyObject->key, 'update', [
                    'inventoryItemId' => $inventoryItemId,
                    'locationId' => $context->locationId,
                    'quantity' => $newQuantity,
                    'changeFromQuantity' => $oldQuantity,
                ]);
                ActivityLog::log(
                    $this->pdo, $shop, 'shopify', $context->shopifyObject->key, 'stock_update', $result->success,
                    $result->success
                        ? "Rückbuchung Kassenrechnung {$number}: „{$itemName}“ ({$matchValue}) {$oldQuantity} -> {$newQuantity}."
                        : "Rückbuchung Kassenrechnung {$number}: " . $result->message,
                    $shopifyId, $result->requestBody, $result->responseBody, $runId
                );
                if ($result->success) {
                    $written++;
                    $cache->updateCachedFields($context->shopifyConnectionId, $context->shopifyObject->key, $shopifyId, [$context->shopifyStockField->key => $newQuantity]);
                    $titleField = ProductMatchContext::titleField($context->shopifyObject);
                    $label = $titleField !== null ? ($live[$titleField->key] ?? null) : null;
                    $adjustments[] = [
                        'match_value' => $matchValue,
                        'label' => $label !== null && $label !== '' ? (string) $label : $itemName,
                        'direction' => 'stock_back',
                        'old_quantity' => $oldQuantity,
                        'new_quantity' => $newQuantity,
                    ];
                } else {
                    $failed++;
                    $notes[] = "„{$itemName}“ ({$matchValue}): " . $result->message;
                }
            }
            if (!$claim['stock_done']) {
                $stockStatus = $failed > 0 ? 'error' : 'success';
            }
        }

        // Dummy-Bestellung (eigener Status, damit ein Wiederholungsversuch sie nicht doppelt anlegt)
        $dummyStatus = $claim['dummy_done'] ? 'success' : null;
        if ($dummyOrders && !$claim['dummy_done']) {
            if ($dummyLines === [] || isset($detail['_is_cancellation'])) {
                $dummyStatus = 'skipped';
                $notes[] = 'Keine Dummy-Bestellung (keine zugeordneten Artikel oder Stornorechnung).';
            } elseif ($stockStatus === 'error') {
                $dummyStatus = null; // erst nach erfolgreicher Bestandsbuchung (Wiederholung)
            } else {
                try {
                    $dummyNote = 'POS helloCash #' . $number;
                    $created = $this->createDummyOrder($shop, $runId, $context->shopifyConnector, (string) $context->locationId, $dummyNote, $number, $dummyLines);
                    $dummyStatus = $created ? 'success' : 'skipped';
                    $notes[] = $created ? "Dummy-Bestellung „{$dummyNote}“ angelegt." : "Dummy-Bestellung „{$dummyNote}“ gab es schon in Shopify.";
                } catch (\Throwable $e) {
                    $dummyStatus = 'error';
                    $failed++;
                    $notes[] = 'Dummy-Bestellung fehlgeschlagen: ' . $e->getMessage();
                }
            }
        }

        $status = ($stockStatus === 'error' || $dummyStatus === 'error') ? 'error' : 'success';

        return [
            'status' => $status,
            'stock' => $stockStatus,
            'dummy' => $dummyStatus,
            'message' => $notes === [] ? 'ok' : implode(' ', $notes),
            'matched' => $matched,
            'written' => $written,
            'failed' => $failed,
            'adjustments' => $adjustments,
        ];
    }

    /**
     * Bestellung in Shopify anlegen, ausser es gibt sie schon (Notiz, letzte 2 Stunden, live geprueft).
     * Bestandsverhalten ist "bypass" (Standard von orderCreate): der Bestand wurde schon gesetzt.
     *
     * @param array<string, int> $lines variantId => Menge
     * @return bool true = angelegt, false = gab es schon
     */
    private function createDummyOrder(string $shop, ?int $runId, ConnectorInterface $shopifyConnector, string $locationId, string $note, string $number, array $lines): bool
    {
        if (!$shopifyConnector instanceof GenericHttpConnector) {
            throw new \RuntimeException('Shopify-Connector unterstützt keine GraphQL-Abfrage.');
        }

        $since = gmdate('Y-m-d\TH:i:s\Z', time() - 2 * 3600);
        $existing = $shopifyConnector->graphQlQuery(
            'query($q: String!) { orders(first: 100, query: $q) { nodes { id note } } }',
            ['q' => 'created_at:>=' . $since]
        );
        if (isset($existing['errors'])) {
            throw new \RuntimeException('Prüfung auf vorhandene Bestellung fehlgeschlagen: ' . json_encode($existing['errors']));
        }
        foreach ((array) ($existing['data']['orders']['nodes'] ?? []) as $order) {
            if (is_array($order) && (string) ($order['note'] ?? '') === $note) {
                return false;
            }
        }

        $email = 'hellocash-' . ($number !== '' ? $number : 'pos') . '@oview.at';
        $lineItems = [];
        foreach ($lines as $variantId => $quantity) {
            $lineItems[] = ['variantId' => $variantId, 'quantity' => $quantity];
        }
        $variables = ['order' => [
            'email' => $email,
            'note' => $note,
            'lineItems' => $lineItems,
            'customer' => ['toUpsert' => ['email' => $email, 'firstName' => 'POS', 'lastName' => 'helloCash']],
            'fulfillment' => ['locationId' => $locationId, 'notifyCustomer' => false],
        ]];
        // Aliasiert als "result", damit die userErrors-Pruefung greift (siehe CLAUDE.md)
        $response = $shopifyConnector->graphQlQuery(
            'mutation($order: OrderCreateOrderInput!) { result: orderCreate(order: $order) { order { id name } userErrors { field message } } }',
            $variables
        );
        $errors = $response['errors'] ?? null;
        $userErrors = $response['data']['result']['userErrors'] ?? [];
        $orderId = $response['data']['result']['order']['id'] ?? null;
        $success = $errors === null && $userErrors === [] && $orderId !== null;
        ActivityLog::log(
            $this->pdo, $shop, 'shopify', 'order', 'create_pos_order', $success,
            $success ? "Dummy-Bestellung „{$note}“ angelegt." : "Dummy-Bestellung „{$note}“ fehlgeschlagen.",
            $orderId !== null ? (string) $orderId : null, json_encode($variables) ?: null, json_encode($response) ?: null, $runId
        );
        if (!$success) {
            $detail = $errors !== null ? json_encode($errors) : json_encode($userErrors);
            throw new \RuntimeException('orderCreate: ' . ($detail !== false && $detail !== '[]' ? $detail : 'keine Bestellung zurückgegeben'));
        }

        return true;
    }

    /**
     * Atomarer Claim je Kassenrechnung.
     *
     * @return array{stock_done: bool, dummy_done: bool, attempts: int}|null null = nicht uebernommen (fertig oder in Arbeit)
     */
    private function claim(string $shop, string $invoiceId, string $number): ?array
    {
        $insert = $this->pdo->prepare(
            "INSERT IGNORE INTO connect_stock_back_log (shop, invoice_id, invoice_number, status, attempts, claimed_at)
             VALUES (:shop, :invoice, :number, 'pending', 1, NOW())"
        );
        $insert->execute(['shop' => $shop, 'invoice' => $invoiceId, 'number' => $number !== '' ? $number : null]);
        if ($insert->rowCount() === 1) {
            return ['stock_done' => false, 'dummy_done' => false, 'attempts' => 1];
        }

        // Fehlgeschlagen (hoechstens MAX_ATTEMPTS) oder haengend: erneut uebernehmen
        $retry = $this->pdo->prepare(
            "UPDATE connect_stock_back_log
             SET status = 'pending', attempts = attempts + 1, claimed_at = NOW()
             WHERE shop = :shop AND invoice_id = :invoice AND attempts < :max
               AND (status = 'error' OR (status = 'pending' AND claimed_at < (NOW() - INTERVAL " . self::STALE_CLAIM_MINUTES . ' MINUTE)))'
        );
        $retry->execute(['shop' => $shop, 'invoice' => $invoiceId, 'max' => self::MAX_ATTEMPTS]);
        if ($retry->rowCount() !== 1) {
            return null;
        }

        $row = $this->pdo->prepare('SELECT stock_status, dummy_status, attempts FROM connect_stock_back_log WHERE shop = :shop AND invoice_id = :invoice');
        $row->execute(['shop' => $shop, 'invoice' => $invoiceId]);
        $data = $row->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'stock_done' => ($data['stock_status'] ?? null) === 'success',
            'dummy_done' => in_array($data['dummy_status'] ?? null, ['success', 'skipped'], true),
            'attempts' => (int) ($data['attempts'] ?? 1),
        ];
    }

    /**
     * @param array{status: string, stock: ?string, dummy: ?string, message: string} $outcome
     */
    private function finish(string $shop, string $invoiceId, array $outcome): void
    {
        $this->pdo->prepare(
            'UPDATE connect_stock_back_log
             SET status = :status, stock_status = COALESCE(:stock, stock_status), dummy_status = COALESCE(:dummy, dummy_status),
                 message = :message, finished_at = NOW()
             WHERE shop = :shop AND invoice_id = :invoice'
        )->execute([
            'status' => $outcome['status'],
            'stock' => $outcome['stock'],
            'dummy' => $outcome['dummy'],
            'message' => mb_substr($outcome['message'], 0, 4000),
            'shop' => $shop,
            'invoice' => $invoiceId,
        ]);
    }

    /** Hat Connect diese Rechnungsnummer selbst (fuer eine Shopify-Bestellung) angelegt? */
    private function createdByConnect(string $shop, string $number): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM connect_activity_log
             WHERE shop = :shop AND object_key = 'invoice' AND operation = 'create' AND status = 'success' AND external_id = :number
             LIMIT 1"
        );
        $stmt->execute(['shop' => $shop, 'number' => $number]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Shopify-Varianten nach Vergleichswert (nur aktive Zeilen am gewaehlten Standort), samt Mehrfachwerten.
     *
     * @return array{records: array<string, array<string, mixed>>, duplicates: array<string, mixed>}
     */
    private function shopifyIndex(ObjectCache $cache, ProductMatchContext $context): array
    {
        $locationScoped = $context->shopifyStockField->key === 'location_stock_quantity';
        $records = [];
        foreach ($cache->getCached($context->shopifyConnectionId, $context->shopifyObject->key) as $record) {
            if (($record['_status'] ?? 'active') === 'orphaned') {
                continue;
            }
            if ($locationScoped && (string) ($record['_location_id'] ?? '') !== (string) $context->locationId) {
                continue;
            }
            $records[] = $record;
        }
        $duplicates = ComparisonValueCheck::duplicateValues($records, $context->shopifyMatchField->key);
        $index = [];
        foreach ($records as $record) {
            $value = $record[$context->shopifyMatchField->key] ?? null;
            if ($value !== null && $value !== '') {
                $index[(string) $value] = $record;
            }
        }

        return ['records' => $index, 'duplicates' => $duplicates];
    }

    private static function invoiceObject(ConnectorInterface $connector): ?ObjectDefinition
    {
        foreach ($connector->getObjects() as $object) {
            if ($object->key === self::INVOICE_OBJECT) {
                return $object;
            }
        }

        return null;
    }

    /**
     * @return array{status: string, stock: ?string, dummy: ?string, message: string, matched: int, written: int, failed: int, adjustments: array<int, array{match_value: string, label: ?string, direction: string, old_quantity: ?int, new_quantity: int}>}
     */
    private static function outcome(string $status, ?string $stock, ?string $dummy, string $message): array
    {
        return ['status' => $status, 'stock' => $stock, 'dummy' => $dummy, 'message' => $message, 'matched' => 0, 'written' => 0, 'failed' => 0, 'adjustments' => []];
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
