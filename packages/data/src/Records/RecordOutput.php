<?php

declare(strict_types=1);

namespace Preflow\Data\Records;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\FieldVisibility;
use Preflow\Data\LocaleValue;
use Preflow\Data\Locales;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\ListsReferences;
use Preflow\Data\Values\ListsVisibleReferences;

/**
 * A record of any type as an API answers with it (a REST resource, a GraphQL object): its id, its
 * fields in their output shapes and the keys the packages' output parts add (RecordParts). The
 * same answer on every storage driver and for every way the value was stored (JSON text on SQL, a
 * number as numeric text, tags as a comma string, rich text a script imported unclean):
 *
 *     {"_id": "j1",
 *      "title": {"en": "Gardener", "de": "Gärtnerin"},
 *      "body": {"en": "<p>Work outside.</p>", "de": "<p>Arbeit draussen.</p>"},
 *      "hours": 40, "remote": false, "starts": "2026-12-01",
 *      "tags": ["garden", "outdoor"], "company": "c1", "photos": ["i1", "i2"],
 *      "video": null,
 *      "_layout": {…}, "_areas": {…}, "_unplaced": {},
 *      "_media": {"i1": {"url": "/media/…", "kind": "image", …}}}
 *
 * - "_id": the record's id, as a string.
 * - Each field of the type, in declared order, its value through the field type's FieldValue
 *   (FieldKinds::valueOf(): toOutput()), in the shape the description names in its "value"
 *   (Describe\Description; "raw": as stored, for a type that registered no value).
 * - A translatable field (on a site with more than one language: the description's
 *   "translatable") is an object of each of the type's languages => its value, null where none is
 *   stored; with ReadOptions::$locale it is that language's value, following the type's fallback
 *   chain (LocaleValue::resolve(): the language, the fallback, the default). Inside blocks a
 *   translatable block field keeps its map (shapes "blocks", "area_map").
 * - A field its show_when hides is null (FieldVisibility); its stored value is kept.
 * - A field declared "hidden": true (a password hash, a code's payload: what Folio's form never
 *   shows) is never read: its key is not in the output, and the description leaves it out too.
 * - Relations and media fields are ids (shapes "id", "id_list"); the site builds links, and the
 *   media package adds URLs and sizes only when asked (ReadOptions(with: ['media'])).
 * - Rich text is cleaned again on the way out (the core cleaner), whatever the driver holds.
 * - The output parts' keys follow, each starting with "_" (OutputPart).
 *
 * The output is JSON-safe plain data; an empty map is an object ({}). Reading never writes.
 *
 * Versioning: VERSION is 1. A new key may be added within a version (a new part's key, a new
 * field shape named in the description's "values"); a key that changes meaning or goes, or a
 * shape that changes, bumps it. The shapes named in "values" are frozen in version 1.
 *
 * @since 0.93.0
 */
