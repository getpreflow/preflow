<?php

declare(strict_types=1);

namespace Preflow\Data;

use Preflow\Data\Schema\ColumnKind;
use Preflow\Data\Validation\MaxChars;
use Preflow\Data\Values\Core\RelationValue;
use Preflow\Data\Values\FieldValue;
use Preflow\Data\Values\RawValue;

/**
 * Which field types count as free text and which as a relation, which column kind each
 * type is stored as, (since 0.91.0) which checks a type brings itself and (since 0.93.0) what its
 * value means (FieldValue: input to stored, stored to output, the output's shape). Extensions
 * register their own types. DataManager::fieldKinds() starts with the core types' values and
 * date's stored rule (CoreValues); a FieldKinds built by hand starts with none.
 */
final class FieldKinds
{
    /** Length of a unique or indexed string column without an own "length" (fits a utf8mb4 index). */
    public const DEFAULT_LENGTH = 191;

    /** @var array<string, true> */
    private array $text = ['string' => true, 'text' => true];

    /** @var array<string, true> */
    private array $relation = ['relation' => true];

    /** @var array<string, true> */
    private array $list = [];

    /** @var array<string, \Closure(array<string, mixed>): bool> field type => whether a field of its config holds a list */
    private array $listWhen;

    /** @var array<string, ColumnKind|\Closure(array<string, mixed>): ColumnKind> */
    private array $columns;

    /** @var array<string, \Closure(array<string, mixed>): list<string|\Preflow\Validation\ValidationRule>> field type => its own rules for a field's config */
    private array $rules = [];

    /** @var array<string, FieldValue> field type => its value */
    private array $values = [];

    private ?RawValue $raw = null;

    public function __construct()
    {
        $this->columns = [
            'number' => ColumnKind::Number,
            'boolean' => ColumnKind::Boolean,
            'date' => ColumnKind::Date,
            'string' => ColumnKind::Short,
            // A relation field holds a list of ids when its relation says so (relation.multiple), one id otherwise.
            'relation' => static fn (array $config): ColumnKind => self::multipleRelation($config) ? ColumnKind::Text : ColumnKind::Short,
        ];
        // A multiple relation stores its ids as a list (0.89), a single one its id.
        $this->listWhen = ['relation' => self::multipleRelation(...)];
    }

    /** @param array<string, mixed> $config */
    private static function multipleRelation(array $config): bool
    {
        return RelationValue::multiple($config);
    }

    public function text(string ...$types): void
    {
        foreach ($types as $type) {
            $this->text[$type] = true;
        }
    }

    public function relation(string ...$types): void
    {
        foreach ($types as $type) {
            $this->relation[$type] = true;
        }
    }

    /**
     * Field types whose value is a list (Folio's tags): DataManager writes it as a list, also when
     * it is given as JSON text or a comma string (SQL storage keeps it as JSON text in its column).
     *
     * @since 0.78
     */
    public function list(string ...$types): void
    {
        foreach ($types as $type) {
            $this->list[$type] = true;
        }
    }

    /**
     * A field type that holds a list for some configs only (a multiple relation or asset): $when
     * gets the field's config and says whether that field's value is a list. DataManager then
     * writes such a field's value as a list, as for list(); SQL storage keeps JSON text. Replaces
     * an earlier registration for the type.
     *
     * @param \Closure(array<string, mixed>): bool $when
     * @since 0.89
     */
    public function listWhen(string $fieldType, \Closure $when): void
    {
        $this->listWhen[$fieldType] = $when;
    }

    /**
     * Register the column kind of a field type: a kind, or a closure from the field's config to one.
     *
     * @param ColumnKind|\Closure(array<string, mixed>): ColumnKind $kind
     */
    public function column(string $fieldType, ColumnKind|\Closure $kind): void
    {
        $this->columns[$fieldType] = $kind;
    }

    /**
     * A field type's own checks (preflow/fields' link: a valid address for its kind): $rules gets
     * the field's config and gives rule names or ValidationRule objects. Every validation of a typed
     * record runs them after the field's "validate" (DataManager::validate(), saveType()), so Folio,
     * the REST API, imports and seeds check the same values; a field its show_when hides is not
     * checked, and rules a caller gives for the field replace them, as they replace "validate".
     * Replaces an earlier registration for the type. Since 0.94 the closure is also given the field
     * itself as a second argument (a check that depends on the field's "validate", such as a
     * required list); a closure that declares one parameter is called as before.
     *
     * @param \Closure(array<string, mixed>, TypeFieldDefinition=): list<string|\Preflow\Validation\ValidationRule> $rules
     * @since 0.91.0
     */
    public function rules(string $fieldType, \Closure $rules): void
    {
        $this->rules[$fieldType] = $rules;
    }

