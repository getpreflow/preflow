<?php

declare(strict_types=1);

namespace Preflow\DevTools\Command;

use Preflow\Core\Application;
use Preflow\Data\ModelSchema;
use Preflow\Data\TypeRegistry;

/**
 * `preflow make:model <Name>` scaffolds a model class.
 *
 * `preflow make:model <Name> --from-type=<key>` moves a JSON type to a class: it writes
 * app/Models/<Name>.php with the type's data (table, id, fields, rules) and rewrites
 * config/models/<key>.json to its admin settings, so the registry serves exactly the type it
 * served before and nothing in the database changes. Before writing anything it loads the
 * class and compares the type it gives with the JSON type, so a key the class can't carry is
 * an error, never a silent drop.
 */
final class MakeModelCommand implements CommandInterface
{
    /** Words PHP does not allow as a class name (keywords and reserved type names), lowercase. */
    private const array RESERVED = [
        'abstract', 'and', 'array', 'as', 'bool', 'break', 'callable', 'case', 'catch', 'class', 'clone',
        'const', 'continue', 'declare', 'default', 'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare',
        'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'enum', 'eval', 'exit', 'extends', 'false',
        'final', 'finally', 'float', 'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if', 'implements',
        'include', 'instanceof', 'insteadof', 'int', 'interface', 'isset', 'iterable', 'list', 'match', 'mixed',
        'namespace', 'never', 'new', 'null', 'object', 'or', 'parent', 'print', 'private', 'protected', 'public',
        'readonly', 'require', 'return', 'self', 'static', 'string', 'switch', 'throw', 'trait', 'true', 'try',
        'unset', 'use', 'var', 'void', 'while', 'xor', 'yield',
    ];

    /** Field types whose stored value is text, so the property is a string. */
    private const array STRING_KINDS = ['string', 'text', 'richtext', 'date', 'select', 'env'];

    /** Field types stored as a list or a structure, which DataManager's typed read gives as an array (from JSON text too). */
    private const array STRUCTURED_KINDS = ['blocks', 'matrix'];

    /**
     * Schema keys with a #[Field] parameter or #[Validate]. The parameter is used only where
     * ModelSchema gives the JSON value back as it is; any other value (false, null, 1, "80")
     * goes in `config:` as it is, so the type's schema stays the same array.
     */
    private const array DEDICATED_KEYS = ['type', 'validate', 'translatable', 'unique', 'index', 'length', 'searchable', 'transform', 'default'];

    /**
     * @param string|null   $basePath app root (default: the working directory)
     * @param resource|null $stderr   error stream (default STDERR)
     */
    public function __construct(
        private readonly ?string $basePath = null,
        private readonly mixed $stderr = null,
    ) {}

    public function getName(): string { return 'make:model'; }
    public function getDescription(): string { return 'Create a new model; --from-type=<key> moves a JSON type to a model class'; }

