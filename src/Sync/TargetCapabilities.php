<?php

declare(strict_types=1);

namespace Connect\Sync;

use PDO;

/**
 * Was kann das Zielsystem (Kasse/Warenwirtschaft) eines Shops? -- abgeleitet aus der Einrichtung
 * im Connector Builder, nicht aus dem Systemnamen. Damit gelten die Zusatzfunktionen fuer jedes
 * System, sobald es die noetigen Felder/Operationen hat (z.B. MGM mit einem Preisfeld).
 *
 * Nur Datenbank-Abfragen (kein Connector-Aufbau, keine API) -- schnell genug fuer Dashboard und
 * Seitenaufbau. Massgeblich ist das Bestands-Object des Zielsystems: das Object mit einem
 * Lagerbestand-Feld und einem Feld mit der Rolle des shop-weiten Vergleichswerts (wie im
 * Lagerabgleich, ProductMatchContext).
 *
 *   price_read    Feld mit Rolle "Preis"                     -> Preisunterschiede, Sync Kasse -> Shopify
 *   price_write   + Operation "update_price"                 -> Sync Shopify -> Kasse, Sammelaenderung im Kassensystem
 *   stock_write   Operation "update" (Bestand als Delta)     -> Set-Einzelteile abbuchen
 *   invoices      Object "invoice" mit Operation "create"    -> Rechnungsmail-Anhaenge, Set-Abbuchung beim Verkauf
 *   pos_leads     Richtung "Kassensystem fuehrt"             -> Sicherheitsbestand, Sets
 */
final class TargetCapabilities
{
    /** @var array<string, array<string, bool>> je Anfrage zwischengespeichert */
    private static array $cache = [];

    /**
     * @return array{connected: bool, price_read: bool, price_write: bool, stock_write: bool, invoices: bool, pos_leads: bool, target_name: string}
     */
    public static function forShop(PDO $pdo, string $shop): array
    {
        if (isset(self::$cache[$shop])) {
            return self::$cache[$shop];
        }
        $none = ['connected' => false, 'price_read' => false, 'price_write' => false, 'stock_write' => false, 'invoices' => false, 'pos_leads' => false, 'target_name' => 'Kassensystem'];
        try {
            $stmt = $pdo->prepare(
                'SELECT s.comparison_key, s.stock_sync_direction, s.shopify_prices_include_tax, c.connector_type_id, t.name
                 FROM connect_shops s JOIN connect_connections c ON c.id = s.target_connection_id
                 JOIN connect_connector_types t ON t.id = c.connector_type_id WHERE s.shop = :shop'
            );
            $stmt->execute(['shop' => $shop]);
            $shopRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($shopRow === false) {
                return self::$cache[$shop] = $none;
            }
            $typeId = (int) $shopRow['connector_type_id'];

            // Bestands-Object: Lagerbestand-Feld + Feld mit der Vergleichswert-Rolle des Shops
            $objects = $pdo->prepare(
                "SELECT o.id, o.object_key,
                        MAX(f.is_stock_quantity = 1) AS has_stock,
                        MAX(f.comparison_role = :role) AS has_match,
                        MAX(f.comparison_role = 'price') AS has_price,
                        MAX(f.comparison_role = 'price_net') AS has_price_net
                 FROM connect_objects o JOIN connect_object_fields f ON f.object_id = o.id
                 WHERE o.connector_type_id = :type GROUP BY o.id, o.object_key ORDER BY o.id"
            );
            $objects->execute(['role' => (string) $shopRow['comparison_key'], 'type' => $typeId]);
            $stockObject = null;
            foreach ($objects->fetchAll(PDO::FETCH_ASSOC) as $object) {
                if ((int) $object['has_stock'] === 1 && (int) $object['has_match'] === 1) {
                    $stockObject = $object;
                    break;
                }
            }

            $operations = $pdo->prepare(
                'SELECT o.object_key, op.operation_type FROM connect_object_operations op JOIN connect_objects o ON o.id = op.object_id WHERE o.connector_type_id = :type'
            );
            $operations->execute(['type' => $typeId]);
            $ops = [];
            foreach ($operations->fetchAll(PDO::FETCH_ASSOC) as $op) {
                $ops[$op['object_key'] . ':' . $op['operation_type']] = true;
            }

            // Preis passend zur Basis des Shops (NULL gilt als Brutto): Brutto -> Rolle "Preis", Netto -> "Preis netto"
            $gross = ($shopRow['shopify_prices_include_tax'] ?? null) === null || (int) $shopRow['shopify_prices_include_tax'] === 1;
            $priceRead = $stockObject !== null && (int) $stockObject[$gross ? 'has_price' : 'has_price_net'] === 1;
            $stockKey = $stockObject['object_key'] ?? '';

            return self::$cache[$shop] = [
                'connected' => true,
                'price_read' => $priceRead,
                'price_write' => $priceRead && isset($ops[$stockKey . ':update_price']),
                'stock_write' => $stockObject !== null && isset($ops[$stockKey . ':update']),
                'invoices' => isset($ops['invoice:create']),
                'pos_leads' => ($shopRow['stock_sync_direction'] ?? '') === 'target_to_shopify',
                'target_name' => (string) $shopRow['name'],
            ];
        } catch (\PDOException) {
            return self::$cache[$shop] = $none;
        }
    }

