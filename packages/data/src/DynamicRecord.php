<?php

declare(strict_types=1);

namespace Preflow\Data;

final class DynamicRecord
{
    private array $data;

    public function __construct(
        private readonly TypeDefinition $type,
        array $data = [],
    ) {
        $this->data = $data;
    }

    public function get(string $field): mixed
    {
        return $this->data[$field] ?? null;
    }

    public function set(string $field, mixed $value): void
    {
        $this->data[$field] = $value;
    }

    public function getId(): ?string
    {
        return $this->data[$this->type->idField] ?? null;
    }

    public function getType(): TypeDefinition
    {
        return $this->type;
    }

    public function toArray(): array
    {
        $out = $this->data;
        foreach ($this->type->transformers as $field => $transformer) {
            if (array_key_exists($field, $out)) {
                $out[$field] = $transformer->toStorage($out[$field]);
            }
        }
        return $out;
    }

    public static function fromArray(TypeDefinition $type, array $data): self
    {
        foreach ($type->transformers as $field => $transformer) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $transformer->fromStorage($data[$field]);
            }
        }
        return new self($type, $data);
    }
}
