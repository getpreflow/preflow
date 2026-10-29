<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeDefinition;
use Preflow\Folio\Field\FieldType;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\HandlesUpload;
use Preflow\Folio\Field\ReportsTranslatableTexts;
use Preflow\Folio\Field\StructuredFieldType;
use Preflow\Folio\Field\Types\AssetFieldType;
use Preflow\Folio\Field\Types\BooleanFieldType;
use Preflow\Folio\Field\Types\EnvFieldType;
use Preflow\Form\FieldRenderer;

/**
 * Which translatable texts of a record a language misses or holds outdated.
 *
 * The texts are the record's translatable fields plus the texts every field type
 * implementing ReportsTranslatableTexts reports (block leaves among them). Missing:
 * the default locale has text, the locale has none. Outdated: the record's
 * _translated_from ({locale: {path: fingerprint}}) holds the fingerprint of the
 * default text the translation was made from, and the default text has changed
 * since. A translation without a fingerprint is never outdated. Fingerprints are
 * stamped when a translation is saved (stamp(), from DraftRecordFactory) and
 * confirmed by an editor (confirm()).
 */
final class TranslationStatus
{
    /** The record key holding the fingerprints: {locale: {path: fingerprint}}. */
    public const string KEY = '_translated_from';

    public function __construct(
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly ?DisplayLabels $labels = null,
    ) {}

    /**
     * Every translatable text of the record; none for a single-locale app.
     *
     * @return list<TranslatableText>
     */
    public function texts(TypeDefinition $def, DynamicRecord $record): array
    {
        $locales = $def->locales;
        if (!$locales->isMultilingual()) {
            return [];
        }
        $translatable = array_flip($def->translatableFields());

        $texts = [];
        foreach ($def->fields as $name => $field) {
            // A field an editor cannot fill in on the form has no translation to wait for.
            if ($name === $def->idField || ($field->config['readonly'] ?? false) === true || ($field->config['hidden'] ?? false) === true) {
                continue;
            }
            $type = $this->fieldTypes->get($field->type);
            if ($type instanceof ReportsTranslatableTexts) {
                $label = $this->label($def, $name);
                foreach ($type->translatableTexts($type->fromStorage($record->get($name)), $field->config, $locales) as $text) {
                    $texts[] = $text->within($name, $label);
                }
                continue;
            }
            if (isset($translatable[$name]) && self::holdsText($type)) {
                $texts[] = TranslatableText::of($name, $this->label($def, $name), $record->get($name), $locales);
            }
        }

        return $texts;
    }

    /**
     * Texts the default locale has and $locale has not.
     *
     * @return list<TranslatableText>
     */
    public function missing(TypeDefinition $def, DynamicRecord $record, string $locale): array
    {
        $default = $def->locales->default;
        if ($locale === $default) {
            return [];
        }

        return array_values(array_filter(
            $this->texts($def, $record),
            static fn (TranslatableText $t): bool => $t->in($default) !== null && $t->in($locale) === null,
        ));
    }

    /**
     * Texts $locale has, translated from a default text that has changed since.
     *
     * @return list<TranslatableText>
     */
    public function outdated(TypeDefinition $def, DynamicRecord $record, string $locale): array
    {
        $default = $def->locales->default;
        $stamped = self::fingerprints($record)[$locale] ?? [];
        if ($locale === $default || $stamped === []) {
            return [];
        }

        return array_values(array_filter(
            $this->texts($def, $record),
            fn (TranslatableText $t): bool => $t->in($locale) !== null
                && isset($stamped[$t->path])
                && $stamped[$t->path] !== $this->fingerprint($t->in($default)),
        ));
    }

    /** The fingerprint of a default text (trimmed). */
    public function fingerprint(?string $defaultText): string
    {
        return substr(hash('sha256', trim($defaultText ?? '')), 0, 16);
    }

