<?php

declare(strict_types=1);

namespace Preflow\Data;

final readonly class TypeDefinition
{
    /**
     * @param array<string, TypeFieldDefinition> $fields
     * @param string[] $searchableFields
     * @param array<string, FieldTransformer> $transformers
     * @param list<list<string>> $indexes composite indexes, each the fields it covers in order
     * @throws \InvalidArgumentException an index without fields, or over a field the type does not have
     */
    public function __construct(
        public string $key,
        public string $table,
        public string $storage,
        public array $fields,
        public string $idField = 'uuid',
        public array $searchableFields = [],
        public array $transformers = [],
        public Locales $locales = new Locales(['en'], 'en'),
        public array $indexes = [],
    ) {
        foreach ($indexes as $columns) {
            if ($columns === []) {
                throw new \InvalidArgumentException("Type \"{$key}\" has an index without fields.");
            }
            foreach ($columns as $column) {
                if (!isset($fields[$column])) {
                    throw new \InvalidArgumentException("Type \"{$key}\" has an index over \"{$column}\", which is not one of its fields.");
                }
            }
        }
    }

    /**
     * Fields whose values differ per locale. Empty for single-locale apps:
     * "translatable" has no effect there and values stay plain scalars.
     *
     * @return list<string>
     */
    public function translatableFields(): array
    {
        if (!$this->locales->isMultilingual()) {
            return [];
        }

        return array_keys(array_filter($this->fields, static fn ($f) => $f->translatable));
    }

    /**
     * Unique fields with the fields that scope their uniqueness (empty: unique across the table).
     *
     * @return array<string, list<string>>
     */
    public function uniqueFields(): array
    {
        $unique = [];
        foreach ($this->fields as $name => $field) {
            if ($field->unique !== false) {
                $unique[$name] = $field->unique === true ? [] : $field->unique;
            }
        }

        return $unique;
    }

    /** @return list<string> */
    public function indexedFields(): array
    {
        return array_keys(array_filter($this->fields, static fn ($f) => $f->index));
    }

    /**
     * Fields stored as indexed columns without an index of their own: the scope fields of unique
     * fields and the fields of composite indexes.
     *
     * @return list<string>
     */
    public function indexedByOthers(): array
    {
        $names = [];
        foreach ($this->uniqueFields() as $scope) {
            array_push($names, ...$scope);
        }
        foreach ($this->indexes as $columns) {
            array_push($names, ...$columns);
        }

        return array_values(array_unique($names));
    }

    /**
     * Collect validation rules from all field definitions.
     *
     * @return array<string, list<string>>
     */
    public function validationRules(): array
    {
        $rules = [];

        foreach ($this->fields as $name => $field) {
            if ($field->validate !== []) {
                $rules[$name] = $field->validate;
            }
        }

        return $rules;
    }
}
