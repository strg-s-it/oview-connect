<?php

declare(strict_types=1);

namespace Connect\Wishes;

use Connect\Config;
use Connect\Notify\Notifier;
use Connect\Setup\Branding;
use PDO;

/**
 * Funktionswuensche der Merchants (Migration 0093).
 *
 * Merchant (app/wishes.php): Wunsch einreichen, freigegebene Wuensche aller Shops sehen -- fremde
 * immer OHNE Shop-Namen --, abstimmen ("brauch ich auch"). Admin (admin/wishes.php): alles mit
 * Shop-Namen, freigeben, Status setzen, oeffentlich antworten, Text vor der Freigabe anpassen,
 * Doppelte zusammenfuehren.
 *
 * Bei "umgesetzt" bekommen Verfasser und alle Unterstuetzer einen Hinweis in der App-Uebersicht
 * (ueber connect_messages, nur in der App, keine Mail). Neue Wuensche gehen einmal pro Woche
 * gesammelt an den Admin (montags ab 7 Uhr, bin/scheduler.php), Nutzer-Vorgabe: lieber gebuendelt.
 */
final class WishBoard
{
    public const STATUSES = [
        'review' => ['wird geprüft', 'bg-secondary-lt'],
        'open' => ['neu', 'bg-blue-lt'],
        'planned' => ['geplant', 'bg-yellow-lt'],
        'done' => ['umgesetzt', 'bg-green-lt'],
        'rejected' => ['nicht geplant', 'bg-red-lt'],
        'merged' => ['zusammengeführt', 'bg-secondary-lt'],
    ];

    /** Fuer alle sichtbar */
    private const PUBLIC_STATUSES = ['open', 'planned', 'done'];
    /** Hoechstens so viele neue Wuensche je Shop und Tag (Schutz vor Versehen/Spam) */
    private const MAX_PER_DAY = 5;
    private const LOCK_NAME = 'connect_wish_digest';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function available(): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM connect_wishes LIMIT 1');

            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    /**
     * @return string|null Fehlermeldung oder null = angelegt
     */
    public function submit(string $shop, string $title, string $body): ?string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
        $body = trim($body);
        if (mb_strlen($title) < 5) {
            return 'Bitte beschreib deinen Wunsch in der Überschrift kurz (mindestens 5 Zeichen).';
        }
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM connect_wishes WHERE shop = :shop AND created_at > NOW() - INTERVAL 1 DAY');
        $count->execute(['shop' => $shop]);
        if ((int) $count->fetchColumn() >= self::MAX_PER_DAY) {
            return 'Du hast heute schon ' . self::MAX_PER_DAY . ' Wünsche eingereicht – bitte morgen wieder.';
        }
        $this->pdo->prepare('INSERT INTO connect_wishes (shop, title, body) VALUES (:shop, :title, :body)')
            ->execute(['shop' => $shop, 'title' => mb_substr($title, 0, 150), 'body' => $body !== '' ? mb_substr($body, 0, 5000) : null]);
        $id = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT IGNORE INTO connect_wish_votes (wish_id, shop) VALUES (:id, :shop)')->execute(['id' => $id, 'shop' => $shop]);

