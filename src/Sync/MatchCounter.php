<?php

declare(strict_types=1);

namespace Connect\Sync;

use PDO;

/**
 * Zaehlt, wie viele Produkte zwischen Shopify und dem Zielsystem ueber einen Vergleichswert
 * (sku/barcode/title) zusammenpassen -- rein aus dem Zwischenspeicher, ohne API-Aufrufe.
 * Genutzt von der Einrichtung (Trefferzahlen je Vergleichswert) und der Merchant-Uebersicht
 * (Kachel Produkte). Die genaue Liste mit Varianten-Logik bleibt bei ProductComparisonService.
 */
final class MatchCounter
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{matched: int, target_total: int, shopify_total: int, shopify_matched: int}|null
     *         null = eines der Systeme kennt diesen Vergleichswert nicht (Connector Builder)
     */
    public function count(int $shopifyConnectionId, int $targetConnectionId, string $role): ?array
    {
        $shopifyField = $this->roleField($shopifyConnectionId, $role);
        $targetField = $this->roleField($targetConnectionId, $role);
        if ($shopifyField === null || $targetField === null) {
            return null;
        }

        $cache = new ObjectCache($this->pdo);
        $shopifyValues = [];
        $shopifyRowValues = [];
        foreach ($cache->getCached($shopifyConnectionId, $shopifyField['object_key']) as $row) {
            if (($row['_status'] ?? 'active') === 'orphaned') {
                continue;
            }
            $value = self::normalize($row[$shopifyField['field_key']] ?? null, $role);
            $shopifyRowValues[] = $value;
            if ($value !== '') {
                $shopifyValues[$value] = true;
            }
        }

        $matched = 0;
        $targetTotal = 0;
        $targetValues = [];
        foreach ($cache->getCached($targetConnectionId, $targetField['object_key']) as $row) {
            if (($row['_status'] ?? 'active') === 'orphaned') {
                continue;
            }
            $targetTotal++;
            $value = self::normalize($row[$targetField['field_key']] ?? null, $role);
            if ($value !== '') {
                $targetValues[$value] = true;
                if (isset($shopifyValues[$value])) {
                    $matched++;
                }
            }
        }

        $shopifyMatched = 0;
        foreach ($shopifyRowValues as $value) {
            if ($value !== '' && isset($targetValues[$value])) {
                $shopifyMatched++;
            }
        }

        return [
            'matched' => $matched,
            'target_total' => $targetTotal,
            'shopify_total' => count($shopifyRowValues),
            'shopify_matched' => $shopifyMatched,
        ];
    }

    /**
     * Feld mit dieser Vergleichswert-Rolle beim System der Connection -- bevorzugt am Object
     * mit Lagerbestand-Feld (dasselbe, das der Lagerabgleich nutzt).
     *
     * @return array{object_key: string, field_key: string}|null
     */
    private function roleField(int $connectionId, string $role): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.object_key, f.field_key,
                    (SELECT COUNT(*) FROM connect_object_fields sf WHERE sf.object_id = o.id AND sf.is_stock_quantity = 1) AS has_stock
             FROM connect_connections c
             JOIN connect_objects o ON o.connector_type_id = c.connector_type_id
             JOIN connect_object_fields f ON f.object_id = o.id AND f.comparison_role = :role
             WHERE c.id = :id
             ORDER BY has_stock DESC, o.id LIMIT 1'
        );
        $stmt->execute(['role' => $role, 'id' => $connectionId]);
        $row = $stmt->fetch();

        return $row !== false ? ['object_key' => (string) $row['object_key'], 'field_key' => (string) $row['field_key']] : null;
    }

    private static function normalize(mixed $value, string $role): string
    {
        if ($value === null || is_array($value)) {
            return '';
        }
        $value = trim((string) $value);

        return $role === 'title' ? mb_strtolower($value) : $value;
    }
}
