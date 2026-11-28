<?php

declare(strict_types=1);

namespace Preflow\Data\Records;

use Preflow\Core\Context\Contexts;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\FieldVisibility;
use Preflow\Data\LocaleValue;
use Preflow\Data\TypeAction;
use Preflow\Data\TypeActionRefused;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\MergesInput;
use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidationResult;
use Preflow\Validation\ValidationRule;
use Preflow\Validation\ValidatorFactory;

/**
 * A write of any type (a REST body, a GraphQL mutation's input) merged onto a record under the
 * rules Folio's form uses, without Folio: the body is the input shape RecordOutput reads out,
 *
 *     {"_id": "j1", "title": {"de": "Gärtnerin"}, "hours": 40, "tags": ["garden"], "photos": ["i3"],
 *      "_layout": "standard", "_areas": {"main": […]}}
 *
 * and it takes:
 * - "_id": on create the record's id when given (else none: the caller's save, or
 *   RecordWriter::create(), gives one); on update it may only repeat the record's id.
 * - Each field it names, converted to what saveType() stores by the field type's value
 *   (FieldKinds::valueOf(): FieldValue::toStored(), or MergesInput::merge() onto the stored
 *   value where the value merges partial input, such as an areas field). A field the body does
 *   not name keeps its stored value: a body may be partial (a PATCH).
 * - A translatable field (on a site with more than one language) given as an object whose keys
 *   all look like language codes ({"de": …}) replaces only the languages it names; given any
 *   other value, that value is the language $locale names (default: the type's default
 *   language). A field whose value is itself such an object is given per language.
 * - A field its show_when hides keeps its stored value whatever the body gives
 *   (FieldVisibility::keepStored(), decided on the new values, as Folio's form does), and what
 *   its value's merge refuses does not refuse the body; a new record keeps nothing for it.
 * - The keys the packages' input parts take (RecordParts::inputKeys(): the blocks package's
 *   "_layout" and "_areas"), each merged by its part.
 *
 * Refused (ValidationException, all problems of the body in one, keyed as Folio's form keys
 * them; nothing merged): a key that is no field and no part's key ("<key>", validation.record.
 * unknown_field), a language the type lacks ("<field>.<locale>", validation.record.
 * unknown_locale), a field whose config has "readonly": true given another value than its
 * stored one ("<field>", validation.record.readonly; its stored value given back, as a client
 * read it, is kept), an "_id" other than the record's on update or one that is no id ("_id",
 * validation.record.id, validation.record.id_shape), and what a merging value or an input part
 * refuses (keyed by them). Types and fields are named by DataManager::labels(). Silently
 * dropping a client's data would lose it, so nothing is ignored.
 *
 * Everything else is the core rules' to judge, on save: required, a select's options, a link's
 * address, the layouts' limits are DataManager::saveType()'s (or publishing's RecordWriter's)
 * rules, the same Folio's form runs: there is no second set of rules here. Whether the type
 * allows the write is asked first (TypeDefinition::assertAllows(): TypeActionRefused); who may
 * write is the caller's (TypePermissions).
 *
 * Neither method saves: the caller saves the record it gets with saveType() or RecordWriter.
 *
 * @since 0.93.0
 */
