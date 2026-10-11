<?php

declare(strict_types=1);

namespace Connect\Http;

/**
 * Konfiguration fuer Connect\Http\RateLimiter: welcher Connector Type, mit welchem
 * (optionalen) Limit. $maxRequests/$windowSeconds NULL = kein Limit konfiguriert.
 */
final class RateLimitConfig
{
    public function __construct(
        public readonly int $connectorTypeId,
        public readonly ?int $maxRequests,
        public readonly ?int $windowSeconds
    ) {
    }
}
