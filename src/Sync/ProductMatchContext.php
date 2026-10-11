<?php

declare(strict_types=1);

namespace Connect\Sync;

use Connect\Connector\ConnectorFactory;
use Connect\Connector\ConnectorInterface;
use Connect\Connector\FieldDefinition;
use Connect\Connector\ObjectDefinition;
use PDO;

/**
 * Loest den gemeinsamen Objekt-/Feld-Kontext auf, den sowohl der schreibende
 * Lagerbestand-Abgleich (StockSyncRunner) als auch die rein lesende
 * Produkt-Vergleichsuebersicht (ProductComparisonService) brauchen: Shop-
 * Einstellungen (connect_shops.comparison_key/target_connection_id/...),
 * Shopify- und Ziel-Connection, sowie je Seite das Object mit sowohl einer
 * comparison_role- als auch einer is_stock_quantity-Markierung (Migration
 * 0027, im Connector Builder gesetzt).
 *
 * Extrahiert aus StockSyncRunner::runForShop(), das vorher genau diese Logik
 * lokal hatte -- ausgelagert, damit die neue Produkt-Vergleichsuebersicht sie
 * nicht duplizieren muss.
 */
final class ProductMatchContext
{
    public function __construct(
        public readonly ConnectorInterface $shopifyConnector,
        public readonly ConnectorInterface $targetConnector,
        public readonly int $shopifyConnectionId,
        public readonly int $targetConnectionId,
        public readonly ObjectDefinition $shopifyObject,
        public readonly FieldDefinition $shopifyMatchField,
        public readonly FieldDefinition $shopifyStockField,
        public readonly ObjectDefinition $targetObject,
        public readonly FieldDefinition $targetMatchField,
        public readonly FieldDefinition $targetStockField,
        public readonly string $direction,
        public readonly ?string $locationId,
        public readonly string $comparisonKey,
        public readonly ?string $variantComparisonKey,
        public readonly bool $shopifySupportsVariants,
        public readonly bool $targetSupportsVariants
    ) {
    }

    /**
     * @throws ProductMatchUnresolvedException wenn der Kontext (noch) nicht
     *         aufgeloest werden kann -- $status uebernimmt die bisherige
     *         skipped/error-Unterscheidung aus StockSyncRunner::runForShop().
     */
    public static function resolve(PDO $pdo, ConnectorFactory $connectorFactory, string $shop): self
    {
        $shopSettings = self::loadShopSettings($pdo, $shop);
        if ($shopSettings === null) {
            throw new ProductMatchUnresolvedException('skipped', 'Shop-Einstellungen (connect_shops) nicht gefunden.');
        }
        if ($shopSettings['target_connection_id'] === null) {
            throw new ProductMatchUnresolvedException('skipped', 'Kein Zielsystem verbunden (connect_shops.target_connection_id).');
        }

        $shopifyConnectionId = self::findConnectionId($pdo, $shop, 'shopify');
        if ($shopifyConnectionId === null) {
            throw new ProductMatchUnresolvedException('error', 'Keine Shopify-Connection fuer diesen Shop gefunden.');
        }

        $targetConnectionId = (int) $shopSettings['target_connection_id'];
        $comparisonKey = (string) $shopSettings['comparison_key'];
        $direction = (string) $shopSettings['stock_sync_direction'];
        $locationId = $shopSettings['stock_primary_location_id'];
        $variantComparisonKey = $shopSettings['variant_comparison_key'] !== null && $shopSettings['variant_comparison_key'] !== ''
            ? (string) $shopSettings['variant_comparison_key']
            : null;

        try {
            $shopifyConnector = $connectorFactory->forConnection($shopifyConnectionId);
            $targetConnector = $connectorFactory->forConnection($targetConnectionId);
        } catch (\Throwable $e) {
            throw new ProductMatchUnresolvedException('error', 'Connector konnte nicht aufgebaut werden: ' . $e->getMessage());
        }

        $shopifyMatch = self::findStockObjectAndFields($shopifyConnector, $comparisonKey);
        $targetMatch = self::findStockObjectAndFields($targetConnector, $comparisonKey);

        if ($shopifyMatch === null || $targetMatch === null) {
            throw new ProductMatchUnresolvedException(
                'skipped',
                'Vergleichswert- oder Lagerbestand-Feld noch nicht im Connector Builder markiert -- Shopify: '
                    . ($shopifyMatch === null ? 'fehlt' : 'ok') . ', Ziel: ' . ($targetMatch === null ? 'fehlt' : 'ok') . '.'
            );
        }

        [$shopifyObject, $shopifyMatchField, $shopifyStockField] = $shopifyMatch;
        [$targetObject, $targetMatchField, $targetStockField] = $targetMatch;

        return new self(
            $shopifyConnector,
            $targetConnector,
            $shopifyConnectionId,
            $targetConnectionId,
            $shopifyObject,
            $shopifyMatchField,
            $shopifyStockField,
            $targetObject,
            $targetMatchField,
            $targetStockField,
            $direction,
            $locationId,
            $comparisonKey,
            $variantComparisonKey,
            self::connectionSupportsVariants($pdo, $shopifyConnectionId),
            self::connectionSupportsVariants($pdo, $targetConnectionId)
        );
    }

