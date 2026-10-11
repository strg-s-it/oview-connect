<?php

declare(strict_types=1);

namespace Connect\Notify;

use Connect\Config;
use Connect\Security\Crypto;
use Connect\Setup\Branding;
use Connect\Setup\LegalTexts;
use Connect\Shopify\ScopeCheck;
use PDO;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Nachrichten an Merchants (Migration 0082, admin/messages.php).
 *
 * Absender office@oview.at (CONNECT_MESSAGE_FROM), Versand ueber dessen SMTP-Postfach
 * (CONNECT_MESSAGE_SMTP_HOST/_PORT/_USER/_PASS/_ENCRYPTION in connect/.env) -- ohne diese
 * Angaben ueber die Mailfunktion des Servers mit Reply-To office@oview.at (landet eher im Spam).
 *
 * Versand im Hintergrund (bin/scheduler.php -> sendPending()): benannte Sperre, hoechstens
 * PER_RUN Mails je Aufruf (= je Minute), jede Zeile in connect_message_recipients wird genau
 * einmal versendet (auch nach Abbruch).
 *
 * Platzhalter je Shop: {shop} {firma} {app_link} {berechtigungen_link} {fehlende_berechtigungen} {abo}
 * Art "info" bekommt einen Abmelde-Link, Abgemeldete werden fuer "info" uebersprungen.
 */
final class MessageService
{
    public const PLACEHOLDERS = [
        '{shop}' => 'Shop-Name in Shopify (z. B. danysmusicshop)',
        '{firma}' => 'Firmenname (helloCash), sonst Shop-Name',
        '{app_link}' => 'öffnet oView im Shopify-Admin',
        '{berechtigungen_link}' => 'persönlicher Link zum Erteilen der Shopify-Berechtigungen',
        '{fehlende_berechtigungen}' => 'Liste der fehlenden Shopify-Berechtigungen',
        '{abo}' => 'aktuelles Abo',
        '{vorbereitung_link}' => 'persönlicher Link „Umstieg vorbereiten“: Shopify-Berechtigungen + neuer helloCash-Token (60 Tage gültig)',
    ];

    public const PREPARE_LINK_DAYS = 60;

    private const LOCK_NAME = 'connect_messages';
    private const PER_RUN = 20;

    public function __construct(private readonly PDO $pdo, private readonly Config $config)
    {
    }

    public function fromAddress(): string
    {
        return $this->config->optional('CONNECT_MESSAGE_FROM', 'office@oview.at');
    }

    public function smtpConfigured(): bool
    {
        return $this->config->optional('CONNECT_MESSAGE_SMTP_HOST', '') !== '' && $this->config->optional('CONNECT_MESSAGE_SMTP_USER', '') !== '';
    }

    /**
     * Betreff, Text- und HTML-Fassung fuer einen Merchant.
     *
     * @param array{kind: string, subject: string, body: string} $message
     * @param array<string, mixed> $merchant aus MerchantDirectory
     * @return array{0: string, 1: string, 2: string}
     */
    public function render(array $message, array $merchant): array
    {
        $values = $this->placeholderValues($merchant);
        $subject = strtr((string) $message['subject'], $values);
        $body = strtr((string) $message['body'], $values);

        $brand = Branding::load($this->pdo, $this->config);
        $footerText = $brand->mailFooterText();
        $footerHtml = '';
        if (($message['kind'] ?? 'service') === 'info') {
            $optout = $this->optoutUrl((string) $merchant['shop']);
            $footerText .= "\nKeine Informations-Mails mehr erhalten: {$optout}";
            $footerHtml = 'Keine Informations-Mails mehr erhalten: <a href="' . htmlspecialchars($optout, ENT_QUOTES) . '" style="color:#8a94a3">abmelden</a>';
        }

        $html = $brand->mailHtml(LegalTexts::render($body), $footerHtml);

        return [$subject, $body . $footerText, $html];
    }

