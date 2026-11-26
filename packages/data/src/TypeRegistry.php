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
        private readonly ?\Preflow\Core\Boot\BootManifest $manifest = null,
    ) {
        if ($this->manifest === null) {
            $this->assertOneDefinitionPerKey();
            $this->assertTitleFields();
            $this->assertActions();
            $this->assertShowWhen();
        } else {
            // Checked when the manifest is built (and live while none is loaded); a loaded one skips the scans.
            $sources = $this->manifest->isRecording() ? $this->sources() : [];
            $this->manifest->remember('data.one_definition_per_key', function (): bool {
                $this->assertOneDefinitionPerKey();

                return true;
            }, $sources);
            $this->manifest->remember('data.title_fields', function (): bool {
                $this->assertTitleFields();

                return true;
            }, $sources);
            $this->manifest->remember('data.type_actions', function (): bool {
                $this->assertActions();

                return true;
            }, $sources);
            $this->manifest->remember('data.show_when', function (): bool {
                $this->assertShowWhen();

                return true;
            }, $sources);
        }
    }

    /**
     * The files type definitions are read from: the model files folder and the files of the model
     * classes. A check over every type that is kept in the boot manifest lists these as its sources
     * (`BootManifest::remember()`), so `preflow optimize --check` knows when it is out of date.
     *
     * @return list<string> absolute paths
     */
    public function sources(): array
    {
        $sources = [$this->modelsPath];
        foreach ($this->classes as $class) {
            $file = (new \ReflectionClass($class))->getFileName();
            if (is_string($file)) {
                $sources[] = $file;
            }
        }

        return array_values(array_unique($sources));
    }

    /**
     * A titleField the type does not have, or a class and its admin file naming different ones, is
     * an error when the registry is built (at boot), not when a request first uses the type. With a
     * boot manifest the check runs when the manifest is built (preflow optimize), so a loaded one
     * decodes no model file for it. A class is reflected only for its #[Entity], and its fields
     * only when a titleField is set. A file with "extends": true patches a runtime type, which
     * define() checks.
     */
    private function assertTitleFields(): void
    {
        foreach ($this->classes as $key => $class) {
            $path = $this->modelsPath . '/' . $key . '.json';
            $file = is_file($path) ? \Preflow\Core\Config\ParsedJsonFiles::read($path) : null;
            $file = is_array($file) && ($file['extends'] ?? false) !== true ? $file : [];
            $entity = (new \ReflectionClass($class))->getAttributes(Attributes\Entity::class);
            $attribute = $entity === [] ? null : $entity[0]->newInstance()->titleField;
            if ($attribute === null && !array_key_exists('titleField', $file)) {
                continue;
            }
            $schema = ModelSchema::of($class);
            $titleField = ModelSchema::titleField($schema, $file, $path, $class);
            self::assertTitleField((string) $key, self::titleField($titleField, (string) $key), $schema['fields']);
        }
        foreach (glob($this->modelsPath . '/*.json') ?: [] as $path) {
            $key = basename($path, '.json');
            if (isset($this->classes[$key])) {
                continue;
            }
            $file = \Preflow\Core\Config\ParsedJsonFiles::read($path);
            if (!is_array($file) || ($file['extends'] ?? false) === true || !array_key_exists('titleField', $file)) {
                continue;
            }
            $type = is_string($file['key'] ?? null) ? $file['key'] : $key;
            self::assertTitleField($type, self::titleField($file['titleField'], $type), is_array($file['fields'] ?? null) ? $file['fields'] : []);
        }
    }

    /**
     * A model file's "readonly" or "creatable" that is not true or false (or a read-only type that
     * says it is creatable) is an error when the registry is built (at boot), naming the option; as
     * the titleField check, run when a boot manifest is built and skipped when one is loaded. A
     * class's #[Entity] values are typed; its admin file's are checked here, a disagreement between
     * the two in assertOneDefinitionPerKey() (ModelSchema::assertActionsAgree()).
     */
    private function assertActions(): void
    {
        foreach (glob($this->modelsPath . '/*.json') ?: [] as $path) {
            $file = \Preflow\Core\Config\ParsedJsonFiles::read($path);
            if (!is_array($file) || ($file['extends'] ?? false) === true
                || (!array_key_exists('readonly', $file) && !array_key_exists('creatable', $file))) {
                continue;
            }
            self::actions($file, is_string($file['key'] ?? null) ? $file['key'] : basename($path, '.json'));
        }
    }

    /**
     * A field's show_when that cannot work (malformed, or naming an unknown field, the field
     * itself, a translatable field or a field that depends on it again) is an error when the
     * registry is built (at boot), naming the type and the field (FieldVisibility::assertRules()).
     * As the titleField check, run when a boot manifest is built and skipped when one is loaded.
     * A model file is checked as written; a class-backed type only when its fields' config or its
     * admin file holds a show_when, then as its schema (the class with its admin file over it).
     * A runtime type is checked by define().
     */
    private function assertShowWhen(): void
    {
        foreach ($this->classes as $key => $class) {
            $path = $this->modelsPath . '/' . $key . '.json';
            $file = is_file($path) ? \Preflow\Core\Config\ParsedJsonFiles::read($path) : null;
            $file = is_array($file) && ($file['extends'] ?? false) !== true ? $file : [];
            if (self::fileHasShowWhen($file) || self::classHasShowWhen($class)) {
                self::assertShowWhenRules((string) $key, $this->schema((string) $key));
            }
        }
        foreach (glob($this->modelsPath . '/*.json') ?: [] as $path) {
            if (isset($this->classes[basename($path, '.json')])) {
                continue;
            }
            $file = \Preflow\Core\Config\ParsedJsonFiles::read($path);
            if (!is_array($file) || ($file['extends'] ?? false) === true || !self::fileHasShowWhen($file)) {
                continue;
            }
            self::assertShowWhenRules(is_string($file['key'] ?? null) ? $file['key'] : basename($path, '.json'), $file);
        }
    }

    /** @param array<string, mixed> $file */
    private static function fileHasShowWhen(array $file): bool
    {
        foreach (is_array($file['fields'] ?? null) ? $file['fields'] : [] as $field) {
            if (is_array($field) && array_key_exists('show_when', $field)) {
                return true;
            }
        }

        return false;
    }

    /** @param class-string $class */
    private static function classHasShowWhen(string $class): bool
    {
        foreach ((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            foreach ($property->getAttributes(Attributes\Field::class) as $attribute) {
                if (array_key_exists('show_when', $attribute->newInstance()->config)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string, mixed> $schema */
    private static function assertShowWhenRules(string $key, array $schema): void
    {
        $fields = array_filter(is_array($schema['fields'] ?? null) ? $schema['fields'] : [], 'is_array');
        FieldVisibility::assertRules(
            "Type \"{$key}\"",
            $fields,
            static fn (string $field): bool => (bool) ($fields[$field]['translatable'] ?? false),
        );
    }

    /** @param array<string, mixed> $fields */
    private static function assertTitleField(string $key, ?string $titleField, array $fields): void
    {
        if ($titleField !== null && !array_key_exists($titleField, $fields)) {
            throw new \InvalidArgumentException("Type \"{$key}\" has the titleField \"{$titleField}\", which is not one of its fields.");
        }
    }

    /**
     * A class-backed key whose model file also defines schema is an error when the registry is
     * built (at boot), not when a request first uses the type. Reads only the files of class
     * keys (decoded once per process, as the type's first use reads them anyway); the full
     * overlay (a file entry for a field the class lacks) still runs on first use. With a boot
     * manifest it runs when the manifest is built, as the titleField check does, so a loaded one
     * reads no admin file for it.
     */
    private function assertOneDefinitionPerKey(): void
    {
        foreach ($this->classes as $key => $class) {
            $path = $this->modelsPath . '/' . $key . '.json';
            $file = is_file($path) ? \Preflow\Core\Config\ParsedJsonFiles::read($path) : null;
            if (is_array($file) && ($file['extends'] ?? false) !== true) {
                ModelSchema::assertAdminOnly($file, $path, $key, $class);
                ModelSchema::assertActionsAgree($file, $path, (string) $key, $class);
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

        self::assertShowWhenRules($key, $schema);
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
     * The composite indexes of a model file ("indexes": [["state", "updated_at"]]; "published_indexes"
     * the same, for the published copy only).
     *
     * @return list<list<string>>
     */
    private static function indexes(mixed $value, string $key, string $name = 'indexes'): array
    {
        if ($value === null) {
            return [];
        }
        $indexes = [];
        foreach (is_array($value) && array_is_list($value) ? $value : [null] as $columns) {
            if (!is_array($columns) || !array_is_list($columns) || array_filter($columns, static fn (mixed $c): bool => !is_string($c)) !== []) {
                throw new \InvalidArgumentException("Type \"{$key}\": \"{$name}\" is a list of field lists, such as [[\"state\", \"updated_at\"]].");
            }
            $indexes[] = $columns;
        }

        return $indexes;
    }

    /** Whether the type declares full-text search ("fullText": true); false when the model file says nothing. */
    private static function fullText(mixed $value, string $key): bool
    {
        if ($value === null) {
            return false;
        }
        if (!is_bool($value)) {
            throw new \InvalidArgumentException("Type \"{$key}\": \"fullText\" is true or false.");
        }

        return $value;
    }

    /**
     * The editor actions a model file allows ("readonly": true, "creatable": false; both absent: all).
     * A value other than true or false, or a read-only type that says it is creatable, is refused.
     *
     * @param array<string, mixed> $schema
     * @return array{readonly: bool, creatable: bool}
     * @since 0.78
     */
    public static function actions(array $schema, string $key): array
    {
        foreach (['readonly', 'creatable'] as $option) {
            if (array_key_exists($option, $schema) && !is_bool($schema[$option])) {
                throw new \InvalidArgumentException("Type \"{$key}\": \"{$option}\" is true or false.");
            }
        }
        $readonly = ($schema['readonly'] ?? false) === true;
        if ($readonly && ($schema['creatable'] ?? null) === true) {
            throw new \InvalidArgumentException("Type \"{$key}\" is read-only, so it creates nothing: drop \"creatable\" (or \"readonly\").");
        }

        return ['readonly' => $readonly, 'creatable' => !$readonly && ($schema['creatable'] ?? true) === true];
    }

    /** A field's "exact": true or false (a case-significant machine value compares exactly). */
    private static function exact(mixed $value, string $key, string $field): bool
    {
        if (!is_bool($value)) {
            throw new \InvalidArgumentException("Type \"{$key}\": \"exact\" on the field \"{$field}\" is true or false.");
        }

        return $value;
    }

    /** The field that names a record ("titleField": "name"); null when the model file sets none. */
    private static function titleField(mixed $value, string $key): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException("Type \"{$key}\": \"titleField\" is the name of one of its fields.");
        }

        return $value;
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
                ['type', 'searchable', 'transform', 'validate', 'label', 'help', 'translatable', 'unique', 'index', 'length', 'exact'],
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
                exact: self::exact($fieldDef['exact'] ?? false, (string) $schema['key'], (string) $name),
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

        $actions = self::actions($schema, (string) $schema['key']);

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
            titleField: self::titleField($schema['titleField'] ?? null, (string) $schema['key']),
            fullText: self::fullText($schema['fullText'] ?? null, (string) $schema['key']),
            readonly: $actions['readonly'],
            creatable: $actions['creatable'],
            publishedIndexes: self::indexes($schema['published_indexes'] ?? null, (string) $schema['key'], 'published_indexes'),
            idKind: isset($this->classes[$schema['key']]) ? IdKind::ofModel($this->classes[$schema['key']], $schema['id_field'] ?? 'uuid') : null,
        );
    }
}