    /**
     * The rules the field's type registered for this field's config (rules()); none for a type
     * without.
     *
     * @return list<string|\Preflow\Validation\ValidationRule>
     * @since 0.91.0
     */
    public function rulesOf(TypeFieldDefinition $field): array
    {
        $rules = $this->rules[$field->type] ?? null;

        return $rules === null ? [] : $rules($field->config, $field);
    }

    /**
     * Whether the field type registered checks of its own (rules()): an adapter that brings its
     * field types' rules to the core (Folio) leaves those of a core package in place.
     *
     * @since 0.92.0
     */
    public function hasRules(string $fieldType): bool
    {
        return isset($this->rules[$fieldType]);
    }

    /**
     * What a field type's value means (FieldValue): how an input value is stored, how a stored
     * value reads out and the output's shape. Every adapter (Folio's form, an API's read and write
     * classes, imports) converts through it. Replaces an earlier registration for the type (a
     * package that brings a core type's richer value replaces the core one).
     *
     * @since 0.93.0
     */
    public function value(string $fieldType, FieldValue $value): void
    {
        $this->values[$fieldType] = $value;
    }

    /**
     * The value registered for the field's type (value()), or RawValue (read and written
     * unchanged, shape "raw") for a type without one.
     *
     * @since 0.93.0
     */
    public function valueOf(TypeFieldDefinition $field): FieldValue
    {
        return $this->values[$field->type] ?? ($this->raw ??= new RawValue());
    }

    /** @since 0.93.0 */
    public function hasValue(string $fieldType): bool
    {
        return isset($this->values[$fieldType]);
    }

    /**
     * Every registered value, by field type, in the order registered.
     *
     * @return array<string, FieldValue>
     * @since 0.93.0
     */
    public function values(): array
    {
        return $this->values;
    }

    public function isText(string $type): bool
    {
        return isset($this->text[$type]);
    }

    public function isRelation(string $type): bool
    {
        return isset($this->relation[$type]);
    }

    /** @since 0.78 */
    public function isList(string $type): bool
    {
        return isset($this->list[$type]);
    }

    /**
     * Whether this field's value is a list: its type always holds one (list()), or holds one with
     * this field's config (listWhen()).
     *
     * @since 0.89
     */
    public function holdsList(TypeFieldDefinition $field): bool
    {
        if (isset($this->list[$field->type])) {
            return true;
        }
        $when = $this->listWhen[$field->type] ?? null;

        return $when !== null && $when($field->config);
    }

    /** Translatable fields are text (a map per locale); Short is text unless the field is unique or indexed. */
    public function columnKindOf(TypeFieldDefinition $field): ColumnKind
    {
        if ($field->translatable) {
            return ColumnKind::Text;
        }
        $kind = $this->columns[$field->type] ?? ColumnKind::Text;
        if ($kind instanceof \Closure) {
            $kind = $kind($field->config);
        }
        if ($kind === ColumnKind::Short && $field->unique === false && !$field->index) {
            return ColumnKind::Text;
        }

        return $kind;
    }

    /**
     * The length rules of a type's bounded string columns: its unique or indexed string fields, the
     * scope fields of its unique fields and the fields of its composite indexes (stored as
     * indexed), by field.
     *
     * @return array<string, MaxChars>
     */
    public function lengthRules(TypeDefinition $def): array
    {
        return array_map(static fn (int $length): MaxChars => new MaxChars($length), $this->lengths($def));
    }

    /**
     * The column lengths of a type's bounded string columns (what lengthRules() limits them to),
     * by field.
     *
     * @return array<string, int>
     */
    public function lengths(TypeDefinition $def): array
    {
        // A field only a published index covers is a long column in the working table, so its values are limited here too (the published copy's column is short).
        $indexed = [...$def->indexedByOthers(), ...array_merge([], ...$def->publishedIndexes)];
        $lengths = [];
        foreach ($def->fields as $name => $field) {
            $field = in_array($name, $indexed, true) ? $field->withIndex() : $field;
            if ($this->columnKindOf($field) === ColumnKind::Short) {
                $lengths[$name] = $field->length ?? self::DEFAULT_LENGTH;
            }
        }

        return $lengths;
    }

    /** The length rule of a unique or indexed string column (at most 191 characters), or null for any other field. */
    public function lengthRule(TypeFieldDefinition $field): ?MaxChars
    {
        if ($this->columnKindOf($field) !== ColumnKind::Short) {
            return null;
        }

        return new MaxChars($field->length ?? self::DEFAULT_LENGTH);
    }
}
