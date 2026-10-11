<?php

declare(strict_types=1);

namespace Connect\Notify;

use Connect\Config;
use Connect\Connector\ConnectorFactory;
use Connect\Setup\Branding;
use Connect\Sync\ProductComparisonService;
use Connect\Sync\TargetCapabilities;
use PDO;

/**
 * Mail bei niedrigem Lagerstand (Migration 0092, Einstellungen -> Benachrichtigungen).
 *
 * Einmal am Tag ab 7 Uhr (Uhr der Datenbank) je Shop mit Schwellwert: Bestand aus dem
 * Zwischenspeicher (ProductComparisonService, kein API-Aufruf) auf der fuehrenden Seite --
 * "Kassensystem fuehrt" = Bestand im Kassensystem, sonst der Shopify-Bestand. Gemeldet werden
 * nur Artikel, die seit der letzten Mail neu knapp geworden sind (connect_low_stock_items),
 * keine Mail, wenn nichts Neues dazugekommen ist. Sammelmail statt Einzelmeldungen
 * (Nutzer-Vorgabe: lieber gebuendelt als sofort).
 *
 * Laeuft aus bin/scheduler.php mit benannter Sperre (GET_LOCK, Wartezeit 0), damit von mehreren
 * Schedulern genau einer prueft.
 */
