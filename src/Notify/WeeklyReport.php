<?php

declare(strict_types=1);

namespace Connect\Notify;

use Connect\Config;
use Connect\Setup\Branding;
use Connect\Setup\MerchantOverview;
use PDO;

/**
 * Wochenbericht an den Merchant (Migration 0079): jeden Montag ab 7 Uhr (Uhr der Datenbank)
 * die Zahlen der Vorwoche -- Rechnungen, Lagerabgleich, offene Punkte -- an die Adresse aus
 * "Benachrichtigungen". Nur fuer Shops mit eingeschaltetem Bericht und Aktivitaet in der Woche.
 *
 * Laeuft aus bin/scheduler.php: benannte Sperre (GET_LOCK, Wartezeit 0) wie die Bereinigung,
 * damit von mehreren Schedulern genau einer sendet, und connect_report_mails (eindeutig je
 * Shop + Woche) als Nachweis -- kein Bericht doppelt, auch nach einem Abbruch.
 */
final class WeeklyReport
{
    private const LOCK_NAME = 'connect_weekly_report';
    private const TYPE = 'weekly';

    public function __construct(private readonly PDO $pdo, private readonly Config $config)
    {
    }

    /**
     * @return array{sent: int, skipped: int, failed: int}|null null = nichts zu tun
     */
    public function maybeRun(int $timeBudgetSeconds = 20): ?array
    {
        try {
            $clock = $this->pdo->query('SELECT DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) + 7 DAY) AS period_start, WEEKDAY(NOW()) AS weekday, HOUR(NOW()) AS hour')->fetch();
            if ((int) $clock['weekday'] === 0 && (int) $clock['hour'] < 7) {
                return null;
            }
            $periodStart = (string) $clock['period_start'];
            if ($this->pendingShops($periodStart, 1) === []) {
                return null;
            }
        } catch (\PDOException) {
            return null; // Migration 0079 fehlt
        }

        if ((int) $this->pdo->query("SELECT GET_LOCK('" . self::LOCK_NAME . "', 0)")->fetchColumn() !== 1) {
            return null;
        }
        $result = ['sent' => 0, 'skipped' => 0, 'failed' => 0];
        try {
            $deadline = microtime(true) + $timeBudgetSeconds;
            $notifier = new Notifier($this->pdo, $this->config);
            foreach ($this->pendingShops($periodStart, 50) as $shop) {
                if (microtime(true) > $deadline) {
                    break;
                }
                try {
                    [$status, $recipient, $note] = $this->sendFor($shop, $periodStart, $notifier);
                } catch (\Throwable $e) {
                    [$status, $recipient, $note] = ['failed', null, mb_substr($e->getMessage(), 0, 250)];
                }
                $this->pdo->prepare(
                    'INSERT IGNORE INTO connect_report_mails (shop, report_type, period_start, recipient, status, note)
                     VALUES (:shop, :type, :start, :recipient, :status, :note)'
                )->execute(['shop' => $shop, 'type' => self::TYPE, 'start' => $periodStart, 'recipient' => $recipient, 'status' => $status, 'note' => $note]);
                $result[$status]++;
            }
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')");
        }

        return $result;
    }

