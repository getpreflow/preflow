<?php

declare(strict_types=1);

namespace Preflow\Folio\Block;

use Preflow\Data\TypeFieldDefinition;

final readonly class BlockDefinition
{
    /**
     * @param class-string                       $class
     * @param array<string, TypeFieldDefinition> $fields
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $group,
        public ?string $icon,
        public ?string $description,
        public string $class,
        public bool $isComponent,
        public array $fields,
    ) {}
}
