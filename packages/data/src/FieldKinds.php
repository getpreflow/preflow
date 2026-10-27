<?php

declare(strict_types=1);

namespace Preflow\Data;

use Preflow\Data\Schema\ColumnKind;
use Preflow\Data\Validation\MaxChars;

/**
 * Which field types count as free text and which as a relation, and which column kind each
 * type is stored as. Extensions register their own types.
 */
final class FieldKinds
{
    /** Length of a unique or indexed string column without an own "length" (fits a utf8mb4 index). */
    public const DEFAULT_LENGTH = 191;

    /** @var array<string, true> */
    private array $text = ['string' => true, 'text' => true];

    /** @var array<string, true> */
    private array $relation = ['relation' => true];

    /** @var array<string, ColumnKind|\Closure(array<string, mixed>): ColumnKind> */
    private array $columns;

    public function __construct()
    {
        $this->columns = [
            'number' => ColumnKind::Number,
            'boolean' => ColumnKind::Boolean,
            'date' => ColumnKind::Date,
            'string' => ColumnKind::Short,
            'relation' => static fn (array $config): ColumnKind => ($config['multiple'] ?? false) ? ColumnKind::Text : ColumnKind::Short,
        ];
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
     * Register the column kind of a field type: a kind, or a closure from the field's config to one.
     *
     * @param ColumnKind|\Closure(array<string, mixed>): ColumnKind $kind
     */
    public function column(string $fieldType, ColumnKind|\Closure $kind): void
    {
        $this->columns[$fieldType] = $kind;
    }

    public function isText(string $type): bool
    {
        return isset($this->text[$type]);
    }

    public function isRelation(string $type): bool
    {
        return isset($this->relation[$type]);
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
     * The length rules of a type's bounded string columns: its unique or indexed string fields and
     * the scope fields of its unique fields (stored as indexed), by field.
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
        $scopes = [];
        foreach ($def->uniqueFields() as $scope) {
            array_push($scopes, ...$scope);
        }
        $lengths = [];
        foreach ($def->fields as $name => $field) {
            $field = in_array($name, $scopes, true) ? $field->withIndex() : $field;
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
