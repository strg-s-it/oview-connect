<?php

declare(strict_types=1);

namespace Connect\Http;

/**
 * Haengt den Seiten-Token (Connect\Security\PageToken, von bootstrap.php nach $GLOBALS['connect_ct'] gelegt)
 * an eine interne Adresse -- fuer serverseitige Weiterleitungen (header('Location: ...')) auf Merchant-Seiten.
 * Ist die cookielose Anmeldung fuer den Shop aus, bleibt die Adresse unveraendert.
 */
final class Url
{
    public static function withToken(string $url): string
    {
        $token = (string) ($GLOBALS['connect_ct'] ?? '');
        // Nur relative Adressen (kein Schema, kein "//host"): der Token darf die Seite nie verlassen
        if ($token === '' || preg_match('~^([a-z][a-z0-9+.-]*:|//)~i', $url) === 1) {
            return $url;
        }
        $fragment = '';
        $hashPos = strpos($url, '#');
        if ($hashPos !== false) {
            $fragment = substr($url, $hashPos);
            $url = substr($url, 0, $hashPos);
        }
        // vorhandenen ct-Parameter ersetzen statt verdoppeln
        $url = (string) preg_replace('~([?&])ct=[^&]*&?~', '$1', $url);
        $url = rtrim($url, '?&');

        return $url . (str_contains($url, '?') ? '&' : '?') . 'ct=' . rawurlencode($token) . $fragment;
    }
}
