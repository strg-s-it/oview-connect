<?php

declare(strict_types=1);

namespace Connect;

use PDO;
use PDOException;

/**
 * Duenner PDO-Wrapper fuer die Connect-eigene Datenbank (oview_connect).
 *
 * Bewusst kein ORM/Query-Builder: das Schema ist klein und ueberschaubar,
 * ein Abstraktions-Layer wuerde hier nur Komplexitaet ohne Nutzen bringen.
 * PDO (statt mysqli wie im Hauptprojekt) primaer wegen Named Parameters und
 * durchgaengiger Exception-basierter Fehlerbehandlung.
 */
final class Database
{
    private PDO $pdo;

    private function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Verbindung zur Connect-eigenen DB, Zugangsdaten aus connect/.env.
     */
    public static function connect(Config $config): self
    {
        return self::connectTo(
            $config->require('CONNECT_DB_HOST'),
            $config->optional('CONNECT_DB_PORT', '3306'),
            $config->require('CONNECT_DB_NAME'),
            $config->require('CONNECT_DB_USER'),
            $config->optional('CONNECT_DB_PASS')
        );
    }

    /**
     * Separater Verbindungsaufbau (ohne Config-Objekt) fuer die optionale
     * Migrationsbruecke, die auf die ALTE Datenbank zugreift (LEGACY_DB_*
     * in .env.example) -- bewusst eine eigene, explizit benannte Methode,
     * damit an jeder Aufrufstelle im Code klar ist, welche der beiden
     * Datenbanken gerade angesprochen wird.
     */
    public static function connectTo(string $host, string $port, string $name, string $user, string $pass): self
    {
        // charset=utf8mb4 direkt im DSN, damit die Verbindung von der
        // ersten Query an korrekt kodiert ist (nicht erst per SET NAMES
        // danach -- das waere ein Zeitfenster mit falscher Kodierung).
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            // Verbindungsfehler nicht 1:1 durchreichen -- die PDOException
            // haengt bei falschem Passwort teils den DSN (inkl. Host/DB) an
            // die Meldung, aber nie das Passwort selbst; trotzdem bewusst
            // eine eigene, kontrollierte Meldung statt der rohen Exception.
            throw new \RuntimeException(
                "Datenbankverbindung fehlgeschlagen (Host: {$host}, DB: {$name}): " . $e->getMessage()
            );
        }

        return new self($pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
