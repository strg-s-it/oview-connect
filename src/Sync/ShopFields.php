<?php

declare(strict_types=1);

namespace Connect\Sync;

use Connect\Connector\Generic\GenericHttpConnector;
use PDO;

/**
 * Shop-Felder (Migration 0074): Werte, die nur ein bestimmter Shop hat, als
 * Shopify-Produkt-Metafield definiert (admin/shop_fields.php). Beispiel:
 * danysmusicshop, Netto-Einkaufspreis im Metafield ...article_net_purchase_price.
 *
 * Ablauf:
 *   - Die Shopify-Produktabfrage hat 5 Metafield-Plaetze (sf1..sf5), per Variablen
 *     ein-/ausgeschaltet -- gleiches Muster wie der Standort ($locationId/$hasLocation).
 *     queryParams() setzt sie je Shop, Connect\Sync\ObjectCache ruft das bei jedem Abruf.
 *   - Der Rohwert landet in shop_metafield_<platz>, applyValues() loest ihn auf
 *     (optional Teilwert, z.B. amount) und legt ihn als sf_<field_key> im Cache ab.
 *   - Connect\Mapping\MappingEngine, Umrechnung "Shop-Feld", liest sf_<field_key>.
 *     Eine Vorlage gilt so fuer alle Shops, nur die Quelle ist je Shop definiert.
 */
final class ShopFields
{
    public const MAX_SLOTS = 5;
    public const CACHE_PREFIX = 'sf_';
    public const RAW_PREFIX = 'shop_metafield_';

