<?php

declare(strict_types=1);

namespace Connect\Sync;

use Connect\Billing\Plans;
use Connect\Connector\ConnectorInterface;
use Connect\Sync\Document\OrderPreparer;
use PDO;

/**
 * Sets und Bundles (Migration 0091). Ein Set ist ein Shopify-Artikel aus Einzelteilen des
 * Kassensystems.
 *
 *   Bestand (StockSyncRunner, nur "Kassensystem fuehrt"): Shopify-Bestand des Sets =
 *     min ueber die Einzelteile (Kassenbestand / Menge, abgerundet), abzueglich Sicherheitsbestand.
 *   Verkauf (InvoiceService): ein in Shopify verkauftes Set bucht die Einzelteile im Kassensystem
 *     ab (Operation "update" mit stock_change, wie der Lagerabgleich) -- nach der Rechnung bzw. im
 *     Modus "Nur Lagerbestand anpassen".
 *   Produkte-Seite/automatischer Import: Sets erscheinen als "Set" statt "Fehlt im Zielsystem" und
 *     werden dadurch nicht als Artikel ins Kassensystem importiert.
 *
 * Bei "Shopify fuehrt" nicht moeglich: der Abgleich wuerde die Abbuchung im Kassensystem sofort mit
 * dem Shopify-Bestand der Einzelteile ueberschreiben, den Shopify beim Set-Verkauf nicht senkt.
 */
