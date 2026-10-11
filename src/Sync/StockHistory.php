<?php

declare(strict_types=1);

namespace Connect\Sync;

use Connect\Connector\ConnectorFactory;
use PDO;

/**
 * Bestandsverlauf EINES Artikels -- beantwortet "warum stimmt der Bestand nicht?" ohne Support.
 * Fuehrt zusammen, was Connect ohnehin hat:
 *   - aktueller Stand beider Seiten aus dem Zwischenspeicher (+ wann zuletzt gelesen, Sicherheitsbestand)
 *   - Bestandsaenderungen durch den Abgleich (connect_stock_sync_log)
 *   - Bestellungen aus den Shopify-Webhooks (orders/paid, orders/create) ueber die Varianten-ID
 *   - fehlgeschlagene Schreibversuche (connect_activity_log, stock_update)
 *   - Preisaenderungen (connect_batch_change_items, Migration 0088)
 * Zeitraum DAYS Tage. Bestell-Webhooks werden nach der Aufbewahrungsfrist geloescht -- aeltere
 * Verkaeufe fehlen dann.
 */
final class StockHistory
{
    public const DAYS = 30;

    public function __construct(private readonly PDO $pdo, private readonly ConnectorFactory $factory)
    {
    }

    /**
     * @return array{found: bool, label: string, match_value: string, direction: string, buffer: int,
     *               shopify: ?array{quantity: ?int, price: ?float, fetched_at: ?string, id: string},
     *               target: ?array{quantity: ?int, price: ?float, fetched_at: ?string, id: string},
     *               expected_shopify: ?int,
     *               events: list<array{at: string, kind: string, title: string, text: string, quantity: ?int}>,
     *               series: list<array{at: string, quantity: int}>}
     */
    public function build(string $shop, string $matchValue): array
    {
        $context = ProductMatchContext::resolve($this->pdo, $this->factory, $shop);
        $cache = new ObjectCache($this->pdo);
        $rules = ShopStockRules::forShop($this->pdo, $shop);

        $shopifyRecord = $cache->findOneByFieldValue($context->shopifyConnectionId, $context->shopifyObject->key, $context->shopifyMatchField->key, $matchValue);
        $targetRecord = $cache->findOneByFieldValue($context->targetConnectionId, $context->targetObject->key, $context->targetMatchField->key, $matchValue);
        $shopifyPrice = ProductMatchContext::fieldWithComparisonRole($context->shopifyObject, 'price');
        $targetPrice = ShopStockRules::targetPriceField($context->targetObject, $rules['prices_gross']);
        $titleField = ProductMatchContext::titleField($context->shopifyObject) ?? ProductMatchContext::titleField($context->targetObject);
        $shopifyIdKey = ObjectCache::requireIdentifierField($context->shopifyObject)->key;
        $targetIdKey = ObjectCache::requireIdentifierField($context->targetObject)->key;

        $side = static function (?array $record, string $stockKey, ?\Connect\Connector\FieldDefinition $priceField, string $idKey): ?array {
            if ($record === null) {
                return null;
            }

            return [
                'quantity' => array_key_exists($stockKey, $record) && $record[$stockKey] !== null ? (int) $record[$stockKey] : null,
                'price' => $priceField !== null ? ShopStockRules::price($record[$priceField->key] ?? null) : null,
                'fetched_at' => isset($record['_fetched_at']) ? (string) $record['_fetched_at'] : null,
                'id' => (string) ($record[$idKey] ?? ''),
            ];
        };
        $shopify = $side($shopifyRecord, $context->shopifyStockField->key, $shopifyPrice, $shopifyIdKey);
        $target = $side($targetRecord, $context->targetStockField->key, $targetPrice, $targetIdKey);
        $label = '';
        foreach ([$shopifyRecord, $targetRecord] as $record) {
            if ($label === '' && $record !== null && $titleField !== null && isset($record[$titleField->key])) {
                $label = (string) $record[$titleField->key];
            }
        }

        $events = [];
        $since = date('Y-m-d H:i:s', time() - self::DAYS * 86400);

        // 1. Abgleich
        $stmt = $this->pdo->prepare('SELECT direction, old_quantity, new_quantity, created_at FROM connect_stock_sync_log WHERE shop = :shop AND match_value = :m AND created_at >= :since ORDER BY created_at');
        $stmt->execute(['shop' => $shop, 'm' => $matchValue, 'since' => $since]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $toShopify = $row['direction'] === 'target_to_shopify';
            $events[] = [
                'at' => (string) $row['created_at'], 'kind' => 'sync',
                'title' => $toShopify ? 'Abgleich: Bestand in Shopify gesetzt' : 'Abgleich: Bestand im Kassensystem angepasst',
                'text' => ($row['old_quantity'] !== null ? $row['old_quantity'] . ' → ' : 'auf ') . $row['new_quantity'],
                'quantity' => (int) $row['new_quantity'],
            ];
        }

        // 2. Bestellungen (Shopify-Webhooks) ueber die numerische Varianten-ID
        $variantNumeric = $shopify !== null && preg_match('~(\d+)$~', $shopify['id'], $m) === 1 ? $m[1] : null;
        if ($variantNumeric !== null) {
            $stmt = $this->pdo->prepare(
                "SELECT topic, payload, received_at FROM connect_webhook_events
                 WHERE shop = :shop AND topic IN ('orders/paid', 'orders/create') AND received_at >= :since AND payload LIKE :needle ORDER BY received_at"
            );
            $stmt->execute(['shop' => $shop, 'since' => $since, 'needle' => '%' . $variantNumeric . '%']);
            $seenOrders = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $order = json_decode((string) $row['payload'], true);
                if (!is_array($order)) {
                    continue;
                }
                $orderKey = (string) ($order['id'] ?? '');
                $quantity = 0;
                foreach ((array) ($order['line_items'] ?? []) as $line) {
                    if ((string) ($line['variant_id'] ?? '') === $variantNumeric) {
                        $quantity += (int) ($line['quantity'] ?? 0);
                    }
                }
                if ($quantity === 0 || isset($seenOrders[$orderKey])) {
                    continue; // create + paid derselben Bestellung nur einmal zeigen
                }
                $seenOrders[$orderKey] = true;
                $events[] = [
                    'at' => (string) $row['received_at'], 'kind' => 'order',
                    'title' => 'Bestellung ' . (string) ($order['name'] ?? ('#' . ($order['order_number'] ?? ''))),
                    'text' => $quantity . ' Stück verkauft (Shopify)' . ($row['topic'] === 'orders/paid' ? ', bezahlt' : ''),
                    'quantity' => null,
                ];
            }
        }

