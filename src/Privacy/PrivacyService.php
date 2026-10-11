<?php

declare(strict_types=1);

namespace Connect\Privacy;

use PDO;
use PDOException;

/**
 * Datenschutz-Logik fuer die Shopify-Pflicht-Webhooks und die regelmaessige
 * Bereinigung (DSGVO). Alle Methoden sind idempotent: Shopify wiederholt einen
 * Pflicht-Webhook, wenn die Antwort ausbleibt oder ein Fehler kommt -- ein
 * zweiter Durchlauf darf nichts kaputt machen und findet einfach nichts mehr.
 *
 * Wo in Connect Personendaten liegen (Stand Migration 0051):
 *   - connect_webhook_events.payload   -- voller Bestell-Payload (Name, E-Mail, Adressen)
 *   - connect_object_records.data      -- gecachte Bestellungen (Object "order")
 *   - connect_activity_log bodies      -- rohe Sende-/Antworttexte (aktuell Produkt-Importe)
 *   - connect_connections.credentials  -- Zugangsdaten, verschluesselt (Shop-Loeschung)
 * Kundendaten in Zielsystemen (HelloCash, MGM) gehoeren dem Haendler und liegen
 * nicht in Connect.
 *
 * Kein JSON-Typ in der Datenbank (MariaDB 5.x): Treffer werden per LIKE
 * vorgefiltert und danach in PHP anhand der dekodierten Struktur geprueft, damit
 * eine zufaellig passende Ziffernfolge nichts Fremdes loescht.
 */
final class PrivacyService
{
    /** Object-Key der gecachten Shopify-Bestellungen. */
    private const ORDER_OBJECT_KEY = 'order';

    /** Datensaetze pro Durchlauf beim Durchsuchen grosser Tabellen. */
    private const SCAN_BATCH = 500;

    public function __construct(private readonly PDO $pdo) {}

    // ------------------------------------------------------------------
    // shop/redact -- alles zu einem Shop entfernen
    // ------------------------------------------------------------------