final readonly class RecordInput
{
    /** A language code as a translatable field's map keys it ("en", "de-CH"); as LocaleValue reads maps. */
    private const string LOCALE = '/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/';

    public function __construct(
        private DataManager $dm,
        private RecordParts $parts,
        private ValidatorFactory $validators,
    ) {}

    /**
     * A new record of the type from the body; not saved.
     *
     * @param array<string, mixed> $input the body
     * @param string|null $locale the language a translatable field given as one value is in (default: the type's default)
     * @throws TypeActionRefused the type creates nothing ("creatable": false, "readonly": true)
     * @throws ValidationException the body's refusals (see the class)
     */
    public function create(TypeDefinition $def, array $input, ?string $locale = null): DynamicRecord
    {
        $def->assertAllows(TypeAction::Create);

        return DynamicRecord::fromArray($def, $this->merged($def, [], $input, $locale, null));
    }

    /**
     * The record with the body merged into it (the given record, changed and returned); not saved.
     * Refused, the record is left as it was.
     *
     * @param array<string, mixed> $input the body: only what changes
     * @param string|null $locale the language a translatable field given as one value is in (default: the type's default)
     * @throws TypeActionRefused the type is read-only
     * @throws ValidationException the body's refusals (see the class)
     */
    public function update(DynamicRecord $record, array $input, ?string $locale = null): DynamicRecord
    {
        $def = $record->getType();
        $def->assertAllows(TypeAction::Update);

        $values = $this->merged($def, $record->toArray(), $input, $locale, $record->getId() ?? '');
        $merged = DynamicRecord::fromArray($def, $values);
        foreach (array_keys($values) as $key) {
            $record->set((string) $key, $merged->get((string) $key));
        }

        return $record;
    }

    /**
     * @param array<string, mixed> $stored the record's stored values ([] for a new one)
     * @param array<string, mixed> $input
     * @param string|null          $id     the record's id on update; null on create
     * @return array<string, mixed> the values to store
     * @throws ValidationException
     */
    private function merged(TypeDefinition $def, array $stored, array $input, ?string $locale, ?string $id): array
    {
        $locale ??= $def->locales->default;
        $partKeys = $this->parts->inputKeys($def);
        $translatable = array_flip($def->translatableFields());
        $values = $stored;
        /** @var array<string, Message> $problems */
        $problems = [];
        /** @var array<string, list<string>> $refused what a merging value or a part refused, in its words */
        $refused = [];
        /** @var array<string, array<string, list<string>>> $refusedBy what each field's merge refused */
        $refusedBy = [];

        foreach ($input as $key => $given) {
            $key = (string) $key;
            if ($key === RecordParts::ID || $key === $def->idField) {
                $this->id($def, $key, $given, $id, $values, $problems);
                continue;
            }
            if (isset($partKeys[$key])) {
                continue;
            }
            $field = $def->fields[$key] ?? null;
            // A field declared "hidden": true is no field to an API (RecordOutput never reads it, the
            // description leaves it out): any value is refused, its stored one too, so a client
            // cannot test a guess of what it holds; the stored value is kept.
            if ($field === null || RecordOutput::declaredHidden($field)) {
                $problems[$key] = new Message('validation.record.unknown_field', ['type' => $this->typeLabel($def), 'field' => $key]);
                continue;
            }
            if (($field->config['readonly'] ?? false) === true) {
                // Given back as stored (a client sends what it read): kept, no change; another value is refused.
                if (!$this->unchanged($def, $field, $stored[$key] ?? null, $given, $locale, isset($translatable[$key]))) {
                    $problems[$key] = new Message('validation.record.readonly', ['field' => $this->dm->labels()->field($field, self::uiLocale(), $def)]);
                }
                continue;
            }
            try {
                $values[$key] = isset($translatable[$key])
                    ? $this->translated($def, $field, $stored[$key] ?? null, $given, $locale, $problems)
                    : $this->value($field, $stored[$key] ?? null, $given);
            } catch (ValidationException $e) {
                $refusedBy[$key] = $e->errors();
            }
        }
        // A field its show_when hides keeps its stored value (below), so what its value's merge
        // refused is no problem of this write: Folio's form does not build a hidden field either.
        $hidden = array_flip(FieldVisibility::hiddenFieldsOf($def, $values));
        foreach ($refusedBy as $key => $errors) {
            if (!isset($hidden[$key])) {
                $refused += $errors;
            }
        }

        // Each part the body gives keys of merges them onto the values so far, once; only the keys
        // the body gives are taken from what it returns (a part changes nothing else).
        $byPart = [];
        foreach ($partKeys as $key => $part) {
            if (array_key_exists($key, $input)) {
                $byPart[spl_object_id($part)] ??= [$part, []];
                $byPart[spl_object_id($part)][1][] = $key;
            }
        }
        foreach ($byPart as [$part, $keys]) {
            try {
                $after = $part->merge($def, $values, $input);
            } catch (ValidationException $e) {
                $refused += $e->errors();
                continue;
            }
            foreach ($keys as $key) {
                if (array_key_exists($key, $after)) {
                    $values[$key] = $after[$key];
                }
            }
        }

        $this->refuse($problems, $refused);

        return FieldVisibility::keepStored($def, $values, $stored);
    }

    /**
     * The id: taken on create, only the record's own on update.
     *
     * @param array<string, mixed>   $values
     * @param array<string, Message> $problems
     */
    private function id(TypeDefinition $def, string $key, mixed $given, ?string $id, array &$values, array &$problems): void
    {
        if (!(is_string($given) && $given !== '') && !is_int($given)) {
            $problems[$key] = new Message('validation.record.id_shape');

            return;
        }
        if ($id === null) {
            $values[$def->idField] = $given;

            return;
        }
        if ((string) $given !== $id) {
            $problems[$key] = new Message('validation.record.id', ['id' => $id]);
        }
    }

    /** One locale's (or a plain field's) value as it is stored after the write. */
    private function value(TypeFieldDefinition $field, mixed $stored, mixed $given): mixed
    {
        $value = $this->dm->fieldKinds()->valueOf($field);

        return $value instanceof MergesInput ? $value->merge($stored, $given, $field) : $value->toStored($given, $field);
    }

    /**
     * A translatable field's locale map after the write: the languages given replace theirs.
     *
     * @param array<string, Message> $problems
     * @return array<string, mixed>
     */
    private function translated(TypeDefinition $def, TypeFieldDefinition $field, mixed $stored, mixed $given, string $locale, array &$problems): array
    {
        $map = LocaleValue::map($stored, $def->locales);
        $byLocale = self::isLocaleObject($given) ? $given : [$locale => $given];
        $refused = [];
        foreach ($byLocale as $one => $value) {
            $one = (string) $one;
            if (!$def->locales->has($one)) {
                $problems[$field->name . '.' . $one] = new Message('validation.record.unknown_locale', [
                    'type' => $this->typeLabel($def),
                    'locale' => $one,
                    'locales' => implode(', ', $def->locales->available),
                ]);
                continue;
            }
            try {
                $map[$one] = $this->value($field, $map[$one] ?? null, $value);
            } catch (ValidationException $e) {
                // A merging value's keys under the language, as the core keys a translatable field's.
                foreach ($e->errors() as $key => $messages) {
                    $refused[$key === $field->name ? $field->name . '.' . $one : $key] = $messages;
                }
            }
        }
        if ($refused !== []) {
            throw new ValidationException(new ValidationResult($refused));
        }

        return $map;
    }

    /**
     * Whether $given is the field's stored value: compared as read out (FieldValue::toOutput()),
     * so a value in its output shape (tags as a list, stored as JSON text) is the same; a
     * translatable field per language given, an object of languages or one value of $locale.
     */
    private function unchanged(TypeDefinition $def, TypeFieldDefinition $field, mixed $stored, mixed $given, string $locale, bool $translatable): bool
    {
        $value = $this->dm->fieldKinds()->valueOf($field);
        $same = static function (mixed $was, mixed $now) use ($value, $field): bool {
            $read = $value->toOutput($was, $field);
            // As the client read it: the same JSON (objects and empty maps alike, keys as given), whatever
            // shape toStored() does not take back (areas carry "resolved", "unplaced").
            if (self::canonical($read) === self::canonical($now)) {
                return true;
            }

            return $read === $value->toOutput($value->toStored($now, $field), $field);
        };
        if (!$translatable) {
            return $same(LocaleValue::default($stored, $def->locales), $given);
        }
        $map = LocaleValue::map($stored, $def->locales);
        foreach (self::isLocaleObject($given) ? $given : [$locale => $given] as $one => $now) {
            if (!$same($map[(string) $one] ?? null, $now)) {
                return false;
            }
        }

        return true;
    }

    /** A value as JSON text with an empty object and an empty list alike, keys in the order given. */
    private static function canonical(mixed $value): string
    {
        $plain = static function (mixed $v) use (&$plain): mixed {
            if ($v instanceof \stdClass) {
                $v = (array) $v;
            }

            return is_array($v) ? array_map($plain, $v) : $v;
        };

        return json_encode($plain($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) ?: '';
    }

    /** An object whose keys all look like language codes: a value per language. */
    private static function isLocaleObject(mixed $given): bool
    {
        if (!is_array($given) || $given === [] || array_is_list($given)) {
            return false;
        }
        foreach (array_keys($given) as $key) {
            if (!is_string($key) || preg_match(self::LOCALE, $key) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function typeLabel(TypeDefinition $def): string
    {
        return $this->dm->labels()->type($def->key, self::uiLocale());
    }

    /** The language messages name things in: the admin's interface language, else the render's, else English. */
    private static function uiLocale(): string
    {
        $context = Contexts::peek();

        return $context === null ? 'en' : ($context->surface->admin ? ($context->surface->uiLocale ?? $context->locale) : $context->locale);
    }

    /**
     * Throws the body's refusals in one ValidationException: its own in the validator's language
     * (the render's, else English), then those a merging value or a part gave.
     *
     * @param array<string, Message>      $problems
     * @param array<string, list<string>> $refused
     */
    private function refuse(array $problems, array $refused): void
    {
        if ($problems === [] && $refused === []) {
            return;
        }
        $errors = [];
        if ($problems !== []) {
            $rules = [];
            foreach ($problems as $key => $message) {
                $rules[$key] = [new class ($message) implements ValidationRule {
                    public function __construct(private readonly Message $message) {}

                    public function validate(mixed $value, ValidationContext $context): Message
                    {
                        return $this->message;
                    }
                }];
            }
            $errors = $this->validators->make($rules, [])->validate()->errors();
        }

        throw new ValidationException(new ValidationResult($errors + $refused));
    }
}
