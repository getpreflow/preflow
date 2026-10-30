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
use Preflow\Folio\Field\Types\RichTextFieldType;
use Preflow\Form\FieldRenderer;
use Preflow\I18n\Machine\MachineMarks;

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
 *
 * Next to each fingerprint, _translated_source keeps the default text itself, so a
 * translator can be shown what changed (previousSource()); records stamped before it
 * existed simply have none. Machine-made texts carry a mark in _machine_translated
 * (MachineMarks: the fingerprint of the text as the machine wrote it), so they count as
 * not checked (unchecked()) until a person edits or confirms them.
 */
final class TranslationStatus
{
    /** The record key holding the fingerprints: {locale: {path: fingerprint}}. */
    public const string KEY = '_translated_from';

    /** The record key holding the default texts the translations were made from: {locale: {path: text}}. */
    public const string SOURCES = '_translated_source';

    /** The record key holding the marks of machine-made texts not checked yet (MachineMarks shape). */
    public const string MACHINE = '_machine_translated';

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
                $texts[] = TranslatableText::of($name, $this->label($def, $name), $record->get($name), $locales, format: self::format($type));
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
        $stamped = self::entries($record, self::KEY)[$locale] ?? [];
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

    /**
     * Texts $locale has that are still exactly what the machine wrote: not checked by a person yet.
     *
     * @return list<TranslatableText>
     */
    public function unchecked(TypeDefinition $def, DynamicRecord $record, string $locale): array
    {
        $marks = self::entries($record, self::MACHINE);
        if (($marks[$locale] ?? []) === []) {
            return [];
        }

        return array_values(array_filter(
            $this->texts($def, $record),
            static fn (TranslatableText $t): bool => $t->in($locale) !== null
                && MachineMarks::isUnchecked($marks, $locale, $t->path, $t->in($locale)),
        ));
    }

    /**
     * The default text the translation at $path in $locale was made from, as stored when it was
     * saved or confirmed; null when none was stored (e.g. stamped before sources were kept).
     */
    public function previousSource(DynamicRecord $record, string $locale, string $path): ?string
    {
        return self::entries($record, self::SOURCES)[$locale][$path] ?? null;
    }

    /**
     * The fingerprint stamped for the translation at $path in $locale: of the default text it was
     * made from; null when none was stamped.
     */
    public function stampedFingerprint(DynamicRecord $record, string $locale, string $path): ?string
    {
        return self::entries($record, self::KEY)[$locale][$path] ?? null;
    }

    /**
     * Marks the texts at $paths in $locale as machine-made, with their current texts, so they count
     * as not checked until edited or confirmed. Paths that are no text of the record, or that
     * $locale has no text for, are ignored, as is the default locale (its texts are the sources,
     * never translations). Returns an updated copy; nothing is saved.
     *
     * @param list<string> $paths
     */
    public function markMachine(TypeDefinition $def, DynamicRecord $record, string $locale, array $paths): DynamicRecord
    {
        if ($locale === $def->locales->default) {
            return clone $record;
        }
        $wanted = array_flip(array_filter($paths, 'is_string'));
        $marks = self::entries($record, self::MACHINE);
        foreach ($this->texts($def, $record) as $text) {
            $now = $text->in($locale);
            if (isset($wanted[$text->path]) && $now !== null) {
                $marks = MachineMarks::mark($marks, $locale, $text->path, $now);
            }
        }

        $copy = clone $record;
        self::put($copy, self::MACHINE, $marks);

        return $copy;
    }

    /** The fingerprint of a default text (trimmed). */
    public function fingerprint(?string $defaultText): string
    {
        return substr(hash('sha256', trim($defaultText ?? '')), 0, 16);
    }

