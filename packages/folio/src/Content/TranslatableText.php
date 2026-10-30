<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\LocaleValue;
use Preflow\Data\Locales;

/**
 * One translatable text of a record: a plain translatable field ("title"), a block
 * leaf ("sections.{uid}.headline", nested "sections.{uid}.{child}.{cuid}.{leaf}")
 * or a text a custom field type reports ("{field}.{its own path}"). Paths are
 * stable across saves and reordering; the label is for people
 * ("Sections › Hero › Headline"). A structured field may report a kind ("quiz-option",
 * "feedback") so tuning can target it; the format ("plain", "html", "markdown") tells a
 * translator which markup the text carries and must keep.
 */
final readonly class TranslatableText
{
    /** @param array<string, ?string> $values the text per available locale, null when empty */
    public function __construct(
        public string $path,
        public string $label,
        public array $values,
        public ?string $kind = null,
        public string $format = 'plain',
    ) {}

    /** A text from a stored value (a locale map, or a plain value as the default locale's text). */
    public static function of(string $path, string $label, mixed $raw, Locales $locales, ?string $kind = null, string $format = 'plain'): self
    {
        $map = LocaleValue::map($raw, $locales);
        $values = [];
        foreach ($locales->available as $locale) {
            $values[$locale] = self::text($map[$locale] ?? null);
        }

        return new self($path, $label, $values, $kind, $format);
    }

    /** A stored value as text: null when empty (null, '' or only whitespace). */
    public static function text(mixed $value): ?string
    {
        $text = match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? '1' : null,
            is_array($value) && $value !== [] => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
            default => null,
        };

        return $text === null || trim($text) === '' ? null : $text;
    }

    public function in(string $locale): ?string
    {
        return $this->values[$locale] ?? null;
    }

    /** The same text under "{prefix}.{path}", labelled "{label} › {its label}" (kind and format kept). */
    public function within(string $pathPrefix, string $labelPrefix): self
    {
        return new self($pathPrefix . '.' . $this->path, $labelPrefix . ' › ' . $this->label, $this->values, $this->kind, $this->format);
    }
}