    /**
     * Ist eine Zusatzfunktion mit diesem Zielsystem nutzbar? (unabhaengig vom Abo)
     *
     * @param string $feature price_tools | price_sync | attachments | bundles | safety_stock
     */
    public static function supports(PDO $pdo, string $shop, string $feature): bool
    {
        $c = self::forShop($pdo, $shop);

        return match ($feature) {
            // Sammelaenderung nur in Shopify geht immer, sobald ein Zielsystem verbunden ist
            'price_tools' => $c['connected'],
            'price_sync' => $c['price_read'],
            'attachments' => $c['invoices'],
            'bundles' => $c['pos_leads'] && $c['stock_write'] && $c['invoices'],
            'safety_stock' => $c['pos_leads'],
            default => true,
        };
    }

    private static function pricesGross(PDO $pdo, string $shop): bool
    {
        return ShopStockRules::forShop($pdo, $shop)['prices_gross'];
    }

    /** Erklaerung, warum eine Funktion nicht geht (fuer die Seiten). */
    public static function reason(PDO $pdo, string $shop, string $feature): string
    {
        $c = self::forShop($pdo, $shop);
        $name = $c['target_name'];

        return match ($feature) {
            'price_sync' => "{$name} liefert Connect keine passenden Preise (im Connector Builder fehlt am Artikel ein Feld mit der Rolle „"
                . (self::pricesGross($pdo, $shop) ? 'Preis (brutto)' : 'Preis netto') . "“ – dein Shop führt die Preise in Shopify "
                . (self::pricesGross($pdo, $shop) ? 'brutto' : 'netto') . ') – Preise vergleichen und angleichen ist deshalb nicht möglich. Gesammelte Preisänderungen in Shopify funktionieren trotzdem.',
            'attachments' => "Für {$name} erstellt Connect keine Rechnungen – deshalb gibt es keine Rechnungsmail, an die etwas angehängt werden könnte.",
            'bundles' => !$c['pos_leads']
                ? 'Sets funktionieren nur, wenn du den Bestand im Kassensystem bearbeitest (Einstellungen → Lagerbestand).'
                : "Mit {$name} kann Connect die Einzelteile eines verkauften Sets nicht abbuchen – Sets sind deshalb nicht verfügbar.",
            'safety_stock' => 'Der Sicherheitsbestand gilt nur, wenn du den Bestand im Kassensystem bearbeitest.',
            default => '',
        };
    }
}
