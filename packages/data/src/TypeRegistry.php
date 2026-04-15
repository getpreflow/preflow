<?php

declare(strict_types=1);

namespace Preflow\Data;

final class TypeRegistry
{
    /** @var array<string, TypeDefinition> */
    private array $cache = [];

    public function __construct(
        private readonly string $modelsPath,
    ) {}

    public function get(string $type): TypeDefinition
    {
        if (!isset($this->cache[$type])) {
            $this->cache[$type] = $this->load($type);
        }
        return $this->cache[$type];
    }

    public function has(string $type): bool
    {
        return file_exists($this->modelsPath . '/' . $type . '.json');
    }

    private function load(string $type): TypeDefinition
    {
        $path = $this->modelsPath . '/' . $type . '.json';
        if (!file_exists($path)) {
            throw new \RuntimeException("Unknown type: {$type}. No schema at {$path}");
        }

        $schema = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $fields = [];
        $searchableFields = [];
        $transformers = [];

        foreach ($schema['fields'] ?? [] as $name => $fieldDef) {
            $fieldType = $fieldDef['type'] ?? 'string';
            $searchable = $fieldDef['searchable'] ?? false;
            $transform = $fieldDef['transform'] ?? null;
            $validate = $fieldDef['validate'] ?? [];

            $fields[$name] = new TypeFieldDefinition(
                name: $name,
                type: $fieldType,
                searchable: $searchable,
                transform: $transform,
                validate: $validate,
            );

            if ($searchable) {
                $searchableFields[] = $name;
            }

            if ($transform !== null) {
                if (!class_exists($transform)) {
                    throw new \RuntimeException("Transformer class not found: {$transform}");
                }
                $transformers[$name] = new $transform();
            }
        }

        return new TypeDefinition(
            key: $schema['key'],
            table: $schema['table'],
            storage: $schema['storage'] ?? 'default',
            fields: $fields,
            idField: $schema['id_field'] ?? 'uuid',
            searchableFields: $searchableFields,
            transformers: $transformers,
        );
    }
}
