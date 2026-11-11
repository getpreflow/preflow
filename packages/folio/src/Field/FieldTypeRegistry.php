<?php

declare(strict_types=1);

namespace Preflow\Folio\Field;

final class FieldTypeRegistry
{
    /** @var array<string, FieldType> */
    private array $types = [];

    /** @var array<string, string> */
    private array $aliases = [];

    /** @var list<\Closure(FieldType): void> */
    private array $listeners = [];

    /** A type registered with a key already taken replaces the earlier one. */
    public function register(FieldType $type): void
    {
        $this->types[$type->key()] = $type;
        foreach ($this->listeners as $listener) {
            $listener($type);
        }
    }

    /**
     * Calls $listener for every type registered from now on. Folio tells the data layer each type's
     * field kinds this way, so a type a provider registers after Folio booted is treated like one
     * registered before (since 0.66).
     *
     * @param \Closure(FieldType): void $listener
     */
    public function whenRegistered(\Closure $listener): void
    {
        $this->listeners[] = $listener;
    }

    public function alias(string $from, string $to): void
    {
        $this->aliases[$from] = $to;
    }

    /** @return array<string, FieldType> by key */
    public function all(): array
    {
        return $this->types;
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
