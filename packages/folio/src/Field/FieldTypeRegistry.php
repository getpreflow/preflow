<?php

declare(strict_types=1);

namespace Preflow\Folio\Field;

final class FieldTypeRegistry
{
    /** @var array<string, FieldType> */
    private array $types = [];

    /** @var array<string, string> */
    private array $aliases = [];

    public function register(FieldType $type): void
    {
        $this->types[$type->key()] = $type;
    }

    public function alias(string $from, string $to): void
    {
        $this->aliases[$from] = $to;
    }

    public function has(string $key): bool
    {
        $key = $this->aliases[$key] ?? $key;
        return isset($this->types[$key]);
    }

    /**
     * Resolve a field type by key. Unknown keys fall back to the `string` type.
     */
    public function get(string $key): FieldType
    {
        $key = $this->aliases[$key] ?? $key;

        return $this->types[$key]
            ?? $this->types['string']
            ?? throw new \RuntimeException('No field type registered for "' . $key . '" and no "string" fallback.');
    }
}
