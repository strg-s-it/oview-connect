<?php

declare(strict_types=1);

namespace Connect\Sync;

/**
 * Prueft die Vergleichswerte EINER Seite (Shopify oder Zielsystem) auf
 * Mehrfachvorkommen -- Altsystem-Pruefung "Vergleichswert-Duplikate in ...":
 * Artikel mit mehrfach vorkommendem (oder leerem) Vergleichswert koennen nicht
 * eindeutig zugeordnet und deshalb nicht synchronisiert werden. Gemeinsam von
 * ProductComparisonService (Anzeige) und StockSyncRunner (Schreibschutz)
 * genutzt, damit beide dieselbe Definition von "Duplikat" verwenden.
 */
final class ComparisonValueCheck
{
    /**
     * @param array<int, array<string, mixed>> $records gecachte Datensaetze (verwaiste werden ignoriert)
     * @return array<string, int> Vergleichswert => Anzahl, nur Werte mit Anzahl > 1
     */
    public static function duplicateValues(array $records, string $matchFieldKey): array
    {
        $counts = [];
        foreach ($records as $record) {
            if (($record['_status'] ?? 'active') === 'orphaned') {
                continue;
            }
            $value = $record[$matchFieldKey] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $counts[(string) $value] = ($counts[(string) $value] ?? 0) + 1;
        }

        return array_filter($counts, static fn (int $count): bool => $count > 1);
    }
}