    /**
     * Shop-Felder des Shops dieser Connection -- nur fuer Shopify-Connections, sonst leer.
     * Fehlt die Tabelle noch (Migration 0074), ebenfalls leer: der Abruf laeuft wie bisher.
     *
     * @return array<int, array{field_key: string, label: string, metafield_namespace: string, metafield_key: string, value_path: ?string, slot: int}>
     */
    public static function forConnection(PDO $pdo, int $connectionId, string $objectKey): array
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT f.field_key, f.label, f.metafield_namespace, f.metafield_key, f.value_path, f.slot
                 FROM connect_shop_fields f
                 JOIN connect_connections c ON c.shop = f.shop
                 JOIN connect_connector_types t ON t.id = c.connector_type_id
                 WHERE c.id = :connection_id AND t.type_key = 'shopify' AND f.object_key = :object_key
                 ORDER BY f.slot"
            );
            $stmt->execute(['connection_id' => $connectionId, 'object_key' => $objectKey]);
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        return array_map(static fn (array $r): array => [
            'field_key' => (string) $r['field_key'],
            'label' => (string) $r['label'],
            'metafield_namespace' => (string) $r['metafield_namespace'],
            'metafield_key' => (string) $r['metafield_key'],
            'value_path' => $r['value_path'] !== null && $r['value_path'] !== '' ? (string) $r['value_path'] : null,
            'slot' => (int) $r['slot'],
        ], $rows);
    }

    /**
     * GraphQL-Variablen fuer ALLE Plaetze einer Shopify-Produktabfrage: unbelegte als
     * Key "-" + hasSfN false. Shopify wendet den Standardwert einer nicht mitgeschickten
     * String!-Variable NICHT an ("Variable $sf2Key of type String! was provided invalid
     * value", live 2026-10-05) -- deshalb immer alle mitschicken. Fuer andere Objects/
     * Systeme bleiben die Parameter unveraendert. Ein vom Aufrufer gesetzter Wert gewinnt.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function paramsForConnection(PDO $pdo, int $connectionId, string $objectKey, array $params): array
    {
        if ($objectKey !== 'product' || !self::isShopifyConnection($pdo, $connectionId)) {
            return $params;
        }
        for ($slot = 1; $slot <= self::MAX_SLOTS; $slot++) {
            $params += ["sf{$slot}Key" => '-', "hasSf{$slot}" => false];
        }

        return self::queryParams($params, self::forConnection($pdo, $connectionId, $objectKey), true);
    }

    private static function isShopifyConnection(PDO $pdo, int $connectionId): bool
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT 1 FROM connect_connections c JOIN connect_connector_types t ON t.id = c.connector_type_id
                 WHERE c.id = :id AND t.type_key = 'shopify'"
            );
            $stmt->execute(['id' => $connectionId]);

            return $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * GraphQL-Variablen fuer die belegten Plaetze. Ein vom Aufrufer gesetzter Wert gewinnt
     * (ausser $overrideDefaults: dann werden die Platzhalter aus paramsForConnection() ersetzt).
     *
     * @param array<string, mixed> $params
     * @param array<int, array<string, mixed>> $fields aus forConnection()
     * @return array<string, mixed>
     */
    public static function queryParams(array $params, array $fields, bool $overrideDefaults = false): array
    {
        foreach ($fields as $field) {
            $slot = (int) $field['slot'];
            if ($slot < 1 || $slot > self::MAX_SLOTS || (!$overrideDefaults && array_key_exists("hasSf{$slot}", $params))) {
                continue;
            }
            $params["sf{$slot}Namespace"] = $field['metafield_namespace'];
            $params["sf{$slot}Key"] = $field['metafield_key'];
            $params["hasSf{$slot}"] = true;
        }

        return $params;
    }

    /**
     * Rohwerte der Plaetze aufloesen und als sf_<field_key> ablegen. Die Rohfelder
     * shop_metafield_* werden entfernt (sie bedeuten je Shop etwas anderes).
     *
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $fields aus forConnection()
     * @return array<string, mixed>
     */
    public static function applyValues(array $row, array $fields): array
    {
        $raw = [];
        for ($slot = 1; $slot <= self::MAX_SLOTS; $slot++) {
            if (array_key_exists(self::RAW_PREFIX . $slot, $row)) {
                $raw[$slot] = $row[self::RAW_PREFIX . $slot];
                unset($row[self::RAW_PREFIX . $slot]);
            }
        }

        foreach ($fields as $field) {
            $row[self::CACHE_PREFIX . $field['field_key']] = self::resolve($raw[(int) $field['slot']] ?? null, $field['value_path']);
        }

        return $row;
    }

    /**
     * jsonValue eines Metafields -> Wert. Geldbetrag {"amount": "12.5", "currency_code": "EUR"}
     * mit Teilwert "amount" -> "12.5". Ohne Teilwert: Skalar direkt, Objekt/Liste als JSON.
     */
    public static function resolve(mixed $jsonValue, ?string $valuePath): mixed
    {
        if ($jsonValue === null) {
            return null;
        }
        // Manche Metafield-Typen liefern JSON als Text
        if (is_string($jsonValue) && $valuePath !== null && ($jsonValue[0] ?? '') === '{') {
            $decoded = json_decode($jsonValue, true);
            $jsonValue = is_array($decoded) ? $decoded : $jsonValue;
        }
        if ($valuePath !== null) {
            return is_array($jsonValue) ? GenericHttpConnector::extractByPath($jsonValue, $valuePath) : null;
        }

        return is_array($jsonValue) ? json_encode($jsonValue, JSON_UNESCAPED_UNICODE) : $jsonValue;
    }

    /**
     * Live: alle Metafields eines Shopify-Produkts (fuer die Auswahl in der Maske).
     * Ohne Produkt-ID das zuletzt geaenderte Produkt.
     *
     * @return array{product: ?string, metafields: array<int, array{namespace: string, key: string, type: string, value: string}>}
     */
    public static function discoverMetafields(GenericHttpConnector $connector, ?string $productGid = null): array
    {
        $selection = 'id title metafields(first: 100) { nodes { namespace key type value } }';
        $decoded = $productGid !== null
            ? $connector->graphQlQuery('query($id: ID!) { product(id: $id) { ' . $selection . ' } }', ['id' => $productGid])
            : $connector->graphQlQuery('query { products(first: 1, sortKey: UPDATED_AT, reverse: true) { nodes { ' . $selection . ' } } }', []);

        $product = $productGid !== null ? ($decoded['data']['product'] ?? null) : ($decoded['data']['products']['nodes'][0] ?? null);
        if (!is_array($product)) {
            return ['product' => null, 'metafields' => []];
        }

        $metafields = [];
        foreach ($product['metafields']['nodes'] ?? [] as $node) {
            $metafields[] = [
                'namespace' => (string) ($node['namespace'] ?? ''),
                'key' => (string) ($node['key'] ?? ''),
                'type' => (string) ($node['type'] ?? ''),
                'value' => mb_substr((string) ($node['value'] ?? ''), 0, 200),
            ];
        }

        return ['product' => (string) ($product['title'] ?? '') . ' (' . (string) ($product['id'] ?? '') . ')', 'metafields' => $metafields];
    }
}
