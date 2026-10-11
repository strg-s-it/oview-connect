<?php

declare(strict_types=1);

namespace Connect\Connector;

/**
 * Beschreibt, WIE ein Object gelesen/geschrieben wird (siehe
 * connect_object_operations). Ein Object kann mehrere Operations haben
 * (z.B. read_list UND create).
 */
final class OperationDefinition
{
    /**
     * @param array<string, mixed> $staticParams
     */
    public function __construct(
        public readonly string $objectKey,
        public readonly string $operationType,
        public readonly string $httpMethod,
        public readonly string $pathTemplate,
        public readonly ?string $bodyTemplate,
        public readonly ?string $responseRecordsPath,
        public readonly array $staticParams = []
    ) {
    }
}
