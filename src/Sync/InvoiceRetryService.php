<?php

declare(strict_types=1);

namespace Connect\Sync;

use Connect\Connector\ConnectorFactory;
use PDO;

/**
 * Fehlgeschlagene Rechnungen nachtraeglich anlegen ("Erneut versuchen" auf der Seite Rechnungen).
 *
 * Quelle sind die Dedup-Eintraege (connect_translation_log, status = 'error') der Rechnungs-
 * Aktivierung des Shops. Der Versuch laeuft durch denselben Weg wie Webhook und Nachlauf
 * (SyncRunner::runTranslationForRecord): atomarer Claim, Kontrollen (Steuer, Betragssumme),
 * Belegprofil. Eine bereits angelegte Rechnung wird dadurch nie doppelt erzeugt -- 'success'
 * und 'pending' blockieren. Anders als der Nachlauf (48 h) gilt hier kein Zeitfenster; die
 * Bestellung wird frisch aus Shopify geladen.
 */
final class InvoiceRetryService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ConnectorFactory $connectorFactory
    ) {
    }

    /**
     * Fehlgeschlagene Bestellungen des Shops (neueste zuerst).
     *
     * @return array<int, array{mapping_id: int, source_id: string, order: string, message: string, at: string}>
     */
    public function failedForShop(string $shop, int $limit = 20): array
    {
        $mapping = $this->invoiceMapping($shop);
        if ($mapping === null) {
            return [];
        }

        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->prepare(
            "SELECT source_external_id, error_message, created_at FROM connect_translation_log
             WHERE sync_mapping_id = :mapping_id AND status = 'error'
             ORDER BY created_at DESC, id DESC LIMIT {$limit}"
        );
        $stmt->execute(['mapping_id' => (int) $mapping['id']]);

        $cache = new ObjectCache($this->pdo);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $sourceId = (string) $row['source_external_id'];
            $cached = $cache->getCachedOne((int) $mapping['source_connection_id'], 'order', $sourceId);
            $order = $cached !== null && trim((string) ($cached['order_number'] ?? '')) !== ''
                ? (string) $cached['order_number']
                : '#' . substr($sourceId, (int) strrpos($sourceId, '/') + 1);
            $rows[] = [
                'mapping_id' => (int) $mapping['id'],
                'source_id' => $sourceId,
                'order' => $order,
                'message' => (string) $row['error_message'],
                'at' => (string) $row['created_at'],
            ];
        }

        return $rows;
    }

    /**
     * Genau eine fehlgeschlagene Bestellung noch einmal anlegen.
     *
     * @return array{ok: bool, message: string}
     */
    public function retry(string $shop, string $sourceId): array
    {
        $mapping = $this->invoiceMapping($shop);
        if ($mapping === null) {
            return ['ok' => false, 'message' => 'Die Rechnungserstellung ist für diesen Shop nicht eingerichtet.'];
        }

        // Nur Bestellungen, die wirklich als fehlgeschlagen vermerkt sind (nie etwas Beliebiges aus POST)
        $check = $this->pdo->prepare(
            "SELECT COUNT(*) FROM connect_translation_log WHERE sync_mapping_id = :mapping_id AND source_external_id = :source_id AND status = 'error'"
        );
        $check->execute(['mapping_id' => (int) $mapping['id'], 'source_id' => $sourceId]);
        if ((int) $check->fetchColumn() === 0) {
            return ['ok' => false, 'message' => 'Für diese Bestellung gibt es nichts zu wiederholen (bereits angelegt oder in Arbeit).'];
        }

        $shopStmt = $this->pdo->prepare('SELECT live_in_connect, invoice_creation_enabled FROM connect_shops WHERE shop = :shop');
        $shopStmt->execute(['shop' => $shop]);
        $shopRow = $shopStmt->fetch();
        if ($shopRow === false || (int) $shopRow['live_in_connect'] !== 1 || (int) $shopRow['invoice_creation_enabled'] !== 1) {
            return ['ok' => false, 'message' => 'Die automatische Rechnungserstellung ist für diesen Shop nicht aktiv.'];
        }

        try {
            $connector = $this->connectorFactory->forConnection((int) $mapping['source_connection_id']);
            $orderRow = $connector->readOne('order', ['id' => $sourceId]);
            if ($orderRow === null) {
                return ['ok' => false, 'message' => 'Die Bestellung konnte in Shopify nicht geladen werden.'];
            }

            $result = (new SyncRunner($this->pdo, $this->connectorFactory))->runTranslationForRecord($mapping, $orderRow);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Fehler beim erneuten Versuch: ' . $e->getMessage()];
        }

        if ($result['skipped']) {
            return ['ok' => false, 'message' => $result['message']];
        }

        return ['ok' => $result['success'], 'message' => $result['message']];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function invoiceMapping(string $shop): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM connect_sync_mappings
             WHERE shop = :shop AND mapping_kind = 'invoice' AND enabled = 1 AND target_connection_id IS NOT NULL
             ORDER BY id LIMIT 1"
        );
        $stmt->execute(['shop' => $shop]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }
}
