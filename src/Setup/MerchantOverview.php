<?php

declare(strict_types=1);

namespace Connect\Setup;

use Connect\Sync\MatchCounter;
use PDO;

/**
 * Daten fuer die Merchant-Uebersicht (app/_dashboard_body.php): Ampel, Probleme in Klartext
 * mit passender Aktion, Kacheln Lagerbestand/Rechnungen/Produkte, Verlauf, ungenutzte
 * Funktionen. Rein lesend. Technisches (Connections, Webhooks, Jobs) steht auf der
 * Status-Seite (app/_status_body.php).
 */
final class MerchantOverview
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(string $shop): array
    {
        $s = $this->row(
            'SELECT s.*, c.status AS target_status, c.last_test_result AS target_test_result, t.name AS target_name
             FROM connect_shops s
             LEFT JOIN connect_connections c ON c.id = s.target_connection_id
             LEFT JOIN connect_connector_types t ON t.id = c.connector_type_id
             WHERE s.shop = :shop',
            ['shop' => $shop]
        ) ?? [];
        $targetName = (string) ($s['target_name'] ?? 'deiner Software');

        $stock = $this->stockTile($shop, $s, $targetName);
        $invoice = $this->invoiceTile($shop, $s);
        $products = $this->productTile($shop, $s);

        $problems = [];
        if (($s['target_connection_id'] ?? null) !== null && ($s['target_status'] ?? '') === 'error') {
            $problems[] = [
                'title' => 'Die Verbindung zu ' . $targetName . ' funktioniert nicht',
                'text' => $targetName . ' hat die Anfrage abgelehnt. Meist ist der Schlüssel abgelaufen oder wurde geändert.',
                'action' => ['Schlüssel prüfen', 'status.php'],
            ];
        }
        if ($stock['enabled'] && $stock['last'] !== null && $stock['last']['status'] === 'error') {
            $problems[] = [
                'title' => 'Der letzte Lagerabgleich hat nicht geklappt',
                'text' => 'Beim Abgleich um ' . $stock['last']['time'] . ' ist ein Fehler aufgetreten. oView versucht es beim nächsten Abgleich automatisch erneut.',
                'detail' => (string) ($stock['last']['error'] ?? ''),
                'action' => ['Lagerbestand ansehen', 'stock.php'],
            ];
        }
        // Sicherheitsbremse (Connect\Sync\StockGuard): ruhiger Hinweis, keine Mail an den Merchant
        if ($stock['enabled'] && \Connect\Sync\StockGuard::isPaused((new \Connect\Sync\StockGuard($this->pdo))->load($shop))) {
            $problems[] = [
                'title' => 'Lagerabgleich vorübergehend angehalten',
                'text' => 'Die Bestände aus ' . $targetName . ' sahen zuletzt ungewöhnlich aus. Zur Sicherheit hat oView deshalb nichts in Shopify geändert und den Abgleich angehalten. Wir prüfen das und schalten ihn wieder ein – du musst nichts tun.',
                'action' => ['Lagerbestand ansehen', 'stock.php'],
            ];
        }
        foreach ($invoice['open_errors'] as $error) {
            $problems[] = $this->invoiceProblem($error, $targetName);
        }
        if ($stock['enabled'] && ($s['stock_primary_location_id'] ?? '') === '') {
            $problems[] = [
                'title' => 'Shopify-Standort fehlt – Lagerabgleich pausiert',
                'text' => 'Ohne Standort gleicht oView keine Bestände ab. Wähle den Standort, dessen Bestand mit ' . $targetName . ' abgeglichen wird.',
                'action' => ['Standort wählen', 'sync_mappings.php#setup-stock'],
            ];
        }

        $unused = [];
        if (!$stock['enabled']) {
            $unused[] = ['Lagerbestand automatisch abgleichen', 'sync_mappings.php#setup-stock'];
        }
        if ($invoice['mode'] === 'off') {
            $unused[] = ['Rechnungen für bezahlte Bestellungen erstellen', 'sync_mappings.php#setup-invoice'];
        } elseif ($invoice['mode'] === 'invoice' && (int) ($s['invoice_mail_enabled'] ?? 0) !== 1) {
            $unused[] = ['Rechnung per E-Mail an Kunden schicken', 'sync_mappings.php#setup-invoice'];
        }
        foreach ($this->unusedFeatures($shop, $s, $stock['enabled']) as $entry) {
            $unused[] = $entry;
        }

        $lastActivity = $this->row(
            "SELECT MAX(r.ended_at) AS at, TIMESTAMPDIFF(MINUTE, MAX(r.ended_at), NOW()) AS ago
             FROM connect_sync_runs r JOIN connect_sync_mappings m ON m.id = r.sync_mapping_id
             WHERE m.shop = :shop AND r.status IN ('success', 'partial')",
            ['shop' => $shop]
        );

        return [
            'target_name' => $targetName,
            'stock' => $stock,
            'invoice' => $invoice,
            'products' => $products,
            'problems' => $problems,
            'feed' => $this->feed($shop, $invoice['object_keys']),
            'unused' => $unused,
            'last_activity_ago' => $lastActivity !== null && $lastActivity['at'] !== null ? (int) $lastActivity['ago'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $s
     * @return array<string, mixed>
     */
    private function stockTile(string $shop, array $s, string $targetName): array
    {
        $mapping = $this->row("SELECT id, enabled, schedule_interval_minutes FROM connect_sync_mappings WHERE shop = :shop AND mapping_kind = 'stock_sync' LIMIT 1", ['shop' => $shop]);
        $enabled = (int) ($s['stock_sync_enabled'] ?? 0) === 1 && $mapping !== null && (int) $mapping['enabled'] === 1;
        $direction = ($s['stock_sync_direction'] ?? '') === 'target_to_shopify' ? $targetName . ' → Shopify' : 'Shopify → ' . $targetName;

        $last = null;
        $next = null;
        $running = false;
        if ($mapping !== null) {
            $run = $this->row(
                "SELECT status, records_written, error_summary, DATE_FORMAT(ended_at, '%H:%i') AS time, DATE(ended_at) = CURDATE() AS today,
                        DATE_FORMAT(ended_at, '%d.%m.') AS day
                 FROM connect_sync_runs WHERE sync_mapping_id = :id AND ended_at IS NOT NULL ORDER BY id DESC LIMIT 1",
                ['id' => (int) $mapping['id']]
            );
            if ($run !== null) {
                $last = [
                    'status' => $run['status'] === 'error' ? 'error' : 'ok',
                    'time' => (int) $run['today'] === 1 ? (string) $run['time'] : $run['day'] . ' ' . $run['time'],
                    'changed' => (int) $run['records_written'],
                    'error' => $run['error_summary'],
                ];
            }
            $running = $this->value("SELECT COUNT(*) FROM connect_sync_jobs WHERE sync_mapping_id = :id AND status IN ('pending', 'running')", ['id' => (int) $mapping['id']]) > 0;
            $interval = (int) ($mapping['schedule_interval_minutes'] ?? 60);
            $nextRow = $this->row(
                "SELECT DATE_FORMAT(DATE_ADD(MAX(scheduled_for), INTERVAL :i MINUTE), '%H:%i') AS time FROM connect_sync_jobs WHERE sync_mapping_id = :id",
                ['i' => $interval, 'id' => (int) $mapping['id']]
            );
            $next = $running ? 'läuft gerade' : ($nextRow !== null && $nextRow['time'] !== null ? 'ca. ' . $nextRow['time'] : 'in Kürze');
        }

        return [
            'enabled' => $enabled,
            'direction' => $direction,
            'last' => $last,
            'next' => $next,
            'changed_today' => $this->value('SELECT COUNT(*) FROM connect_stock_sync_log WHERE shop = :shop AND created_at >= CURDATE()', ['shop' => $shop]),
        ];
    }

    /**
     * @param array<string, mixed> $s
     * @return array<string, mixed>
     */
    private function invoiceTile(string $shop, array $s): array
    {
        $on = (int) ($s['invoice_creation_enabled'] ?? 0) === 1;
        $mode = !$on ? 'off' : ((int) ($s['invoice_suppress_and_adjust_stock'] ?? 0) === 1 ? 'stockonly' : 'invoice');

        $objectKeys = $this->invoiceObjectKeys($shop);
        [$inSql, $inParams] = self::inList('k', $objectKeys);
        $base = "FROM connect_activity_log WHERE shop = :shop AND object_key IN ({$inSql}) AND operation IN ('create', 'adjust_stock')";
        $params = ['shop' => $shop] + $inParams;

        $today = $this->value("SELECT COUNT(*) {$base} AND status = 'success' AND external_id IS NOT NULL AND created_at >= CURDATE()", $params);
        $todayStock = $this->value("SELECT COUNT(*) {$base} AND status = 'success' AND operation = 'adjust_stock' AND created_at >= CURDATE()", $params);
        $month = $this->value("SELECT COUNT(*) {$base} AND status = 'success' AND (external_id IS NOT NULL OR operation = 'adjust_stock') AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')", $params);
        $lastRow = $this->row(
            "SELECT message, external_id, operation, DATE_FORMAT(created_at, '%H:%i') AS time, DATE(created_at) = CURDATE() AS today, DATE_FORMAT(created_at, '%d.%m.') AS day
             {$base} AND status = 'success' AND (external_id IS NOT NULL OR operation = 'adjust_stock') ORDER BY id DESC LIMIT 1",
            $params
        );
        $last = null;
        if ($lastRow !== null) {
            $last = [
                'order' => self::orderNumber((string) $lastRow['message']),
                'number' => $lastRow['external_id'],
                'time' => (int) $lastRow['today'] === 1 ? (string) $lastRow['time'] : $lastRow['day'] . ' ' . $lastRow['time'],
            ];
        }

        // Fehler der letzten 7 Tage, fuer die es danach keinen Erfolg zur selben Bestellung gab
        $stmt = $this->pdo->prepare("SELECT status, message, DATE_FORMAT(created_at, '%d.%m. %H:%i') AS at {$base} AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY id ASC");
        $stmt->execute($params);
        $latestByOrder = [];
        foreach ($stmt->fetchAll() as $row) {
            $order = self::orderNumber((string) $row['message']) ?? ('?' . count($latestByOrder));
            $latestByOrder[$order] = $row;
        }
        $openErrors = [];
        foreach ($latestByOrder as $order => $row) {
            if ($row['status'] === 'error') {
                $openErrors[] = ['order' => str_starts_with((string) $order, '?') ? null : (string) $order, 'message' => self::stripOrder((string) $row['message']), 'at' => $row['at']];
            }
        }

        return [
            'mode' => $mode,
            'today' => $mode === 'stockonly' ? $todayStock : $today,
            'month' => $month,
            'last' => $last,
            'open_errors' => array_slice(array_reverse($openErrors), 0, 5),
            'object_keys' => $objectKeys,
        ];
    }

    /**
     * Protokoll-Schluessel der Rechnungserstellung: "invoice" (bisheriger Weg) und das
     * Ziel-Object der Beleg-Vorlage (DocumentPipeline protokolliert darunter).
     *
     * @return array<int, string>
     */
    public function invoiceObjectKeys(string $shop): array
    {
        $objectKeys = ['invoice'];
        $templateObject = $this->row(
            "SELECT o.object_key FROM connect_sync_mappings m
             JOIN connect_mapping_templates t ON t.id = m.mapping_template_id
             JOIN connect_objects o ON o.id = t.target_object_id
             WHERE m.shop = :shop AND m.mapping_kind = 'invoice' LIMIT 1",
            ['shop' => $shop]
        );
        if ($templateObject !== null && !in_array($templateObject['object_key'], $objectKeys, true)) {
            $objectKeys[] = (string) $templateObject['object_key'];
        }

        return $objectKeys;
    }

    /**
     * @param array<string, mixed> $s
     * @return array<string, mixed>|null null = noch keine Daten
     */
    private function productTile(string $shop, array $s): ?array
    {
        $shopifyId = $this->value(
            "SELECT c.id FROM connect_connections c JOIN connect_connector_types t ON t.id = c.connector_type_id WHERE c.shop = :shop AND t.type_key = 'shopify' LIMIT 1",
            ['shop' => $shop]
        );
        if ($shopifyId === 0 || ($s['target_connection_id'] ?? null) === null) {
            return null;
        }
        $role = (string) ($s['comparison_key'] ?? 'sku');
        $count = (new MatchCounter($this->pdo))->count($shopifyId, (int) $s['target_connection_id'], $role);
        if ($count === null || ($count['shopify_total'] === 0 && $count['target_total'] === 0)) {
            return null;
        }

        return [
            'linked' => $count['shopify_matched'],
            'only_shopify' => $count['shopify_total'] - $count['shopify_matched'],
            'only_target' => $count['target_total'] - $count['matched'],
            'role' => SetupWizard::COMPARE_LABELS[$role] ?? $role,
        ];
    }

    /**
     * Letzte Ereignisse in Merchant-Sprache (Rechnungen, E-Mails, Produkte, Lagerabgleiche).
     *
     * @param array<int, string> $invoiceKeys
     * @return array<int, array{time: string, tone: string, title: string, text: string}>
     */
    private function feed(string $shop, array $invoiceKeys): array
    {
        $items = [];
        $stmt = $this->pdo->prepare(
            "SELECT object_key, operation, status, message, external_id, created_at, DATE_FORMAT(created_at, '%H:%i') AS time,
                    DATE(created_at) = CURDATE() AS today, DATE_FORMAT(created_at, '%d.%m.') AS day
             FROM connect_activity_log WHERE shop = :shop ORDER BY id DESC LIMIT 12"
        );
        $stmt->execute(['shop' => $shop]);
        foreach ($stmt->fetchAll() as $row) {
            $ok = $row['status'] === 'success';
            $order = self::orderNumber((string) $row['message']);
            $orderText = $order !== null ? 'Bestellung #' . ltrim($order, '#') : 'Bestellung';
            $isInvoice = in_array($row['object_key'], $invoiceKeys, true);
            [$title, $text] = match (true) {
                $isInvoice && $row['operation'] === 'create' && $ok && $row['external_id'] !== null => ['Rechnung ' . $row['external_id'] . ' erstellt', $orderText],
                $isInvoice && $row['operation'] === 'create' && $ok => [$orderText . ': keine Rechnung nötig', self::short(self::stripOrder((string) $row['message']))],
                $isInvoice && $row['operation'] === 'create' => [$orderText . ': keine Rechnung erstellt', self::short(self::stripOrder((string) $row['message']))],
                $isInvoice && $row['operation'] === 'adjust_stock' => [$ok ? 'Bestand für ' . $orderText . ' abgebucht' : $orderText . ': Bestand nicht abgebucht', $ok ? '' : self::short(self::stripOrder((string) $row['message']))],
                $row['operation'] === 'send_mail' => [$ok ? 'Rechnung per E-Mail verschickt' : 'Rechnungs-E-Mail nicht verschickt', $ok ? '' : self::short((string) $row['message'])],
                $row['operation'] === 'set_status' => ['Produkt-Sichtbarkeit geändert', self::short((string) $row['message'])],
                in_array($row['operation'], ['create', 'create_variant'], true) => [$ok ? 'Produkt angelegt' : 'Produkt nicht angelegt', self::short((string) $row['message'])],
                default => [$ok ? 'Erledigt' : 'Fehler', self::short((string) $row['message'])],
            };
            $items[] = ['sort' => (string) $row['created_at'], 'time' => (int) $row['today'] === 1 ? (string) $row['time'] : $row['day'], 'tone' => $ok ? 'ok' : 'warn', 'title' => $title, 'text' => $text];
        }

        $runs = $this->pdo->prepare(
            "SELECT r.status, r.records_written, r.ended_at, DATE_FORMAT(r.ended_at, '%H:%i') AS time, DATE(r.ended_at) = CURDATE() AS today, DATE_FORMAT(r.ended_at, '%d.%m.') AS day
             FROM connect_sync_runs r JOIN connect_sync_mappings m ON m.id = r.sync_mapping_id
             WHERE m.shop = :shop AND m.mapping_kind = 'stock_sync' AND r.ended_at IS NOT NULL
             ORDER BY r.id DESC LIMIT 6"
        );
        $runs->execute(['shop' => $shop]);
        foreach ($runs->fetchAll() as $i => $run) {
            $changed = (int) $run['records_written'];
            if ($i > 0 && $changed === 0 && $run['status'] !== 'error') {
                continue; // nur den letzten Lauf ohne Aenderungen zeigen
            }
            $items[] = [
                'sort' => (string) $run['ended_at'],
                'time' => (int) $run['today'] === 1 ? (string) $run['time'] : $run['day'],
                'tone' => $run['status'] === 'error' ? 'warn' : 'ok',
                'title' => $run['status'] === 'error' ? 'Lagerabgleich fehlgeschlagen' : 'Lagerbestand abgeglichen',
                'text' => $run['status'] === 'error' ? 'wird beim nächsten Abgleich wiederholt' : ($changed > 0 ? $changed . ' Bestände angepasst' : 'keine Änderungen'),
            ];
        }

        usort($items, static fn (array $a, array $b): int => strcmp($b['sort'], $a['sort']));

        return array_map(static function (array $item): array {
            unset($item['sort']);

            return $item;
        }, array_slice($items, 0, 8));
    }

    /**
     * Fehlermeldung der Rechnungserstellung in Merchant-Sprache mit passender Aktion.
     *
     * @param array{order: ?string, message: string, at: string} $error
     * @return array{title: string, text: string, detail: string, action: array{0: string, 1: string}}
     */
    private function invoiceProblem(array $error, string $targetName): array
    {
        $title = ($error['order'] !== null ? 'Bestellung #' . ltrim($error['order'], '#') : 'Eine Bestellung') . ': keine Rechnung erstellt';
        $message = $error['message'];

        [$text, $action] = match (true) {
            (bool) preg_match('/nicht gefunden|kein(en)? (passenden )?Artikel|not found|fehlt in/i', $message)
                => ['Ein Artikel aus der Bestellung fehlt in ' . $targetName . ' oder hat eine andere Artikelnummer. Lege ihn an oder korrigiere die Nummer.', ['Produkte ansehen', 'products.php']],
            (bool) preg_match('/Kontrolle fehlgeschlagen|stimmt nicht|Abweichung/i', $message)
                => ['Die Beträge der Rechnung hätten nicht zu Shopify gepasst. oView hat deshalb nichts angelegt. Bitte prüfe die Bestellung.', ['Rechnungen ansehen', 'invoices.php']],
            (bool) preg_match('/HTTP 40[13]|Token|Unauthori[sz]ed|Zugangsdaten/i', $message)
                => [$targetName . ' hat die Zugangsdaten abgelehnt.', ['Verbindung prüfen', 'status.php']],
            default => ['oView konnte für diese Bestellung keine Rechnung anlegen.', ['Rechnungen ansehen', 'invoices.php']],
        };

        return ['title' => $title, 'text' => $text, 'detail' => self::short($message, 220), 'action' => $action];
    }

    /**
     * @return array<int, string>
     */
    private function locations(string $shop): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.external_id FROM connect_object_records r
             JOIN connect_connections c ON c.id = r.connection_id
             JOIN connect_connector_types t ON t.id = c.connector_type_id
             WHERE c.shop = :shop AND t.type_key = 'shopify' AND r.object_key = 'location' AND r.status = 'active'"
        );
        $stmt->execute(['shop' => $shop]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private static function orderNumber(string $message): ?string
    {
        return preg_match('/^Bestellung ([^:]+):/', $message, $m) === 1 ? trim($m[1]) : null;
    }

    private static function stripOrder(string $message): string
    {
        return (string) preg_replace('/^Bestellung [^:]+:\s*/', '', $message);
    }

    private static function short(string $text, int $length = 120): string
    {
        return mb_strimwidth(trim(preg_replace('/\s+/', ' ', $text) ?? ''), 0, $length, '…');
    }

    /**
     * @param array<int, string> $values
     * @return array{0: string, 1: array<string, string>}
     */
    private static function inList(string $prefix, array $values): array
    {
        $names = [];
        $params = [];
        foreach (array_values($values) as $i => $value) {
            $names[] = ':' . $prefix . $i;
            $params[$prefix . $i] = $value;
        }

        return [implode(', ', $names), $params];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function row(string $sql, array $params): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Zusatzfunktionen fuer "Noch nicht genutzt": im Abo enthalten, aber noch nicht eingerichtet
     * ([Bezeichnung, Ziel]) -- oder erst in einem hoeheren Abo ([Bezeichnung, billing.php, Abo-Name]).
     * Freigaben aus Connect\Billing\Plans::FEATURES, jede Abfrage einzeln abgesichert (fehlende
     * Migration = Eintrag einfach weglassen).
     *
     * @param array<string, mixed> $s connect_shops-Zeile
     * @return list<array{0: string, 1: string, 2?: string}>
     */
    private function unusedFeatures(string $shop, array $s, bool $stockEnabled): array
    {
        $plan = \Connect\Billing\Plans::forShop($this->pdo, $shop);
        $allows = static fn (string $feature): bool => \Connect\Billing\Plans::allowsFeature($plan, $feature);
        $upsell = static fn (string $feature): string => \Connect\Billing\Plans::lowestPlanFor($feature);
        $count = function (string $sql) use ($shop): ?int {
            try {
                return $this->value($sql, ['shop' => $shop]);
            } catch (\PDOException) {
                return null;
            }
        };
        $posLeads = ($s['stock_sync_direction'] ?? '') === 'target_to_shopify';
        // Was das verbundene System kann (Connector Builder) -- nur Moegliches anbieten, auch beim Upsell
        $supports = fn (string $feature): bool => \Connect\Sync\TargetCapabilities::supports($this->pdo, $shop, $feature);
        $entries = [];

        // Fuer alle Abos
        if ($stockEnabled && $supports('safety_stock') && (int) ($s['stock_safety_buffer'] ?? 0) === 0 && array_key_exists('stock_safety_buffer', $s)) {
            $entries[] = ['Sicherheitsbestand online (schützt vor Überverkauf)', 'sync_mappings.php#setup-stock'];
        }
        if ($count('SELECT COUNT(*) FROM connect_product_checks WHERE shop = :shop') === 0) {
            $entries[] = ['Produkt-Check: Datenqualität und SEO deiner Produkte', 'product_check.php'];
        }

        // Je Abo
        $features = [
            'price_tools' => [
                $supports('price_sync')
                    ? 'Preise mit ' . ((string) ($s['target_name'] ?? 'der Kasse')) . ' synchronisieren und gesammelt ändern'
                    : 'Preise in Shopify gesammelt ändern (z. B. +5 % oder Sale)',
                'prices.php',
                ($s['price_sync_mode'] ?? 'off') === 'off' && $count('SELECT COUNT(*) FROM connect_batch_changes WHERE shop = :shop') === 0],
            'attachments' => ['Widerrufsbelehrung oder AGB an die Rechnungsmail hängen', 'sync_mappings.php#setup-invoice',
                (int) ($s['invoice_mail_enabled'] ?? 0) === 1 && $count('SELECT COUNT(*) FROM connect_shop_attachments WHERE shop = :shop') === 0],
            'bundles' => ['Sets und Bundles verkaufen', 'bundles.php',
                $posLeads && $count('SELECT COUNT(*) FROM connect_bundles WHERE shop = :shop') === 0],
        ];
        foreach ($features as $feature => [$label, $target, $notYetUsed]) {
            if (!$supports($feature)) {
                continue; // mit diesem System nicht moeglich -- weder anbieten noch verkaufen
            }
            if (!$allows($feature)) {
                $entries[] = [$label, 'billing.php', $upsell($feature)];
            } elseif ($notYetUsed) {
                $entries[] = [$label, $target];
            }
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function value(string $sql, array $params): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }
}
