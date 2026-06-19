<?php

declare(strict_types=1);

namespace Preflow\Folio\Field;

/**
 * Everything an editor needs to render one field instance.
 */
final readonly class FieldContext
{
    /**
     * @param list<string> $errors
     * @param array<string, mixed> $config
     */
    public function __construct(
        public string $name,
        public ?string $label = null,
        public ?string $help = null,
        public mixed $value = null,
        public array $errors = [],
        public array $config = [],
        public bool $required = false,
    ) {}
}