    /**
     * Zahlen der Woche ab $periodStart (Montag) fuer Bericht und Vorschau.
     *
     * @return array<string, mixed>
     */
    public function stats(string $shop, string $periodStart): array
    {
        $periodEnd = (new \DateTimeImmutable($periodStart))->modify('+7 days')->format('Y-m-d');
        $overview = new MerchantOverview($this->pdo);
        $keys = $overview->invoiceObjectKeys($shop);
        $names = [];
        $params = ['shop' => $shop, 's' => $periodStart, 'e' => $periodEnd];
        foreach ($keys as $i => $key) {
            $names[] = ':k' . $i;
            $params['k' . $i] = $key;
        }
        $in = implode(', ', $names);

        $inv = $this->pdo->prepare(
            "SELECT SUM(status = 'success' AND (external_id IS NOT NULL OR operation = 'adjust_stock')) AS ok, SUM(status = 'error') AS failed
             FROM connect_activity_log
             WHERE shop = :shop AND object_key IN ({$in}) AND operation IN ('create', 'adjust_stock') AND created_at >= :s AND created_at < :e"
        );
        $inv->execute($params);
        $invoices = $inv->fetch() ?: [];

        $mails = $this->pdo->prepare(
            "SELECT COUNT(*) FROM connect_activity_log WHERE shop = :shop AND operation = 'send_mail' AND status = 'success' AND created_at >= :s AND created_at < :e"
        );
        $mails->execute(['shop' => $shop, 's' => $periodStart, 'e' => $periodEnd]);

        $stock = $this->pdo->prepare(
            "SELECT COUNT(*) AS runs, COALESCE(SUM(r.records_written), 0) AS changed, COALESCE(SUM(r.status = 'error'), 0) AS failed
             FROM connect_sync_runs r JOIN connect_sync_mappings m ON m.id = r.sync_mapping_id
             WHERE m.shop = :shop AND m.mapping_kind = 'stock_sync' AND r.started_at >= :s AND r.started_at < :e"
        );
        $stock->execute(['shop' => $shop, 's' => $periodStart, 'e' => $periodEnd]);
        $stockRow = $stock->fetch() ?: [];

        $now = $overview->build($shop);

        return [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'invoices_ok' => (int) ($invoices['ok'] ?? 0),
            'invoices_failed' => (int) ($invoices['failed'] ?? 0),
            'mails_sent' => (int) $mails->fetchColumn(),
            'stock_runs' => (int) ($stockRow['runs'] ?? 0),
            'stock_changed' => (int) ($stockRow['changed'] ?? 0),
            'stock_failed' => (int) ($stockRow['failed'] ?? 0),
            'invoice_mode' => $now['invoice']['mode'],
            'stock_enabled' => $now['stock']['enabled'],
            'products' => $now['products'],
            'problems' => $now['problems'],
            'target_name' => $now['target_name'],
        ];
    }

    /**
     * @return array{0: string, 1: ?string, 2: ?string} [status, Empfaenger, Hinweis]
     */
    private function sendFor(string $shop, string $periodStart, Notifier $notifier): array
    {
        $recipient = $notifier->settings($shop)['effective'];
        if ($recipient === null) {
            return ['skipped', null, 'keine E-Mail-Adresse'];
        }
        $stats = $this->stats($shop, $periodStart);
        if ($stats['invoices_ok'] + $stats['invoices_failed'] + $stats['stock_runs'] === 0) {
            return ['skipped', $recipient, 'keine Aktivität'];
        }

        [$subject, $text, $html] = $this->render($shop, $stats, $notifier->appUrl($shop));

        return $notifier->send($recipient, $subject, $text, $html)
            ? ['sent', $recipient, null]
            : ['failed', $recipient, 'mail() fehlgeschlagen'];
    }

