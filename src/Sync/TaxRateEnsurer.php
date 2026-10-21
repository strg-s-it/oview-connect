<?php

declare(strict_types=1);

namespace Connect\Sync;

use Connect\Connector\ConnectorFactory;
use Connect\Connector\Generic\GenericHttpConnector;
use Connect\Sync\Document\OrderPreparer;
use PDO;

/**
 * Stellt sicher, dass die Steuersaetze einer Bestellung im Kassensystem als aktive Steuersaetze der
 * Firma existieren. helloCash lehnt Positionen mit einem Satz ab, der weder ein aktiver Satz der
 * Firma noch der Satz des Artikels ist (tax_rate_not_found) -- frueher wurde jeder Satz angenommen.
 * Typischer Fall: Lieferung ins EU-Ausland, Shopify rechnet mit dem Satz des Ziellandes (z. B. 19 %
 * bei einem oesterreichischen Konto).
 *
 * Ablauf wie bei PaymentMethodEnsurer: erst im Zwischenspeicher (Object "tax_rate") nachsehen, bei
 * Fehlen frisch laden, nur dann fehlende Saetze per Operation "create" am Object anlegen. Angelegt
 * wird ausschliesslich {tax_rate, status: active} -- kein Standardsatz, kein DATEV-Konto. Nur Saetze,
 * die Shopify selbst fuer diese Bestellung meldet, und nur zwischen 0 und MAX_RATE Prozent.
 * Ohne Einrichtung im Connector Builder (Object "tax_rate" mit "Lesen (Liste)" und "Anlegen")
 * passiert nichts.
 */
final class TaxRateEnsurer
{
    private const OBJECT_KEY = 'tax_rate';

    /** Plausibilitaetsgrenze: ein Ausreisser in Shopify soll keinen Satz im Konto des Haendlers hinterlassen */
    private const MAX_RATE = 30.0;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ConnectorFactory $connectorFactory
    ) {
    }

    /**
     * @param array<string, mixed> $orderRow Shopify-"order"-Datensatz
     * @return array{status: string, message: string} status: exists | created | unavailable | failed
     */
    public function ensureForOrder(string $shop, array $orderRow): array
    {
        $stmt = $this->pdo->prepare('SELECT target_connection_id, shopify_prices_include_tax FROM connect_shops WHERE shop = :shop');
        $stmt->execute(['shop' => $shop]);
        $row = $stmt->fetch();
        if ($row === false || $row['target_connection_id'] === null) {
            return ['status' => 'unavailable', 'message' => 'Kein Kassensystem verbunden.'];
        }
        $connectionId = (int) $row['target_connection_id'];

        $rates = $this->ratesOf($orderRow, $row['shopify_prices_include_tax'] === null || (int) $row['shopify_prices_include_tax'] === 1);
        if ($rates === []) {
            return ['status' => 'exists', 'message' => ''];
        }

        try {
            $cache = new ObjectCache($this->pdo);
            $missing = self::missing($cache->getCached($connectionId, self::OBJECT_KEY), $rates);
            if ($missing === []) {
                return ['status' => 'exists', 'message' => ''];
            }

            $objectStmt = $this->pdo->prepare(
                'SELECT o.id FROM connect_objects o JOIN connect_connections c ON c.connector_type_id = o.connector_type_id
                 WHERE c.id = :conn AND o.object_key = :key LIMIT 1'
            );
            $objectStmt->execute(['conn' => $connectionId, 'key' => self::OBJECT_KEY]);
            $objectId = (int) $objectStmt->fetchColumn();
            if ($objectId === 0) {
                return ['status' => 'unavailable', 'message' => 'Steuersätze sind für dieses System nicht eingerichtet.'];
            }

            $connector = $this->connectorFactory->forConnection($connectionId);
            if (!$connector instanceof GenericHttpConnector || !$connector->hasOperation(self::OBJECT_KEY, 'create')) {
                return ['status' => 'unavailable', 'message' => 'Anlegen von Steuersätzen ist nicht eingerichtet.'];
            }
            $object = $this->connectorFactory->objectDefinitionById($objectId);

            // Frisch laden: der Zwischenspeicher kann veraltet sein (Haendler hat den Satz gerade selbst angelegt)
            $cache->refresh($connector, $connectionId, $object);
            $fresh = $cache->getCached($connectionId, self::OBJECT_KEY);
            $missing = self::missing($fresh, $rates);

            $created = [];
            $failed = [];
            foreach ($missing as $rate) {
                $result = $connector->write(self::OBJECT_KEY, 'create', ['tax_rate' => $rate, 'status' => 'active']);
                $label = self::label($rate);
                if ($result->success) {
                    $created[] = $label;
                    ActivityLog::log($this->pdo, $shop, null, self::OBJECT_KEY, 'create', true, "Steuersatz {$label} wurde automatisch angelegt.", null, $result->requestBody, $result->responseBody);
                } else {
                    $failed[] = $label . ': ' . $result->message;
                    ActivityLog::log($this->pdo, $shop, null, self::OBJECT_KEY, 'create', false, "Steuersatz {$label} konnte nicht angelegt werden: " . $result->message, null, $result->requestBody, $result->responseBody);
                }
            }

            if ($created !== []) {
                try {
                    $cache->refresh($connector, $connectionId, $object);
                } catch (\Throwable) {
                    // Anlegen hat geklappt; der Zwischenspeicher zieht beim naechsten Lauf nach
                }
            }
            if ($failed !== []) {
                return ['status' => 'failed', 'message' => 'Steuersatz konnte nicht angelegt werden: ' . implode(' | ', $failed)];
            }
            if ($created !== []) {
                return ['status' => 'created', 'message' => 'Steuersatz ' . implode(', ', $created) . ' wurde automatisch angelegt.'];
            }

            return ['status' => 'exists', 'message' => ''];
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'message' => 'Steuersätze konnten nicht geprüft werden: ' . $e->getMessage()];
        }
    }

    /**
     * Verschiedene Steuersaetze der Bestellung (Prozent), nur im plausiblen Bereich.
     *
     * @param array<string, mixed> $orderRow
     * @return list<float>
     */
    private function ratesOf(array $orderRow, bool $pricesIncludeTax): array
    {
        $rates = [];
        foreach ((new OrderPreparer())->prepare($orderRow, $pricesIncludeTax)['lines'] as $line) {
            $rate = round((float) ($line['tax_rate_percent'] ?? 0), 2);
            if ($rate >= 0.0 && $rate <= self::MAX_RATE && !in_array($rate, $rates, true)) {
                $rates[] = $rate;
            }
        }

        return $rates;
    }

    /**
     * @param array<int, array<string, mixed>> $records Zwischenspeicher des Objects "tax_rate"
     * @param list<float> $rates
     * @return list<float> Saetze, die nicht als aktiver Satz vorhanden sind
     */
    private static function missing(array $records, array $rates): array
    {
        $active = [];
        foreach ($records as $record) {
            $status = strtolower((string) ($record['status'] ?? 'active'));
            if (is_numeric($record['tax_rate'] ?? null) && in_array($status, ['active', '1', 'true'], true)) {
                $active[] = (float) $record['tax_rate'];
            }
        }

        $missing = [];
        foreach ($rates as $rate) {
            $found = false;
            foreach ($active as $existing) {
                if (abs($existing - $rate) < 0.001) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $missing[] = $rate;
            }
        }

        return $missing;
    }

    private static function label(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, ',', ''), '0'), ',') . ' %';
    }
}
