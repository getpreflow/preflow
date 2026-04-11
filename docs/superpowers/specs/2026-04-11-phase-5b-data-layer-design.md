# Phase 5b: Data Layer — MySQL Driver, Field Transformers, Dynamic Models

## Goal

Extend `preflow/data` with three capabilities: a MySQL storage driver (via a dialect abstraction that makes adding future SQL backends trivial), a field transformer infrastructure (with two built-in transformers and a clean interface for user-defined ones), and a dynamic model system (JSON schema definitions queried without PHP classes).

## Architecture

All three features build on the existing `StorageDriver` / `PdoDriver` / `QueryBuilder` / `DataManager` foundation from Phase 5a. No rewrites — only extensions and targeted refactors.

```
StorageDriver (interface)
├── JsonFileDriver              (unchanged)
├── PdoDriver (abstract)        (refactored: receives Dialect)
│   ├── SqliteDriver            (passes SqliteDialect)
│   └── MysqlDriver             (new, passes MysqlDialect)
└── [future: MongoDriver, SupabaseDriver, etc.]

Dialect (interface)              (new)
├── SqliteDialect               (new)
└── MysqlDialect                (new)

FieldTransformer (interface)     (new)
├── JsonTransformer              (new)
└── DateTimeTransformer          (new)

TypeRegistry                     (new) — loads JSON schema files
TypeDefinition                   (new) — readonly parsed schema
DynamicRecord                    (new) — generic data container
```

Non-SQL backends (MongoDB, Supabase REST, Firebase) implement `StorageDriver` directly — they don't share the PDO/Dialect path. The interface is the universal contract; the dialect system is a convenience for SQL databases.

---

## 1. Dialect System + MySQL Driver

### Dialect Interface

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

interface Dialect
{
    /**
     * Quote a table or column identifier for this database.
     * SQLite: "name", MySQL: `name`
     */
    public function quoteIdentifier(string $name): string;

    /**
     * Generate an upsert statement (insert or update if exists).
     *
     * @param string[] $columns All columns in the row
     */
    public function upsertSql(string $table, array $columns, string $idField): string;
}
```

### SqliteDialect

```php
final class SqliteDialect implements Dialect
{
    public function quoteIdentifier(string $name): string
    {
        return '"' . $name . '"';
    }

    public function upsertSql(string $table, array $columns, string $idField): string
    {
        $quoted = array_map(fn (string $c) => $this->quoteIdentifier($c), $columns);
        $placeholders = array_fill(0, count($columns), '?');

        return sprintf(
            'INSERT OR REPLACE INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            implode(', ', $quoted),
            implode(', ', $placeholders),
        );
    }
}
```

### MysqlDialect

```php
final class MysqlDialect implements Dialect
{
    public function quoteIdentifier(string $name): string
    {
        return '`' . $name . '`';
    }

    public function upsertSql(string $table, array $columns, string $idField): string
    {
        $quoted = array_map(fn (string $c) => $this->quoteIdentifier($c), $columns);
        $placeholders = array_fill(0, count($columns), '?');

        $updates = [];
        foreach ($columns as $col) {
            if ($col === $idField) {
                continue;
            }
            $q = $this->quoteIdentifier($col);
            $updates[] = "{$q} = VALUES({$q})";
        }

        return sprintf(
            'INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
            $this->quoteIdentifier($table),
            implode(', ', $quoted),
            implode(', ', $placeholders),
            implode(', ', $updates),
        );
    }
}
```

### QueryCompiler Refactor

`QueryCompiler` currently hardcodes `"` for quoting. Change its constructor to accept a `Dialect`:

```php
final class QueryCompiler
{
    public function __construct(
        private readonly Dialect $dialect = new SqliteDialect(),
    ) {}
}
```

All `"$field"` references in `compile()`, `compileWheres()`, `compileSearch()`, `compileOrderBy()` change to `$this->dialect->quoteIdentifier($field)`.

