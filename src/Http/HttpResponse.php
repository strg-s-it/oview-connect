<?php

declare(strict_types=1);

namespace Connect\Http;

/**
 * Rohes Ergebnis eines HTTP-Aufrufs. Bewusst ohne Interpretation des Bodys
 * (JSON/XML-Parsing passiert erst in GenericHttpConnector, je nach
 * request_format des jeweiligen Connector Type).
 */
final class HttpResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body,
        public readonly array $headers = []
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }
}
