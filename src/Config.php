<?php

declare(strict_types=1);

namespace Connect;

/**
 * Liest connect/.env ein und stellt die Werte typisiert zur Verfuegung.
 *
 * Bewusst ohne externe Dotenv-Bibliothek: das Format ist einfaches
 * KEY=value (wie die bestehende Root-.env, die per parse_ini_file gelesen
 * wird), damit Connect ausser PHPMailer keine weitere Abhaengigkeit braucht.
 *
 * Wichtig fuer die Portabilitaet (siehe Plan): Config kennt nur den Pfad zu
 * EINER .env-Datei (Standard: connect/.env). Es gibt keinen Fallback auf die
 * Root-.env/config.php des Hauptprojekts.
 */
final class Config
{
    /** @var array<string, string> */
    private array $values;

    private function __construct(array $values)
    {
        $this->values = $values;
    }

    /**
     * Laedt die .env-Datei. Wirft eine Exception, wenn sie fehlt oder leer
     * ist -- ein fehlendes .env ist ein Konfigurationsfehler, kein Fall fuer
     * stille Defaults.
     */
    public static function load(?string $envPath = null): self
    {
        $envPath = $envPath ?? dirname(__DIR__) . '/.env';

        if (!is_file($envPath)) {
            throw new \RuntimeException(
                "Connect-Konfiguration fehlt: {$envPath} nicht gefunden. " .
                "Bitte connect/.env.example nach connect/.env kopieren und ausfuellen."
            );
        }

        $values = parse_ini_file($envPath, false, INI_SCANNER_RAW);

        if ($values === false || count($values) === 0) {
            throw new \RuntimeException("Connect-Konfiguration konnte nicht gelesen werden: {$envPath}");
        }

        // Wacht ueber alle ausgehenden Aufrufe (siehe Http\OutboundGuard). Notschalter: CONNECT_OUTBOUND_GUARD=off.
        \Connect\Http\OutboundGuard::configure((string) ($values['CONNECT_OUTBOUND_GUARD'] ?? 'on'));
        // Ausgehende IP dieser Installation (z.B. je Worker-Abo eine eigene), siehe HttpClient.
        \Connect\Http\HttpClient::configureOutboundIp((string) ($values['CONNECT_OUTBOUND_IP'] ?? ''));
        // Name dieser Installation (Hauptserver/Worker), siehe Connect\Runtime.
        \Connect\Runtime::configure((string) ($values['CONNECT_WORKER_NAME'] ?? ''));

        return new self($values);
    }

    /**
     * Liest einen Pflichtwert. Wirft eine Exception, wenn er fehlt oder leer
     * ist -- so schlaegt ein unvollstaendiges Setup sofort beim Start fehl,
     * statt spaeter mit einem kryptischen DB-/API-Fehler.
     */
    public function require(string $key): string
    {
        $value = $this->values[$key] ?? '';

        if ($value === '') {
            throw new \RuntimeException("Pflicht-Konfigurationswert fehlt in connect/.env: {$key}");
        }

        return $value;
    }

    /**
     * Liest einen optionalen Wert mit Default -- fuer Werte, die sinnvoll
     * leer bleiben duerfen (z. B. die optionale Migrationsbruecke).
     */
    public function optional(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? '';

        return $value !== '' ? $value : $default;
    }

    /**
     * True, wenn ein Wert gesetzt ist. Wird u.a. genutzt, um zu pruefen, ob
     * die optionale Migrationsbruecke zur alten Welt ueberhaupt konfiguriert
     * ist (siehe LEGACY_DB_* in .env.example).
     */
    public function has(string $key): bool
    {
        return ($this->values[$key] ?? '') !== '';
    }
}