    /**
     * Entfernt ALLE Daten eines Shops aus Connect, inklusive der gespeicherten
     * Zugangsdaten. Die Loeschung der Connections kaskadiert (Fremdschluessel)
     * auf Cache-Datensaetze, Webhook-Abos, Sync-Laeufe und Uebersetzungs-Log.
     * Nicht kaskadierende Tabellen mit Shop-Spalte werden ausdruecklich geleert.
     * Das Protokoll connect_privacy_requests bleibt bewusst stehen (Nachweis).
     * Alles in einer Transaktion: entweder komplett oder gar nicht.
     *
     * @return array<string, int> geloeschte Zeilen je Bereich
     */
    public function eraseShop(string $shop): array
    {
        $statements = [
            'webhook_events' => 'DELETE FROM connect_webhook_events WHERE shop = :shop',
            'activity_log' => 'DELETE FROM connect_activity_log WHERE shop = :shop',
            'stock_sync_log' => 'DELETE FROM connect_stock_sync_log WHERE shop = :shop',
            'sync_mappings' => 'DELETE FROM connect_sync_mappings WHERE shop = :shop',
            'connections' => 'DELETE FROM connect_connections WHERE shop = :shop',
            'shop' => 'DELETE FROM connect_shops WHERE shop = :shop',
        ];

        // Tabellen neuerer Migrationen (0073/0074) -- fehlen sie noch, wird nur dieser Teil uebersprungen
        $optionalStatements = [
            'product_visibility_log' => 'DELETE FROM connect_product_visibility_log WHERE shop = :shop',
            'shop_fields' => 'DELETE FROM connect_shop_fields WHERE shop = :shop',
        ];

        $counts = [];
        $this->pdo->beginTransaction();
        try {
            foreach ($optionalStatements as $label => $sql) {
                try {
                    $counts[$label] = $this->deleteCount($sql, ['shop' => $shop]);
                } catch (\PDOException) {
                    $counts[$label] = 0;
                }
            }
            foreach ($statements as $label => $sql) {
                $counts[$label] = $this->deleteCount($sql, ['shop' => $shop]);
            }
            // Zustimmungs-Nachweise bleiben 3 Jahre (Retention-Regel "consents"), nur markiert
            $counts['consents_marked'] = \Connect\Setup\LegalTexts::markShopDeleted($this->pdo, $shop);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $counts;
    }

    // ------------------------------------------------------------------
    // app/uninstalled -- Automatisierung stoppen (Daten bleiben bis shop/redact)
    // ------------------------------------------------------------------

    /**
     * Stoppt jede Automatisierung fuer den Shop, damit nach der Deinstallation
     * nichts mehr laeuft. Die Daten selbst bleiben bis zum spaeteren shop/redact
     * (Shopify sendet den 48 Stunden nach der Deinstallation, sofern der Shop die
     * App nicht wieder installiert). Bewusst kein Loeschen und kein Wiederanschalten:
     * nach einer Neuinstallation muss ein Admin die Automatisierung ausdruecklich
     * wieder aktivieren.
     *
     * @return array<string, int>
     */
    public function handleUninstall(string $shop): array
    {
        $counts = [];
        $counts['sync_mappings_disabled'] = $this->deleteCount(
            'UPDATE connect_sync_mappings SET enabled = 0 WHERE shop = :shop AND enabled = 1',
            ['shop' => $shop]
        );
        $counts['shop_settings_disabled'] = $this->deleteCount(
            'UPDATE connect_shops SET stock_sync_enabled = 0, invoice_creation_enabled = 0 WHERE shop = :shop',
            ['shop' => $shop]
        );

        return $counts;
    }

    // ------------------------------------------------------------------
    // customers/redact und customers/data_request
    // ------------------------------------------------------------------

    /**
     * Loescht alle Datensaetze zu einem Kunden bzw. zu den genannten Bestellungen.
     *
     * @param array<string, mixed> $payload Payload des Shopify-Webhooks
     * @return array<string, int>
     */
    public function redactCustomer(string $shop, array $payload): array
    {
        $matcher = self::buildMatcher($payload);
        if ($matcher === null) {
            return ['webhook_events' => 0, 'order_cache' => 0];
        }

        $eventIds = [];
        foreach ($this->scanWebhookEvents($shop, $matcher) as $event) {
            $eventIds[] = $event['id'];
        }

        $recordIds = [];
        foreach ($this->scanOrderCache($shop, $matcher) as $record) {
            $recordIds[] = $record['id'];
        }

        return [
            'webhook_events' => $this->deleteByIds('connect_webhook_events', $eventIds),
            'order_cache' => $this->deleteByIds('connect_object_records', $recordIds),
        ];
    }

    /**
     * Stellt alle zu einem Kunden gefundenen Daten fuer die Datenauskunft
     * zusammen (Shopify verlangt, dass der Haendler die Daten bekommt).
     *
     * @param array<string, mixed> $payload Payload des Shopify-Webhooks
     * @return array{webhook_events: list<array<string, mixed>>, order_cache: list<array<string, mixed>>}
     */
    public function collectCustomerData(string $shop, array $payload): array
    {
        $result = ['webhook_events' => [], 'order_cache' => []];

        $matcher = self::buildMatcher($payload);
        if ($matcher === null) {
            return $result;
        }

        foreach ($this->scanWebhookEvents($shop, $matcher) as $event) {
            $result['webhook_events'][] = [
                'topic' => $event['topic'],
                'received_at' => $event['received_at'],
                'payload' => $event['decoded'],
            ];
        }

        foreach ($this->scanOrderCache($shop, $matcher) as $record) {
            $result['order_cache'][] = [
                'order' => $record['external_id'],
                'data' => $record['decoded'],
            ];
        }

        return $result;
    }

    // Regelmaessige Bereinigung (Aufbewahrungsfristen): siehe Connect\Maintenance\Retention

    // ------------------------------------------------------------------
    // Interna: Treffer suchen
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $payload
     * @return array{customer_id: string, email: string, order_ids: list<string>}|null
     *         null, wenn der Payload nichts enthaelt, wonach sich suchen liesse
     *         (verhindert, dass eine leere Suche "alles" trifft).
     */
    private static function buildMatcher(array $payload): ?array
    {
        $customer = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];

        $customerId = isset($customer['id']) ? trim((string) $customer['id']) : '';
        $email = strtolower(trim((string) ($customer['email'] ?? '')));

        $orderIds = [];
        foreach (['orders_to_redact', 'orders_requested'] as $key) {
            if (is_array($payload[$key] ?? null)) {
                foreach ($payload[$key] as $orderId) {
                    $orderId = trim((string) $orderId);
                    if ($orderId !== '' && ctype_digit($orderId)) {
                        $orderIds[] = $orderId;
                    }
                }
            }
        }
        $orderIds = array_values(array_unique($orderIds));

        if (($customerId === '' || !ctype_digit($customerId)) && $email === '' && $orderIds === []) {
            return null;
        }

        return [
            'customer_id' => ctype_digit($customerId) ? $customerId : '',
            'email' => $email,
            'order_ids' => $orderIds,
        ];
    }