final class LowStockAlert
{
    private const LOCK_NAME = 'connect_low_stock';
    /** So viele Artikel stehen hoechstens einzeln in der Mail */
    private const MAX_LISTED = 40;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
        private readonly ConnectorFactory $factory
    ) {
    }

    /**
     * @return array{checked: int, sent: int, failed: int}|null null = nichts zu tun
     */
    public function maybeRun(int $timeBudgetSeconds = 15): ?array
    {
        try {
            if ((int) $this->pdo->query('SELECT HOUR(NOW())')->fetchColumn() < 7 || $this->dueShops(1) === []) {
                return null;
            }
        } catch (\PDOException) {
            return null; // Migration 0092 fehlt
        }
        if ((int) $this->pdo->query("SELECT GET_LOCK('" . self::LOCK_NAME . "', 0)")->fetchColumn() !== 1) {
            return null;
        }

        $result = ['checked' => 0, 'sent' => 0, 'failed' => 0];
        try {
            $deadline = microtime(true) + $timeBudgetSeconds;
            $notifier = new Notifier($this->pdo, $this->config);
            foreach ($this->dueShops(20) as $row) {
                if (microtime(true) > $deadline) {
                    break;
                }
                $shop = (string) $row['shop'];
                // Zuerst als geprueft markieren -- ein Fehler soll nicht jeden Scheduler-Lauf wiederholen
                $this->pdo->prepare('UPDATE connect_shops SET low_stock_checked_on = CURDATE() WHERE shop = :shop')->execute(['shop' => $shop]);
                $result['checked']++;
                try {
                    $sent = $this->checkShop($shop, (int) $row['low_stock_threshold'], (string) $row['stock_sync_direction'], $notifier);
                    if ($sent === true) {
                        $result['sent']++;
                    } elseif ($sent === false) {
                        $result['failed']++;
                    }
                } catch (\Throwable $e) {
                    $result['failed']++;
                    fwrite(STDERR, "Lagerstand-Mail {$shop}: " . $e->getMessage() . "\n");
                }
            }
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')");
        }

        return $result;
    }

    /**
     * Knappe Artikel jetzt (fuer die Mail und die Vorschau in den Einstellungen).
     *
     * @return list<array{match_value: string, label: string, quantity: int}>
     */
    public function lowItems(string $shop, int $threshold, string $direction): array
    {
        if ($threshold <= 0) {
            return [];
        }
        $report = (new ProductComparisonService($this->pdo, $this->factory))->buildReport($shop);
        if ($report['status'] !== 'success') {
            return [];
        }
        $side = $direction === 'target_to_shopify' ? 'target_quantity' : 'shopify_quantity';
        $items = [];
        foreach ($report['rows'] as $r) {
            if ($r['status'] === 'bundle' || $r['match_value'] === null || $r[$side] === null || $r[$side] > $threshold) {
                continue;
            }
            $label = trim((string) ($r['label'] ?? '')) ?: (string) $r['match_value'];
            if (($r['variant_title'] ?? null) !== null && $r['variant_title'] !== 'Default Title') {
                $label .= ' – ' . $r['variant_title'];
            }
            $items[(string) $r['match_value']] = ['match_value' => (string) $r['match_value'], 'label' => $label, 'quantity' => (int) $r[$side]];
        }
        uasort($items, static fn (array $a, array $b): int => [$a['quantity'], $a['label']] <=> [$b['quantity'], $b['label']]);

        return array_values($items);
    }

    /**
     * @return bool|null true = Mail gesendet, false = Versand fehlgeschlagen, null = nichts Neues
     */
    private function checkShop(string $shop, int $threshold, string $direction, Notifier $notifier): ?bool
    {
        $items = $this->lowItems($shop, $threshold, $direction);
        $current = [];
        foreach ($items as $item) {
            $current[$item['match_value']] = $item;
        }

        $stmt = $this->pdo->prepare('SELECT match_value, notified_at FROM connect_low_stock_items WHERE shop = :shop');
        $stmt->execute(['shop' => $shop]);
        $known = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $known[(string) $row['match_value']] = $row['notified_at'] !== null;
        }

        // Wieder aufgefuellt: vergessen, beim naechsten Unterschreiten wieder melden
        $delete = $this->pdo->prepare('DELETE FROM connect_low_stock_items WHERE shop = :shop AND match_value = :m');
        foreach (array_diff_key($known, $current) as $matchValue => $_) {
            $delete->execute(['shop' => $shop, 'm' => (string) $matchValue]);
        }
        $upsert = $this->pdo->prepare(
            'INSERT INTO connect_low_stock_items (shop, match_value, label, quantity) VALUES (:shop, :m, :label, :q)
             ON DUPLICATE KEY UPDATE label = VALUES(label), quantity = VALUES(quantity)'
        );
        foreach ($current as $item) {
            $upsert->execute(['shop' => $shop, 'm' => $item['match_value'], 'label' => mb_substr($item['label'], 0, 255), 'q' => $item['quantity']]);
        }

        $new = array_values(array_filter($current, static fn (array $i): bool => !($known[$i['match_value']] ?? false)));
        if ($new === []) {
            return null;
        }
        $recipient = $notifier->settings($shop)['effective'];
        if ($recipient === null) {
            return null;
        }

        [$subject, $text, $html] = $this->render($shop, $threshold, $direction, $new, count($current) - count($new), $notifier->appUrl($shop));
        if (!$notifier->send($recipient, $subject, $text, $html)) {
            return false;
        }
        $this->pdo->prepare('UPDATE connect_low_stock_items SET notified_at = CURRENT_TIMESTAMP WHERE shop = :shop AND notified_at IS NULL')->execute(['shop' => $shop]);

        return true;
    }

    /**
     * @param list<array{match_value: string, label: string, quantity: int}> $new
     * @return array{0: string, 1: string, 2: string} [Betreff, Text, HTML]
     */
    public function render(string $shop, int $threshold, string $direction, array $new, int $alreadyKnown, string $appUrl): array
    {
        $brand = Branding::load($this->pdo, $this->config);
        $where = $direction === 'target_to_shopify' ? 'in ' . TargetCapabilities::forShop($this->pdo, $shop)['target_name'] : 'in Shopify';
        $count = count($new);
        $subject = $count === 1 ? 'Lagerstand niedrig: ' . $new[0]['label'] : "Lagerstand niedrig: {$count} Artikel";
        $listed = array_slice($new, 0, self::MAX_LISTED);
        $more = $count - count($listed);
        $intro = ($count === 1 ? 'Dieser Artikel hat' : 'Diese Artikel haben') . " {$where} nur noch {$threshold} Stück oder weniger auf Lager:";
        $footer = 'Den Schwellwert änderst oder abschaltest du in ' . $brand->shortName . ' unter Einstellungen → Benachrichtigungen.';
        $qty = static fn (int $q): string => $q <= 0 ? 'ausverkauft' : $q . ' Stück';

        $text = "Hallo,\n\n{$intro}\n\n";
        foreach ($listed as $i) {
            $text .= '  - ' . $i['label'] . ': ' . $qty($i['quantity']) . "\n";
        }
        if ($more > 0) {
            $text .= "  … und {$more} weitere\n";
        }
        if ($alreadyKnown > 0) {
            $text .= "\nDazu kommen {$alreadyKnown} Artikel, die schon früher gemeldet wurden und noch knapp sind.\n";
        }
        $text .= "\n{$brand->shortName} öffnen: {$appUrl}\n\n{$footer}" . $brand->mailFooterText() . "\n";

        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $rows = '';
        foreach ($listed as $i) {
            $rows .= '<tr><td style="padding:8px 0;border-top:1px solid #e6e9ee">' . $e($i['label']) . '</td>'
                . '<td style="padding:8px 0;border-top:1px solid #e6e9ee;text-align:right;font-weight:600;white-space:nowrap;color:' . ($i['quantity'] <= 0 ? '#c0392b' : '#b26b00') . '">'
                . $e($qty($i['quantity'])) . '</td></tr>';
        }
        if ($more > 0) {
            $rows .= '<tr><td colspan="2" style="padding:8px 0;border-top:1px solid #e6e9ee;color:#5b6573">… und ' . $more . ' weitere</td></tr>';
        }
        $html = $brand->mailHtml(
            '<h2 style="font-size:20px;margin:0 0 4px">Lagerstand niedrig</h2>'
            . '<p style="margin:0 0 16px;color:#5b6573">' . $e($intro) . '</p>'
            . '<table style="width:100%;border-collapse:collapse;font-size:14px">' . $rows . '</table>'
            . ($alreadyKnown > 0 ? '<p style="margin:16px 0 0;color:#5b6573">Dazu kommen ' . $alreadyKnown . ' Artikel, die schon früher gemeldet wurden und noch knapp sind.</p>' : '')
            . '<p style="margin:24px 0 0">' . $brand->mailButton($appUrl, $brand->shortName . ' öffnen') . '</p>',
            $e($footer),
            false
        );

        return [$subject, $text, $html];
    }

    /**
     * @return list<array{shop: string, low_stock_threshold: int, stock_sync_direction: string}>
     */
    private function dueShops(int $limit): array
    {
        return $this->pdo->query(
            'SELECT shop, low_stock_threshold, stock_sync_direction FROM connect_shops
             WHERE low_stock_threshold > 0 AND target_connection_id IS NOT NULL AND live_in_connect = 1
               AND (low_stock_checked_on IS NULL OR low_stock_checked_on < CURDATE())
             ORDER BY shop LIMIT ' . max(1, $limit)
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}