    /**
     * Marks the translations in $locale as made from the current default texts ("still
     * correct"): all of them, or those under $pathPrefix (the path itself or below it).
     * Only texts $locale has are confirmed. Returns an updated copy; nothing is saved.
     */
    public function confirm(TypeDefinition $def, DynamicRecord $record, string $locale, ?string $pathPrefix = null): DynamicRecord
    {
        $default = $def->locales->default;
        $fingerprints = self::fingerprints($record);
        foreach ($this->texts($def, $record) as $text) {
            if ($text->in($locale) === null || ($pathPrefix !== null && !self::under($text->path, $pathPrefix))) {
                continue;
            }
            $fingerprints[$locale][$text->path] = $this->fingerprint($text->in($default));
        }

        $copy = clone $record;
        $copy->set(self::KEY, $fingerprints);

        return $copy;
    }

    /**
     * The record as saved in $locale: each translation that changed from $before takes the
     * fingerprint of the default text it is saved with ($after's, so a default written in
     * the same request counts), a cleared one loses it, and fingerprints of texts that no
     * longer exist (deleted blocks) are dropped. Saving the default locale stamps nothing.
     * Sets them on $after and returns it.
     */
    public function stamp(TypeDefinition $def, DynamicRecord $before, DynamicRecord $after, string $locale): DynamicRecord
    {
        $locales = $def->locales;
        if (!$locales->isMultilingual()) {
            return $after;
        }

        $texts = [];
        foreach ($this->texts($def, $after) as $text) {
            $texts[$text->path] = $text;
        }
        $fingerprints = self::fingerprints($after);

        if ($locale !== $locales->default && $locales->has($locale)) {
            $previous = [];
            foreach ($this->texts($def, $before) as $text) {
                $previous[$text->path] = $text->in($locale);
            }
            foreach ($texts as $path => $text) {
                $now = $text->in($locale);
                if (trim($now ?? '') === trim($previous[$path] ?? '')) {
                    continue;
                }
                if ($now === null) {
                    unset($fingerprints[$locale][$path]);
                } else {
                    $fingerprints[$locale][$path] = $this->fingerprint($text->in($locales->default));
                }
            }
        }

        foreach ($fingerprints as $stampedLocale => $entries) {
            $entries = array_intersect_key($entries, $texts);
            if ($entries === []) {
                unset($fingerprints[$stampedLocale]);
            } else {
                $fingerprints[$stampedLocale] = $entries;
            }
        }

        if ($fingerprints !== [] || $after->get(self::KEY) !== null) {
            $after->set(self::KEY, $fingerprints);
        }

        return $after;
    }

    /**
     * Whether a field type's translatable value is text to translate: not uploads or
     * assets (shared across locales), booleans (false is an answer, not a gap), structured values or environment variable names.
     */
    public static function holdsText(FieldType $type): bool
    {
        return !$type instanceof StructuredFieldType
            && !$type instanceof AssetFieldType
            && !$type instanceof BooleanFieldType
            && !$type instanceof HandlesUpload
            && !$type instanceof EnvFieldType;
    }

    /**
     * The stored fingerprints (SQL drivers return them as JSON text).
     *
     * @return array<string, array<string, string>>
     */
    private static function fingerprints(DynamicRecord $record): array
    {
        $raw = $record->get(self::KEY);
        if (is_string($raw) && $raw !== '') {
            $raw = json_decode($raw, true);
        }

        $out = [];
        foreach (is_array($raw) ? $raw : [] as $locale => $entries) {
            if (!is_string($locale) || !is_array($entries)) {
                continue;
            }
            $out[$locale] = array_filter($entries, static fn ($f, $path): bool => is_string($f) && is_string($path), ARRAY_FILTER_USE_BOTH);
        }

        return $out;
    }

    private static function under(string $path, string $prefix): bool
    {
        return $path === $prefix || str_starts_with($path, $prefix . '.');
    }

    private function label(TypeDefinition $def, string $field): string
    {
        return $this->labels?->field($def->key, $field)
            ?? $def->fields[$field]->label
            ?? (new FieldRenderer())->humanize($field);
    }
}