### PdoDriver Refactor

`PdoDriver`'s constructor gains a `Dialect` parameter. The `save()` method uses `$this->dialect->upsertSql()` instead of building SQL inline. The `findOne()` and `delete()` methods use `$this->dialect->quoteIdentifier()`.

```php
abstract class PdoDriver implements StorageDriver
{
    public function __construct(
        protected readonly \PDO $pdo,
        protected readonly Dialect $dialect,
        protected readonly QueryCompiler $compiler,
    ) {}
}
```

### SqliteDriver (updated)

```php
final class SqliteDriver extends PdoDriver
{
    public function __construct(\PDO $pdo)
    {
        $dialect = new SqliteDialect();
        parent::__construct($pdo, $dialect, new QueryCompiler($dialect));
    }
}
```

### MysqlDriver

```php
final class MysqlDriver extends PdoDriver
{
    public function __construct(\PDO $pdo)
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec("SET NAMES 'utf8mb4'");

        $dialect = new MysqlDialect();
        parent::__construct($pdo, $dialect, new QueryCompiler($dialect));
    }
}
```

### Schema DDL Dialect Support

The migration `Table` class currently generates SQLite-flavored DDL. Add dialect awareness:

- `Schema` receives a `Dialect` (derived from the PDO driver type or passed explicitly)
- `Table::toSql()` accepts a `Dialect` to handle differences:
  - SQLite: `INTEGER PRIMARY KEY` with `AUTOINCREMENT`
  - MySQL: `INT PRIMARY KEY AUTO_INCREMENT`, `CHARSET=utf8mb4`
  - SQLite: `TEXT` for JSON columns
  - MySQL: `JSON` native type

### Application.php bootDataLayer()

Add `'mysql'` case to the driver factory:

```php
'mysql' => function (array $config) {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $config['host'] ?? '127.0.0.1',
        $config['port'] ?? '3306',
        $config['database'] ?? '',
    );
    $pdo = new \PDO($dsn, $config['username'] ?? 'root', $config['password'] ?? '');
    return new \Preflow\Data\Driver\MysqlDriver($pdo);
},
```

Config example (`config/data.php`):

```php
'drivers' => [
    'mysql' => [
        'driver' => 'mysql',
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'database' => getenv('DB_NAME') ?: '',
        'username' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASS') ?: '',
    ],
],
'default' => 'mysql',
```

---

## 2. Field Transformer Infrastructure

### FieldTransformer Interface

```php
<?php

declare(strict_types=1);

namespace Preflow\Data;

interface FieldTransformer
{
    /**
     * Transform a PHP value to its storage representation.
     * Called before save.
     */
    public function toStorage(mixed $value): mixed;

    /**
     * Transform a storage value to its PHP representation.
     * Called after load.
     */
    public function fromStorage(mixed $value): mixed;
}
```

### Built-in Transformers

Located in `packages/data/src/Transform/`:

**JsonTransformer:**

```php
final class JsonTransformer implements FieldTransformer
{
    public function toStorage(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public function fromStorage(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }
}
```

**DateTimeTransformer:**

```php
final class DateTimeTransformer implements FieldTransformer
{
    private const FORMAT = 'Y-m-d H:i:s';

    public function toStorage(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(self::FORMAT);
        }
        return $value;
    }

    public function fromStorage(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat(self::FORMAT, $value);
        if ($dt === false) {
            // Try ISO 8601 fallback
            $dt = new \DateTimeImmutable($value);
        }
        return $dt;
    }
}
```

### Wiring via #[Field] Attribute

The existing `#[Field]` attribute already has a `transform` property (currently unused, `?string`). It stays as `?string` holding a `class-string<FieldTransformer>`:

```php
#[Field(transform: JsonTransformer::class)]
public array $metadata = [];

#[Field(transform: DateTimeTransformer::class)]
public ?\DateTimeImmutable $publishedAt = null;
```