final class BundleService
{
    public const MAX_COMPONENTS = 10;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, array{id: int, label: ?string, components: list<array{match: string, quantity: int}>}> bundle_match => Set
     */
    public function definitions(string $shop): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT b.id, b.bundle_match, b.label, c.component_match, c.quantity
                 FROM connect_bundles b LEFT JOIN connect_bundle_components c ON c.bundle_id = b.id
                 WHERE b.shop = :shop ORDER BY b.bundle_match, c.id'
            );
            $stmt->execute(['shop' => $shop]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return []; // Migration 0091 fehlt
        }
        $out = [];
        foreach ($rows as $row) {
            $key = (string) $row['bundle_match'];
            $out[$key] ??= ['id' => (int) $row['id'], 'label' => $row['label'] !== null ? (string) $row['label'] : null, 'components' => []];
            if ($row['component_match'] !== null) {
                $out[$key]['components'][] = ['match' => (string) $row['component_match'], 'quantity' => max(1, (int) $row['quantity'])];
            }
        }

        return $out;
    }

    /** Sets fuer diesen Shop aktiv? (Abo + Richtung) */
    public function activeFor(string $shop, string $direction): bool
    {
        return $direction === 'target_to_shopify'
            && Plans::allowsFeature(Plans::forShop($this->pdo, $shop), 'bundles')
            && TargetCapabilities::supports($this->pdo, $shop, 'bundles');
    }

    /**
     * Verfuegbare Sets aus den Kassenbestaenden der Einzelteile.
     *
     * @param list<array{match: string, quantity: int}> $components
     * @param array<string, ?int> $targetQuantities Vergleichswert => Kassenbestand (null = unbekannt)
     * @return ?int null = ein Einzelteil fehlt oder Bestand unbekannt
     */
    public static function availableSets(array $components, array $targetQuantities): ?int
    {
        if ($components === []) {
            return null;
        }
        $min = null;
        foreach ($components as $component) {
            $quantity = $targetQuantities[$component['match']] ?? null;
            if ($quantity === null) {
                return null;
            }
            $sets = intdiv(max(0, $quantity), max(1, $component['quantity']));
            $min = $min === null ? $sets : min($min, $sets);
        }

        return $min;
    }

    /**
     * Set speichern (neu oder ersetzen).
     *
     * @param list<array{match: string, quantity: int}> $components
     */
    public function save(string $shop, string $bundleMatch, ?string $label, array $components): void
    {
        $components = array_values(array_filter($components, static fn (array $c): bool => trim($c['match']) !== '' && $c['quantity'] > 0));
        if (trim($bundleMatch) === '' || $components === []) {
            throw new \RuntimeException('Bitte den Set-Artikel und mindestens ein Einzelteil angeben.');
        }
        if (count($components) > self::MAX_COMPONENTS) {
            throw new \RuntimeException('Höchstens ' . self::MAX_COMPONENTS . ' Einzelteile je Set.');
        }
        foreach ($components as $c) {
            if (trim($c['match']) === trim($bundleMatch)) {
                throw new \RuntimeException('Ein Set kann nicht sich selbst enthalten.');
            }
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO connect_bundles (shop, bundle_match, label) VALUES (:shop, :m, :l)
                 ON DUPLICATE KEY UPDATE label = VALUES(label)'
            )->execute(['shop' => $shop, 'm' => mb_substr(trim($bundleMatch), 0, 190), 'l' => $label !== null && trim($label) !== '' ? mb_substr(trim($label), 0, 255) : null]);
            $idStmt = $this->pdo->prepare('SELECT id FROM connect_bundles WHERE shop = :shop AND bundle_match = :m');
            $idStmt->execute(['shop' => $shop, 'm' => mb_substr(trim($bundleMatch), 0, 190)]);
            $bundleId = (int) $idStmt->fetchColumn();
            $this->pdo->prepare('DELETE FROM connect_bundle_components WHERE bundle_id = :id')->execute(['id' => $bundleId]);
            $insert = $this->pdo->prepare('INSERT INTO connect_bundle_components (bundle_id, component_match, quantity) VALUES (:id, :m, :q)');
            foreach ($components as $c) {
                $insert->execute(['id' => $bundleId, 'm' => mb_substr(trim($c['match']), 0, 190), 'q' => (int) $c['quantity']]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function delete(string $shop, int $bundleId): void
    {
        $this->pdo->prepare('DELETE FROM connect_bundles WHERE id = :id AND shop = :shop')->execute(['id' => $bundleId, 'shop' => $shop]);
    }

    /**
     * Verkaufte Sets einer Bestellung: Einzelteile im Kassensystem abbuchen.
     *
     * @param array<string, mixed> $shopRow
     * @param array<string, mixed> $orderRow
     * @param callable(ConnectorInterface, string): ?array $articleMatch InvoiceService::findArticleMatchFields
     * @return list<string> Hinweise fuer das Protokoll (leer = keine Sets in der Bestellung)
     */
    public function deductComponentsForOrder(string $shop, array $shopRow, array $orderRow, ConnectorInterface $targetConnector, callable $articleMatch, string $orderNumber, ?int $syncRunId): array
    {
        $direction = (string) ($shopRow['stock_sync_direction'] ?? '');
        if (!$this->activeFor($shop, $direction)) {
            return [];
        }
        $definitions = $this->definitions($shop);
        if ($definitions === []) {
            return [];
        }
        $comparisonKey = (string) ($shopRow['comparison_key'] ?? '');
        $lineMatchKey = OrderPreparer::LINE_ITEM_MATCH_FIELDS[$comparisonKey] ?? null;
        $match = $lineMatchKey !== null ? $articleMatch($targetConnector, $comparisonKey) : null;
        if ($match === null) {
            return [];
        }
        [$targetObject, $matchField, $identifierField] = $match;
        $cache = new ObjectCache($this->pdo);
        $notes = [];

        foreach ((array) ($orderRow['line_items'] ?? []) as $line) {
            if (!is_array($line)) {
                continue;
            }
            $value = trim((string) ($line[$lineMatchKey] ?? ''));
            $sold = (int) round((float) ($line['line_quantity'] ?? 0));
            if ($value === '' || $sold <= 0 || !isset($definitions[$value])) {
                continue;
            }
            foreach ($definitions[$value]['components'] as $component) {
                $article = $cache->findOneByFieldValue((int) $shopRow['target_connection_id'], $targetObject->key, $matchField->key, $component['match']);
                if ($article === null || !isset($article[$identifierField->key])) {
                    $notes[] = "Set \"{$value}\": Einzelteil \"{$component['match']}\" im Kassensystem nicht gefunden – nicht abgebucht.";
                    continue;
                }
                $change = -$sold * $component['quantity'];
                try {
                    $result = $targetConnector->write($targetObject->key, 'update', [
                        $identifierField->key => $article[$identifierField->key],
                        'id' => $article[$identifierField->key],
                        'stock_change' => $change,
                        'stock_description' => "Shopify Bestellung {$orderNumber} (Set {$value})",
                    ]);
                    $ok = $result->success;
                    $message = $ok ? "Set \"{$value}\": Einzelteil \"{$component['match']}\" {$change}." : "Set \"{$value}\": Einzelteil \"{$component['match']}\" fehlgeschlagen: " . $result->message;
                    if ($ok) {
                        // Zwischenspeicher sofort nachziehen, damit der naechste Abgleich mit dem neuen Bestand rechnet
                        $stockField = null;
                        foreach ($targetObject->fields as $field) {
                            if ($field->isStockQuantity) {
                                $stockField = $field;
                            }
                        }
                        if ($stockField !== null && isset($article[$stockField->key]) && $article[$stockField->key] !== null) {
                            $cache->updateCachedFields((int) $shopRow['target_connection_id'], $targetObject->key, (string) $article[$identifierField->key], [$stockField->key => (int) $article[$stockField->key] + $change]);
                        }
                    }
                    ActivityLog::log($this->pdo, $shop, null, $targetObject->key, 'bundle_stock', $ok, "Bestellung {$orderNumber}: {$message}", (string) $article[$identifierField->key], $result->requestBody, $result->responseBody, $syncRunId);
                    $notes[] = $message;
                } catch (\Throwable $e) {
                    $notes[] = "Set \"{$value}\": Einzelteil \"{$component['match']}\" fehlgeschlagen: " . $e->getMessage();
                }
            }
        }

        return $notes;
    }
}