        return null;
    }

    /**
     * Sicht eines Merchants: freigegebene Wuensche aller Shops plus eigene (auch in Pruefung/abgelehnt).
     * Fremde Wuensche enthalten keinen Shop.
     *
     * @return list<array{id: int, title: string, body: ?string, status: string, answer: ?string, votes: int, voted: bool, mine: bool, created_at: string}>
     */
    public function forMerchant(string $shop, string $filter = '', string $sort = 'votes'): array
    {
        $where = "(w.status IN ('open', 'planned', 'done') OR (w.shop = :own AND w.status <> 'merged'))";
        $params = ['own' => $shop, 'me' => $shop, 'mine' => $shop];
        if ($filter === 'mine') {
            $where .= ' AND w.shop = :only';
            $params['only'] = $shop;
        } elseif (isset(self::STATUSES[$filter])) {
            $where .= ' AND w.status = :status';
            $params['status'] = $filter;
        }
        $order = $sort === 'new' ? 'w.created_at DESC' : "votes DESC, w.created_at DESC";

        $stmt = $this->pdo->prepare(
            "SELECT w.id, w.title, w.body, w.status, w.answer, DATE_FORMAT(w.created_at, '%d.%m.%Y') AS created_at,
                    (SELECT COUNT(*) FROM connect_wish_votes v WHERE v.wish_id = w.id) AS votes,
                    EXISTS (SELECT 1 FROM connect_wish_votes v2 WHERE v2.wish_id = w.id AND v2.shop = :me) AS voted,
                    (w.shop = :mine) AS mine
             FROM connect_wishes w WHERE {$where}
             ORDER BY FIELD(w.status, 'review', 'open', 'planned', 'done', 'rejected'), {$order}"
        );
        $stmt->execute($params);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'body' => $r['body'] !== null ? (string) $r['body'] : null,
            'status' => (string) $r['status'],
            'answer' => $r['answer'] !== null ? (string) $r['answer'] : null,
            'votes' => (int) $r['votes'],
            'voted' => (int) $r['voted'] === 1,
            'mine' => (int) $r['mine'] === 1,
            'created_at' => (string) $r['created_at'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Stimme setzen/zuruecknehmen -- nur fuer freigegebene, noch nicht umgesetzte fremde Wuensche.
     */
    public function vote(string $shop, int $wishId, bool $on): void
    {
        $stmt = $this->pdo->prepare("SELECT shop FROM connect_wishes WHERE id = :id AND status IN ('open', 'planned')");
        $stmt->execute(['id' => $wishId]);
        $owner = $stmt->fetchColumn();
        if ($owner === false || (string) $owner === $shop) {
            return;
        }
        $this->pdo->prepare($on
            ? 'INSERT IGNORE INTO connect_wish_votes (wish_id, shop) VALUES (:id, :shop)'
            : 'DELETE FROM connect_wish_votes WHERE wish_id = :id AND shop = :shop')
            ->execute(['id' => $wishId, 'shop' => $shop]);
    }

    /**
     * Admin-Sicht mit Shop und Unterstuetzern.
     *
     * @return list<array<string, mixed>>
     */
    public function forAdmin(string $filter = ''): array
    {
        $where = "w.status <> 'merged'";
        $params = [];
        if ($filter === 'merged' || ($filter !== '' && isset(self::STATUSES[$filter]))) {
            $where = 'w.status = :status';
            $params['status'] = $filter;
        }
        $stmt = $this->pdo->prepare(
            "SELECT w.*, DATE_FORMAT(w.created_at, '%d.%m.%Y %H:%i') AS created_label,
                    (SELECT COUNT(*) FROM connect_wish_votes v WHERE v.wish_id = w.id) AS votes,
                    (SELECT GROUP_CONCAT(v2.shop ORDER BY v2.created_at SEPARATOR ', ') FROM connect_wish_votes v2 WHERE v2.wish_id = w.id AND v2.shop <> w.shop) AS supporters
             FROM connect_wishes w WHERE {$where}
             ORDER BY FIELD(w.status, 'review', 'open', 'planned', 'done', 'rejected', 'merged'), votes DESC, w.created_at DESC"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function pendingCount(): int
    {
        try {
            return (int) $this->pdo->query("SELECT COUNT(*) FROM connect_wishes WHERE status = 'review'")->fetchColumn();
        } catch (\PDOException) {
            return 0;
        }
    }

    /**
     * Admin: Text, Status und Antwort speichern. Wechsel auf "umgesetzt" benachrichtigt Verfasser
     * und Unterstuetzer in der App.
     *
     * @return int Anzahl benachrichtigter Shops
     */
    public function update(int $wishId, string $title, string $body, string $status, string $answer, string $adminEmail): int
    {
        if (!isset(self::STATUSES[$status]) || $status === 'merged') {
            throw new \InvalidArgumentException('Unbekannter Status.');
        }
        $current = $this->wish($wishId) ?? throw new \RuntimeException('Wunsch nicht gefunden.');
        $title = trim($title) !== '' ? mb_substr(trim($title), 0, 150) : (string) $current['title'];

        $this->pdo->prepare(
            "UPDATE connect_wishes SET title = :title, body = :body, status = :status, answer = :answer,
                    reviewed_at = IF(:st1 <> 'review' AND reviewed_at IS NULL, NOW(), reviewed_at),
                    done_at = IF(:st2 = 'done', COALESCE(done_at, NOW()), NULL)
             WHERE id = :id"
        )->execute([
            'title' => $title,
            'body' => trim($body) !== '' ? trim($body) : null,
            'status' => $status,
            'answer' => trim($answer) !== '' ? trim($answer) : null,
            'st1' => $status,
            'st2' => $status,
            'id' => $wishId,
        ]);

        if ($status === 'done' && $current['status'] !== 'done') {
            return $this->notifyDone($wishId, $title, trim($answer), $adminEmail);
        }

        return 0;
    }

    /**
     * Doppelten Wunsch in einen anderen ueberfuehren: Stimmen (inkl. Verfasser) wandern mit.
     */
    public function merge(int $wishId, int $intoId): void
    {
        if ($wishId === $intoId || $this->wish($intoId) === null) {
            throw new \RuntimeException('Ziel-Wunsch nicht gefunden.');
        }
        $this->pdo->prepare(
            'INSERT IGNORE INTO connect_wish_votes (wish_id, shop, created_at) SELECT :into, shop, created_at FROM connect_wish_votes WHERE wish_id = :id'
        )->execute(['into' => $intoId, 'id' => $wishId]);
        $this->pdo->prepare("UPDATE connect_wishes SET status = 'merged', merged_into = :into, reviewed_at = COALESCE(reviewed_at, NOW()) WHERE id = :id")
            ->execute(['into' => $intoId, 'id' => $wishId]);
    }

    /** @return array<string, mixed>|null */
    public function wish(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM connect_wishes WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Hinweis "umgesetzt" in der App (connect_messages, nur App, Empfaenger mit Status skipped =
     * keine Mail). Nur Shops, die in Connect sind -- nur dort gibt es die App-Uebersicht.
     */
    private function notifyDone(int $wishId, string $title, string $answer, string $adminEmail): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT v.shop FROM connect_wish_votes v JOIN connect_shops s ON s.shop = v.shop WHERE v.wish_id = :id'
        );
        $stmt->execute(['id' => $wishId]);
        $shops = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($shops === []) {
            return 0;
        }
        // Platzhalter des Nachrichtensystems ({shop} …) im Wunschtext nicht ersetzen lassen
        $safe = static fn (string $s): string => str_replace(['{', '}'], ['(', ')'], $s);
        $body = 'Ein Funktionswunsch, den du eingereicht oder unterstützt hast, ist jetzt umgesetzt: „' . $safe($title) . '“.'
            . ($answer !== '' ? "\n\n" . $safe($answer) : '')
            . "\n\nDanke für die Anregung! Alle Wünsche findest du unter „Wünsche“.";
        try {
            $this->pdo->prepare(
                "INSERT INTO connect_messages (kind, subject, body, in_app, filter, status, recipient_count, created_by, queued_at, finished_at)
                 VALUES ('service', :subject, :body, 1, :filter, 'done', :n, :by, NOW(), NOW())"
            )->execute([
                'subject' => 'Dein Wunsch ist umgesetzt: ' . mb_substr($safe($title), 0, 200),
                'body' => $body,
                'filter' => json_encode(['wish_id' => $wishId]),
                'n' => count($shops),
                'by' => $adminEmail,
            ]);
            $messageId = (int) $this->pdo->lastInsertId();
            $insert = $this->pdo->prepare(
                "INSERT IGNORE INTO connect_message_recipients (message_id, shop, world, email, status, error)
                 VALUES (:m, :shop, 'connect', NULL, 'skipped', 'nur in der App (Wunsch umgesetzt)')"
            );
            foreach ($shops as $shop) {
                $insert->execute(['m' => $messageId, 'shop' => (string) $shop]);
            }
        } catch (\PDOException) {
            return 0; // Migration 0082 fehlt
        }

        return count($shops);
    }

    /**
     * Woechentliche Zusammenfassung neuer Wuensche an den Admin (montags ab 7 Uhr).
     *
     * @return array{sent: bool, wishes: int}|null null = nichts zu tun
     */
    public function maybeSendDigest(Config $config): ?array
    {
        try {
            $clock = $this->pdo->query('SELECT WEEKDAY(NOW()) AS weekday, HOUR(NOW()) AS hour, CURDATE() AS today')->fetch(PDO::FETCH_ASSOC);
            if ((int) $clock['weekday'] !== 0 || (int) $clock['hour'] < 7) {
                return null;
            }
            // hoechstens eine Mail am Montag -- was danach dazukommt, kommt naechste Woche
            $sentToday = $this->pdo->query('SELECT COUNT(*) FROM connect_wishes WHERE digest_sent_at >= CURDATE()')->fetchColumn();
            $fresh = $this->pdo->query('SELECT COUNT(*) FROM connect_wishes WHERE digest_sent_at IS NULL')->fetchColumn();
            if ((int) $fresh === 0 || (int) $sentToday > 0) {
                return null;
            }
        } catch (\PDOException) {
            return null; // Migration 0093 fehlt
        }
        if ((int) $this->pdo->query("SELECT GET_LOCK('" . self::LOCK_NAME . "', 0)")->fetchColumn() !== 1) {
            return null;
        }
        try {
            $rows = $this->pdo->query(
                "SELECT w.id, w.shop, w.title, w.body, w.status,
                        (SELECT COUNT(*) FROM connect_wish_votes v WHERE v.wish_id = w.id) AS votes
                 FROM connect_wishes w WHERE w.digest_sent_at IS NULL ORDER BY w.id"
            )->fetchAll(PDO::FETCH_ASSOC);
            if ($rows === []) {
                return null;
            }
            $notifier = new Notifier($this->pdo, $config);
            $brand = Branding::load($this->pdo, $config);
            $url = rtrim($config->optional('CONNECT_APP_URL', 'https://connect.oview.at'), '/') . '/admin/wishes.php';
            $review = count(array_filter($rows, static fn (array $r): bool => $r['status'] === 'review'));
            $subject = count($rows) === 1 ? 'Neuer Funktionswunsch' : count($rows) . ' neue Funktionswünsche';

            $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
            $text = "Neue Funktionswünsche der letzten Woche" . ($review > 0 ? " ({$review} warten auf Freigabe)" : '') . ":\n\n";
            $html = '<h2 style="font-size:20px;margin:0 0 12px">' . $e($subject) . '</h2>'
                . ($review > 0 ? '<p style="margin:0 0 12px;color:#b26b00">' . $review . ' warten auf deine Freigabe.</p>' : '');
            foreach ($rows as $r) {
                $text .= '- ' . $r['title'] . ' (' . $r['shop'] . ', ' . self::STATUSES[(string) $r['status']][0] . ")\n"
                    . ($r['body'] !== null ? '  ' . mb_strimwidth(str_replace("\n", ' ', (string) $r['body']), 0, 300, '…') . "\n" : '');
                $html .= '<div style="border-top:1px solid #e6e9ee;padding:10px 0"><strong>' . $e((string) $r['title']) . '</strong>'
                    . '<br><span style="color:#5b6573;font-size:13px">' . $e((string) $r['shop']) . ' · ' . $e(self::STATUSES[(string) $r['status']][0]) . '</span>'
                    . ($r['body'] !== null ? '<div style="margin-top:4px">' . nl2br($e(mb_strimwidth((string) $r['body'], 0, 600, '…'))) . '</div>' : '')
                    . '</div>';
            }
            $text .= "\nAnsehen und freigeben: {$url}\n";
            $html .= '<p style="margin:20px 0 0">' . $brand->mailButton($url, 'Wünsche ansehen') . '</p>';

            $sent = $notifier->send($notifier->adminEmail(), $subject, $text, $brand->mailHtml($html, '', false));
            if ($sent) {
                $ids = implode(',', array_map(static fn (array $r): int => (int) $r['id'], $rows));
                $this->pdo->exec("UPDATE connect_wishes SET digest_sent_at = NOW() WHERE id IN ({$ids})");
            }

            return ['sent' => $sent, 'wishes' => count($rows)];
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')");
        }
    }
}
