<?php

declare(strict_types=1);

namespace Preflow\Data;

final readonly class TypeFieldDefinition
{
    /**
     * @param list<string> $validate
     * @param array<string, mixed> $config
     */
    public function __construct(
        public string $name,
        public string $type = 'string',
        public bool $searchable = false,
        public ?string $transform = null,
        public array $validate = [],
        public ?string $label = null,
        public ?string $help = null,
        public array $config = [],
    ) {}
}
