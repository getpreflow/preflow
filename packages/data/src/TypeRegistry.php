<?php

declare(strict_types=1);

namespace Preflow\Data;

final class TypeRegistry
{
    /** @var array<string, TypeDefinition> */
    private array $cache = [];

    /** @var array<string, bool> per type with a model file: whether the file defines it on its own */
    private array $files = [];

    /** @var array<string, array<string, mixed>> the schema array per loaded type */
    private array $schemas = [];

    /**
     * @param array<string, class-string> $classes type key => model class marked #[Entity(type: …)] (ModelClasses::all()).
     *                                             A class is the type; its model file, if any, only adds admin presentation.
     */
    public function __construct(
        private readonly string $modelsPath,
        private readonly ?Locales $locales = null,
        private readonly array $classes = [],
    ) {
        $this->assertOneDefinitionPerKey();
    }

    /**
     * A class-backed key whose model file also defines schema is an error when the registry is
     * built (at boot), not when a request first uses the type. Reads only the files of class
     * keys (decoded once per process, as the type's first use reads them anyway); the full
     * overlay (a file entry for a field the class lacks) still runs on first use.
     */
    private function assertOneDefinitionPerKey(): void
    {
        foreach ($this->classes as $key => $class) {
            $path = $this->modelsPath . '/' . $key . '.json';
            $file = is_file($path) ? \Preflow\Core\Config\ParsedJsonFiles::read($path) : null;
            if (is_array($file) && ($file['extends'] ?? false) !== true) {
                ModelSchema::assertAdminOnly($file, $path, $key, $class);
            }
        }
    }

    /** The model class that defines a type, or null for a type defined by a JSON file or at runtime. */
    public function classFor(string $type): ?string
    {
        return $this->classes[$type] ?? null;
    }

    /**
     * The schema array of a type, in the shape of a model JSON file: for a class-backed type the
     * class's schema with its admin file laid over it, else the file's (or the runtime definition's).
     *
     * @return array<string, mixed>
     */
    public function schema(string $type): array
    {
        if (!isset($this->schemas[$type])) {
            $this->schemas[$type] = $this->read($type);
        }

        return $this->schemas[$type];
    }

    public function get(string $type): TypeDefinition
    {
        if (!isset($this->cache[$type])) {
            $this->cache[$type] = $this->load($type);
        }
        return $this->cache[$type];
    }

