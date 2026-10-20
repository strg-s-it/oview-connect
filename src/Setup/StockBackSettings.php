<?php

declare(strict_types=1);

namespace Connect\Setup;

use PDO;

/**
 * Einstellung "Rueckbuchung bei Kassenverkauf" (Migration 0104, Connect\Sync\StockBackRunner):
 * lesen und speichern, gemeinsam fuer die Haendler-Einstellungen (nur die Rueckbuchung) und den
 * Admin-Bereich (zusaetzlich die Dummy-Bestellung, legt echte Bestellungen im Shop an).
 */
final class StockBackSettings
{
    /**
     * @return array{enabled: bool, dummy: bool, since: ?string, direction: string, available: bool}|null
     *         null = Migration 0104 fehlt oder Shop unbekannt. available = das Zielsystem kann Kassenrechnungen lesen
     *         (Object "invoice_read" im Connector Builder, siehe HANDOFF.md).
     */
    public static function state(PDO $pdo, string $shop): ?array
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT stock_back_enabled, stock_back_dummy_order, stock_back_since, stock_sync_direction, target_connection_id
                 FROM connect_shops WHERE shop = :shop'
            );
            $stmt->execute(['shop' => $shop]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return null;
        }
        if ($row === false) {
            return null;
        }

        $available = false;
        if ($row['target_connection_id'] !== null) {
            $check = $pdo->prepare(
                'SELECT 1 FROM connect_objects o JOIN connect_connections c ON c.connector_type_id = o.connector_type_id
                 WHERE c.id = :id AND o.object_key = :key LIMIT 1'
            );
            $check->execute(['id' => (int) $row['target_connection_id'], 'key' => \Connect\Sync\StockBackRunner::INVOICE_OBJECT]);
            $available = $check->fetchColumn() !== false;
        }

        return [
            'enabled' => (int) $row['stock_back_enabled'] === 1,
            'dummy' => (int) $row['stock_back_dummy_order'] === 1,
            'since' => $row['stock_back_since'] !== null ? (string) $row['stock_back_since'] : null,
            'direction' => (string) $row['stock_sync_direction'],
            'available' => $available,
        ];
    }

    /**
     * Speichert die Einstellung und legt/schaltet die Aktivierung. Beim Einschalten (war aus) beginnt die
     * Verarbeitung ab JETZT -- aeltere Rechnungen werden nie nachtraeglich verarbeitet.
     *
     * @param ?bool $dummy null = unveraendert lassen (Haendler duerfen die Dummy-Bestellung nicht aendern)
     * @return array{ok: bool, message: string}
     */
    public static function save(PDO $pdo, \Connect\AppContext $app, string $shop, bool $enabled, ?bool $dummy): array
    {
        $state = self::state($pdo, $shop);
        if ($state === null) {
            return ['ok' => false, 'message' => 'Migration 0104 ist noch nicht eingespielt.'];
        }
        if ($enabled && $state['direction'] !== 'shopify_to_target') {
            return ['ok' => false, 'message' => 'Die Rückbuchung gibt es nur, wenn der Bestand in Shopify geführt wird. Bei „Kassensystem führt“ kommt der Kassenbestand ohnehin zu Shopify.'];
        }
        if ($enabled && !$state['available']) {
            return ['ok' => false, 'message' => 'Für diese Software ist die Rückbuchung noch nicht eingerichtet (Object „' . \Connect\Sync\StockBackRunner::INVOICE_OBJECT . '“ im Connector Builder).'];
        }

        $dummyValue = $dummy === null ? $state['dummy'] : ($enabled && $dummy);
        $sets = 'stock_back_enabled = :enabled, stock_back_dummy_order = :dummy';
        $params = ['enabled' => $enabled ? 1 : 0, 'dummy' => $dummyValue ? 1 : 0, 'shop' => $shop];
        if ($enabled && !$state['enabled']) {
            $sets .= ', stock_back_since = :since';
            $params['since'] = date('Y-m-d H:i:s');
        }
        $pdo->prepare("UPDATE connect_shops SET {$sets} WHERE shop = :shop")->execute($params);

        (new ShopActivations($pdo, $app->crypto, $app->config))->syncStockBackMapping($shop, $enabled);

        return ['ok' => true, 'message' => $enabled
            ? 'Rückbuchung bei Kassenverkauf ist an – neue Kassenverkäufe werden ab jetzt alle ' . ShopActivations::STOCK_BACK_MINUTES . ' Minuten in Shopify nachgetragen.'
            : 'Rückbuchung bei Kassenverkauf ist aus.'];
    }
}
