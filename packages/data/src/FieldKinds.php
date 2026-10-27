<?php

declare(strict_types=1);

namespace Preflow\Data;

use Preflow\Data\Schema\ColumnKind;

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

    /** The length rule of a unique or indexed string column ("max:191"), or null for any other field. */
    public function lengthRule(TypeFieldDefinition $field): ?string
    {
        if ($this->columnKindOf($field) !== ColumnKind::Short) {
            return null;
        }

        return 'max:' . ($field->length ?? self::DEFAULT_LENGTH);
    }
}