    /**
     * @param array<string, mixed> $s
     * @return array{0: string, 1: string, 2: string} [Betreff, Text, HTML]
     */
    public function render(string $shop, array $s, string $appUrl): array
    {
        $start = new \DateTimeImmutable($s['period_start']);
        $end = $start->modify('+6 days');
        $range = $start->format('d.m.') . ' bis ' . $end->format('d.m.Y');
        $brand = Branding::load($this->pdo, $this->config);
        $subject = 'Dein ' . $brand->shortName . '-Wochenbericht · KW ' . $start->format('W');

        $rows = [];
        if ($s['invoice_mode'] !== 'off' || $s['invoices_ok'] + $s['invoices_failed'] > 0) {
            $rows[] = [$s['invoice_mode'] === 'stockonly' ? 'Bestellungen abgebucht' : 'Rechnungen erstellt', (string) $s['invoices_ok']];
            if ($s['invoices_failed'] > 0) {
                $rows[] = ['Fehlgeschlagene Versuche', (string) $s['invoices_failed']];
            }
            if ($s['mails_sent'] > 0) {
                $rows[] = ['Rechnungen per E-Mail verschickt', (string) $s['mails_sent']];
            }
        }
        if ($s['stock_enabled'] || $s['stock_runs'] > 0) {
            $rows[] = ['Lagerabgleiche', (string) $s['stock_runs'] . ($s['stock_failed'] > 0 ? ' (davon ' . $s['stock_failed'] . ' mit Fehler)' : '')];
            $rows[] = ['Bestände angepasst', (string) $s['stock_changed']];
        }
        if (is_array($s['products'])) {
            $rows[] = ['Produkte verknüpft', (string) $s['products']['linked']];
            if ($s['products']['only_shopify'] > 0) {
                $rows[] = ['Nur in Shopify (ohne Gegenstück)', (string) $s['products']['only_shopify']];
            }
        }

        $problems = array_slice($s['problems'], 0, 5);

        // Text
        $text = "Hallo,\n\nhier ist, was {$brand->shortName} vom {$range} für deinen Shop {$shop} erledigt hat:\n\n";
        foreach ($rows as [$label, $value]) {
            $text .= "  {$label}: {$value}\n";
        }
        if ($problems !== []) {
            $text .= "\nBraucht deine Aufmerksamkeit:\n";
            foreach ($problems as $p) {
                $text .= '  - ' . $p['title'] . ': ' . $p['text'] . "\n";
            }
        } else {
            $text .= "\nAktuell ist nichts offen.\n";
        }
        $text .= "\n{$brand->shortName} öffnen: {$appUrl}\n\nDen Wochenbericht kannst du in {$brand->shortName} unter Einstellungen → Benachrichtigungen abbestellen."
            . $brand->mailFooterText() . "\n";

        // HTML (Inline-Styles, Mailprogramme ignorieren <style> oft)
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $tableRows = '';
        foreach ($rows as [$label, $value]) {
            $tableRows .= '<tr><td style="padding:8px 0;border-top:1px solid #e6e9ee;color:#5b6573">' . $e($label) . '</td>'
                . '<td style="padding:8px 0;border-top:1px solid #e6e9ee;text-align:right;font-weight:600">' . $e($value) . '</td></tr>';
        }
        $problemHtml = '';
        if ($problems !== []) {
            $problemHtml = '<h3 style="font-size:16px;margin:24px 0 8px;color:#b26b00">Braucht deine Aufmerksamkeit</h3>';
            foreach ($problems as $p) {
                $problemHtml .= '<div style="background:#fdf1dc;border-radius:8px;padding:10px 12px;margin-bottom:8px"><strong>' . $e($p['title'])
                    . '</strong><br><span style="color:#5b6573">' . $e($p['text']) . '</span></div>';
            }
        } else {
            $problemHtml = '<p style="background:#e4f4ea;color:#2f8a4e;border-radius:8px;padding:10px 12px;margin:24px 0 0">Aktuell ist nichts offen.</p>';
        }
        $html = $brand->mailHtml(
            '<h2 style="font-size:20px;margin:0 0 4px">Dein Wochenbericht</h2>'
            . '<p style="margin:0 0 16px;color:#5b6573">' . $e($range) . ' · ' . $e($shop) . '</p>'
            . '<table style="width:100%;border-collapse:collapse;font-size:14px">' . $tableRows . '</table>'
            . $problemHtml
            . '<p style="margin:24px 0 0">' . $brand->mailButton($appUrl, $brand->shortName . ' öffnen') . '</p>',
            'Den Wochenbericht kannst du in ' . $e($brand->shortName) . ' unter Einstellungen → Benachrichtigungen abbestellen.',
            false
        );

        return [$subject, $text, $html];
    }

    /**
     * @return array<int, string>
     */
    private function pendingShops(string $periodStart, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT s.shop FROM connect_shops s
             WHERE s.weekly_report_enabled = 1 AND s.target_connection_id IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM connect_report_mails r WHERE r.shop = s.shop AND r.report_type = 'weekly' AND r.period_start = :start)
             ORDER BY s.shop LIMIT " . max(1, $limit)
        );
        $stmt->execute(['start' => $periodStart]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