    /**
     * @param array{customer_id: string, email: string, order_ids: list<string>} $matcher
     * @return list<string> LIKE-Muster als Vorfilter
     */
    private static function likePatterns(array $matcher): array
    {
        $patterns = [];
        foreach ($matcher['order_ids'] as $orderId) {
            $patterns[] = '%' . $orderId . '%';
        }
        if ($matcher['customer_id'] !== '') {
            $patterns[] = '%' . $matcher['customer_id'] . '%';
        }
        if ($matcher['email'] !== '') {
            $patterns[] = '%' . self::escapeLike($matcher['email']) . '%';
        }

        return $patterns;
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * Prueft einen dekodierten Bestell-Payload (Webhook) auf einen Treffer.
     *
     * @param array<string, mixed> $data
     * @param array{customer_id: string, email: string, order_ids: list<string>} $matcher
     */
    private static function payloadMatches(array $data, array $matcher): bool
    {
        if (isset($data['id']) && in_array((string) $data['id'], $matcher['order_ids'], true)) {
            return true;
        }

        $customer = is_array($data['customer'] ?? null) ? $data['customer'] : [];

        if ($matcher['customer_id'] !== '' && isset($customer['id']) && (string) $customer['id'] === $matcher['customer_id']) {
            return true;
        }

        if ($matcher['email'] !== '') {
            foreach ([$data['email'] ?? null, $data['contact_email'] ?? null, $customer['email'] ?? null] as $candidate) {
                if (is_string($candidate) && strtolower(trim($candidate)) === $matcher['email']) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array{customer_id: string, email: string, order_ids: list<string>} $matcher
     * @return \Generator<int, array{id: int, topic: string, received_at: string, decoded: array<string, mixed>}>
     */
    private function scanWebhookEvents(string $shop, array $matcher): \Generator
    {
        $patterns = self::likePatterns($matcher);
        if ($patterns === []) {
            return;
        }

        $likeSql = implode(' OR ', array_map(static fn(int $i): string => 'payload LIKE :p' . $i, array_keys($patterns)));
        $stmt = $this->pdo->prepare(
            "SELECT id, topic, received_at, payload FROM connect_webhook_events
             WHERE shop = :shop AND id > :after_id AND topic NOT LIKE 'products/%' AND ({$likeSql})
             ORDER BY id ASC LIMIT " . self::SCAN_BATCH
        );

        $afterId = 0;
        do {
            $params = ['shop' => $shop, 'after_id' => $afterId];
            foreach ($patterns as $i => $pattern) {
                $params['p' . $i] = $pattern;
            }
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            foreach ($rows as $row) {
                $afterId = (int) $row['id'];
                $decoded = json_decode((string) $row['payload'], true);
                if (is_array($decoded) && self::payloadMatches($decoded, $matcher)) {
                    yield [
                        'id' => (int) $row['id'],
                        'topic' => (string) $row['topic'],
                        'received_at' => (string) $row['received_at'],
                        'decoded' => $decoded,
                    ];
                }
            }
        } while (count($rows) === self::SCAN_BATCH);
    }

    /**
     * Gecachte Bestellungen (Object "order") aller Connections des Shops.
     * Im Cache sind die Felder flach abgelegt (siehe Migrationen 0006/0021):
     * "shopify_id" (gid://shopify/Order/<id>) und "email".
     *
     * @param array{customer_id: string, email: string, order_ids: list<string>} $matcher
     * @return \Generator<int, array{id: int, external_id: string, decoded: array<string, mixed>}>
     */
    private function scanOrderCache(string $shop, array $matcher): \Generator
    {
        $conditions = [];
        $bindings = [];
        foreach ($matcher['order_ids'] as $i => $orderId) {
            $conditions[] = 'r.external_id LIKE :o' . $i;
            $bindings['o' . $i] = '%/Order/' . $orderId;
        }
        if ($matcher['email'] !== '') {
            $conditions[] = 'r.data LIKE :email';
            $bindings['email'] = '%' . self::escapeLike($matcher['email']) . '%';
        }
        if ($conditions === []) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'SELECT r.id, r.external_id, r.data FROM connect_object_records r
             JOIN connect_connections c ON c.id = r.connection_id
             WHERE c.shop = :shop AND r.object_key = :object_key AND r.id > :after_id
               AND (' . implode(' OR ', $conditions) . ')
             ORDER BY r.id ASC LIMIT ' . self::SCAN_BATCH
        );

        $afterId = 0;
        do {
            $stmt->execute(array_merge($bindings, ['shop' => $shop, 'object_key' => self::ORDER_OBJECT_KEY, 'after_id' => $afterId]));
            $rows = $stmt->fetchAll();

            foreach ($rows as $row) {
                $afterId = (int) $row['id'];
                $decoded = json_decode((string) $row['data'], true);
                $decoded = is_array($decoded) ? $decoded : [];
                $externalId = (string) $row['external_id'];

                $hit = false;
                foreach ($matcher['order_ids'] as $orderId) {
                    if (str_ends_with($externalId, '/Order/' . $orderId)) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit && $matcher['email'] !== '') {
                    $cachedEmail = $decoded['email'] ?? null;
                    $hit = is_string($cachedEmail) && strtolower(trim($cachedEmail)) === $matcher['email'];
                }

                if ($hit) {
                    yield ['id' => (int) $row['id'], 'external_id' => $externalId, 'decoded' => $decoded];
                }
            }
        } while (count($rows) === self::SCAN_BATCH);
    }

    // ------------------------------------------------------------------
    // Interna: Datenbank
    // ------------------------------------------------------------------

    /**
     * Fuehrt ein DELETE/UPDATE aus und liefert die betroffenen Zeilen. Eine
     * fehlende Tabelle (SQLSTATE 42S02) zaehlt als 0 -- so bricht eine Installation,
     * der eine der optionalen Tabellen fehlt, nicht die ganze Loeschung ab.
     *
     * @param array<string, mixed> $params
     */
    private function deleteCount(string $sql, array $params): int
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt->rowCount();
        } catch (PDOException $e) {
            if ($e->getCode() === '42S02') {
                return 0;
            }
            throw $e;
        }
    }

    /**
     * @param list<int> $ids
     */
    private function deleteByIds(string $table, array $ids): int
    {
        $deleted = 0;
        foreach (array_chunk($ids, 200) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->pdo->prepare('DELETE FROM ' . $table . ' WHERE id IN (' . $placeholders . ')');
            $stmt->execute($chunk);
            $deleted += $stmt->rowCount();
        }

        return $deleted;
    }
}
