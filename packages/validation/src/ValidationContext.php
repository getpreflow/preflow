<?php

declare(strict_types=1);

namespace Preflow\Validation;

final readonly class ValidationContext
{
    /**
     * @param array<string, mixed> $data All data being validated
     */
    public function __construct(
        public string $field,
        public array $data,
        public mixed $subject = null,
    ) {}

    /**
     * Get another field's value from the data being validated.
     */
    public function getValue(string $field): mixed
    {
        return $this->data[$field] ?? null;
    }
}
