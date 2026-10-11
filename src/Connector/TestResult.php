<?php

declare(strict_types=1);

namespace Connect\Connector;

/**
 * Ergebnis eines Verbindungstests (siehe ConnectorInterface::testConnection).
 * Bewusst ein unveraenderliches (readonly) Wertobjekt statt eines simplen
 * Arrays -- macht an jeder Aufrufstelle im Code sichtbar, welche Felder
 * garantiert vorhanden sind.
 */
final class TestResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message
    ) {
    }

    public static function success(string $message = 'Verbindung erfolgreich.'): self
    {
        return new self(true, $message);
    }

    public static function failure(string $message): self
    {
        return new self(false, $message);
    }
}
