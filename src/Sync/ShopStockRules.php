<?php

declare(strict_types=1);

namespace Connect\Sync;

use PDO;

/**
 * Regeln je Shop, die Bestands- und Preisvergleich gemeinsam nutzen (Migration 0087):
 * Sicherheitsbestand und Preis-Vergleich. An EINER Stelle, damit Lagerabgleich
 * (StockSyncRunner), Produkte-Seite (ProductComparisonService) und Preissynchronisation
 * (PriceSyncRunner) dasselbe rechnen.
 */
final class ShopStockRules
{
    /** Preise gelten als gleich, wenn sie weniger als einen halben Cent auseinanderliegen. */
    private const PRICE_EPSILON = 0.005;

    /**
     * @return array{buffer: int, prices_gross: bool, price_mode: string, price_max_pct: int, price_confirmed: bool}
     */
    public static function forShop(PDO $pdo, string $shop): array
    {
        $row = [];
        try {
            $stmt = $pdo->prepare(
                'SELECT stock_safety_buffer, shopify_prices_include_tax, price_sync_mode, price_sync_max_change_pct, price_sync_confirmed_at
                 FROM connect_shops WHERE shop = :shop'
            );
            $stmt->execute(['shop' => $shop]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException) {
            // Migration 0087 fehlt: alles aus
            try {
                $stmt = $pdo->prepare('SELECT shopify_prices_include_tax FROM connect_shops WHERE shop = :shop');
                $stmt->execute(['shop' => $shop]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            } catch (\PDOException) {
                $row = [];
            }
        }

        $mode = (string) ($row['price_sync_mode'] ?? 'off');

        return [
            'buffer' => max(0, (int) ($row['stock_safety_buffer'] ?? 0)),
            // NULL = noch nicht festgelegt, gilt wie ueberall in Connect als Brutto
            'prices_gross' => ($row['shopify_prices_include_tax'] ?? null) === null || (int) $row['shopify_prices_include_tax'] === 1,
            'price_mode' => in_array($mode, ['target_to_shopify', 'shopify_to_target'], true) ? $mode : 'off',
            'price_max_pct' => max(0, (int) ($row['price_sync_max_change_pct'] ?? 30)),
            'price_confirmed' => !empty($row['price_sync_confirmed_at']),
        ];
    }

    /**
     * Bestand, den Shopify zeigen soll, wenn das Kassensystem fuehrt: Bestand minus Puffer, nie
     * unter 0. Bei anderer Richtung oder ohne Puffer unveraendert.
     */
    public static function shopifyQuantity(int $targetQuantity, int $buffer, string $direction): int
    {
        if ($direction !== 'target_to_shopify' || $buffer <= 0) {
            return $targetQuantity;
        }

        return max(0, $targetQuantity - $buffer);
    }

    /**
     * Preisfeld des Zielsystems passend zur Preis-Basis des Shops: Shopify kennt je Artikel nur EINEN
     * Preis -- Brutto oder Netto je nach Shop-Einstellung (shopify_prices_include_tax). Das Zielsystem
     * fuehrt oft beide (z.B. helloCash gross_selling_price/net_selling_price): Rolle "Preis" = Brutto
     * (bzw. der einzige Preis), Rolle "Preis netto" (price_net) = Netto. Netto-Shop ohne
     * "Preis netto"-Feld = kein Preisvergleich (null).
     */
    public static function targetPriceField(\Connect\Connector\ObjectDefinition $targetObject, bool $pricesGross): ?\Connect\Connector\FieldDefinition
    {
        return ProductMatchContext::fieldWithComparisonRole($targetObject, $pricesGross ? 'price' : 'price_net');
    }

    /** Preis aus dem Zwischenspeicher als Zahl (Komma oder Punkt), null wenn leer/unlesbar. */
    public static function price(mixed $value): ?float
    {
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }
        $text = str_replace([' ', "\u{00A0}"], '', (string) $value);
        if (str_contains($text, ',') && !str_contains($text, '.')) {
            $text = str_replace(',', '.', $text);
        }

        return is_numeric($text) ? round((float) $text, 2) : null;
    }

    public static function pricesDiffer(?float $a, ?float $b): bool
    {
        return $a !== null && $b !== null && abs($a - $b) >= self::PRICE_EPSILON;
    }

    /** Aenderung in Prozent von $from auf $to (bei 0 als Ausgang: 100 %). */
    public static function changePercent(float $from, float $to): float
    {
        if (abs($from) < self::PRICE_EPSILON) {
            return abs($to) < self::PRICE_EPSILON ? 0.0 : 100.0;
        }

        return abs($to - $from) / abs($from) * 100;
    }
}
