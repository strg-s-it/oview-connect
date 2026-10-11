<?php

declare(strict_types=1);

namespace Connect\Http;

/**
 * Duenner cURL-Wrapper. Bewusst kein Guzzle/andere HTTP-Bibliothek als
 * Composer-Abhaengigkeit -- ein einzelner, gut lesbarer Wrapper reicht fuer
 * das, was Connect braucht (synchrone Requests, kein Connection-Pooling
 * o.ae.), und haelt die Abhaengigkeitsliste minimal (siehe Plan,
 * "Portabilitaet").
 */
final class HttpClient
{
    /**
     * Quell-IP fuer ALLE ausgehenden Aufrufe dieses Prozesses (CURLOPT_INTERFACE),
     * gesetzt aus CONNECT_OUTBOUND_IP in connect/.env (Config::load()). Leer = die
     * Standard-IP des Servers. Noetig fuer Worker-Installationen: liegen mehrere
     * Plesk-Abos auf demselben Server, gilt deren IP nur fuer EINGEHENDE Anfragen --
     * ein PHP-Prozess geht ohne Bindung immer ueber die Standard-IP hinaus.
     */
    private static string $outboundIp = '';

    public static function configureOutboundIp(string $ip): void
    {
        $ip = trim($ip);
        self::$outboundIp = filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
    }

    /** Konfigurierte Quell-IP oder '' (Standard) -- auch Schluessel fuer den Rate-Limiter je IP. */
    public static function outboundIp(): string
    {
        return self::$outboundIp;
    }

    /** Ziel (Host[:Port]) => zuletzt benutzte Absender-IP, seit resetUsedSourceIps(). */
    private static array $usedSourceIps = [];

    public static function resetUsedSourceIps(): void
    {
        self::$usedSourceIps = [];
    }

    /** @return array<string, string> Ziel => Absender-IP (fuer das Lauf-Protokoll, Connect\Sync\SyncRunner) */
    public static function usedSourceIps(): array
    {
        return self::$usedSourceIps;
    }

    public function __construct(
        private readonly int $timeoutSeconds = 30,
        private readonly bool $verifySsl = true,
        private readonly ?string $interfaceIp = null
    ) {
    }

    /**
     * Kopie mit fester Quell-IP fuer GENAU DIESE Connection (Connector Type "Feste
     * Absender-IP", Migration 0081) -- hat Vorrang vor der IP des Workers. Fuer Systeme,
     * die beim Kunden nur eine freigegebene IP durchlassen (z.B. MGM/AGILA).
     */
    public function withOutboundIp(string $ip): self
    {
        $ip = trim($ip);

        return new self($this->timeoutSeconds, $this->verifySsl, filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null);
    }

    /**
     * Liefert eine Kopie mit geaendertem $verifySsl -- fuer einzelne
     * Connections mit einem selbstsignierten/internen Zertifikat (z.B.
     * ein On-Premise-ERP wie MGM/AGILA, nur ueber IP:Port erreichbar), bei
     * denen die normale Zertifikatspruefung fehlschlaegt (siehe
     * ConnectorFactory::forConnection(), config.verify_ssl). Deaktiviert
     * TLS-Pruefung nur fuer GENAU DIESE Connection, niemals global.
     */
    public function withVerifySsl(bool $verifySsl): self
    {
        return new self($this->timeoutSeconds, $verifySsl, $this->interfaceIp);
    }

    /**
     * @param array<string, string> $headers Key-Value, z.B. ['Authorization' => 'Bearer xyz']
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        // Ziel pruefen (nur https, nur oeffentliche Adressen, siehe OutboundGuard) und den Aufruf an die
        // geprueften Adressen binden -- wirft eine RuntimeException mit Begruendung, wenn das Ziel gesperrt ist.
        $pinnedAddresses = OutboundGuard::checkUrl($url);

        $ch = curl_init();
        $method = strtoupper($method);

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            // Ohne User-Agent senden manche Bot-/Sicherheitsabwehrsysteme
            // (z.B. bei Shopify beobachtet) eine "Access denied"-Blockseite
            // statt einer echten API-Antwort -- cURL schickt ohne
            // CURLOPT_USERAGENT gar keinen User-Agent-Header mit.
            CURLOPT_USERAGENT => 'oView-Connect/1.0 (+https://connect.oview.at)',
        ];

        // Wichtig: fuer POST *muss* CURLOPT_POST verwendet werden, nicht
        // CURLOPT_CUSTOMREQUEST -- nur dann berechnet/sendet cURL den
        // Content-Length-Header zuverlaessig. Mit CUSTOMREQUEST=POST +
        // POSTFIELDS haben wir bei Shopify einen HTTP 411 (Length Required)
        // bekommen. CUSTOMREQUEST bleibt fuer Methoden ohne eigene
        // CURLOPT_*-Konstante (PUT, DELETE, PATCH) mit optionalem Body.
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body ?? '';
        } elseif ($method !== 'GET') {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
            if ($body !== null) {
                $options[CURLOPT_POSTFIELDS] = $body;
            }
        }

        if ($pinnedAddresses !== []) {
            $options[CURLOPT_RESOLVE] = $pinnedAddresses; // cURL verbindet nur zu den soeben geprueften Adressen
        }

        if ($this->interfaceIp !== null) {
            $options[CURLOPT_INTERFACE] = $this->interfaceIp; // feste Absender-IP des Systems, siehe withOutboundIp()
        } elseif (self::$outboundIp !== '') {
            $options[CURLOPT_INTERFACE] = self::$outboundIp; // eigene Quell-IP (Worker-Abo), siehe configureOutboundIp()
        }

        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);

        // Tatsaechlich benutzte Absender-IP merken (Lauf-Protokoll, Fehlermeldung). Kam keine
        // Verbindung zustande (z.B. Zeitueberschreitung), meldet cURL keine -- dann die
        // vorgegebene IP bzw. "Standard-IP des Servers".
        $sourceIp = (string) curl_getinfo($ch, CURLINFO_LOCAL_IP);
        if ($sourceIp === '') {
            $sourceIp = $this->interfaceIp ?? (self::$outboundIp !== '' ? self::$outboundIp : 'Standard-IP des Servers');
        }
        $target = (string) parse_url($url, PHP_URL_HOST) . (parse_url($url, PHP_URL_PORT) !== null ? ':' . parse_url($url, PHP_URL_PORT) : '');
        self::$usedSourceIps[$target] = $sourceIp;

        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("HTTP-Aufruf fehlgeschlagen ({$method} {$url}, Absender-IP {$sourceIp}): {$error}");
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr($raw, 0, $headerSize);
        $responseBody = substr($raw, $headerSize);

        return new HttpResponse($statusCode, $responseBody, self::parseHeaders($rawHeaders));
    }

    /**
     * @return array<string, string>
     */
    private static function parseHeaders(string $rawHeaders): array
    {
        $headers = [];

        // Bei Redirects enthaelt $rawHeaders mehrere Header-Bloecke
        // hintereinander -- nur den letzten (die tatsaechliche finale
        // Antwort) auswerten.
        $blocks = explode("\r\n\r\n", trim($rawHeaders));
        $lastBlock = end($blocks);

        foreach (explode("\r\n", $lastBlock) as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $headers;
    }
}
