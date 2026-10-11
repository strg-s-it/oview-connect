<?php

declare(strict_types=1);

namespace Connect\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/**
 * Versendet eine fertig erstellte HelloCash-Rechnung (PDF, direkt aus der Erstellungs-
 * Antwort, siehe Connect\Sync\InvoiceService) an den Kunden -- eigenstaendige, von der
 * alten Welt unabhaengige Entsprechung zu inc/mailer_helper.php/jobs/cron_send_mail.php
 * dort (siehe Plan, "Verhaeltnis zur bestehenden Codebasis": keine Laufzeit-Abhaengigkeit).
 *
 * Zwei Versandwege, wie in der alten Welt:
 *   - eigenes SMTP-Konto des Merchants (connect_shops.invoice_mail_smtp_*), falls hinterlegt
 *   - sonst SYSTEM-Versand ueber die lokale Mailfunktion des Servers (PHP mail()), mit der
 *     Absenderadresse aus CONNECT_SYSTEM_MAIL_FROM
 * Schlaegt das eigene SMTP-Konto fehl, wird automatisch ueber den System-Weg nachgesendet --
 * der Merchant soll durch einen Tippfehler in den eigenen Zugangsdaten keine Rechnungsmails
 * verlieren.
 */
final class InvoiceMailer
{
    /** @var list<array{content: string, filename: string, mime: string}> fuer den laufenden send()-Aufruf */
    private array $extraAttachments = [];

    /**
     * @param array{
     *     smtp_host: ?string, smtp_port: ?int, smtp_username: ?string, smtp_password: ?string, smtp_encryption: ?string
     * } $ownSmtp Bereits entschluesselte Zugangsdaten -- oder alle Werte null/leer, wenn keine hinterlegt sind
     * @param list<array{content: string, filename: string, mime: string}> $extraAttachments weitere Anhaenge
     *        (Connect\Mail\ShopAttachments, z.B. Widerrufsbelehrung), Migration 0089
     * @return array{sent: bool, note: string} note beschreibt den tatsaechlich genutzten Weg (fuers Protokoll)
     */
    public function send(
        array $ownSmtp,
        string $systemFromAddress,
        string $fromName,
        string $replyToAddress,
        string $recipient,
        string $subject,
        string $message,
        string $pdfContent,
        string $pdfFilename,
        array $extraAttachments = []
    ): array {
        $this->extraAttachments = $extraAttachments;
        $ownSmtpComplete = !empty($ownSmtp['smtp_host']) && !empty($ownSmtp['smtp_username']) && !empty($ownSmtp['smtp_password']);

        if ($ownSmtpComplete) {
            $error = $this->sendViaSmtp(
                (string) $ownSmtp['smtp_host'],
                (int) ($ownSmtp['smtp_port'] ?: 587),
                (string) $ownSmtp['smtp_username'],
                (string) $ownSmtp['smtp_password'],
                (string) ($ownSmtp['smtp_encryption'] ?: 'tls'),
                $fromName !== '' ? $fromName : 'oView Connect',
                $replyToAddress !== '' ? $replyToAddress : $systemFromAddress,
                $recipient,
                $subject,
                $message,
                $pdfContent,
                $pdfFilename
            );

            if ($error === null) {
                return ['sent' => true, 'note' => 'per eigenem SMTP versendet'];
            }

            // Fallback auf den System-Versand -- der Grund fuer das Scheitern des eigenen
            // SMTP-Kontos steht im Rueckgabewert und wird vom Aufrufer (InvoiceService) protokolliert.
            $systemSent = $this->sendViaSystemMail($systemFromAddress, $fromName, $replyToAddress, $recipient, $subject, $message, $pdfContent, $pdfFilename);

            return [
                'sent' => $systemSent,
                'note' => $systemSent
                    ? "eigenes SMTP fehlgeschlagen ({$error}), per System-Postfach nachgesendet"
                    : "eigenes SMTP fehlgeschlagen ({$error}), System-Postfach ebenfalls fehlgeschlagen",
            ];
        }

        $systemSent = $this->sendViaSystemMail($systemFromAddress, $fromName, $replyToAddress, $recipient, $subject, $message, $pdfContent, $pdfFilename);

        return ['sent' => $systemSent, 'note' => $systemSent ? 'per System-Postfach versendet' : 'System-Postfach-Versand fehlgeschlagen'];
    }

