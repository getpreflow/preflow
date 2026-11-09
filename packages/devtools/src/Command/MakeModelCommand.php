<?php

declare(strict_types=1);

namespace Preflow\DevTools\Command;

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

    /** Field types stored as a list or a structure: an array, or its JSON text as an SQL driver returns it. */
    private const array STRUCTURED_KINDS = ['asset', 'blocks', 'matrix'];

    /** Schema keys the class carries as #[Field] parameters or #[Validate]; the other schema keys go in `config:`. */
    private const array DEDICATED_KEYS = ['type', 'validate', 'translatable', 'unique', 'index', 'length', 'searchable', 'transform', 'default'];

    /** Field keys that are not schema keys but decide how a value is stored (FieldKinds: a multiple relation is a text column): on the class, in `config:`. */
    private const array STORED_KEYS = ['multiple'];

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
            $this->assertNotClassBacked($base, $key, $type, $file);
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
     * A file without schema is already the admin side of a class; a class under app/Models
     * marked with the key is one too (one definition per key).
     *
     * @param array<string, mixed> $type
     */
    private function assertNotClassBacked(string $base, string $key, array $type, string $file): void
    {
        if (($type['extends'] ?? false) === true) {
            throw new \RuntimeException("{$file} only patches a type an extension defines (\"extends\": true); it is not a type of its own.");
        }
        if (array_intersect_key($type, array_flip(array_diff(ModelSchema::SCHEMA_TYPE_KEYS, ['key']))) === []) {
            throw new \RuntimeException("Type \"{$key}\" is already defined by a model class: {$file} holds only its admin settings.");
        }
        $dir = "{$base}/app/Models";
        if (!is_dir($dir)) {
            return;
        }
        $pattern = '/#\[[^\]]*\bEntity\s*\([^\]]*\btype\s*:\s*[\'"]' . preg_quote($key, '/') . '[\'"]/s';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $found) {
            if ($found->isFile() && $found->getExtension() === 'php' && preg_match($pattern, (string) file_get_contents($found->getPathname())) === 1) {
                throw new \RuntimeException("Type \"{$key}\" is already defined by a model class (" . substr($found->getPathname(), strlen($base) + 1) . ').');
            }
        }
    }

    /**
     * The class: the type's data, plus labels and help (they say what a field is). Per-field
     * keys that only steer the admin form (readonly, hidden, placeholder, ...) stay in the admin
     * file, as do the type's own presentation keys.
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
        if (($type['matrixable'] ?? false) !== false) {
            throw new \RuntimeException("{$file} sets \"matrixable\", which a model class cannot carry yet: keep {$key} a JSON type.");
        }
        if (array_filter((array) ($type['views'] ?? []), 'is_string') !== []) {
            throw new \RuntimeException("{$file} sets \"views\", which a model class cannot carry yet: keep {$key} a JSON type.");
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

        $uses = array_unique($uses);
        sort($uses);
        $entity = self::attribute('Entity', ['table' => $table, 'storage' => $storage, 'type' => $key], '');

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
            in_array($fieldType, self::STRING_KINDS, true), $fieldType === 'relation' && !($def['multiple'] ?? false) => 'string',
            in_array($fieldType, self::STRUCTURED_KINDS, true), $fieldType === 'relation' => 'array|string',
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
        if ($translatable) {
            $args['translatable'] = true;
        }
        // As TypeRegistry reads them: a scope of field names, or yes/no.
        $unique = $def['unique'] ?? false;
        $unique = is_array($unique) ? (array_values(array_filter($unique, 'is_string')) ?: true) : (bool) $unique;
        if ($unique !== false) {
            $args['unique'] = $unique;
        }
        if ((bool) ($def['index'] ?? false)) {
            $args['index'] = true;
        }
        if (isset($def['length'])) {
            $args['length'] = (int) $def['length'];
        }
        if (isset($def['searchable'])) {
            $args['searchable'] = (bool) $def['searchable'];
        }
        if (isset($def['transform'])) {
            if (!is_string($def['transform'])) {
                throw new \RuntimeException("{$where}: \"transform\" is not a class name.");
            }
            $args['transform'] = $def['transform'];
        }
        if (isset($def['default'])) {
            $args['default'] = $def['default'];
        }
        // The other schema keys (options, blocks, fields, a relation #[Relation] can't give back, a null default) and the stored keys as they are.
        $config = [];
        foreach ($def as $configKey => $value) {
            $inConfig = in_array($configKey, [...ModelSchema::SCHEMA_FIELD_KEYS, ...self::STORED_KEYS], true)
                && !in_array($configKey, self::DEDICATED_KEYS, true)
                && !($configKey === 'relation' && $relation !== null);
            if ($inConfig || ($configKey === 'default' && $value === null)) {
                $config[$configKey] = $value;
            }
        }
        if ($config !== []) {
            $args['config'] = $config;
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
        $lines[] = '    public ' . self::declaration($phpType, $name, in_array('required', $rules, true), $translatable) . ';';

        return implode("\n", $lines);
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
            if (!in_array($k, ['to', 'onDelete', 'onSelfDelete', 'labelField'], true) || !is_string($v)) {
                return null;
            }
        }
        $args = ['to' => $relation['to']];
        if ($relation['onDelete'] !== 'restrict') {
            $args['onDelete'] = $relation['onDelete'];
        }

        return $args + array_intersect_key($relation, ['onSelfDelete' => true, 'labelField' => true]);
    }

    /** A property nullable unless the field is required, with its type's empty value as default. */
    private static function declaration(string $phpType, string $name, bool $required, bool $translatable): string
    {
        if ($phpType === 'mixed') {
            return "mixed \${$name} = null";
        }
        if (!$required) {
            return (str_contains($phpType, '|') ? "{$phpType}|null" : "?{$phpType}") . " \${$name} = null";
        }
        $empty = match ($phpType) {
            'bool' => 'false',
            'int|float' => '0',
            'array|string' => $translatable ? "''" : '[]',
            default => "''",
        };

        return "{$phpType} \${$name} = {$empty}";
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
                    $onClass = in_array($fieldKey, [...ModelSchema::SCHEMA_FIELD_KEYS, ...self::STORED_KEYS], true)
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
     * Loads the class under a throwaway namespace and compares the type it gives (with its admin
     * file) with the JSON type: the move must not change the type.
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
            $expected = (new TypeRegistry(dirname($jsonFile)))->get($key);
            try {
                require "{$dir}/Model.php";
                $actual = (new TypeRegistry($dir, null, [$key => "{$namespace}\\{$class[1]}"]))->get($key);
            } catch (\Throwable $e) {
                throw new \RuntimeException("The class make:model wrote for \"{$key}\" does not load as a type ({$e->getMessage()}): please report this.", 0, $e);
            }
            if (array_keys($expected->fields) !== array_keys($actual->fields) || self::normalized($expected) !== self::normalized($actual)) {
                throw new \RuntimeException("The class make:model wrote for \"{$key}\" gives a different type than {$key}.json: please report this.");
            }
        } finally {
            @unlink("{$dir}/{$key}.json");
            @unlink("{$dir}/Model.php");
            @rmdir($dir);
        }
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