    /**
     * Empfaenger festschreiben und zum Versand freigeben.
     *
     * @param list<array<string, mixed>> $merchants
     * @return array{queued: int, skipped: int}
     */
    public function queue(int $messageId, array $merchants): array
    {
        $message = $this->message($messageId);
        if ($message === null || $message['status'] !== 'draft') {
            throw new \RuntimeException('Nachricht nicht gefunden oder schon versendet.');
        }
        $insert = $this->pdo->prepare(
            'INSERT IGNORE INTO connect_message_recipients (message_id, shop, world, email, status, error)
             VALUES (:message_id, :shop, :world, :email, :status, :error)'
        );
        $queued = 0;
        $skipped = 0;
        foreach ($merchants as $m) {
            $reason = null;
            if ($m['email'] === null) {
                $reason = 'keine E-Mail-Adresse';
            } elseif ($message['kind'] === 'info' && $m['optout']) {
                $reason = 'von Informations-Mails abgemeldet';
            }
            $insert->execute([
                'message_id' => $messageId,
                'shop' => $m['shop'],
                'world' => $m['world'] === 'legacy' ? 'legacy' : 'connect',
                'email' => $m['email'],
                'status' => $reason === null ? 'pending' : 'skipped',
                'error' => $reason,
            ]);
            $reason === null ? $queued++ : $skipped++;
        }
        $this->pdo->prepare("UPDATE connect_messages SET status = 'queued', queued_at = NOW(), recipient_count = :n WHERE id = :id")
            ->execute(['n' => count($merchants), 'id' => $messageId]);

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    /**
     * Hintergrund-Versand (Scheduler): hoechstens PER_RUN Mails je Aufruf.
     *
     * @return array{sent: int, failed: int}|null null = nichts zu tun
     */
    public function sendPending(int $timeBudgetSeconds = 20): ?array
    {
        try {
            $pending = (int) $this->pdo->query("SELECT COUNT(*) FROM connect_message_recipients WHERE status = 'pending'")->fetchColumn();
        } catch (\PDOException) {
            return null; // Migration 0082 fehlt
        }
        if ($pending === 0) {
            return null;
        }
        if ((int) $this->pdo->query("SELECT GET_LOCK('" . self::LOCK_NAME . "', 0)")->fetchColumn() !== 1) {
            return null;
        }

        $result = ['sent' => 0, 'failed' => 0];
        try {
            $deadline = microtime(true) + $timeBudgetSeconds;
            $rows = $this->pdo->query(
                "SELECT r.id, r.message_id, r.shop, r.email FROM connect_message_recipients r
                 JOIN connect_messages m ON m.id = r.message_id AND m.status = 'queued'
                 WHERE r.status = 'pending' ORDER BY r.id LIMIT " . self::PER_RUN
            )->fetchAll();
            if ($rows === []) {
                return null;
            }

            // Platzhalter brauchen die aktuellen Merchant-Daten (einmal je Lauf)
            $directory = [];
            foreach ((new MerchantDirectory($this->pdo, $this->config))->all() as $m) {
                $directory[$m['shop']] = $m;
            }
            $messages = [];

            foreach ($rows as $row) {
                if (microtime(true) > $deadline) {
                    break;
                }
                $messages[$row['message_id']] ??= $this->message((int) $row['message_id']);
                $message = $messages[$row['message_id']];
                $merchant = $directory[$row['shop']] ?? ['shop' => $row['shop'], 'name' => $row['shop'], 'company' => null, 'plan_label' => '', 'scopes_missing' => null];

                [$subject, $text, $html] = $this->render($message, $merchant);
                $error = $this->sendMail((string) $row['email'], $subject, $text, $html);
                $this->pdo->prepare('UPDATE connect_message_recipients SET status = :status, error = :error, sent_at = NOW() WHERE id = :id')
                    ->execute(['status' => $error === null ? 'sent' : 'failed', 'error' => $error !== null ? mb_substr($error, 0, 250) : null, 'id' => (int) $row['id']]);
                $error === null ? $result['sent']++ : $result['failed']++;
            }

            // Fertige Nachrichten abschliessen
            $this->pdo->query(
                "UPDATE connect_messages m SET m.status = 'done', m.finished_at = NOW()
                 WHERE m.status = 'queued' AND NOT EXISTS (SELECT 1 FROM connect_message_recipients r WHERE r.message_id = m.id AND r.status = 'pending')"
            );
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')");
        }

        return $result;
    }

    /**
     * Testmail (nur an die angegebene Adresse, zaehlt nicht als Versand).
     *
     * @param array{kind: string, subject: string, body: string} $message
     * @param array<string, mixed> $merchant
     */
    public function sendTest(array $message, array $merchant, string $to): ?string
    {
        [$subject, $text, $html] = $this->render($message, $merchant);

        return $this->sendMail($to, '[Test] ' . $subject, $text, $html);
    }

    /**
     * Hinweise fuer die Uebersicht eines Connect-Shops (Nachrichten mit "auch in der App").
     *
     * @return list<array{id: int, subject: string, body_html: string, at: string}>
     */
    public function unreadInApp(string $shop): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT m.id, m.kind, m.subject, m.body, DATE_FORMAT(m.queued_at, '%d.%m.%Y') AS at FROM connect_message_recipients r
                 JOIN connect_messages m ON m.id = r.message_id
                 WHERE r.shop = :shop AND r.read_at IS NULL AND m.in_app = 1 AND m.status IN ('queued', 'done')
                 ORDER BY m.id DESC LIMIT 3"
            );
            $stmt->execute(['shop' => $shop]);
            $rows = $stmt->fetchAll();
        } catch (\PDOException) {
            return []; // Migration 0082 fehlt
        }
        if ($rows === []) {
            return [];
        }
        $merchant = null;
        foreach ((new MerchantDirectory($this->pdo, $this->config))->all() as $m) {
            if ($m['shop'] === $shop) {
                $merchant = $m;
            }
        }
        $merchant ??= ['shop' => $shop, 'name' => $shop, 'company' => null, 'plan_label' => '', 'scopes_missing' => null];

        $result = [];
        foreach ($rows as $row) {
            $values = $this->placeholderValues($merchant);
            $result[] = [
                'id' => (int) $row['id'],
                'subject' => strtr((string) $row['subject'], $values),
                'body_html' => LegalTexts::render(strtr((string) $row['body'], $values)),
                'at' => (string) $row['at'],
            ];
        }

        return $result;
    }

    public function markRead(string $shop, int $messageId): void
    {
        $this->pdo->prepare('UPDATE connect_message_recipients SET read_at = NOW() WHERE shop = :shop AND message_id = :id AND read_at IS NULL')
            ->execute(['shop' => $shop, 'id' => $messageId]);
    }

    public function optoutUrl(string $shop): string
    {
        $base = rtrim($this->config->optional('CONNECT_APP_URL', 'https://connect.oview.at'), '/');

        return $base . '/message_optout.php?shop=' . rawurlencode($shop) . '&sig=' . $this->optoutSignature($shop);
    }

    /** Persoenlicher Link "Umstieg vorbereiten" (public/prepare.php), gueltig PREPARE_LINK_DAYS Tage. */
    public function prepareUrl(string $shop): string
    {
        $base = rtrim($this->config->optional('CONNECT_APP_URL', 'https://connect.oview.at'), '/');
        $expires = time() + self::PREPARE_LINK_DAYS * 86400;

        return $base . '/prepare.php?shop=' . rawurlencode($shop) . '&exp=' . $expires . '&sig=' . $this->prepareSignature($shop, $expires);
    }

    public function prepareSignature(string $shop, int $expires): string
    {
        return substr(hash_hmac('sha256', 'prepare|' . $shop . '|' . $expires, $this->config->require('CONNECT_ENCRYPTION_KEY')), 0, 40);
    }

    public function optoutSignature(string $shop): string
    {
        return substr(hash_hmac('sha256', 'optout|' . $shop, $this->config->require('CONNECT_ENCRYPTION_KEY')), 0, 32);
    }

    /** @return array<string, mixed>|null */
    public function message(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM connect_messages WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * @param array<string, mixed> $m
     * @return array<string, string>
     */
    private function placeholderValues(array $m): array
    {
        $shop = (string) $m['shop'];
        $scopeCheck = new ScopeCheck($this->pdo, new Crypto($this->config->require('CONNECT_ENCRYPTION_KEY')), $this->config);
        $missing = $m['scopes_missing'] ?? null;

        return [
            '{shop}' => $shop,
            '{firma}' => (string) ($m['company'] ?? $m['name'] ?? $shop),
            '{app_link}' => 'https://admin.shopify.com/store/' . rawurlencode($shop) . '/apps/' . rawurlencode($this->config->optional('CONNECT_SHOPIFY_API_KEY', '')),
            '{berechtigungen_link}' => $scopeCheck->authorizeUrl($shop),
            '{fehlende_berechtigungen}' => is_array($missing) && $missing !== [] ? implode(', ', $missing) : '–',
            '{abo}' => (string) ($m['plan_label'] ?? ''),
            '{vorbereitung_link}' => $this->prepareUrl($shop),
        ];
    }

    /** @return string|null Fehlermeldung, null = versendet */
    private function sendMail(string $to, string $subject, string $text, string $html): ?string
    {
        $from = $this->fromAddress();
        if (!$this->smtpConfigured()) {
            $sent = (new Notifier($this->pdo, $this->config))->send($to, $subject, $text, $html);

            return $sent ? null : 'Versand über die Mailfunktion des Servers fehlgeschlagen.';
        }

        $mailer = null;
        try {
            $mailer = new PHPMailer(true);
            $mailer->isSMTP();
            $mailer->Host = $this->config->require('CONNECT_MESSAGE_SMTP_HOST');
            $mailer->Port = (int) $this->config->optional('CONNECT_MESSAGE_SMTP_PORT', '587');
            $mailer->SMTPAuth = true;
            $mailer->Username = $this->config->require('CONNECT_MESSAGE_SMTP_USER');
            $mailer->Password = $this->config->optional('CONNECT_MESSAGE_SMTP_PASS', '');
            $encryption = $this->config->optional('CONNECT_MESSAGE_SMTP_ENCRYPTION', 'tls');
            $mailer->SMTPSecure = $encryption === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : ($encryption === 'none' ? '' : PHPMailer::ENCRYPTION_STARTTLS);
            if ($encryption === 'none') {
                $mailer->SMTPAutoTLS = false;
            }
            $mailer->Timeout = 20;
            $mailer->CharSet = 'UTF-8';
            $senderName = Branding::load($this->pdo, $this->config)->shortName;
            $mailer->setFrom($from, $senderName);
            $mailer->addReplyTo($from, $senderName);
            $mailer->addAddress($to);
            $mailer->Subject = $subject;
            $mailer->isHTML(true);
            $mailer->Body = $html;
            $mailer->AltBody = $text;
            $mailer->send();

            return null;
        } catch (\Throwable $e) {
            return ($mailer instanceof PHPMailer && $mailer->ErrorInfo !== '') ? $mailer->ErrorInfo : $e->getMessage();
        }
    }
}