    /**
     * Prueft nur Verbindung + Login eines SMTP-Kontos (Button "SMTP-Verbindung
     * testen" bei den Merchant-Einstellungen) -- sendet KEINE Mail. Entspricht
     * inc/mailer_helper.php::oview_test_smtp_connection() in der alten Welt,
     * bewusst als eigene Methode hier statt geteiltem Code (siehe Plan:
     * Connect hat keine Laufzeit-Abhaengigkeit von altem Code).
     *
     * @param array{host: string, port: int|string, username: string, password: string, encryption: string} $smtp
     * @return string|null null bei Erfolg, sonst die Fehlermeldung (bei einem
     *                      Verbindungsfehler mit den letzten Protokollzeilen,
     *                      damit DNS/Firewall/TLS-Ursachen erkennbar sind)
     */
    public function testConnection(array $smtp): ?string
    {
        if (empty($smtp['host']) || empty($smtp['username']) || empty($smtp['password'])) {
            return 'Unvollständige SMTP-Zugangsdaten.';
        }

        $mailer = null;
        $debugLog = [];

        try {
            $mailer = new PHPMailer(true);
            $mailer->SMTPDebug = SMTP::DEBUG_CONNECTION;
            $mailer->Debugoutput = function (string $str) use (&$debugLog): void {
                $debugLog[] = trim($str);
            };

            $mailer->isSMTP();
            $mailer->Host = (string) $smtp['host'];
            $mailer->Port = (int) ($smtp['port'] ?: 587);
            $mailer->SMTPAuth = true;
            $mailer->Username = (string) $smtp['username'];
            $mailer->Password = (string) $smtp['password'];
            $mailer->Timeout = 10;
            $encryption = (string) ($smtp['encryption'] ?? 'tls');
            $mailer->SMTPSecure = match ($encryption) {
                'ssl' => PHPMailer::ENCRYPTION_SMTPS,
                'none' => '',
                default => PHPMailer::ENCRYPTION_STARTTLS,
            };
            if ($encryption === 'none') {
                $mailer->SMTPAutoTLS = false;
            }

            if (!$mailer->smtpConnect()) {
                $detail = $mailer->ErrorInfo !== '' ? $mailer->ErrorInfo : 'Verbindung fehlgeschlagen.';
                if ($debugLog !== []) {
                    $detail .= ' [' . implode(' / ', array_slice($debugLog, -5)) . ']';
                }

                return $detail;
            }

            $mailer->smtpClose();

            return null;
        } catch (\Throwable $e) {
            $detail = ($mailer instanceof PHPMailer && $mailer->ErrorInfo !== '') ? $mailer->ErrorInfo : $e->getMessage();
            if ($debugLog !== []) {
                $detail .= ' [' . implode(' / ', array_slice($debugLog, -5)) . ']';
            }

            return $detail;
        }
    }

    /**
     * @return string|null null bei Erfolg, sonst die Fehlermeldung
     */
    private function sendViaSmtp(
        string $host,
        int $port,
        string $username,
        string $password,
        string $encryption,
        string $fromName,
        string $replyTo,
        string $recipient,
        string $subject,
        string $message,
        string $pdfContent,
        string $pdfFilename
    ): ?string {
        $mailer = null;

        try {
            $mailer = new PHPMailer(true);
            $mailer->isSMTP();
            $mailer->Host = $host;
            $mailer->Port = $port;
            $mailer->SMTPAuth = true;
            $mailer->Username = $username;
            $mailer->Password = $password;
            $mailer->SMTPSecure = match ($encryption) {
                'ssl' => PHPMailer::ENCRYPTION_SMTPS,
                'none' => false,
                default => PHPMailer::ENCRYPTION_STARTTLS,
            };
            if ($encryption === 'none') {
                $mailer->SMTPSecure = '';
                $mailer->SMTPAutoTLS = false;
            }

            $mailer->CharSet = 'UTF-8';
            $mailer->setFrom($username, $fromName); // Absenderadresse muss zum SMTP-Konto gehoeren (SPF/DKIM)
            $mailer->addReplyTo($replyTo !== '' ? $replyTo : $username);
            $mailer->addAddress($recipient);
            $mailer->Subject = $subject;
            $mailer->Body = $message;
            $mailer->addStringAttachment($pdfContent, $pdfFilename, 'base64', 'application/pdf');
            foreach ($this->extraAttachments as $attachment) {
                $mailer->addStringAttachment($attachment['content'], $attachment['filename'], 'base64', $attachment['mime']);
            }

            $mailer->send();

            return null;
        } catch (\Throwable $e) {
            return ($mailer instanceof PHPMailer && $mailer->ErrorInfo !== '') ? $mailer->ErrorInfo : $e->getMessage();
        }
    }

    private function sendViaSystemMail(
        string $fromAddress,
        string $fromName,
        string $replyTo,
        string $recipient,
        string $subject,
        string $message,
        string $pdfContent,
        string $pdfFilename
    ): bool {
        $boundary = md5(uniqid((string) time(), true));
        $fromName = trim(preg_replace('/[\r\n]+/', ' ', $fromName) ?? '');
        $replyTo = trim(preg_replace('/[\r\n]+/', ' ', $replyTo) ?? '') ?: $fromAddress;

        $headers = 'From: ' . mb_encode_mimeheader($fromName !== '' ? $fromName : 'oView Connect', 'UTF-8') . " <{$fromAddress}>\r\n"
            . "Reply-To: {$replyTo}\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";

        $body = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=\"utf-8\"\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . "{$message}\r\n\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: application/pdf; name=\"{$pdfFilename}\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "Content-Disposition: attachment; filename=\"{$pdfFilename}\"\r\n\r\n"
            . chunk_split(base64_encode($pdfContent)) . "\r\n";
        foreach ($this->extraAttachments as $attachment) {
            $name = str_replace(['"', "\r", "\n"], '', $attachment['filename']);
            $body .= "--{$boundary}\r\n"
                . "Content-Type: {$attachment['mime']}; name=\"{$name}\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . "Content-Disposition: attachment; filename=\"{$name}\"\r\n\r\n"
                . chunk_split(base64_encode($attachment['content'])) . "\r\n";
        }
        $body .= "--{$boundary}--";

        return @mail($recipient, $subject, $body, $headers, "-f{$fromAddress}");
    }
}
