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

    public function localized(string $locale): LocalizedRecord
    {
        return new LocalizedRecord($this, $locale);
    }

    /**
     * Locales this record is published in (system key _locales). Missing means
     * the default locale only; the default is always included and first.
     *
     * @return list<string>
     */
    public function availableLocales(): array
    {
        $locales = $this->type->locales;
        $stored = $this->data['_locales'] ?? [];
        if (is_string($stored)) {
            $stored = json_decode($stored, true) ?? []; // SQL drivers return lists as JSON text
        }
        $listed = array_values(array_filter(
            (array) $stored,
            static fn ($l) => is_string($l) && $locales->has($l),
        ));

        return array_values(array_unique([$locales->default, ...$listed]));
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
