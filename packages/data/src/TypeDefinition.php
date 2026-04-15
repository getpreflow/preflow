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
    ) {}

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
