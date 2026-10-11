<?php

declare(strict_types=1);

namespace Connect\Sync;

use Connect\Connector\ConnectorFactory;
use PDO;

/**
 * Sammelaenderungen an Preisen (Migration 0088): Artikel per Filter auswaehlen, Aktion
 * (Prozent, Betrag, fester Preis, Sale) mit Rundung, Vorschau alt -> neu, Ausfuehrung im
 * Hintergrund (bin/scheduler.php -> PriceTasks), Rueckgaengig. Die Preissynchronisation legt ihre
 * Aenderungen als Sammelaenderung der Art "sync" an -- gleiche Ausfuehrung, gleicher Verlauf.
 *
 * Datenquelle ist der Zwischenspeicher (Shopify-Varianten + Artikel des Zielsystems, zugeordnet
 * ueber den Vergleichswert wie beim Lagerabgleich). Doppelte Vergleichswerte werden ausgelassen.
 */
final class BatchEditService
{
    public const ACTIONS = [
        'percent' => 'Preis um Prozent ändern',
        'amount' => 'Preis um Betrag ändern',
        'set' => 'Fester Preis',
        'sale' => 'Sale: Vergleichspreis = bisheriger Preis, Preis um Prozent senken',
    ];
    public const ROUNDING = ['none' => 'nicht runden', '90' => 'auf ,90', '99' => 'auf ,99', '00' => 'auf volle Euro', '50' => 'auf ,50 / ,00'];

    /** Shopify-Produkte je Hintergrund-Durchlauf, Zielsystem-Artikel je Durchlauf */
    private const SHOPIFY_PRODUCTS_PER_RUN = 40;
    private const TARGET_ITEMS_PER_RUN = 60;

    public function __construct(private readonly PDO $pdo, private readonly ConnectorFactory $factory)
    {
    }

