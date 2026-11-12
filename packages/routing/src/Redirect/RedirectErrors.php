<?php

declare(strict_types=1);

namespace Preflow\Routing\Redirect;

/** What RedirectRules refused: the field ('address' or 'target') and the reason's key. */
final readonly class RedirectErrors
{
    /** @param array<string, string> $errors field => message key (e.g. 'page_wins') */
    public function __construct(private array $errors = []) {}

    public function isEmpty(): bool
    {
        return $this->errors === [];
    }

    /** The reason key for a field, or null. */
    public function get(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->errors;
    }

    /** These errors, then the other's for fields not refused here. */
    public function merge(self $other): self
    {
        return new self($this->errors + $other->errors);
    }
}