### ModelMetadata Changes

`ModelMetadata::for()` already reads `#[Field]` attributes. It gains a new cached map:

```php
/** @var array<string, FieldTransformer> field name => transformer instance */
public readonly array $transformers;
```

During reflection, if a field has `transform !== null`, instantiate the class and store it. Transformer instances are shared (one per class, cached).

### Model::fill() and Model::toArray() Integration

```php
// In Model::fill(array $data):
$meta = ModelMetadata::for(static::class);
foreach ($data as $key => $value) {
    if (property_exists($this, $key)) {
        if (isset($meta->transformers[$key])) {
            $value = $meta->transformers[$key]->fromStorage($value);
        }
        $this->{$key} = $value;
    }
}

// In Model::toArray():
$meta = ModelMetadata::for(static::class);
$data = [];
foreach ($meta->fields as $name => $field) {
    $value = $this->{$name};
    if (isset($meta->transformers[$name])) {
        $value = $meta->transformers[$name]->toStorage($value);
    }
    $data[$name] = $value;
}
return $data;
```

Transforms are transparent to drivers. Drivers always receive storage-ready values and always return raw storage values. The Model layer handles conversion in both directions.

### Custom Transformers

Users implement `FieldTransformer`:

```php
use Preflow\Data\FieldTransformer;

final class EncryptedTransformer implements FieldTransformer
{
    public function toStorage(mixed $value): mixed
    {
        return sodium_crypto_secretbox($value, $nonce, $key);
    }

    public function fromStorage(mixed $value): mixed
    {
        return sodium_crypto_secretbox_open($value, $nonce, $key);
    }
}
```

```php
#[Field(transform: EncryptedTransformer::class)]
public string $secret = '';
```

No registration step. The attribute points to the class; `ModelMetadata` instantiates it via reflection.

---

## 3. Dynamic Models

### Schema Definition Files

Location: configurable via `data.models_path`, defaults to `config/models/`.

Each file is `{type-key}.json`:

```json
{
    "key": "tournament",
    "table": "tournaments",
    "storage": "mysql",
    "fields": {
        "title": {
            "type": "string",
            "searchable": true
        },
        "start_date": {
            "type": "datetime",
            "transform": "Preflow\\Data\\Transform\\DateTimeTransformer"
        },
        "metadata": {
            "type": "json",
            "transform": "Preflow\\Data\\Transform\\JsonTransformer"
        },
        "max_players": {
            "type": "integer"
        },
        "active": {
            "type": "boolean"
        }
    }
}
```

**Required keys:** `key`, `table`, `fields`.
**Optional keys:** `storage` (defaults to `'default'`).

Each field has:
- `type` — `string`, `integer`, `boolean`, `text`, `json`, `datetime`. Informational for now (used by future validation/migration generation), but `transform` is what drives runtime behavior.
- `searchable` — (optional, default false) include in `->search()` queries
- `transform` — (optional) FQCN of a `FieldTransformer` class

The schema is deliberately minimal. Future layers (CMS, admin panels) can add keys like `label`, `rules`, `tabs`, `relations` — the data layer ignores keys it doesn't recognize.

### TypeDefinition

Readonly value object holding a parsed schema:

```php
final readonly class TypeDefinition
{
    /**
     * @param array<string, TypeFieldDefinition> $fields keyed by field name
     * @param string[] $searchableFields
     * @param array<string, FieldTransformer> $transformers keyed by field name
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
}
```

```php
final readonly class TypeFieldDefinition
{
    public function __construct(
        public string $name,
        public string $type = 'string',
        public bool $searchable = false,
        public ?string $transform = null,
    ) {}
}
```

### TypeRegistry

Loads, parses, and caches schema files:

```php
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

        // Parse fields, resolve transformers, identify searchable fields
        // Return TypeDefinition
    }
}
```

### DynamicRecord

Generic data container for records that don't have a PHP model class:

