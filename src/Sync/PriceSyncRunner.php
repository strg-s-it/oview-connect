<?php

declare(strict_types=1);

namespace Connect\Sync;

use Connect\Billing\Plans;
use Connect\Connector\ConnectorFactory;
use PDO;

/**
 * Preissynchronisation Kasse <-> Shopify (Migration 0087/0088).
 *
 *   price_sync_mode           target_to_shopify (Kasse fuehrt) | shopify_to_target | off
 *   price_sync_confirmed_at   geschrieben wird erst, nachdem die Vorschau aller Unterschiede bestaetigt wurde
 *   price_sync_max_change_pct groessere Spruenge werden nicht geschrieben, nur "zur Pruefung" gezeigt
 *   connect_price_sync_exclusions  nie anfassen
 *
 * Verglichen wird auf der Preis-Basis des Shops (Brutto oder Netto, ShopStockRules::targetPriceField()). Jeder Lauf legt
 * eine Sammelaenderung der Art "sync" an (BatchEditService) -- gleiche Ausfuehrung, im Verlauf
 * sichtbar und rueckgaengig zu machen. Takt wie der Lagerabgleich des Abos (Plans::stockMinutes).
 */
final class PriceSyncRunner
{
    /** Hoechstens so viele Aenderungen je Lauf, Rest im naechsten */
    private const MAX_PER_RUN = 500;

    public function __construct(private readonly PDO $pdo, private readonly ConnectorFactory $factory)
    {
    }

    /**
     * Alle Unterschiede mit Einordnung (fuer die Vorschau und den Lauf).
     *
     * @return array{mode: string, rows: list<array{match_value: string, label: string, shopify_price: float, target_price: float, from: float, to: float, side: string, external_id: string, product_id: ?string, change_pct: float, held: bool, excluded: bool}>, error: ?string}
     */
    public function preview(string $shop, ?string $mode = null): array
    {
        $rules = ShopStockRules::forShop($this->pdo, $shop);
        $mode ??= $rules['price_mode'];
        if (!TargetCapabilities::supports($this->pdo, $shop, 'price_sync')) {
            return ['mode' => $mode, 'rows' => [], 'error' => TargetCapabilities::reason($this->pdo, $shop, 'price_sync')];
        }
        try {
            $candidates = (new BatchEditService($this->pdo, $this->factory))->candidates($shop);
        } catch (\Throwable $e) {
            return ['mode' => $mode, 'rows' => [], 'error' => $e->getMessage()];
        }
        $excluded = $this->exclusions($shop);

        $rows = [];
        foreach ($candidates as $c) {
            if ($c['shopify_price'] === null || $c['target_price'] === null || !ShopStockRules::pricesDiffer($c['shopify_price'], $c['target_price'])) {
                continue;
            }
            $toShopify = $mode !== 'shopify_to_target';
            $from = $toShopify ? $c['shopify_price'] : $c['target_price'];
            $to = $toShopify ? $c['target_price'] : $c['shopify_price'];
            $pct = ShopStockRules::changePercent($from, $to);
            $rows[] = [
                'match_value' => $c['match_value'], 'label' => $c['label'],
                'shopify_price' => $c['shopify_price'], 'target_price' => $c['target_price'],
                'from' => $from, 'to' => $to,
                'side' => $toShopify ? 'shopify' : 'target',
                'external_id' => $toShopify ? $c['shopify_id'] : (string) $c['target_id'],
                'product_id' => $toShopify ? $c['product_id'] : null,
                'change_pct' => round($pct, 1),
                'held' => $rules['price_max_pct'] > 0 && $pct > $rules['price_max_pct'],
                'excluded' => isset($excluded[$c['match_value']]),
            ];
        }

        return ['mode' => $mode, 'rows' => $rows, 'error' => null];
    }

    /**
     * Faellige Shops: Unterschiede als Sammelaenderung (Art "sync") einreihen. Ausgefuehrt wird von
     * BatchEditService::runPending() im selben Scheduler-Aufruf.
     *
     * @return int Anzahl eingereihter Shops
     */
    public function queueDue(): int
    {
        try {
            $shops = $this->pdo->query(
                "SELECT shop, plan, price_sync_last_run_at FROM connect_shops
                 WHERE price_sync_mode <> 'off' AND price_sync_confirmed_at IS NOT NULL AND live_in_connect = 1"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return 0; // Migration 0087/0088 fehlt
        }

        $queued = 0;
        foreach ($shops as $s) {
            $shop = (string) $s['shop'];
            $plan = isset($s['plan'], Plans::PLANS[(string) $s['plan']]) ? (string) $s['plan'] : null;
            if (!Plans::allowsPriceTools($plan)) {
                continue;
            }
            $minutes = Plans::stockMinutes($plan);
            if ($s['price_sync_last_run_at'] !== null && strtotime((string) $s['price_sync_last_run_at']) > time() - $minutes * 60) {
                continue;
            }
            // Noch ein Lauf offen? Dann nicht doppelt einreihen
            $open = $this->pdo->prepare("SELECT COUNT(*) FROM connect_batch_changes WHERE shop = :shop AND kind = 'sync' AND status IN ('queued', 'running')");
            $open->execute(['shop' => $shop]);
            if ((int) $open->fetchColumn() > 0) {
                continue;
            }
            $this->pdo->prepare('UPDATE connect_shops SET price_sync_last_run_at = CURRENT_TIMESTAMP WHERE shop = :shop')->execute(['shop' => $shop]);

            $preview = $this->preview($shop);
            $items = [];
            foreach ($preview['rows'] as $row) {
                if ($row['held'] || $row['excluded'] || count($items) >= self::MAX_PER_RUN) {
                    continue;
                }
                $items[] = [
                    'match_value' => $row['match_value'], 'label' => $row['label'], 'side' => $row['side'],
                    'external_id' => $row['external_id'], 'product_id' => $row['product_id'],
                    'old_price' => $row['from'], 'new_price' => $row['to'], 'set_compare_at' => 0, 'new_compare_at' => null,
                ];
            }
            if ($items === []) {
                continue;
            }
            $label = $preview['mode'] === 'shopify_to_target' ? 'Shopify → Kassensystem' : 'Kassensystem → Shopify';
            (new BatchEditService($this->pdo, $this->factory))->queue($shop, 'sync', 'Preissynchronisation (' . $label . '): ' . count($items) . ' Preise', [], ['mode' => $preview['mode']], $preview['mode'] === 'shopify_to_target' ? 'target' : 'shopify', $items, 'Preissynchronisation');
            $queued++;
        }

        return $queued;
    }

    /** @return array<string, true> */
    public function exclusions(string $shop): array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT match_value FROM connect_price_sync_exclusions WHERE shop = :shop');
            $stmt->execute(['shop' => $shop]);

            return array_fill_keys(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
        } catch (\PDOException) {
            return [];
        }
    }

    public function setExcluded(string $shop, string $matchValue, bool $excluded): void
    {
        if ($excluded) {
            $this->pdo->prepare('INSERT IGNORE INTO connect_price_sync_exclusions (shop, match_value) VALUES (:shop, :m)')->execute(['shop' => $shop, 'm' => $matchValue]);
        } else {
            $this->pdo->prepare('DELETE FROM connect_price_sync_exclusions WHERE shop = :shop AND match_value = :m')->execute(['shop' => $shop, 'm' => $matchValue]);
        }
    }
}