        // 3. Fehlgeschlagene Schreibversuche
        $ids = array_values(array_filter([$shopify['id'] ?? '', $target['id'] ?? '']));
        if ($ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT message, created_at FROM connect_activity_log WHERE shop = ? AND operation = 'stock_update' AND status = 'error'
                 AND external_id IN ({$placeholders}) AND created_at >= ? ORDER BY created_at"
            );
            $stmt->execute(array_merge([$shop], $ids, [$since]));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $events[] = ['at' => (string) $row['created_at'], 'kind' => 'error', 'title' => 'Bestand konnte nicht geschrieben werden', 'text' => mb_substr((string) $row['message'], 0, 300), 'quantity' => null];
            }
        }

        // 4. Preisaenderungen
        try {
            $stmt = $this->pdo->prepare(
                "SELECT i.side, i.old_price, i.new_price, i.done_at, b.kind FROM connect_batch_change_items i JOIN connect_batch_changes b ON b.id = i.batch_id
                 WHERE b.shop = :shop AND i.match_value = :m AND i.status = 'done' AND i.done_at >= :since ORDER BY i.done_at"
            );
            $stmt->execute(['shop' => $shop, 'm' => $matchValue, 'since' => $since]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $events[] = [
                    'at' => (string) $row['done_at'], 'kind' => 'price',
                    'title' => 'Preis ' . ($row['side'] === 'shopify' ? 'in Shopify' : 'im Kassensystem') . ' geändert' . ($row['kind'] === 'sync' ? ' (Synchronisation)' : ''),
                    'text' => number_format((float) $row['old_price'], 2, ',', '.') . ' → ' . number_format((float) $row['new_price'], 2, ',', '.'),
                    'quantity' => null,
                ];
            }
        } catch (\PDOException) {
            // Migration 0088 fehlt
        }

        usort($events, static fn (array $a, array $b): int => strcmp($b['at'], $a['at'])); // neueste zuerst

        // Verlauf fuer die Kurve: gesetzte Werte + aktueller Stand der fuehrenden Seite
        $series = [];
        foreach (array_reverse($events) as $event) {
            if ($event['quantity'] !== null) {
                $series[] = ['at' => $event['at'], 'quantity' => $event['quantity']];
            }
        }
        $current = $context->direction === 'target_to_shopify' ? ($shopify['quantity'] ?? null) : ($target['quantity'] ?? null);
        if ($current !== null) {
            $series[] = ['at' => date('Y-m-d H:i:s'), 'quantity' => $current];
        }

        return [
            'found' => $shopify !== null || $target !== null,
            'label' => $label !== '' ? $label : $matchValue,
            'match_value' => $matchValue,
            'direction' => $context->direction,
            'buffer' => $context->direction === 'target_to_shopify' ? $rules['buffer'] : 0,
            'shopify' => $shopify,
            'target' => $target,
            'expected_shopify' => $target !== null && $target['quantity'] !== null ? ShopStockRules::shopifyQuantity($target['quantity'], $rules['buffer'], $context->direction) : null,
            'events' => $events,
            'series' => $series,
        ];
    }

    /**
     * Kleine Kurve als Inline-SVG (keine Chart-Bibliothek noetig): Stufenlinie ueber die Zeit.
     *
     * @param list<array{at: string, quantity: int}> $series
     */
    public static function svg(array $series, int $width = 640, int $height = 160): string
    {
        if (count($series) < 2) {
            return '';
        }
        $times = array_map(static fn (array $p): int => (int) strtotime($p['at']), $series);
        $values = array_column($series, 'quantity');
        $minT = min($times);
        $maxT = max($times);
        $maxV = max(1, max($values));
        $pad = 24;
        $x = static fn (int $t): float => $pad + ($maxT > $minT ? ($t - $minT) / ($maxT - $minT) : 0) * ($width - 2 * $pad);
        $y = static fn (int $v): float => $height - $pad - ($v / $maxV) * ($height - 2 * $pad);

        $points = [];
        foreach ($series as $i => $point) {
            if ($i > 0) {
                $points[] = round($x($times[$i]), 1) . ',' . round($y($values[$i - 1]), 1); // Stufe
            }
            $points[] = round($x($times[$i]), 1) . ',' . round($y($values[$i]), 1);
        }
        $dots = '';
        foreach ($series as $i => $point) {
            $dots .= '<circle cx="' . round($x($times[$i]), 1) . '" cy="' . round($y($values[$i]), 1) . '" r="3"><title>'
                . htmlspecialchars(date('d.m. H:i', $times[$i]) . ': ' . $values[$i], ENT_QUOTES) . '</title></circle>';
        }

        return '<svg viewBox="0 0 ' . $width . ' ' . $height . '" width="100%" height="' . $height . '" role="img" aria-label="Bestandsverlauf" style="color: var(--tblr-primary)">'
            . '<line x1="' . $pad . '" y1="' . ($height - $pad) . '" x2="' . ($width - $pad) . '" y2="' . ($height - $pad) . '" stroke="currentColor" stroke-opacity=".15"/>'
            . '<text x="' . $pad . '" y="14" font-size="11" fill="currentColor" fill-opacity=".6">max ' . $maxV . '</text>'
            . '<polyline fill="none" stroke="currentColor" stroke-width="2" points="' . implode(' ', $points) . '"/>'
            . '<g fill="currentColor">' . $dots . '</g>'
            . '<text x="' . $pad . '" y="' . ($height - 6) . '" font-size="11" fill="currentColor" fill-opacity=".6">' . date('d.m.', $minT) . '</text>'
            . '<text x="' . ($width - $pad) . '" y="' . ($height - 6) . '" font-size="11" text-anchor="end" fill="currentColor" fill-opacity=".6">' . date('d.m. H:i', $maxT) . '</text>'
            . '</svg>';
    }
}