    /**
     * Alle zuordenbaren Artikel des Shops.
     *
     * @return list<array{match_value: string, label: string, vendor: string, product_type: string, status: string, sku: string,
     *                    shopify_id: string, product_id: string, shopify_price: ?float, target_id: ?string, target_price: ?float}>
     */
    public function candidates(string $shop): array
    {
        $context = ProductMatchContext::resolve($this->pdo, $this->factory, $shop);
        $cache = new ObjectCache($this->pdo);
        $active = static fn (array $r): bool => ($r['_status'] ?? 'active') !== 'orphaned';
        $shopifyRecords = array_values(array_filter($cache->getCached($context->shopifyConnectionId, $context->shopifyObject->key), $active));
        $targetRecords = array_values(array_filter($cache->getCached($context->targetConnectionId, $context->targetObject->key), $active));

        $shopifyMatch = $context->shopifyMatchField->key;
        $targetMatch = $context->targetMatchField->key;
        $shopifyDup = ComparisonValueCheck::duplicateValues($shopifyRecords, $shopifyMatch);
        $targetDup = ComparisonValueCheck::duplicateValues($targetRecords, $targetMatch);
        $shopifyPrice = ProductMatchContext::fieldWithComparisonRole($context->shopifyObject, 'price');
        $targetPrice = ShopStockRules::targetPriceField($context->targetObject, ShopStockRules::forShop($this->pdo, $shop)['prices_gross']);
        $shopifyId = ObjectCache::requireIdentifierField($context->shopifyObject)->key;
        $targetId = ObjectCache::requireIdentifierField($context->targetObject)->key;
        $titleField = ProductMatchContext::titleField($context->shopifyObject);

        $targetIndex = [];
        foreach ($targetRecords as $record) {
            $value = (string) ($record[$targetMatch] ?? '');
            if ($value !== '' && !isset($targetDup[$value])) {
                $targetIndex[$value] = $record;
            }
        }

        $rows = [];
        foreach ($shopifyRecords as $record) {
            $value = (string) ($record[$shopifyMatch] ?? '');
            if ($value === '' || isset($shopifyDup[$value]) || isset($targetDup[$value])) {
                continue;
            }
            $target = $targetIndex[$value] ?? null;
            $title = $titleField !== null ? (string) ($record[$titleField->key] ?? '') : '';
            $variant = (string) ($record['variant_title'] ?? '');
            $rows[] = [
                'match_value' => $value,
                'label' => $title . ($variant !== '' && $variant !== 'Default Title' ? ' – ' . $variant : ''),
                'vendor' => (string) ($record['vendor'] ?? ''),
                'product_type' => (string) ($record['product_type'] ?? ''),
                'status' => strtolower((string) ($record['status'] ?? '')),
                'sku' => (string) ($record['sku'] ?? ''),
                'shopify_id' => (string) ($record[$shopifyId] ?? ''),
                'product_id' => (string) ($record['product_id'] ?? ''),
                'shopify_price' => $shopifyPrice !== null ? ShopStockRules::price($record[$shopifyPrice->key] ?? null) : null,
                'target_id' => $target !== null ? (string) ($target[$targetId] ?? '') : null,
                'target_price' => $target !== null && $targetPrice !== null ? ShopStockRules::price($target[$targetPrice->key] ?? null) : null,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $f q, vendor, product_type, status, price_min, price_max
     * @return list<array<string, mixed>>
     */
    public static function filter(array $rows, array $f): array
    {
        $q = mb_strtolower(trim((string) ($f['q'] ?? '')));
        $min = ShopStockRules::price($f['price_min'] ?? null);
        $max = ShopStockRules::price($f['price_max'] ?? null);

        return array_values(array_filter($rows, static function (array $r) use ($f, $q, $min, $max): bool {
            if ($q !== '' && !str_contains(mb_strtolower($r['label'] . ' ' . $r['sku'] . ' ' . $r['match_value']), $q)) {
                return false;
            }
            foreach (['vendor', 'product_type', 'status'] as $key) {
                if (($f[$key] ?? '') !== '' && strcasecmp((string) $r[$key], (string) $f[$key]) !== 0) {
                    return false;
                }
            }
            $price = $r['shopify_price'] ?? $r['target_price'];
            if ($min !== null && ($price === null || $price < $min)) {
                return false;
            }
            if ($max !== null && ($price === null || $price > $max)) {
                return false;
            }

            return true;
        }));
    }

    /** Neuer Preis aus altem Preis + Aktion + Rundung (nie unter 0). */
    public static function apply(float $old, string $type, float $value, string $rounding): float
    {
        $new = match ($type) {
            'percent' => $old * (1 + $value / 100),
            'amount' => $old + $value,
            'set' => $value,
            'sale' => $old * (1 - abs($value) / 100),
            default => $old,
        };
        $new = max(0.0, $new);

        return match ($rounding) {
            '90' => self::nearestEnding($new, 0.90),
            '99' => self::nearestEnding($new, 0.99),
            '00' => round($new),
            '50' => round($new * 2) / 2,
            default => round($new, 2),
        };
    }

    /** Naechstgelegener Preis mit dieser Endung (z.B. 12,37 -> 11,90 oder 12,90: 12,90 ist naeher). */
    private static function nearestEnding(float $value, float $ending): float
    {
        $base = floor($value);
        $candidates = array_filter([$base - 1 + $ending, $base + $ending], static fn (float $c): bool => $c >= 0);
        if ($candidates === []) {
            return round($value, 2);
        }
        usort($candidates, static fn (float $a, float $b): int => abs($a - $value) <=> abs($b - $value));

        return round($candidates[0], 2);
    }

    /**
     * Vorschau: je Artikel und Seite alt -> neu.
     *
     * @param list<array<string, mixed>> $rows
     * @param array{type: string, value: float, rounding: string} $action
     * @return list<array{match_value: string, label: string, side: string, external_id: string, product_id: ?string, old_price: float, new_price: float, set_compare_at: int, new_compare_at: ?float}>
     */
    public static function preview(array $rows, array $action, string $applyTo): array
    {
        $items = [];
        foreach ($rows as $r) {
            // Basis: Shopify-Preis, nur "nur Zielsystem" rechnet vom Preis des Zielsystems
            $base = $applyTo === 'target' ? ($r['target_price'] ?? null) : ($r['shopify_price'] ?? null);
            if ($base === null) {
                continue;
            }
            $new = self::apply($base, $action['type'], $action['value'], $action['rounding']);
            $sale = $action['type'] === 'sale';
            if (in_array($applyTo, ['shopify', 'both'], true) && $r['shopify_id'] !== '' && $r['shopify_price'] !== null
                && ($sale || ShopStockRules::pricesDiffer($r['shopify_price'], $new))) {
                $items[] = [
                    'match_value' => $r['match_value'], 'label' => $r['label'], 'side' => 'shopify', 'external_id' => $r['shopify_id'],
                    'product_id' => $r['product_id'], 'old_price' => $r['shopify_price'], 'new_price' => $new,
                    'set_compare_at' => $sale ? 1 : 0, 'new_compare_at' => $sale ? $r['shopify_price'] : null,
                ];
            }
            if (in_array($applyTo, ['target', 'both'], true) && $r['target_id'] !== null && $r['target_id'] !== '' && $r['target_price'] !== null
                && ShopStockRules::pricesDiffer($r['target_price'], $new)) {
                $items[] = [
                    'match_value' => $r['match_value'], 'label' => $r['label'], 'side' => 'target', 'external_id' => $r['target_id'],
                    'product_id' => null, 'old_price' => $r['target_price'], 'new_price' => $new, 'set_compare_at' => 0, 'new_compare_at' => null,
                ];
            }
        }

        return $items;
    }

    /**
     * Sammelaenderung anlegen und zur Ausfuehrung freigeben.
     *
     * @param list<array<string, mixed>> $items aus preview()
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $action
     */
    public function queue(string $shop, string $kind, string $title, array $filter, array $action, string $applyTo, array $items, ?string $createdBy, ?int $undoOf = null): int
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO connect_batch_changes (shop, kind, title, filter, action, apply_to, status, total, undo_of, created_by)
                 VALUES (:shop, :kind, :title, :filter, :action, :apply_to, \'queued\', :total, :undo_of, :by)'
            )->execute([
                'shop' => $shop, 'kind' => $kind, 'title' => mb_substr($title, 0, 255),
                'filter' => json_encode($filter, JSON_UNESCAPED_UNICODE), 'action' => json_encode($action, JSON_UNESCAPED_UNICODE),
                'apply_to' => $applyTo, 'total' => count($items), 'undo_of' => $undoOf, 'by' => $createdBy,
            ]);
            $batchId = (int) $this->pdo->lastInsertId();
            $insert = $this->pdo->prepare(
                'INSERT INTO connect_batch_change_items (batch_id, match_value, label, side, external_id, product_id, old_price, new_price, old_compare_at, new_compare_at, set_compare_at)
                 VALUES (:batch, :match, :label, :side, :ext, :product, :old, :new, :old_cmp, :new_cmp, :set_cmp)'
            );
            foreach ($items as $item) {
                $insert->execute([
                    'batch' => $batchId, 'match' => mb_substr((string) $item['match_value'], 0, 190), 'label' => mb_substr((string) $item['label'], 0, 255),
                    'side' => $item['side'], 'ext' => (string) $item['external_id'], 'product' => $item['product_id'] ?? null,
                    'old' => $item['old_price'], 'new' => $item['new_price'], 'old_cmp' => $item['old_compare_at'] ?? null,
                    'new_cmp' => $item['new_compare_at'] ?? null, 'set_cmp' => (int) ($item['set_compare_at'] ?? 0),
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $batchId;
    }

    /**
     * Rueckgaengig: neue Sammelaenderung mit den alten Werten der erfolgreich geschriebenen Artikel.
     * Ein durch "Sale" gesetzter Vergleichspreis wird wieder geleert.
     */
    public function undo(string $shop, int $batchId, ?string $createdBy): ?int
    {
        $batch = $this->pdo->prepare("SELECT * FROM connect_batch_changes WHERE id = :id AND shop = :shop AND status = 'done' AND kind <> 'undo'");
        $batch->execute(['id' => $batchId, 'shop' => $shop]);
        $row = $batch->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        // Atomar beanspruchen: nur EIN Aufruf (Doppelklick, zwei Tabs) kommt durch -- frueher setzten
        // beide nach dem SELECT oben auf 'undone' und stellten je eine Undo-Sammelaenderung ein.
        $claim = $this->pdo->prepare("UPDATE connect_batch_changes SET status = 'undone' WHERE id = :id AND shop = :shop AND status = 'done'");
        $claim->execute(['id' => $batchId, 'shop' => $shop]);
        if ($claim->rowCount() !== 1) {
            return null;
        }
        $release = fn () => $this->pdo->prepare("UPDATE connect_batch_changes SET status = 'done' WHERE id = :id AND status = 'undone'")->execute(['id' => $batchId]);

        $items = $this->pdo->prepare("SELECT * FROM connect_batch_change_items WHERE batch_id = :id AND status = 'done'");
        $items->execute(['id' => $batchId]);
        $undoItems = [];
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $undoItems[] = [
                'match_value' => $item['match_value'], 'label' => $item['label'], 'side' => $item['side'], 'external_id' => $item['external_id'],
                'product_id' => $item['product_id'], 'old_price' => $item['new_price'], 'new_price' => $item['old_price'],
                'set_compare_at' => (int) $item['set_compare_at'], 'old_compare_at' => $item['new_compare_at'], 'new_compare_at' => $item['old_compare_at'],
            ];
        }
        if ($undoItems === []) {
            $release();

            return null;
        }
        try {
            return $this->queue($shop, 'undo', 'Rückgängig: ' . $row['title'], [], ['undo_of' => $batchId], (string) $row['apply_to'], $undoItems, $createdBy, $batchId);
        } catch (\Throwable $e) {
            $release();

            throw $e;
        }
    }

    /**
     * Offene Sammelaenderungen abarbeiten (bin/scheduler.php). Je Aufruf ein Teil, Rest beim naechsten.
     *
     * @return array{batches: int, written: int, failed: int}|null null = nichts zu tun
     */
    public function runPending(int $timeBudgetSeconds = 20): ?array
    {
        $started = time();
        $written = 0;
        $failed = 0;
        $batches = 0;
        $open = $this->pdo->query("SELECT id, shop FROM connect_batch_changes WHERE status IN ('queued', 'running') ORDER BY id LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        if ($open === []) {
            return null;
        }
        foreach ($open as $batch) {
            if (time() - $started >= $timeBudgetSeconds) {
                break;
            }
            $batches++;
            $batchId = (int) $batch['id'];
            $this->pdo->prepare("UPDATE connect_batch_changes SET status = 'running' WHERE id = :id AND status = 'queued'")->execute(['id' => $batchId]);
            try {
                $context = ProductMatchContext::resolve($this->pdo, $this->factory, (string) $batch['shop']);
            } catch (\Throwable $e) {
                $this->pdo->prepare("UPDATE connect_batch_change_items SET status = 'failed', error = :e WHERE batch_id = :id AND status = 'pending'")
                    ->execute(['e' => mb_substr($e->getMessage(), 0, 400), 'id' => $batchId]);
                $this->finish($batchId);
                continue;
            }
            $writer = new PriceWriter($this->pdo, $context, ShopStockRules::forShop($this->pdo, (string) $batch['shop'])['prices_gross']);

            // Shopify: je Produkt gesammelt
            $items = $this->pdo->prepare("SELECT id, external_id, product_id, new_price, new_compare_at, set_compare_at FROM connect_batch_change_items WHERE batch_id = :id AND status = 'pending' AND side = 'shopify' ORDER BY product_id, id LIMIT 2000");
            $items->execute(['id' => $batchId]);
            $byProduct = [];
            foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $byProduct[(string) $item['product_id']][] = $item;
            }
            $productsDone = 0;
            foreach ($byProduct as $productId => $productItems) {
                if ($productsDone >= self::SHOPIFY_PRODUCTS_PER_RUN || time() - $started >= $timeBudgetSeconds) {
                    break;
                }
                $productsDone++;
                if ($productId === '') {
                    foreach ($productItems as $item) {
                        $this->markItem((int) $item['id'], 'Shopify-Produkt-ID fehlt im Zwischenspeicher');
                        $failed++;
                    }
                    continue;
                }
                foreach (array_chunk($productItems, 250) as $chunk) {
                    foreach ($writer->writeShopifyProduct($productId, $chunk) as $itemId => $error) {
                        $this->markItem((int) $itemId, $error);
                        $error === null ? $written++ : $failed++;
                    }
                }
            }

            // Zielsystem: einzeln
            $items = $this->pdo->prepare("SELECT id, external_id, new_price FROM connect_batch_change_items WHERE batch_id = :id AND status = 'pending' AND side = 'target' ORDER BY id LIMIT " . self::TARGET_ITEMS_PER_RUN);
            $items->execute(['id' => $batchId]);
            foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
                if (time() - $started >= $timeBudgetSeconds) {
                    break;
                }
                $error = $item['new_price'] !== null ? $writer->writeTarget((string) $item['external_id'], (float) $item['new_price']) : 'kein neuer Preis';
                $this->markItem((int) $item['id'], $error);
                $error === null ? $written++ : $failed++;
            }

            $this->finish($batchId);
        }

        return ['batches' => $batches, 'written' => $written, 'failed' => $failed];
    }

    private function markItem(int $itemId, ?string $error): void
    {
        $this->pdo->prepare(
            'UPDATE connect_batch_change_items SET status = :status, error = :error, done_at = CURRENT_TIMESTAMP WHERE id = :id'
        )->execute(['status' => $error === null ? 'done' : 'failed', 'error' => $error, 'id' => $itemId]);
    }

    /** Zaehler nachziehen, fertig, wenn nichts mehr offen ist. */
    private function finish(int $batchId): void
    {
        $counts = $this->pdo->prepare(
            "SELECT SUM(status = 'done') AS done, SUM(status = 'failed') AS failed, SUM(status = 'pending') AS pending FROM connect_batch_change_items WHERE batch_id = :id"
        );
        $counts->execute(['id' => $batchId]);
        $c = $counts->fetch(PDO::FETCH_ASSOC) ?: [];
        $pending = (int) ($c['pending'] ?? 0);
        $this->pdo->prepare(
            'UPDATE connect_batch_changes SET done = :done, failed = :failed, status = :status, finished_at = IF(:pending = 0, CURRENT_TIMESTAMP, NULL) WHERE id = :id'
        )->execute([
            'done' => (int) ($c['done'] ?? 0), 'failed' => (int) ($c['failed'] ?? 0),
            'status' => $pending > 0 ? 'running' : ((int) ($c['done'] ?? 0) === 0 && (int) ($c['failed'] ?? 0) > 0 ? 'failed' : 'done'),
            'pending' => $pending, 'id' => $batchId,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function history(string $shop, int $limit = 30): array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM connect_batch_changes WHERE shop = :shop ORDER BY id DESC LIMIT ' . max(1, $limit));
            $stmt->execute(['shop' => $shop]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return [];
        }
    }
}
