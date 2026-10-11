<?php

declare(strict_types=1);

namespace Connect\Sync;

use Connect\Connector\ConnectorFactory;
use PDO;

/**
 * Ausverkaufte Produkte in Shopify auf "Entwurf" setzen und bei Bestand wieder
 * aktivieren -- Job-Art connect_sync_mappings.mapping_kind = 'product_visibility'
 * (Migration 0073, gleiches Prinzip wie 'stock_sync'/'auto_import_products',
 * siehe Connect\Sync\SyncRunner::run()).
 *
 * Allgemeine Fassung von jobs/cron_shopify_products_hide.php der alten Welt
 * (dort fest fuer danysmusicshop). Die dortigen festen Bedingungen sind hier
 * ADMIN-ONLY-Einstellungen je Shop (app/_sync_mappings_body.php):
 *   - visibility_single_variant_only: nur Produkte mit genau einer Variante ausblenden
 *   - visibility_require_description: nur aktivieren, wenn eine Beschreibung da ist
 *   - visibility_only_matched: nur Produkte, die im Zielsystem zugeordnet sind
 *     (alte Welt: Join auf hellocash_products mit SKU)
 *   - visibility_reactivate_mode: own = nur Produkte, die Connect selbst
 *     ausgeblendet hat (schuetzt bewusst vom Merchant angelegte Entwuerfe),
 *     all = jeder Entwurf mit Bestand (alte Welt), never = nie aktivieren
 *
 * Liest nur aus dem Cache (wie StockSyncRunner). Lagerbestand = das im Connector
 * Builder als Lagerbestand markierte Shopify-Feld (ggf. am gewaehlten Standort).
 * Ein Produkt gilt als ausverkauft, wenn ALLE seine Varianten mit bekanntem
 * Bestand <= 0 haben, als verfuegbar, sobald eine Variante > 0 hat.
 * ARCHIVED wird nie angefasst. Max. MAX_PER_RUN je Richtung und Lauf.
 */
final class ProductVisibilityRunner
{
    /** wie das "limit 20" in cron_shopify_products_hide.php */
    private const MAX_PER_RUN = 20;

    /** Felder des Shopify-Produkt-Objects (Migration 0030/0073) */
    private const PRODUCT_ID_FIELD = 'product_id';
    private const STATUS_FIELD = 'status';
    private const DESCRIPTION_FIELD = 'product_description_start';

    public function __construct(
        private readonly PDO $pdo,
        private readonly ConnectorFactory $connectorFactory
    ) {
    }