    /**
     * Marks the translations in $locale as made from the current default texts ("still
     * correct"): all of them, or those under $pathPrefix (the path itself or below it).
     * Only texts $locale has are confirmed; they take the current default text as their source
     * and count as checked (their machine marks are cleared). Returns an updated copy; nothing is saved.
     */
    public function confirm(TypeDefinition $def, DynamicRecord $record, string $locale, ?string $pathPrefix = null): DynamicRecord
    {
        $default = $def->locales->default;
        $fingerprints = self::entries($record, self::KEY);
        $sources = self::entries($record, self::SOURCES);
        $marks = self::entries($record, self::MACHINE);
        foreach ($this->texts($def, $record) as $text) {
            if ($text->in($locale) === null || ($pathPrefix !== null && !self::under($text->path, $pathPrefix))) {
                continue;
            }
            $fingerprints[$locale][$text->path] = $this->fingerprint($text->in($default));
            $sources = self::withSource($sources, $locale, $text->path, $text->in($default));
            $marks = MachineMarks::clear($marks, $locale, $text->path);
        }

        $copy = clone $record;
        $copy->set(self::KEY, $fingerprints);
        self::put($copy, self::SOURCES, $sources);
        self::put($copy, self::MACHINE, $marks);

        return $copy;
    }

    /**
     * The record as saved in $locale: each translation that changed from $before takes the
     * fingerprint and source of the default text it is saved with ($after's, so a default
     * written in the same request counts), a cleared one loses them, and fingerprints and
     * sources of texts that no longer exist (deleted blocks) are dropped. Saving the default
     * locale stamps nothing. Machine marks of texts changed since the machine wrote them (in
     * any locale) are dropped, so an edit counts as checked and a plain re-save does not.
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
        $fingerprints = self::entries($after, self::KEY);
        $sources = self::entries($after, self::SOURCES);

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
                    $sources = self::withSource($sources, $locale, $path, null);
                } else {
                    $fingerprints[$locale][$path] = $this->fingerprint($text->in($locales->default));
                    $sources = self::withSource($sources, $locale, $path, $text->in($locales->default));
                }
            }
        }

        $marks = MachineMarks::prune(
            self::entries($after, self::MACHINE),
            static fn (string $markedLocale, string $path): ?string => isset($texts[$path]) ? $texts[$path]->in($markedLocale) : null,
        );

        self::put($after, self::KEY, self::existing($fingerprints, $texts));
        self::put($after, self::SOURCES, self::existing($sources, $texts));
        self::put($after, self::MACHINE, $marks);

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

    /** The markup a field type's text carries: rich text is HTML, everything else plain. */
    public static function format(FieldType $type): string
    {
        return $type instanceof RichTextFieldType ? 'html' : 'plain';
    }

    /**
     * A stored {locale: {path: string}} map: fingerprints, sources or machine marks (SQL drivers
     * return them as JSON text).
     *
     * @return array<string, array<string, string>>
     */
    private static function entries(DynamicRecord $record, string $key): array
    {
        $raw = $record->get($key);
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

    /**
     * Sets a {locale: {path: string}} map on the record, unless there is nothing to store and
     * nothing stored (records that never had a translation keep no empty key).
     *
     * @param array<string, array<string, string>> $entries
     */
    private static function put(DynamicRecord $record, string $key, array $entries): void
    {
        if ($entries !== [] || $record->get($key) !== null) {
            $record->set($key, $entries);
        }
    }

    /**
     * Only the entries of paths that are still texts of the record, without empty locales.
     *
     * @param array<string, array<string, string>> $entries
     * @param array<string, TranslatableText> $texts by path
     * @return array<string, array<string, string>>
     */
    private static function existing(array $entries, array $texts): array
    {
        foreach ($entries as $locale => $paths) {
            $paths = array_intersect_key($paths, $texts);
            if ($paths === []) {
                unset($entries[$locale]);
            } else {
                $entries[$locale] = $paths;
            }
        }

        return $entries;
    }

    /**
     * $sources with the default text $path in $locale was made from; none when it is empty.
     *
     * @param array<string, array<string, string>> $sources
     * @return array<string, array<string, string>>
     */
    private static function withSource(array $sources, string $locale, string $path, ?string $defaultText): array
    {
        if ($defaultText === null) {
            unset($sources[$locale][$path]);
            if (($sources[$locale] ?? null) === []) {
                unset($sources[$locale]);
            }
        } else {
            $sources[$locale][$path] = $defaultText;
        }

        return $sources;
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