final readonly class RecordOutput
{
    public const int VERSION = 1;

    public function __construct(
        private DataManager $dm,
        private RecordParts $parts,
    ) {}

    /**
     * @return array<string, mixed> "_id", the fields and the parts' keys
     * @throws \LogicException a part key that cannot work (RecordParts::outputKeys()), or one a part reads but did not name
     */
    public function toArray(DynamicRecord $record, ReadOptions $options = new ReadOptions()): array
    {
        $def = $record->getType();
        $stored = $record->toArray();
        $hidden = array_flip(FieldVisibility::hiddenFieldsOf($def, $stored));
        $translatable = array_flip($def->translatableFields());

        $out = [RecordParts::ID => $record->getId()];
        foreach ($def->fields as $name => $field) {
            if (self::declaredHidden($field)) {
                continue;
            }
            $out[$name] = isset($hidden[$name])
                ? null
                : $this->value($field, $stored[$name] ?? null, $options, $def->locales, isset($translatable[$name]));
        }

        foreach (self::byPart($this->parts->outputKeys($def)) as [$part, $keys]) {
            foreach ($part->read($record, $options) as $key => $value) {
                if (!in_array($key, $keys, true)) {
                    throw new \LogicException(sprintf('The output part %s reads the key "%s" for the type "%s", which its keys() does not name: name it there.', $part::class, $key, $def->key));
                }
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * One field's stored value as toArray() reads it out (without show_when, which needs the
     * whole record). With $locales of more than one language a translatable field's value is its
     * locale map, read per language (or the language ReadOptions::$locale asks for); without, or
     * on a site with one language, the value is one value (a map stored while the site had more
     * languages: its default language's value).
     */
    public function field(TypeFieldDefinition $field, mixed $stored, ReadOptions $options, ?Locales $locales = null): mixed
    {
        $locales ??= Locales::single('en');

        return $this->value($field, $stored, $options, $locales, $field->translatable && $locales->isMultilingual());
    }

    /**
     * The records the record's fields reference (ListsReferences: relations, media fields, the
     * blocks and columns inside a blocks field) and those the output parts' keys reference
     * (ListsRecordReferences: a page's areas), by target type, each id once. A field its
     * show_when hides references nothing (it reads null), also inside blocks and columns
     * (ListsVisibleReferences): only what the output shows.
     *
     * @return array<string, list<string>> target type => ids
     */
    public function references(DynamicRecord $record): array
    {
        $def = $record->getType();
        $stored = $record->toArray();
        $hidden = array_flip(FieldVisibility::hiddenFieldsOf($def, $stored));
        $translatable = array_flip($def->translatableFields());
        $kinds = $this->dm->fieldKinds();

        $found = [];
        foreach ($def->fields as $name => $field) {
            $value = $kinds->valueOf($field);
            if (isset($hidden[$name]) || self::declaredHidden($field) || !$value instanceof ListsReferences) {
                continue;
            }
            $raw = $stored[$name] ?? null;
            $values = isset($translatable[$name]) ? array_values(LocaleValue::map($raw, $def->locales)) : [LocaleValue::default($raw, $def->locales)];
            foreach ($values as $one) {
                $references = $value instanceof ListsVisibleReferences ? $value->visibleReferences($one, $field) : $value->references($one, $field);
                foreach ($references as $type => $ids) {
                    $found[$type] = [...$found[$type] ?? [], ...$ids];
                }
            }
        }
        foreach (self::byPart($this->parts->outputKeys($def)) as [$part]) {
            if ($part instanceof ListsRecordReferences) {
                foreach ($part->references($record) as $type => $ids) {
                    $found[$type] = [...$found[$type] ?? [], ...$ids];
                }
            }
        }

        return array_map(static fn (array $ids): array => array_values(array_unique($ids)), $found);
    }

    private function value(TypeFieldDefinition $field, mixed $stored, ReadOptions $options, Locales $locales, bool $translatable): mixed
    {
        $value = $this->dm->fieldKinds()->valueOf($field);
        if (!$translatable) {
            return $value->toOutput(LocaleValue::default($stored, $locales), $field);
        }
        if ($options->locale !== null) {
            return $value->toOutput(LocaleValue::resolve($stored, $locales, $options->locale)[0], $field);
        }
        $map = LocaleValue::map($stored, $locales);
        $out = [];
        foreach ($locales->available as $locale) {
            $out[$locale] = $value->toOutput($map[$locale] ?? null, $field);
        }

        return $out;
    }

    /**
     * The parts of a type's keys, each once, with the keys it named, in the parts' order.
     *
     * @param array<string, OutputPart> $keys
     * @return list<array{0: OutputPart, 1: list<string>}>
     */
    private static function byPart(array $keys): array
    {
        $parts = [];
        foreach ($keys as $key => $part) {
            $at = spl_object_id($part);
            $parts[$at] ??= [$part, []];
            $parts[$at][1][] = $key;
        }

        return array_values($parts);
    }

    /**
     * Whether the field is declared "hidden": true: never read out, never written through the
     * write class (RecordInput), not in the description. @internal
     */
    public static function declaredHidden(TypeFieldDefinition $field): bool
    {
        return ($field->config['hidden'] ?? false) === true;
    }
}
