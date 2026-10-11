<?php

declare(strict_types=1);

namespace Connect\Notify;

use Connect\Config;
use PDO;

/**
 * Mails an den Merchant (Karte "Benachrichtigungen" in den Einstellungen, Migration 0079).
 *
 * Empfaenger: connect_shops.notify_email, sonst die Shop-E-Mail aus Shopify (Object "shop"
 * im Zwischenspeicher). Problem-Meldungen gehen zusaetzlich in Kopie an
 * CONNECT_ERROR_NOTIFICATION_EMAIL (Standard office@oview.at) -- Nutzer-Entscheidung
 * 2026-10-05: "Merchant und mich". Ohne Merchant-Adresse bekommt nur der Admin die Meldung.
 *
 * Versand ueber die Mailfunktion des Servers (System-Postfach), wie bisher die Fehler-Mails.
 */
final class Notifier
{
    public function __construct(private readonly PDO $pdo, private readonly Config $config)
    {
    }

    /**
     * @return array{custom: ?string, shopify: ?string, effective: ?string, problems: bool, weekly: bool}
     */
    public function settings(string $shop): array
    {
        $row = [];
        try {
            $stmt = $this->pdo->prepare('SELECT notify_email, notify_problems, weekly_report_enabled FROM connect_shops WHERE shop = :shop');
            $stmt->execute(['shop' => $shop]);
            $row = $stmt->fetch() ?: [];
        } catch (\PDOException) {
            // Migration 0079 fehlt: bisherige Fehler-Adresse der Rechnungskarte
            $stmt = $this->pdo->prepare('SELECT invoice_error_notification_email AS notify_email FROM connect_shops WHERE shop = :shop');
            $stmt->execute(['shop' => $shop]);
            $row = ($stmt->fetch() ?: []) + ['notify_problems' => 1, 'weekly_report_enabled' => 0];
        }

        $custom = self::validEmail($row['notify_email'] ?? null);
        $shopify = $this->shopifyEmail($shop);

        return [
            'custom' => $custom,
            'shopify' => $shopify,
            'effective' => $custom ?? $shopify,
            'problems' => (int) ($row['notify_problems'] ?? 1) === 1,
            'weekly' => (int) ($row['weekly_report_enabled'] ?? 0) === 1,
        ];
    }

    /** Shop-E-Mail aus Shopify (Zwischenspeicher, Object "shop"). */
    public function shopifyEmail(string $shop): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.data FROM connect_object_records r
             JOIN connect_connections c ON c.id = r.connection_id
             JOIN connect_connector_types t ON t.id = c.connector_type_id
             WHERE c.shop = :shop AND t.type_key = 'shopify' AND r.object_key = 'shop'
             ORDER BY r.fetched_at DESC LIMIT 1"
        );
        $stmt->execute(['shop' => $shop]);
        $data = json_decode((string) $stmt->fetchColumn(), true);

        return is_array($data) ? self::validEmail($data['email'] ?? null) : null;
    }

    public function adminEmail(): string
    {
        return self::validEmail($this->config->optional('CONNECT_ERROR_NOTIFICATION_EMAIL', 'office@oview.at')) ?? 'office@oview.at';
    }

    /**
     * Problem sofort melden: an den Merchant (wenn eingeschaltet und Adresse bekannt), Kopie an den Admin.
     */
    public function sendProblem(string $shop, string $subject, string $text): void
    {
        $settings = $this->settings($shop);
        $admin = $this->adminEmail();
        $merchant = $settings['problems'] ? $settings['effective'] : null;

        if ($merchant !== null && strcasecmp($merchant, $admin) !== 0) {
            $this->send($merchant, $subject, $text, null, $admin);
        } else {
            $this->send($admin, $subject, $text);
        }
    }

    /**
     * Mail ueber das System-Postfach. Mit $html als multipart/alternative.
     */
    public function send(string $to, string $subject, string $text, ?string $html = null, ?string $cc = null): bool
    {
        $from = $this->systemFrom();
        $headers = 'From: ' . mb_encode_mimeheader(\Connect\Setup\Branding::load($this->pdo, $this->config)->name, 'UTF-8') . " <{$from}>\r\n"
            . ($cc !== null ? "Cc: {$cc}\r\n" : '')
            . "MIME-Version: 1.0\r\n";

        if ($html === null) {
            $headers .= "Content-Type: text/plain; charset=\"utf-8\"\r\nContent-Transfer-Encoding: 8bit\r\n";
            $body = $text;
        } else {
            $boundary = md5(uniqid((string) time(), true));
            $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
            $body = "--{$boundary}\r\nContent-Type: text/plain; charset=\"utf-8\"\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$text}\r\n\r\n"
                . "--{$boundary}\r\nContent-Type: text/html; charset=\"utf-8\"\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$html}\r\n\r\n"
                . "--{$boundary}--";
        }

        return @mail($to, mb_encode_mimeheader($subject, 'UTF-8'), $body, $headers, "-f{$from}");
    }

    public function systemFrom(): string
    {
        $configured = $this->config->optional('CONNECT_SYSTEM_MAIL_FROM', '');
        if ($configured !== '') {
            return $configured;
        }
        $host = parse_url($this->config->optional('CONNECT_APP_URL', 'https://connect.oview.at'), PHP_URL_HOST);

        return 'no-reply@' . (is_string($host) && $host !== '' ? $host : 'connect.oview.at');
    }

    /** Link, der die App im Shopify-Admin oeffnet. */
    public function appUrl(string $shop): string
    {
        return 'https://admin.shopify.com/store/' . rawurlencode($shop) . '/apps/' . rawurlencode($this->config->optional('CONNECT_SHOPIFY_API_KEY', ''));
    }

    private static function validEmail(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : null;
    }
}
