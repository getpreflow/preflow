<?php

declare(strict_types=1);

namespace Preflow\Validation;

final class ValidationResult
{
    /**
     * @param array<string, list<string>> $errors Field => error messages
     */
    public function __construct(
        private readonly array $errors = [],
    ) {}

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return list<string>
     */
    public function fieldErrors(string $field): array
    {
        return $this->errors[$field] ?? [];
    }

    public function firstError(string $field): ?string
    {
        return $this->errors[$field][0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_merge(...array_values($this->errors)) ?: [];
    }
}
