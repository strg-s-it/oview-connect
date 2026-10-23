<?php

declare(strict_types=1);

namespace Connect\Security;

/**
 * Zustandsloser Seiten-Token fuer die Merchant-Seiten ("ct"), Ersatz fuer das Session-Cookie im Shopify-iframe.
 *
 * Hintergrund: Im iframe ist das Session-Cookie ein Drittanbieter-Cookie und wird von manchen Browsern blockiert
 * (Chrome, Safari, Firefox je nach Einstellung). Der Token steckt stattdessen in jedem Link, Formular und
 * fetch-Aufruf (Skript in public/app/_footer.php) und wird bei jeder Seite erneuert (gleitende Laufzeit).
 * Nur aktiv fuer Shops, die in connect_settings.cookieless_auth_shops stehen (Settings::cookielessEnabledFor).
 *
 * Aufbau: "<ablauf>.<host base64url>.<hmac>" -- der HMAC bindet Shop, Ablauf und den Shopify-"host"-Wert
 * (den die App Bridge fuer die Einbettung braucht). Schluessel: aus dem App-Secret abgeleitet, getrennt von
 * dem fuer den Handoff-Link (bootstrap.php) und dem App-Secret selbst.
 */
final class PageToken
{
    /** Gleitende Laufzeit in Sekunden: bei jeder Seite neu ausgestellt */
    public const TTL = 1800;

    public static function issue(string $shop, string $host, string $appSecret, ?int $now = null): string
    {
        $expires = ($now ?? time()) + self::TTL;
        $hostPart = self::b64($host);

        return $expires . '.' . $hostPart . '.' . self::mac($shop, $expires, $host, $appSecret);
    }

    /**
     * @return array{host: string, expires: int}|null null = ungueltig oder abgelaufen
     */
    public static function verify(string $token, string $shop, string $appSecret, ?int $now = null): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || !ctype_digit($parts[0])) {
            return null;
        }
        $expires = (int) $parts[0];
        if ($expires < ($now ?? time())) {
            return null;
        }
        $host = self::unb64($parts[1]);
        if ($host === null) {
            return null;
        }
        if (!hash_equals(self::mac($shop, $expires, $host, $appSecret), $parts[2])) {
            return null;
        }

        return ['host' => $host, 'expires' => $expires];
    }

    private static function mac(string $shop, int $expires, string $host, string $appSecret): string
    {
        $key = hash_hmac('sha256', 'oview-connect-page-token-v1', $appSecret);

        return hash_hmac('sha256', $shop . '|' . $expires . '|' . $host, $key);
    }

    private static function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function unb64(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
