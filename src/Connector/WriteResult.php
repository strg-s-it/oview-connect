<?php

declare(strict_types=1);

namespace Connect\Connector;

/**
 * Ergebnis eines Schreibvorgangs (siehe ConnectorInterface::write).
 *
 * responseData haelt die rohe Antwort des externen Systems (z.B. bei MGM
 * GfNr/GfIdNr aus der GefallAdd-Response) -- wird spaeter fuer die
 * "write_back_response_field"-Post-Sync-Action gebraucht (siehe Plan).
 *
 * requestBody/responseBody: die ROHEN Texte (GraphQL-Body inkl. Variablen
 * bzw. XML-Body / rohe JSON-/XML-Antwort), zusaetzlich zum bereits
 * geparsten responseData -- fuer Connect\Sync\ActivityLog (Migration 0043),
 * damit im Protokoll nachvollziehbar ist, was GENAU gesendet/zurueckgegeben
 * wurde, nicht nur eine zusammengefasste Meldung. Optional/nullable, da
 * z.B. bei einem Verbindungsfehler vor der Antwort noch keine responseBody
 * existiert.
 */
final class WriteResult
{
    /**
     * @param array<string, mixed> $responseData
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly array $responseData = [],
        public readonly ?string $requestBody = null,
        public readonly ?string $responseBody = null
    ) {
    }

    /**
     * @param array<string, mixed> $responseData
     */
    public static function success(array $responseData = [], string $message = 'Datensatz erfolgreich geschrieben.', ?string $requestBody = null, ?string $responseBody = null): self
    {
        return new self(true, $message, $responseData, $requestBody, $responseBody);
    }

    public static function failure(string $message, ?string $requestBody = null, ?string $responseBody = null): self
    {
        return new self(false, $message, [], $requestBody, $responseBody);
    }
}