```php
final class DynamicRecord
{
    private array $data;

    public function __construct(
        private readonly TypeDefinition $type,
        array $data = [],
    ) {
        $this->data = $data;
    }

    public function get(string $field): mixed
    {
        return $this->data[$field] ?? null;
    }

    public function set(string $field, mixed $value): void
    {
        $this->data[$field] = $value;
    }

    public function getType(): TypeDefinition
    {
        return $this->type;
    }

    public function getId(): ?string
    {
        return $this->data[$this->type->idField] ?? null;
    }

    /**
     * Export to storage format (runs toStorage transforms).
     */
    public function toArray(): array
    {
        $out = $this->data;
        foreach ($this->type->transformers as $field => $transformer) {
            if (array_key_exists($field, $out)) {
                $out[$field] = $transformer->toStorage($out[$field]);
            }
        }
        return $out;
    }

    /**
     * Hydrate from storage format (runs fromStorage transforms).
     */
    public static function fromArray(TypeDefinition $type, array $data): self
    {
        foreach ($type->transformers as $field => $transformer) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $transformer->fromStorage($data[$field]);
            }
        }
        return new self($type, $data);
    }
}
```

### DataManager Integration

Four new methods on `DataManager`:

```php
public function queryType(string $type): QueryBuilder  // yields DynamicRecord[]
public function findType(string $type, string $id): ?DynamicRecord
public function saveType(DynamicRecord $record): void
public function deleteType(string $type, string $id): void
```

These mirror the typed-model methods but use `TypeRegistry` for metadata and `DynamicRecord` for hydration. The `QueryBuilder` returned by `queryType()` uses the type's table, storage driver, and searchable fields — the same query pipeline as typed models.

`DataManager` receives a `TypeRegistry` in its constructor (nullable — dynamic models are optional). `Application.php` creates it from `data.models_path` config if the path exists.

### Config Integration

`config/data.php` gains:

```php
'models_path' => __DIR__ . '/models',  // or null to disable dynamic models
```

---

## 4. File Structure

New and modified files in `packages/data/`:

```
src/
  FieldTransformer.php                 (new) interface
  TypeDefinition.php                   (new) readonly class
  TypeFieldDefinition.php              (new) readonly class
  TypeRegistry.php                     (new)
  DynamicRecord.php                    (new)
  DataManager.php                      (modified) add queryType/findType/saveType/deleteType
  Model.php                            (modified) transforms in fill/toArray
  ModelMetadata.php                    (modified) resolve transformers
  Query.php                            (unchanged)
  QueryBuilder.php                     (minor) support DynamicRecord result type
  ResultSet.php                        (unchanged)
  SortDirection.php                    (unchanged)
  StorageDriver.php                    (unchanged)
  Attributes/
    Entity.php                         (unchanged)
    Field.php                          (unchanged, transform already declared)
    Id.php                             (unchanged)
    Timestamps.php                     (unchanged)
  Driver/
    Dialect.php                        (new) interface
    PdoDriver.php                      (modified) accept Dialect
    SqliteDriver.php                   (modified) pass SqliteDialect
    MysqlDriver.php                    (new)
    QueryCompiler.php                  (modified) use Dialect for quoting
    SqliteDialect.php                  (new)
    MysqlDialect.php                   (new)
  Transform/
    JsonTransformer.php                (new)
    DateTimeTransformer.php            (new)
  Migration/
    Migration.php                      (unchanged)
    Schema.php                         (modified) dialect-aware DDL
    Table.php                          (modified) dialect-aware column types
    Migrator.php                       (unchanged)
```

---

## 5. Not In Scope

- Field validation rules on dynamic models
- Relations / eager loading
- UI hints (tabs, groups, labels)
- Publishing state, soft deletes
- Auto-timestamps
- Query scopes
- Auto-migration generation from JSON schemas
- PostgreSQL driver (same pattern as MySQL, built when needed)
