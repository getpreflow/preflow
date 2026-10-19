<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * A record's values for one locale: translatable fields follow the fallback
 * chain, shared fields read their (tolerated) plain value, nested data is
 * resolved recursively. Read-only view; the record itself is unchanged.
 */
final class LocalizedRecord
{
    /** @var array<string, mixed> */
    private array $values = [];

    /** @var array<string, bool> */
    private array $fallback = [];

    public function __construct(
        private readonly DynamicRecord $record,
        private readonly string $locale,
    ) {
        $type = $record->getType();
        $locales = $type->locales;
        $translatable = array_flip($type->translatableFields());

        foreach ($record->toArray() as $field => $raw) {
            if (isset($translatable[$field])) {
                [$value, $isFallback] = LocaleValue::resolve($raw, $locales, $locale);
                $this->values[$field] = LocaleValue::resolveTree($value, $locales, $locale);
                $this->fallback[$field] = $isFallback;
                continue;
            }
            $this->values[$field] = LocaleValue::resolveTree(LocaleValue::default($raw, $locales), $locales, $locale);
            $this->fallback[$field] = false;
        }
    }

    public function get(string $field): mixed
    {
        return $this->values[$field] ?? null;
    }

    public function isFallback(string $field): bool
    {
        return $this->fallback[$field] ?? false;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function record(): DynamicRecord
    {
        return $this->record;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->values;
    }
}
