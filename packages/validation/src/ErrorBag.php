<?php

declare(strict_types=1);

namespace Preflow\Validation;

final class ErrorBag
{
    public function __construct(
        private readonly ValidationResult $result,
    ) {}

    public function has(string $field): bool
    {
        return $this->result->fieldErrors($field) !== [];
    }

    public function first(string $field): ?string
    {
        return $this->result->firstError($field);
    }

    /**
     * @return list<string>
     */
    public function get(string $field): array
    {
        return $this->result->fieldErrors($field);
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->result->all();
    }

    public function count(): int
    {
        return count($this->result->all());
    }

    public function isEmpty(): bool
    {
        return $this->result->passes();
    }

    /**
     * @return array<string, list<string>>
     */
    public function toArray(): array
    {
        return $this->result->errors();
    }

    public function getResult(): ValidationResult
    {
        return $this->result;
    }
}
