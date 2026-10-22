<?php

declare(strict_types=1);

namespace Connect\Sync;

use PDO;

/**
 * Filter der Rechnungserstellung (Migration 0106, nur vom Admin pflegbar): Bestellungen, die
 * eine Regel treffen, bekommen KEINE Rechnung -- z.B. Bestellungen mit einem Dummy-Kunden oder
 * einer Notiz "Rechnung extern", weil sie ueber ein anderes System abgerechnet werden.
 *
 * Eine Regel = Feld + "enthaelt" oder "ist gleich" + Wert, Gross-/Kleinschreibung egal.
 * Trifft IRGENDEINE Regel zu, wird die Bestellung uebersprungen. Fehlt die Tabelle (Migration
 * noch nicht eingespielt) oder ein Feld in den Bestelldaten, wird nicht gefiltert.
 */
final class InvoiceFilter
{
    /** @var array<string, string> */
    public const FIELDS = [
        'note' => 'Notiz der Bestellung',
        'tags' => 'Tags der Bestellung',
        'email' => 'Kunden-E-Mail',
        'customer_name' => 'Kundenname',
        'source_name' => 'Bestellquelle (web, pos, ...)',
    ];

    /** @var array<string, string> */
    public const MATCH_TYPES = [
        'contains' => 'enthält',
        'equals' => 'ist gleich',
    ];

    /**
     * @return list<array{id: int, field_key: string, match_type: string, value: string}>
     */
    public static function rulesForShop(PDO $pdo, string $shop): array
    {
        try {
            $stmt = $pdo->prepare('SELECT id, field_key, match_type, value FROM connect_invoice_filters WHERE shop = :shop ORDER BY id');
            $stmt->execute(['shop' => $shop]);
        } catch (\PDOException) {
            return []; // Migration 0106 noch nicht eingespielt
        }

        $rules = [];
        foreach ($stmt->fetchAll() as $row) {
            $rules[] = ['id' => (int) $row['id'], 'field_key' => (string) $row['field_key'], 'match_type' => (string) $row['match_type'], 'value' => (string) $row['value']];
        }

        return $rules;
    }

    /**
     * Erste zutreffende Regel als lesbarer Text (z.B. "Notiz enthält „extern“"), sonst null.
     *
     * @param list<array{id: int, field_key: string, match_type: string, value: string}> $rules
     * @param array<string, mixed> $orderRow Shopify-Bestellung (Felder wie im Cache)
     */
    public static function firstMatch(array $rules, array $orderRow): ?string
    {
        foreach ($rules as $rule) {
            $needle = mb_strtolower(trim($rule['value']));
            if ($needle === '' || !isset(self::FIELDS[$rule['field_key']])) {
                continue;
            }
            foreach (self::candidates($rule['field_key'], $orderRow) as $candidate) {
                $haystack = mb_strtolower(trim($candidate));
                $hit = $rule['match_type'] === 'equals' ? $haystack === $needle : ($haystack !== '' && str_contains($haystack, $needle));
                if ($hit) {
                    return self::FIELDS[$rule['field_key']] . ' ' . (self::MATCH_TYPES[$rule['match_type']] ?? $rule['match_type']) . ' „' . $rule['value'] . '“';
                }
            }
        }

        return null;
    }

    /**
     * Zu pruefende Werte eines Feldes (Tags: jeder Tag einzeln, Kundenname: Vor- und Nachname und beides zusammen).
     *
     * @param array<string, mixed> $orderRow
     * @return list<string>
     */
    private static function candidates(string $fieldKey, array $orderRow): array
    {
        $text = static fn (string $key): string => is_scalar($orderRow[$key] ?? null) ? (string) $orderRow[$key] : '';

        if ($fieldKey === 'tags') {
            $tags = $orderRow['tags'] ?? [];
            if (is_string($tags)) {
                $tags = explode(',', $tags);
            }

            return is_array($tags) ? array_values(array_map(static fn (mixed $t): string => is_scalar($t) ? (string) $t : '', $tags)) : [];
        }
        if ($fieldKey === 'customer_name') {
            $first = $text('customer_first_name');
            $last = $text('customer_last_name');

            return [trim($first . ' ' . $last)];
        }

        return [$text($fieldKey)];
    }
}