    /**
     * Every type: model files that are types of their own, plus runtime definitions. Sorted.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = [...array_keys($this->cache), ...array_keys($this->classes)];
        foreach (glob($this->modelsPath . '/*.json') ?: [] as $path) {
            $type = basename($path, '.json');
            if ($this->hasFile($type)) {
                $keys[] = $type;
            }
        }
        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    public function has(string $type): bool
    {
        return isset($this->cache[$type]) || isset($this->classes[$type]) || $this->hasFile($type);
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
        $schema = \Preflow\Core\Config\ParsedJsonFiles::read($path);

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
        if (isset($this->classes[$key])) {
            throw new \RuntimeException("Type \"{$key}\" is defined by {$this->classes[$key]} and cannot be defined at runtime");
        }
        if ($this->has($key)) {
            throw new \RuntimeException("Type \"{$key}\" already exists (as a model file or an earlier definition)");
        }

        $this->schemas[$key] = $schema;

        return $this->cache[$key] = $this->parse($schema);
    }

    private function load(string $type): TypeDefinition
    {
        return $this->parse($this->schema($type));
    }

    /** @return array<string, mixed> */
    private function read(string $type): array
    {
        $path = $this->modelsPath . '/' . $type . '.json';
        $class = $this->classes[$type] ?? null;
        if ($class === null && !$this->hasFile($type)) {
            throw new \RuntimeException("Unknown type: {$type}. No schema at {$path}");
        }

        $schema = is_file($path) ? $this->readFile($path) : null;
        if ($class !== null) {
            $classSchema = ModelSchema::of($class);
            // An "extends" file patches an extension's type; it is not this class's admin file.
            if ($schema === null || ($schema['extends'] ?? false) === true) {
                return $classSchema;
            }

            return ModelSchema::overlay($classSchema, $schema, $path, $class);
        }
        // A file that holds no schema (a label, a list) is the admin side of a model class that is not there.
        if (array_intersect_key($schema, array_flip(array_diff(ModelSchema::SCHEMA_TYPE_KEYS, ['key']))) === []) {
            throw new \RuntimeException("Model file {$path} holds no schema and there is no model class for type \"{$type}\": mark a class with #[Entity(type: '{$type}')] under data.model_paths, or add the schema to the file.");
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    private function readFile(string $path): array
    {
        $schema = \Preflow\Core\Config\ParsedJsonFiles::read($path);
        if (!is_array($schema)) { // invalid or not an object: decode again for the same error as ever
            $schema = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        }

        return $schema;
    }

    /**
     * A label or help text as the model file writes it: a string, or a map per language.
     * Gives the default text (the string; a map's "en" entry, else its first) and the map.
     *
     * @return array{0: ?string, 1: array<string, string>}
     */
    private static function text(mixed $value): array
    {
        if (is_string($value)) {
            return [$value, []];
        }
        if (!is_array($value)) {
            return [null, []];
        }
        $map = array_filter($value, static fn ($text, $locale): bool => is_string($text) && is_string($locale), ARRAY_FILTER_USE_BOTH);
        if ($map === []) {
            return [null, []];
        }

        return [$map['en'] ?? reset($map), $map];
    }

    /** @return bool|list<string> */
    private static function unique(mixed $value): bool|array
    {
        if (!is_array($value)) {
            return (bool) $value;
        }
        $scope = array_values(array_filter($value, 'is_string'));

        return $scope === [] ? true : $scope;
    }

    /**
     * The composite indexes of a model file ("indexes": [["state", "updated_at"]]).
     *
     * @return list<list<string>>
     */
    private static function indexes(mixed $value, string $key): array
    {
        if ($value === null) {
            return [];
        }
        $indexes = [];
        foreach (is_array($value) && array_is_list($value) ? $value : [null] as $columns) {
            if (!is_array($columns) || !array_is_list($columns) || array_filter($columns, static fn (mixed $c): bool => !is_string($c)) !== []) {
                throw new \InvalidArgumentException("Type \"{$key}\": \"indexes\" is a list of field lists, such as [[\"state\", \"updated_at\"]].");
            }
            $indexes[] = $columns;
        }

        return $indexes;
    }

    private function parse(array $schema): TypeDefinition
    {
        $fields = [];
        $searchableFields = [];
        $transformers = [];

        foreach ($schema['fields'] ?? [] as $name => $fieldDef) {
            $fieldType = $fieldDef['type'] ?? 'string';
            $searchable = isset($fieldDef['searchable']) ? (bool) $fieldDef['searchable'] : null;
            $transform = $fieldDef['transform'] ?? null;
            $validate = $fieldDef['validate'] ?? [];
            [$label, $labels] = self::text($fieldDef['label'] ?? null);
            [$help, $helps] = self::text($fieldDef['help'] ?? null);
            $config = array_diff_key($fieldDef, array_flip(
                ['type', 'searchable', 'transform', 'validate', 'label', 'help', 'translatable', 'unique', 'index', 'length'],
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
                labels: $labels,
                helps: $helps,
                unique: self::unique($fieldDef['unique'] ?? false),
                index: (bool) ($fieldDef['index'] ?? false),
                length: isset($fieldDef['length']) ? (int) $fieldDef['length'] : null,
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
            locales: $this->locales ?? Locales::single('en'),
            indexes: self::indexes($schema['indexes'] ?? null, (string) $schema['key']),
        );
    }
}
