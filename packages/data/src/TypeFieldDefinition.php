<?php

declare(strict_types=1);

namespace Preflow\Data;

final readonly class TypeFieldDefinition
{
    /**
     * @param list<string> $validate
     */
    public function __construct(
        public string $name,
        public string $type = 'string',
        public bool $searchable = false,
        public ?string $transform = null,
        public array $validate = [],
    ) {}
}
