<?php

declare(strict_types=1);

namespace Connect\Http;

/**
 * Wacht ueber jeden AUSGEHENDEN HTTP-Aufruf (Connect\Http\HttpClient): erlaubt nur https,
 * keine Zugangsdaten in der URL und nur Ziele, die auf oeffentliche IPv4-Adressen aufloesen.
 * Verhindert, dass eine manipulierte Basis-URL (z.B. eine vom Merchant eingetragene) Connect
 * dazu bringt, interne Dienste des Servers anzusprechen (SSRF: localhost, private Netze,
 * Cloud-Metadaten 169.254.169.254) -- und damit Token oder das Shopify-App-Secret dorthin zu senden.
 *
 * Gegen "DNS-Rebinding" (ein Name loest bei der Pruefung auf eine oeffentliche, beim Verbinden
 * auf eine interne Adresse auf) werden die GEPRUEFTEN Adressen an cURL uebergeben
 * (CURLOPT_RESOLVE): cURL verbindet nur zu genau diesen.
 *
 * Notschalter: CONNECT_OUTBOUND_GUARD=off in connect/.env deaktiviert den Waechter, falls er
 * einmal eine legitime Anbindung faelschlich blockiert (Standard: aktiv).
 */
final class OutboundGuard
{
    private static bool $active = true;

    /** @var array<string, array{0: int, 1: list<string>}> Aufloesungs-Cache je Host: [gueltig bis, Adressen] */
    private static array $dnsCache = [];

    /** Zusaetzlich zu PHPs "privat"/"reserviert": Carrier-Grade-NAT, Benchmark-, IETF-Bereiche. */
    private const EXTRA_BLOCKED_V4 = ['100.64.0.0/10', '198.18.0.0/15', '192.0.0.0/24', '192.88.99.0/24'];

    public static function configure(string $mode): void
    {
        self::$active = strtolower(trim($mode)) !== 'off';
    }

    /**
     * Prueft eine Ziel-URL. Liefert die CURLOPT_RESOLVE-Eintraege, mit denen der Aufruf an die
     * geprueften Adressen gebunden wird (leer bei einer IP-Adresse als Host oder abgeschaltetem Waechter).
     *
     * @return list<string>
     * @throws \RuntimeException mit einer verstaendlichen Begruendung, wenn das Ziel nicht erlaubt ist
     */
    public static function checkUrl(string $url): array
    {
        if (!self::$active) {
            return [];
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \RuntimeException('Ausgehender Aufruf gesperrt: die URL ist ungueltig.');
        }

        if (strtolower((string) $parts['scheme']) !== 'https') {
            throw new \RuntimeException('Ausgehender Aufruf gesperrt: nur https ist erlaubt.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('Ausgehender Aufruf gesperrt: Zugangsdaten in der URL sind nicht erlaubt.');
        }

        $host = strtolower((string) $parts['host']);
        if (str_starts_with($host, '[')) {
            throw new \RuntimeException('Ausgehender Aufruf gesperrt: IPv6-Adressen sind nicht vorgesehen.');
        }

        $port = (int) ($parts['port'] ?? 443);
        if ($port < 1 || $port > 65535) {
            throw new \RuntimeException('Ausgehender Aufruf gesperrt: ungueltiger Port.');
        }

        // Ist der Host schon eine IPv4-Adresse (z.B. ein On-Premise-ERP), nur diese pruefen.
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            self::assertPublicIp($host, $host);

            return [];
        }

        $ips = self::resolve($host);
        if ($ips === []) {
            throw new \RuntimeException("Ausgehender Aufruf gesperrt: der Host {$host} laesst sich nicht (per IPv4) aufloesen.");
        }

        foreach ($ips as $ip) {
            self::assertPublicIp($ip, $host);
        }

        return [$host . ':' . $port . ':' . implode(',', $ips)];
    }

    /**
     * @throws \RuntimeException
     */
    private static function assertPublicIp(string $ip, string $host): void
    {
        $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;

        if ($public) {
            foreach (self::EXTRA_BLOCKED_V4 as $cidr) {
                if (self::inCidr($ip, $cidr)) {
                    $public = false;
                    break;
                }
            }
        }

        if (!$public) {
            throw new \RuntimeException("Ausgehender Aufruf gesperrt: {$host} zeigt auf eine interne oder reservierte Adresse ({$ip}).");
        }
    }

    /**
     * IPv4-Adressen des Hosts. Nutzt bewusst gethostbynamel(), das auch ungewoehnliche
     * Schreibweisen (z.B. 2130706433 fuer 127.0.0.1) so aufloest, wie cURL es taete --
     * geprueft wird also die Adresse, nicht der Name. Kurz zwischengespeichert.
     *
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        $now = time();
        if (isset(self::$dnsCache[$host]) && self::$dnsCache[$host][0] > $now) {
            return self::$dnsCache[$host][1];
        }

        $ips = gethostbynamel($host);
        $ips = is_array($ips) ? array_values(array_unique($ips)) : [];

        self::$dnsCache[$host] = [$now + 60, $ips];

        return $ips;
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $ipLong = ip2long($ip);
        $networkLong = ip2long($network);
        if ($ipLong === false || $networkLong === false) {
            return false;
        }

        $mask = -1 << (32 - (int) $bits);

        return ($ipLong & $mask) === ($networkLong & $mask);
    }
}
