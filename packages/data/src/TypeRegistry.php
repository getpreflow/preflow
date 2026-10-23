<?php

declare(strict_types=1);

namespace Preflow\Data;

final class TypeRegistry
{
    /** @var array<string, TypeDefinition> */
    private array $cache = [];

    /** @var array<string, bool> per type with a model file: whether the file defines it on its own */
    private array $files = [];

    public function __construct(
        private readonly string $modelsPath,
        private readonly ?Locales $locales = null,
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
        return isset($this->cache[$type]) || $this->hasFile($type);
    }

    /**
     * A model file that is a type of its own. One with "extends": true only patches a type an
     * extension defines at runtime (Folio merges it in before define()), so it is not one.
     */
    private function hasFile(string $type): bool
    {
        if (isset($this->files[$type])) {
            return $this->files[$type];
        }
        $path = $this->modelsPath . '/' . $type . '.json';
        if (!is_file($path)) {
            return false; // not remembered: the file may still be written
        }
        $schema = json_decode((string) file_get_contents($path), true);

        return $this->files[$type] = !(is_array($schema) && ($schema['extends'] ?? false) === true);
    }

    /**
     * Define a type at runtime (extension models). The schema has the shape of a model JSON file.
     */
    public function define(array $schema): TypeDefinition
    {
        $key = $schema['key'] ?? null;
        if (!is_string($key) || $key === '') {
            throw new \InvalidArgumentException('A defined type needs a "key"');
        }
        if ($this->has($key)) {
            throw new \RuntimeException("Type \"{$key}\" already exists (as a model file or an earlier definition)");
        }

        return $this->cache[$key] = $this->parse($schema);
    }

    private function load(string $type): TypeDefinition
    {
        $path = $this->modelsPath . '/' . $type . '.json';
        if (!$this->hasFile($type)) {
            throw new \RuntimeException("Unknown type: {$type}. No schema at {$path}");
        }

        return $this->parse(json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR));
    }

    private function parse(array $schema): TypeDefinition
    {
        $fields = [];
        $searchableFields = [];
        $transformers = [];

        foreach ($schema['fields'] ?? [] as $name => $fieldDef) {
            $fieldType = $fieldDef['type'] ?? 'string';
            $searchable = $fieldDef['searchable'] ?? false;
            $transform = $fieldDef['transform'] ?? null;
            $validate = $fieldDef['validate'] ?? [];
            $label = $fieldDef['label'] ?? null;
            $help = $fieldDef['help'] ?? null;
            $config = array_diff_key($fieldDef, array_flip(
                ['type', 'searchable', 'transform', 'validate', 'label', 'help', 'translatable'],
            ));

            $fields[$name] = new TypeFieldDefinition(
                name: $name,
                type: $fieldType,
                searchable: $searchable,
                transform: $transform,
                validate: $validate,
                label: $label,
                help: $help,
                config: $config,
                translatable: (bool) ($fieldDef['translatable'] ?? false),
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
            matrixable: (bool) ($schema['matrixable'] ?? false),
            views: array_values(array_filter(
                (array) ($schema['views'] ?? []),
                static fn ($v) => is_string($v),
            )),
            locales: $this->locales ?? Locales::single('en'),
        );
    }
}
