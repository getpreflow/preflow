<?php

declare(strict_types=1);

namespace Preflow\Data\Labels;

use Preflow\Data\FieldLabels;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\TypeLabels;
use Preflow\Data\TypeRegistry;
use Preflow\Data\Validation\SelectRules;

/**
 * Names types and fields from what the schema declares, with no admin package: a plain label as
 * it is, a label given per language picked for the locale (then the fallback locale, then
 * English), a translation key (`site.types.job`) looked up through the translate closure when
 * the site has one (the application wires preflow/i18n's; data knows nothing of it), else the
 * declared text. A type without a label is its key capitalised, a field without one its name
 * humanized. Folio's DisplayTypeLabels does the same and adds its own derived keys.
 *
 * @since 0.93.0
 */
final class DeclaredLabels implements TypeLabels, FieldLabels
{
    private const KEY = '/^[a-z][a-z0-9_-]*(\.[a-z0-9_]+)+$/';

    /** @param (\Closure(string, string): ?string)|null $translate (key, locale) to the text, null for an unknown key */
    public function __construct(
        private readonly ?TypeRegistry $types,
        private readonly ?\Closure $translate = null,
        private readonly string $fallbackLocale = 'en',
    ) {}

    public function label(string $type, string $locale): string
    {
        $declared = null;
        try {
            $declared = $this->types?->schema($type)['label'] ?? null;
        } catch (\Throwable) {
            // A type the registry does not know is named by its key.
        }
        $text = is_string($declared) || is_array($declared) ? $this->pick($declared, $locale) : null;

        return $text !== null ? $this->translated($text, $locale) : ucfirst($type);
    }

    public function fieldLabel(TypeFieldDefinition $field, string $locale, ?TypeDefinition $type = null): string
    {
        $declared = $type !== null ? ($field->labels ?: $field->label) : $field->label;
        $text = $declared !== null ? $this->pick($declared, $locale) : null;

        return $text !== null ? $this->translated($text, $locale) : SelectRules::humanize($field->name);
    }

    public function fallbackLocale(): string
    {
        return $this->fallbackLocale;
    }

    private function translated(string $text, string $locale): string
    {
        if ($this->translate !== null && preg_match(self::KEY, $text) === 1) {
            return ($this->translate)($text, $locale) ?? $text;
        }

        return $text;
    }

    /** @param string|array<array-key, mixed> $label */
    private function pick(string|array $label, string $locale): ?string
    {
        if (is_string($label)) {
            return $label;
        }
        foreach ([$locale, $this->fallbackLocale, 'en'] as $candidate) {
            if (isset($label[$candidate]) && is_string($label[$candidate])) {
                return $label[$candidate];
            }
        }
        foreach ($label as $text) {
            if (is_string($text)) {
                return $text;
            }
        }

        return null;
    }
}