    /**
     * @return array{status: string, matched: int, written: int, failed: int, message: string}
     *         status: skipped | success | partial | error
     */
    public function runForShop(string $shop, ?int $runId = null): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT visibility_sync_enabled, visibility_single_variant_only, visibility_require_description,
                    visibility_only_matched, visibility_reactivate_mode
             FROM connect_shops WHERE shop = :shop'
        );
        $stmt->execute(['shop' => $shop]);
        $settings = $stmt->fetch();

        if ($settings === false) {
            return self::result('skipped', 0, 0, 0, 'Shop-Einstellungen (connect_shops) nicht gefunden.');
        }
        if ((int) $settings['visibility_sync_enabled'] !== 1) {
            return self::result('skipped', 0, 0, 0, 'Ausblenden/Einblenden ist für diesen Shop nicht aktiviert (Admin-Einstellung).');
        }

        $singleVariantOnly = (int) $settings['visibility_single_variant_only'] === 1;
        $requireDescription = (int) $settings['visibility_require_description'] === 1;
        $onlyMatched = (int) $settings['visibility_only_matched'] === 1;
        $reactivateMode = in_array($settings['visibility_reactivate_mode'], ['own', 'all', 'never'], true) ? (string) $settings['visibility_reactivate_mode'] : 'own';

        try {
            $context = ProductMatchContext::resolve($this->pdo, $this->connectorFactory, $shop);
        } catch (ProductMatchUnresolvedException $e) {
            return self::result($e->status, 0, 0, 0, $e->getMessage());
        }

        $shopifyObject = $context->shopifyObject;
        $stockKey = $context->shopifyStockField->key;
        $matchKey = $context->shopifyMatchField->key;
        $identifierKey = ObjectCache::requireIdentifierField($shopifyObject)->key;
        $titleField = ProductMatchContext::titleField($shopifyObject);

        $objectCache = new ObjectCache($this->pdo);
        $active = static fn (array $record): bool => ($record['_status'] ?? 'active') !== 'orphaned';
        $shopifyRecords = array_values(array_filter($objectCache->getCached($context->shopifyConnectionId, $shopifyObject->key), $active));
        if ($shopifyRecords === []) {
            return self::result('skipped', 0, 0, 0, 'Noch keine Shopify-Produkte im Zwischenspeicher.');
        }

        $targetValues = [];
        if ($onlyMatched) {
            $targetRecords = array_filter($objectCache->getCached($context->targetConnectionId, $context->targetObject->key), $active);
            foreach ($targetRecords as $record) {
                $value = (string) ($record[$context->targetMatchField->key] ?? '');
                if ($value !== '') {
                    $targetValues[$value] = true;
                }
            }
            if ($targetValues === []) {
                return self::result('skipped', 0, 0, 0, 'Noch keine Daten des Zielsystems im Zwischenspeicher -- „nur zugeordnete Produkte“ kann nicht geprüft werden.');
            }
        }

        $first = $shopifyRecords[0];
        if (!array_key_exists(self::PRODUCT_ID_FIELD, $first) || !array_key_exists(self::STATUS_FIELD, $first)) {
            return self::result('error', 0, 0, 0, 'Shopify-Produkt hat die Felder „product_id“/„status“ nicht (Connector Builder prüfen).');
        }
        $descriptionKnown = array_key_exists(self::DESCRIPTION_FIELD, $first);
        if ($requireDescription && !$descriptionKnown && $reactivateMode !== 'never') {
            return self::result('error', 0, 0, 0, 'Einstellung „nur mit Beschreibung aktivieren“ braucht das Shopify-Feld „product_description_start“ (Migration 0073 bzw. Abfrage im Connector Builder um description(truncateAt: 20) ergänzen).');
        }

        // Varianten je Produkt zusammenfassen
        $products = [];
        foreach ($shopifyRecords as $record) {
            $productId = (string) ($record[self::PRODUCT_ID_FIELD] ?? '');
            if ($productId === '') {
                continue;
            }
            $products[$productId][] = $record;
        }

        $toHide = [];
        $toShow = [];
        foreach ($products as $productId => $variants) {
            $status = strtoupper((string) ($variants[0][self::STATUS_FIELD] ?? ''));
            if ($status !== 'ACTIVE' && $status !== 'DRAFT') {
                continue; // ARCHIVED/unbekannt nie anfassen
            }

            if ($onlyMatched && !self::anyMatched($variants, $matchKey, $targetValues)) {
                continue;
            }

            $known = 0;
            $available = false;
            foreach ($variants as $variant) {
                if (!array_key_exists($stockKey, $variant) || $variant[$stockKey] === null) {
                    continue; // Bestand unbekannt bzw. am Standort nicht gefuehrt
                }
                $known++;
                if ((int) $variant[$stockKey] > 0) {
                    $available = true;
                }
            }
            if ($known === 0) {
                continue;
            }

            if ($status === 'ACTIVE' && !$available) {
                if ($singleVariantOnly && count($variants) !== 1) {
                    continue;
                }
                $toHide[$productId] = $variants;
            } elseif ($status === 'DRAFT' && $available && $reactivateMode !== 'never') {
                if ($requireDescription && trim(strip_tags((string) ($variants[0][self::DESCRIPTION_FIELD] ?? ''))) === '') {
                    continue;
                }
                $toShow[$productId] = $variants;
            }
        }

        if ($reactivateMode === 'own' && $toShow !== []) {
            $toShow = array_intersect_key($toShow, array_flip($this->hiddenByConnect($shop, array_keys($toShow))));
        }

        $matched = count($toHide) + count($toShow);
        $written = 0;
        $failed = 0;
        $errors = [];
        $remaining = max(0, count($toHide) - self::MAX_PER_RUN) + max(0, count($toShow) - self::MAX_PER_RUN);

        foreach (['DRAFT' => array_slice($toHide, 0, self::MAX_PER_RUN, true), 'ACTIVE' => array_slice($toShow, 0, self::MAX_PER_RUN, true)] as $newStatus => $batch) {
            foreach ($batch as $productId => $variants) {
                $productId = (string) $productId;
                $label = $titleField !== null ? (string) ($variants[0][$titleField->key] ?? '') : '';
                $label = $label !== '' ? $label : $productId;
                $action = $newStatus === 'DRAFT' ? 'hide' : 'show';

                $writeResult = $context->shopifyConnector->write($shopifyObject->key, 'set_status', ['productId' => $productId, 'status' => $newStatus]);
                ActivityLog::log(
                    $this->pdo,
                    $shop,
                    'shopify',
                    $shopifyObject->key,
                    'visibility_update',
                    $writeResult->success,
                    $writeResult->success
                        ? "Produkt \"{$label}\" " . ($action === 'hide' ? 'ausverkauft -> Entwurf.' : 'wieder lieferbar -> aktiv.')
                        : "Produkt \"{$label}\": " . $writeResult->message,
                    $productId,
                    $writeResult->requestBody,
                    $writeResult->responseBody,
                    $runId
                );

                if (!$writeResult->success) {
                    $failed++;
                    $errors[] = "{$label}: {$writeResult->message}";
                    continue;
                }

                $written++;
                $this->pdo->prepare(
                    'INSERT INTO connect_product_visibility_log (shop, run_id, product_id, label, action) VALUES (:shop, :run_id, :product_id, :label, :action)'
                )->execute(['shop' => $shop, 'run_id' => $runId, 'product_id' => $productId, 'label' => mb_substr($label, 0, 255), 'action' => $action]);

                // Cache sofort nachziehen, sonst schaltet der naechste Lauf dasselbe Produkt erneut
                foreach ($variants as $variant) {
                    $externalId = (string) ($variant[$identifierKey] ?? '');
                    if ($externalId !== '') {
                        $objectCache->updateCachedFields($context->shopifyConnectionId, $shopifyObject->key, $externalId, [self::STATUS_FIELD => $newStatus]);
                    }
                }
            }
        }

        $status = $failed > 0 ? ($written > 0 ? 'partial' : 'error') : 'success';
        $message = count($toHide) . ' ausverkauft (aktiv), ' . count($toShow) . ' wieder lieferbar (Entwurf) -- '
            . "{$written} umgestellt, {$failed} fehlgeschlagen."
            . ($remaining > 0 ? " Weitere {$remaining} folgen im nächsten Lauf." : '')
            . (!$descriptionKnown ? ' Hinweis: Feld „product_description_start“ fehlt im Zwischenspeicher.' : '');
        if ($errors !== []) {
            $message .= ' Fehler: ' . implode(' | ', array_slice($errors, 0, 10));
        }

        return self::result($status, $matched, $written, $failed, $message);
    }

    /**
     * @param array<int, array<string, mixed>> $variants
     * @param array<string, bool> $targetValues
     */
    private static function anyMatched(array $variants, string $matchKey, array $targetValues): bool
    {
        foreach ($variants as $variant) {
            $value = (string) ($variant[$matchKey] ?? '');
            if ($value !== '' && isset($targetValues[$value])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Produkte, deren LETZTER Eintrag im Protokoll "hide" ist -- also von Connect
     * ausgeblendet und seitdem nicht wieder eingeblendet.
     *
     * @param array<int, string|int> $productIds
     * @return array<int, string>
     */
    private function hiddenByConnect(string $shop, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT l.product_id, l.action FROM connect_product_visibility_log l
             JOIN (SELECT product_id, MAX(id) AS last_id FROM connect_product_visibility_log
                   WHERE shop = ? AND product_id IN ({$placeholders}) GROUP BY product_id) last ON last.last_id = l.id"
        );
        $stmt->execute(array_merge([$shop], array_map('strval', $productIds)));

        $hidden = [];
        foreach ($stmt->fetchAll() as $row) {
            if ($row['action'] === 'hide') {
                $hidden[] = (string) $row['product_id'];
            }
        }

        return $hidden;
    }

    /**
     * @return array{status: string, matched: int, written: int, failed: int, message: string}
     */
    private static function result(string $status, int $matched, int $written, int $failed, string $message): array
    {
        return ['status' => $status, 'matched' => $matched, 'written' => $written, 'failed' => $failed, 'message' => $message];
    }
}
