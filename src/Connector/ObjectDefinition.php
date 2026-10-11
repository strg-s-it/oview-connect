<?php

declare(strict_types=1);

namespace Connect\Connector;

/**
 * Beschreibt ein Object eines Connector Type (z.B. "product", "order")
 * inkl. seiner Felder -- Grundlage fuer den Feld-Mapping-Editor (Phase 3)
 * und fuer die Objekt-/Feld-Definition im Connector-Builder (Phase 2).
 */
final class ObjectDefinition
{
    /**
     * @param FieldDefinition[] $fields
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly array $fields = [],
        public readonly bool $hydrateDetailsAfterList = false,
        public readonly ?int $hydrateBatchSize = null
    ) {
    }
}
