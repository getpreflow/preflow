<?php

declare(strict_types=1);

namespace Preflow\Data;

final readonly class TypeDefinition
{
    /**
     * @param array<string, TypeFieldDefinition> $fields
     * @param string[] $searchableFields
     * @param array<string, FieldTransformer> $transformers
     */
    public function __construct(
        public string $key,
        public string $table,
        public string $storage,
        public array $fields,
        public string $idField = 'uuid',
        public array $searchableFields = [],
        public array $transformers = [],
        public bool $matrixable = false,
        public array $views = [],
        public Locales $locales = new Locales(['en'], 'en'),
    ) {}

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