    /**
     * Kennt das System hinter dieser Connection ueberhaupt Produktvarianten
     * (connect_connector_types.supports_variants, Migration 0034)? Nur
     * Shopify aktuell -- steuert, ob der Varianten-Import-Zweig
     * (Connect\Sync\ProductImportService) fuer diese Connection als Ziel
     * ueberhaupt in Frage kommt, statt es implizit an gesetzten Feld-Rollen
     * zu erkennen.
     */
    private static function connectionSupportsVariants(PDO $pdo, int $connectionId): bool
    {
        $stmt = $pdo->prepare(
            'SELECT t.supports_variants FROM connect_connections c
             JOIN connect_connector_types t ON t.id = c.connector_type_id
             WHERE c.id = :connection_id'
        );
        $stmt->execute(['connection_id' => $connectionId]);

        return ((int) $stmt->fetchColumn()) === 1;
    }

    /**
     * Sucht unter allen Objects eines Connectors dasjenige mit sowohl einem
     * Lagerbestand-Feld (is_stock_quantity) als auch einem Feld fuer den
     * gewuenschten Vergleichswert (comparison_role) -- beides im Connector
     * Builder gesetzt. Liefert null, wenn kein Object beide Markierungen
     * traegt (z.B. weil sie dort noch nicht gesetzt wurden).
     *
     * @return array{0: ObjectDefinition, 1: FieldDefinition, 2: FieldDefinition}|null
     */
    private static function findStockObjectAndFields(ConnectorInterface $connector, string $comparisonKey): ?array
    {
        foreach ($connector->getObjects() as $object) {
            $matchField = null;
            $stockField = null;

            foreach ($object->fields as $field) {
                if ($field->isStockQuantity) {
                    $stockField = $field;
                }
                if ($field->comparisonRole === $comparisonKey) {
                    $matchField = $field;
                }
            }

            if ($matchField !== null && $stockField !== null) {
                return [$object, $matchField, $stockField];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function loadShopSettings(PDO $pdo, string $shop): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT comparison_key, target_connection_id, stock_sync_enabled, stock_sync_direction, stock_primary_location_id, variant_comparison_key
             FROM connect_shops WHERE shop = :shop'
        );
        $stmt->execute(['shop' => $shop]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    private static function findConnectionId(PDO $pdo, string $shop, string $connectorTypeKey): ?int
    {
        $stmt = $pdo->prepare(
            'SELECT c.id FROM connect_connections c
             JOIN connect_connector_types t ON t.id = c.connector_type_id
             WHERE c.shop = :shop AND t.type_key = :type_key LIMIT 1'
        );
        $stmt->execute(['shop' => $shop, 'type_key' => $connectorTypeKey]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    /**
     * Feld mit comparison_role='title' eines Objects, falls im Connector
     * Builder gesetzt -- fuer die Anzeige eines lesbaren Produktnamens in
     * Log/Uebersicht (unabhaengig vom shop-weit gewaehlten comparisonKey,
     * der z.B. auch "sku" sein kann).
     */
    public static function titleField(ObjectDefinition $object): ?FieldDefinition
    {
        return self::fieldWithComparisonRole($object, 'title');
    }

    /**
     * Oeffentlich, da auch Connect\Sync\ProductImportService beliebige
     * comparison_role-Werte (z.B. 'sku'/'barcode' fuer den Varianten-Zweig)
     * unabhaengig vom shop-weit gewaehlten comparisonKey braucht.
     */
    public static function fieldWithComparisonRole(ObjectDefinition $object, string $role): ?FieldDefinition
    {
        foreach ($object->fields as $field) {
            if ($field->comparisonRole === $role) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Feld ueber den exakten field_key finden, unabhaengig von einer
     * comparison_role -- fuer rein informative Zusatzfelder ohne eigene
     * Rolle (z.B. Shopifys "variant_title", Connect\Sync\
     * ProductComparisonService fuer die Produkte-Seite).
     */
    public static function fieldWithKey(ObjectDefinition $object, string $key): ?FieldDefinition
    {
        foreach ($object->fields as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }
}
