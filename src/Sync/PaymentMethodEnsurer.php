<?php

declare(strict_types=1);

namespace Connect\Sync;

use Connect\Connector\ConnectorFactory;
use Connect\Connector\Generic\GenericHttpConnector;
use PDO;

/**
 * Stellt sicher, dass die im Shop eingestellte Zahlungsart im Kassensystem existiert -- der Haendler
 * muss sich darum nicht kuemmern. helloCash lehnt Rechnungen mit unbekannter oder inaktiver
 * Zahlungsart ab (payment_method_not_found); frueher wurde ein freier Name angenommen.
 *
 * Ablauf: erst im Zwischenspeicher (Object "payment_method") nachsehen, bei Fehlen frisch aus dem
 * Zielsystem laden, und nur wenn sie dann immer noch fehlt, per Operation "create" am Object
 * anlegen (Typ "other"). Voraussetzung ist die Einrichtung im Connector Builder (Object
 * "payment_method" mit "Lesen (Liste)" und "Anlegen"); fehlt sie, passiert nichts und alles
 * laeuft wie bisher. Scheitert das Anlegen (z.B. Token ohne Schreibrecht), steht der Grund im
 * Protokoll -- die Rechnung scheitert dann mit der bekannten Meldung.
 */
final class PaymentMethodEnsurer
{
    private const OBJECT_KEY = 'payment_method';
    private const DEFAULT_TYPE = 'other';

    public function __construct(
        private readonly PDO $pdo,
        private readonly ConnectorFactory $connectorFactory
    ) {
    }

    /**
     * @return array{status: string, message: string} status: exists | created | unavailable | failed
     */
    public function ensureForShop(string $shop): array
    {
        $stmt = $this->pdo->prepare('SELECT target_connection_id, invoice_payment_method FROM connect_shops WHERE shop = :shop');
        $stmt->execute(['shop' => $shop]);
        $row = $stmt->fetch();
        $name = $row !== false ? trim((string) $row['invoice_payment_method']) : '';
        if ($row === false || $row['target_connection_id'] === null || $name === '') {
            return ['status' => 'unavailable', 'message' => 'Keine Zahlungsart eingestellt.'];
        }
        $connectionId = (int) $row['target_connection_id'];
        $cache = new ObjectCache($this->pdo);

        try {
            if ($this->contains($cache->getCached($connectionId, self::OBJECT_KEY), $name)) {
                return ['status' => 'exists', 'message' => ''];
            }

            $objectStmt = $this->pdo->prepare(
                'SELECT o.id FROM connect_objects o JOIN connect_connections c ON c.connector_type_id = o.connector_type_id
                 WHERE c.id = :conn AND o.object_key = :key LIMIT 1'
            );
            $objectStmt->execute(['conn' => $connectionId, 'key' => self::OBJECT_KEY]);
            $objectId = (int) $objectStmt->fetchColumn();
            if ($objectId === 0) {
                return ['status' => 'unavailable', 'message' => 'Zahlungsarten sind für dieses System nicht eingerichtet.'];
            }

            $connector = $this->connectorFactory->forConnection($connectionId);
            if (!$connector instanceof GenericHttpConnector || !$connector->hasOperation(self::OBJECT_KEY, 'create')) {
                return ['status' => 'unavailable', 'message' => 'Anlegen von Zahlungsarten ist nicht eingerichtet.'];
            }
            $object = $this->connectorFactory->objectDefinitionById($objectId);

            // Frisch laden: der Zwischenspeicher kann veraltet sein (Haendler hat sie gerade selbst angelegt)
            $cache->refresh($connector, $connectionId, $object);
            // getCached() liefert nach refresh() andere Daten (PHPStan sieht es als rein)
            /** @phpstan-ignore if.alwaysFalse */
            if ($this->contains($cache->getCached($connectionId, self::OBJECT_KEY), $name)) {
                return ['status' => 'exists', 'message' => ''];
            }

            $result = $connector->write(self::OBJECT_KEY, 'create', ['name' => $name, 'type' => self::DEFAULT_TYPE]);
            if (!$result->success) {
                $message = "Zahlungsart \"{$name}\" konnte nicht angelegt werden: " . $result->message;
                ActivityLog::log($this->pdo, $shop, null, self::OBJECT_KEY, 'create', false, $message, null, $result->requestBody, $result->responseBody);

                return ['status' => 'failed', 'message' => $message];
            }

            try {
                $cache->refresh($connector, $connectionId, $object);
            } catch (\Throwable) {
                // Anlegen hat geklappt; der Zwischenspeicher zieht beim naechsten Lauf nach
            }
            $message = "Zahlungsart \"{$name}\" wurde automatisch angelegt.";
            ActivityLog::log($this->pdo, $shop, null, self::OBJECT_KEY, 'create', true, $message, null, $result->requestBody, $result->responseBody);

            return ['status' => 'created', 'message' => $message];
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'message' => 'Zahlungsart konnte nicht geprüft werden: ' . $e->getMessage()];
        }
    }

    /**
     * Ist das automatische Anlegen eingerichtet (Operation "Anlegen" am Object "payment_method"
     * im Connector Builder)? Fuer den Hinweistext in den Einstellungen.
     */
    public static function canCreate(PDO $pdo, int $connectionId): bool
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM connect_object_operations op
                 JOIN connect_objects o ON o.id = op.object_id
                 JOIN connect_connections c ON c.connector_type_id = o.connector_type_id
                 WHERE c.id = :conn AND o.object_key = :key AND op.operation_type = 'create'"
            );
            $stmt->execute(['conn' => $connectionId, 'key' => self::OBJECT_KEY]);

            return (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function contains(array $records, string $name): bool
    {
        foreach ($records as $record) {
            $status = strtolower((string) ($record['status'] ?? 'active'));
            if (strcasecmp(trim((string) ($record['name'] ?? '')), $name) === 0 && in_array($status, ['active', '1', 'true'], true)) {
                return true;
            }
        }

        return false;
    }
}