    public function execute(array $args): int
    {
        $name = null;
        $fromType = null;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--from-type=')) {
                $fromType = substr($arg, strlen('--from-type='));
            } elseif (str_starts_with($arg, '--') || $name !== null) {
                return $this->fail("Unknown argument: {$arg}");
            } else {
                $name = $arg;
            }
        }
        if ($name === null || $fromType === '') {
            return $this->fail('Usage: preflow make:model <Name> [--from-type=<key>]');
        }
        if (preg_match('/^[A-Z][A-Za-z0-9_]*$/', $name) !== 1) {
            return $this->fail("{$name} is not a class name: use letters and digits, starting with a capital.");
        }
        if (in_array(strtolower($name), self::RESERVED, true)) {
            return $this->fail("{$name} is a reserved word in PHP: choose another class name.");
        }

        $base = $this->basePath ?? (getcwd() ?: '.');
        $dir = $base . '/app/Models';
        $path = $dir . '/' . $name . '.php';
        if (file_exists($path)) {
            return $this->fail("Model {$name} already exists.");
        }

        if ($fromType !== null) {
            return $this->fromType($base, $name, $fromType);
        }

        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $table = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name)) . 's';
        $php = <<<PHP
        <?php

        declare(strict_types=1);

        namespace App\\Models;

        use Preflow\\Data\\Model;
        use Preflow\\Data\\Attributes\\Entity;
        use Preflow\\Data\\Attributes\\Id;
        use Preflow\\Data\\Attributes\\Field;
        use Preflow\\Data\\Attributes\\Timestamps;

        #[Entity(table: '{$table}', storage: 'sqlite')]
        final class {$name} extends Model
        {
            #[Id]
            public string \$uuid = '';

            #[Field]
            public string \$name = '';

            #[Timestamps]
            public ?\\DateTimeImmutable \$createdAt = null;
            public ?\\DateTimeImmutable \$updatedAt = null;
        }
        PHP;

        file_put_contents($path, $php);
        echo "Model created: app/Models/{$name}.php\n";
        return 0;
    }

    private function fromType(string $base, string $class, string $key): int
    {
        $file = "config/models/{$key}.json";
        if (preg_match('/^[A-Za-z0-9_-]+$/', $key) !== 1 || !is_file("{$base}/{$file}")) {
            return $this->fail("No model file {$file}: there is no JSON type \"{$key}\" to move to a class.");
        }

        try {
            $json = (string) file_get_contents("{$base}/{$file}");
            $type = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $object = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
            if (!is_array($type) || !$object instanceof \stdClass) {
                throw new \RuntimeException("{$file} does not hold a JSON object.");
            }
            $this->assertNotClassBacked($base, $key, $type, $file, $this->modelPaths($base));
            $php = $this->classSource($class, $key, $type, $file);
            $admin = json_encode($this->adminFile($key, $type, $object), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
            $this->assertSameType($key, "{$base}/{$file}", $php, $admin);
        } catch (\JsonException $e) {
            return $this->fail("{$file} is not valid JSON ({$e->getMessage()}). Nothing was written.");
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage() . ' Nothing was written.');
        }

        if (!is_dir("{$base}/app/Models")) {
            mkdir("{$base}/app/Models", 0755, true);
        }
        file_put_contents("{$base}/app/Models/{$class}.php", $php);
        file_put_contents("{$base}/{$file}", $admin);

        echo "Model created: app/Models/{$class}.php\n";
        echo "Admin settings kept in: {$file}\n";
        echo "Check the class, then run your tests: nothing in the database changes.\n";

        return 0;
    }

    /**
     * Where the app's model classes are (data.model_paths, default app/Models). The new class
     * goes to app/Models, so that must be one of them, or the type would lose its schema at boot.
     *
     * @return list<string>
     */
    private function modelPaths(string $base): array
    {
        $paths = ["{$base}/app/Models"];
        if (is_file("{$base}/config/data.php")) {
            try {
                $data = Application::create($base)->configFile('data');
            } catch (\Throwable $e) {
                throw new \RuntimeException("config/data.php could not be read ({$e->getMessage()}), so its data.model_paths can't be checked for a class of this type.", 0, $e);
            }
            if (is_array($data) && isset($data['model_paths'])) {
                // a relative entry is under the app, as Application reads it
                $paths = array_map(
                    static fn (string $path): string => str_starts_with($path, '/') ? $path : "{$base}/{$path}",
                    array_values(array_filter((array) $data['model_paths'], 'is_string')),
                );
            }
        }
        $target = self::resolved("{$base}/app/Models");
        foreach ($paths as $path) {
            $path = self::resolved($path);
            if ($target === $path || str_starts_with($target, $path . '/')) {
                return $paths;
            }
        }

        throw new \RuntimeException('app/Models is not under data.model_paths (config/data.php), so the class would not be found: add it there first.');
    }

    /** A path with symlinks resolved, also when it (or its tail) does not exist yet. */
    private static function resolved(string $path): string
    {
        $path = rtrim($path, '/');
        $tail = '';
        while ($path !== '' && $path !== '.' && $path !== '/' && realpath($path) === false) {
            $tail = '/' . basename($path) . $tail;
            $path = dirname($path);
        }

        return rtrim((string) realpath($path === '' ? '.' : $path), '/') . $tail;
    }

    /**
     * A file without schema is already the admin side of a class; a class under the model paths
     * marked with the key is one too (one definition per key). Read as text: nothing is loaded.
     *
     * @param array<string, mixed> $type
     * @param list<string>         $modelPaths
     */
    private function assertNotClassBacked(string $base, string $key, array $type, string $file, array $modelPaths): void
    {
        if (($type['extends'] ?? false) === true) {
            throw new \RuntimeException("{$file} only patches a type an extension defines (\"extends\": true); it is not a type of its own.");
        }
        if (array_intersect_key($type, array_flip(array_diff(ModelSchema::SCHEMA_TYPE_KEYS, ['key']))) === []) {
            throw new \RuntimeException("Type \"{$key}\" is already defined by a model class: {$file} holds only its admin settings.");
        }
        $pattern = '/#\[[^\]]*\bEntity\s*\([^\]]*\btype\s*:\s*[\'"]' . preg_quote($key, '/') . '[\'"]/s';
        foreach ($modelPaths as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $found) {
                if ($found->isFile() && $found->getExtension() === 'php' && preg_match($pattern, (string) file_get_contents($found->getPathname())) === 1) {
                    throw new \RuntimeException("Type \"{$key}\" is already defined by a model class ({$found->getPathname()}).");
                }
            }
        }
    }

    /**
     * The class: the type's data, plus labels and help (they say what a field is). Per-field
     * keys that only steer the admin form (readonly, hidden, placeholder, ...) stay in the admin
     * file, as do the type's own admin settings (label, list, matrixable, views, ...).
     *
     * @param array<string, mixed> $type
     */
    private function classSource(string $class, string $key, array $type, string $file): string
    {
        if (($type['key'] ?? $key) !== $key) {
            throw new \RuntimeException("{$file} says \"key\": " . json_encode($type['key']) . ", but its name makes it the type \"{$key}\".");
        }
        $table = $type['table'] ?? null;
        $storage = $type['storage'] ?? 'default';
        $idField = $type['id_field'] ?? 'uuid';
        foreach (['table' => $table, 'storage' => $storage, 'id_field' => $idField] as $name => $value) {
            if (!is_string($value) || $value === '') {
                throw new \RuntimeException("{$file} needs \"{$name}\" as a text.");
            }
        }
        $fields = $type['fields'] ?? [];
        if (!is_array($fields)) {
            throw new \RuntimeException("{$file} has \"fields\" that are not an object.");
        }
        if (array_key_exists($idField, $fields)) {
            throw new \RuntimeException("{$file} has a field \"{$idField}\", which is also its id_field: a class has one property for it, the #[Id]. Remove the field first.");
        }
        self::assertPropertyName($idField, $file);

        $uses = ['Preflow\\Data\\Attributes\\Entity', 'Preflow\\Data\\Attributes\\Id', 'Preflow\\Data\\Model'];
        $body = ["    #[Id]\n    public ?string \${$idField} = null;"];
        foreach ($fields as $name => $def) {
            self::assertPropertyName((string) $name, $file);
            if (!is_array($def)) {
                throw new \RuntimeException("{$file} has a field \"{$name}\" that is not an object.");
            }
            $body[] = self::property((string) $name, $def, $file, $uses);
        }

        $indexes = $type['indexes'] ?? [];
        if (!is_array($indexes) || !array_is_list($indexes)) {
            throw new \RuntimeException("{$file} has \"indexes\" that are not a list of field lists.");
        }
        $entity = '';
        foreach ($indexes as $columns) {
            if (!is_array($columns) || $columns === [] || array_filter($columns, static fn (mixed $c): bool => !is_string($c)) !== []) {
                throw new \RuntimeException("{$file} has an entry in \"indexes\" that is not a list of field names.");
            }
            $entity .= '#[Index(' . implode(', ', array_map(static fn (string $c): string => self::export($c), $columns)) . ")]\n";
            $uses[] = 'Preflow\\Data\\Attributes\\Index';
        }

        $uses = array_unique($uses);
        sort($uses);
        $entity .= self::attribute('Entity', ['table' => $table, 'storage' => $storage, 'type' => $key], '');

        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Models;\n\n"
            . implode('', array_map(static fn (string $use): string => "use {$use};\n", $uses))
            . "\n/**\n * The \"{$key}\" type, moved from {$file} (preflow make:model --from-type={$key}).\n"
            . " * Its admin settings (list, section, readonly, ...) stay in that file.\n */\n"
            . "{$entity}\nfinal class {$class} extends Model\n{\n"
            . implode("\n\n", $body) . "\n}\n";
    }

    /**
     * One field as a property with the attributes that give back its schema.
     *
     * @param array<string, mixed> $def
     * @param list<string>         $uses
     */
    private static function property(string $name, array $def, string $file, array &$uses): string
    {
        $where = "{$file}, field \"{$name}\"";
        $fieldType = $def['type'] ?? 'string';
        if (!is_string($fieldType)) {
            throw new \RuntimeException("{$where}: \"type\" is not a text.");
        }
        $rules = $def['validate'] ?? [];
        if (!is_array($rules) || !array_is_list($rules) || array_filter($rules, static fn (mixed $r): bool => !is_string($r) || str_starts_with($r, 'on:')) !== []) {
            throw new \RuntimeException("{$where}: a class carries \"validate\" only as a list of rules as text (without \"on:\").");
        }
        $translatable = (bool) ($def['translatable'] ?? false);
        $relation = self::relationArgs($def['relation'] ?? null);

        // What the property holds: the kind's stored value as DataManager's typed read gives it.
        $phpType = match (true) {
            isset($def['transform']) => 'mixed',
            $translatable => 'array|string',
            $fieldType === 'boolean' => 'bool',
            $fieldType === 'number' => 'int|float',
            // one asset or one related record: its id; several: the list of them
            in_array($fieldType, self::STRING_KINDS, true), $fieldType === 'relation' && !($def['multiple'] ?? false), $fieldType === 'asset' && !self::multipleAssets($def) => 'string',
            in_array($fieldType, self::STRUCTURED_KINDS, true), $fieldType === 'relation', $fieldType === 'asset' => 'array',
            default => 'mixed',
        };
        // The type ModelSchema reads from the property and its #[Relation] when #[Field] names none.
        $inferred = match ($phpType) {
            'string' => $relation !== null ? 'relation' : 'string',
            'bool' => 'boolean',
            default => null,
        };

        $args = [];
        if ($fieldType !== $inferred) {
            $args['type'] = $fieldType;
        }
        foreach (['label', 'help'] as $text) {
            if (self::textOnClass($def[$text] ?? null)) {
                $args[$text] = $def[$text];
            }
        }
        // A parameter only where ModelSchema gives the value back as it is; else the value goes in config.
        $config = [];
        $exact = [
            'translatable' => static fn (mixed $v): bool => $v === true,
            'unique' => static fn (mixed $v): bool => $v === true || (is_array($v) && $v !== [] && array_is_list($v) && array_filter($v, 'is_string') === $v),
            'index' => static fn (mixed $v): bool => $v === true,
            'length' => static fn (mixed $v): bool => is_int($v),
            'searchable' => static fn (mixed $v): bool => is_bool($v),
            'transform' => static fn (mixed $v): bool => is_string($v),
            'default' => static fn (mixed $v): bool => $v !== null,
        ];
        foreach ($exact as $param => $fits) {
            if (array_key_exists($param, $def)) {
                if ($fits($def[$param])) {
                    $args[$param] = $def[$param];
                } else {
                    $config[$param] = $def[$param];
                }
            }
        }
        if (isset($config['transform'])) {
            throw new \RuntimeException("{$where}: \"transform\" is not a class name.");
        }
        if ($rules === [] && array_key_exists('validate', $def)) {
            $config['validate'] = [];
        }
        // The other schema keys (options, blocks, fields, multiple, a relation #[Relation] can't give back) as they are.
        foreach ($def as $configKey => $value) {
            if (in_array($configKey, ModelSchema::SCHEMA_FIELD_KEYS, true)
                && !in_array($configKey, self::DEDICATED_KEYS, true)
                && !($configKey === 'relation' && $relation !== null)) {
                $config[$configKey] = $value;
            }
        }
        if ($config !== []) {
            $args['config'] = array_intersect_key($def, $config); // in the file's order
        }

        $lines = [];
        if ($translatable) {
            $lines[] = '    /** A locale map ({"en": …, "de": …}) on a multilingual site, the plain value on a single-locale one. */';
        }
        if ($args !== [] || $relation === null) {
            $uses[] = 'Preflow\\Data\\Attributes\\Field';
            $lines[] = self::attribute('Field', $args, '    ');
        }
        if ($relation !== null) {
            $uses[] = 'Preflow\\Data\\Attributes\\Relation';
            $lines[] = self::attribute('Relation', $relation, '    ');
        }
        if ($rules !== []) {
            $uses[] = 'Preflow\\Validation\\Attributes\\Validate';
            $lines[] = '    #[Validate(' . implode(', ', array_map(static fn (string $r): string => self::export($r), $rules)) . ')]';
        }
        $lines[] = '    public ' . self::declaration($phpType, $name, $def['default'] ?? null) . ';';

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $def whether an asset field holds several assets (Folio's "asset": {"multiple": true}) */
    private static function multipleAssets(array $def): bool
    {
        return is_array($def['asset'] ?? null) && (bool) ($def['asset']['multiple'] ?? false);
    }

    /** A label or help text goes on the class when it is a text or a map per locale; anything else stays in the admin file. */
    private static function textOnClass(mixed $value): bool
    {
        return is_string($value) || is_array($value);
    }

    /**
     * #[Relation] arguments when the attribute gives back the JSON relation exactly (it always
     * carries onDelete, and only these keys as text); null puts the relation in `config:`.
     *
     * @return array<string, string>|null
     */
    private static function relationArgs(mixed $relation): ?array
    {
        if (!is_array($relation) || !is_string($relation['to'] ?? null) || !is_string($relation['onDelete'] ?? null)) {
            return null;
        }
        foreach ($relation as $k => $v) {
            if (!in_array($k, ['to', 'onDelete', 'onSelfDelete', 'labelField', 'metaField'], true) || !is_string($v)) {
                return null;
            }
        }
        $args = ['to' => $relation['to']];
        if ($relation['onDelete'] !== 'restrict') {
            $args['onDelete'] = $relation['onDelete'];
        }

        return $args + array_intersect_key($relation, ['onSelfDelete' => true, 'labelField' => true, 'metaField' => true]);
    }

    /**
     * Every property is nullable, required or not ("required" is a rule): a stored NULL reads as
     * null and a typed save writes it back, never an empty value over it. It starts with the
     * field's default when that fits its type, else null.
     */
    private static function declaration(string $phpType, string $name, mixed $default): string
    {
        $fits = match ($phpType) {
            'bool' => is_bool($default),
            'int|float' => is_int($default) || is_float($default),
            'string' => is_string($default),
            'array|string' => is_array($default) || is_string($default),
            'array' => is_array($default),
            default => true,
        };
        $type = match (true) {
            $phpType === 'mixed' => 'mixed',
            str_contains($phpType, '|') => "{$phpType}|null",
            default => "?{$phpType}",
        };

        return "{$type} \${$name} = " . ($fits ? self::export($default) : 'null');
    }

    /**
     * The admin file: the type's key and every key that is not data, in their order, and per
     * field what the class does not carry. Built from the file decoded as objects, so an empty
     * {} stays one.
     *
     * @param array<string, mixed> $type
     */
    private function adminFile(string $key, array $type, \stdClass $object): \stdClass
    {
        $admin = new \stdClass();
        $admin->key = $key;
        foreach (get_object_vars($object) as $name => $value) {
            if ($name !== 'fields') {
                if (!in_array($name, ModelSchema::SCHEMA_TYPE_KEYS, true)) {
                    $admin->{$name} = $value;
                }
                continue;
            }
            $fields = new \stdClass();
            foreach ($value instanceof \stdClass ? get_object_vars($value) : [] as $field => $def) {
                $entry = new \stdClass();
                foreach ($def instanceof \stdClass ? get_object_vars($def) : [] as $fieldKey => $v) {
                    $onClass = in_array($fieldKey, ModelSchema::SCHEMA_FIELD_KEYS, true)
                        || (in_array($fieldKey, ['label', 'help'], true) && self::textOnClass($type['fields'][$field][$fieldKey]));
                    if (!$onClass) {
                        $entry->{$fieldKey} = $v;
                    }
                }
                if (get_object_vars($entry) !== []) {
                    $fields->{$field} = $entry;
                }
            }
            if (get_object_vars($fields) !== []) {
                $admin->fields = $fields;
            }
        }

        return $admin;
    }

    /**
     * Loads the class under a throwaway namespace and compares what the registry serves for it
     * (with its admin file) with what it serves for the JSON type: the TypeDefinition, and the
     * schema array that Folio and others read. The schemas are compared with the defaults the
     * registry applies filled in (key, storage, id_field, a field's type), since a class always
     * states those; every other key is kept as the JSON file has it, falsy and null values too.
     */
    private function assertSameType(string $key, string $jsonFile, string $php, string $admin): void
    {
        $dir = sys_get_temp_dir() . '/preflow-make-model-' . bin2hex(random_bytes(6));
        $namespace = 'PreflowMakeModelCheck\\N' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        try {
            file_put_contents("{$dir}/{$key}.json", $admin);
            file_put_contents("{$dir}/Model.php", str_replace("namespace App\\Models;", "namespace {$namespace};", $php));
            preg_match('/^final class (\w+)/m', $php, $class);
            $json = new TypeRegistry(dirname($jsonFile));
            $expected = $json->get($key);
            $expectedSchema = $json->schema($key);
            try {
                require "{$dir}/Model.php";
                $moved = new TypeRegistry($dir, null, [$key => "{$namespace}\\{$class[1]}"]);
                $actual = $moved->get($key);
                $actualSchema = $moved->schema($key);
            } catch (\Throwable $e) {
                throw new \RuntimeException("The class make:model wrote for \"{$key}\" does not load as a type ({$e->getMessage()}): please report this.", 0, $e);
            }
            if (array_keys($expected->fields) !== array_keys($actual->fields)
                || self::normalized($expected) !== self::normalized($actual)
                || self::normalized(self::withDefaults($expectedSchema, $key)) !== self::normalized(self::withDefaults($actualSchema, $key))) {
                throw new \RuntimeException("The class make:model wrote for \"{$key}\" gives a different type than {$key}.json: please report this.");
            }
        } finally {
            @unlink("{$dir}/{$key}.json");
            @unlink("{$dir}/Model.php");
            @rmdir($dir);
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function withDefaults(array $schema, string $key): array
    {
        $schema += ['key' => $key, 'storage' => 'default', 'id_field' => 'uuid'];
        foreach ((array) ($schema['fields'] ?? []) as $name => $field) {
            if (is_array($field)) {
                $schema['fields'][$name] = $field + ['type' => 'string'];
            }
        }

        return $schema;
    }

    /** A type as nested arrays with sorted keys, so equal types compare identical whatever their key order. */
    private static function normalized(mixed $value): mixed
    {
        if (is_object($value)) {
            // The data layer's value objects by their properties; a transformer by its class.
            $value = str_starts_with($value::class, 'Preflow\\Data\\') && !$value instanceof \Preflow\Data\FieldTransformer
                ? ['@class' => $value::class] + get_object_vars($value)
                : $value::class;
        }
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::normalized(...), $value);
    }

    private static function assertPropertyName(string $name, string $file): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new \RuntimeException("{$file} has a field \"{$name}\", which is not a PHP property name: rename it first or keep the type in JSON.");
        }
    }

    /** @param array<string, mixed> $args */
    private static function attribute(string $name, array $args, string $indent): string
    {
        if ($args === []) {
            return "{$indent}#[{$name}]";
        }
        $inline = [];
        foreach ($args as $arg => $value) {
            $inline[] = "{$arg}: " . self::export($value);
        }
        $line = "{$indent}#[{$name}(" . implode(', ', $inline) . ')]';
        if (mb_strlen($line) <= 120) {
            return $line;
        }
        $lines = [];
        foreach ($args as $arg => $value) {
            $lines[] = "{$indent}    {$arg}: " . self::export($value, "{$indent}    ") . ',';
        }

        return "{$indent}#[{$name}(\n" . implode("\n", $lines) . "\n{$indent})]";
    }

    /** A value as a PHP constant expression: inline when short (or $indent is null), else one entry per line. */
    private static function export(mixed $value, ?string $indent = null): string
    {
        if (!is_array($value)) {
            return match (true) {
                $value === null => 'null',
                is_bool($value) => $value ? 'true' : 'false',
                default => var_export($value, true),
            };
        }
        if ($value === []) {
            return '[]';
        }
        $list = array_is_list($value);
        $items = [];
        foreach ($value as $k => $v) {
            $items[] = ($list ? '' : var_export($k, true) . ' => ') . self::export($v);
        }
        $inline = '[' . implode(', ', $items) . ']';
        if ($indent === null || mb_strlen($inline) <= 80) {
            return $inline;
        }
        $lines = [];
        foreach ($value as $k => $v) {
            $lines[] = "{$indent}    " . ($list ? '' : var_export($k, true) . ' => ') . self::export($v, "{$indent}    ") . ',';
        }

        return "[\n" . implode("\n", $lines) . "\n{$indent}]";
    }

    private function fail(string $message): int
    {
        fwrite($this->stderr ?? STDERR, $message . "\n");

        return 1;
    }
}
