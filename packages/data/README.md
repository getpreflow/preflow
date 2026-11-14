# preflow/data

Multi-storage data layer for Preflow. Supports JSON files, SQLite and MySQL/MariaDB; different models can use different backends simultaneously.

## Installation

```bash
composer require preflow/data
```

Requires PHP 8.4+, `ext-pdo`, and `ext-pdo_sqlite` or `ext-pdo_mysql` for the SQL storage you use.

## What it does

Models are annotated with PHP attributes. `DataManager` is the single entry point — it reads model metadata via reflection, selects the right storage driver, and returns typed results. `QueryBuilder` provides a fluent API for filtering, sorting, searching, and pagination. Migrations are handled by `Schema` + `Table` + `Migrator`.

## API

### Attributes

| Attribute | Target | Description |
|---|---|---|
| `#[Entity(table: 'posts', storage: 'sqlite')]` | Class | Maps model to a table/collection and a named driver. `storage` defaults to `'default'`. |
| `#[Id]` | Property | Marks the primary key field. Works on any property — use `string $uuid` for UUIDs or `int $id` for auto-increment. |
| `#[Field(searchable: true)]` | Property | Marks a field; `searchable: true` includes it in full-text search. `unique: true` (or `unique: ['scope', ...]`), `index: true` and `length: n` are described in [Unique fields and indexes](#unique-fields-and-indexes). |
| `#[Index('state', 'updated_at')]` | Class (repeatable) | A composite index over fields of a type (`#[Entity(type: …)]`), in that order; see [Unique fields and indexes](#unique-fields-and-indexes). |
| `#[Timestamps]` | Class or property | `save()`, `insert()` and `update()` of a plain model set `createdAt` or `created_at` (the first the model has) when it is empty and `updatedAt` or `updated_at` on every write, matching the migration's `$t->timestamps()`. A `?string` property gets `Y-m-d H:i:s` text, an `?int` one the Unix time; a `?\DateTimeImmutable` one is stored as that text. Other types (a mutable `\DateTime` too) are not stamped. A class-backed type (`#[Entity(type: …)]`) is not stamped: it stores what its type definition says, so it sets these values itself or declares them as type fields. |

### `Model`

```php
$model->fill(array $data): void   // hydrate from associative array
$model->toArray(): array          // export public properties
```

A `bool` (or `?bool`) property reads what SQL stores for it: `1`/`0` and `'1'`/`'0'` (SQLite and
MySQL have no boolean type) become `true`/`false` in `fill()`, and null stays null, and an empty value is null for a `?bool` (false for a `bool`) (since 0.65;
before, such a property threw a `TypeError` on a SQL storage).

### `DataManager`

```php
$dm->find(Post::class, $id): ?Post
$dm->query(Post::class): QueryBuilder<Post>
$dm->save(Model $model): void           // INSERT when ID is empty, UPDATE otherwise
$dm->insert(Model $model): void         // explicit INSERT; an int id reads back lastInsertId()
$dm->update(Model $model): void         // explicit UPDATE
$dm->delete(Post::class, $id): void     // delete by class + ID
$dm->delete($model): void               // delete by model instance
$dm->raw(string $sql, array $bindings, string $storage): array  // raw SQL query
$dm->saveTypeIfVersion(DynamicRecord $record, int $expected): bool // compare-and-set, see below
```

`save()` detects an empty ID field and automatically issues an INSERT, then reads back `lastInsertId()` into the model — no need to pre-generate IDs for auto-increment tables. A `string` id that is empty (or unset) gets a UUID v4 before the write instead; `insert()` refuses an id that exists.

### `QueryBuilder`

All methods return `$this` for chaining except the terminal methods.

```php
->where('status', 'published')
->where('views', '>', 100)
->orWhere('featured', true)
->where('deleted_at', null)      // IS NULL; '!=' / '<>' with null: IS NOT NULL
->where('status', 'IN', ['draft', 'review'])
->orderBy('created_at', SortDirection::Desc)
->limit(10)->offset(20)
->search('php')          // searches all #[Field(searchable: true)] fields
->get(): ResultSet       // all results
->first(): ?Model
->paginate(perPage: 15, currentPage: 2): PaginatedResult
```

`where()` / `orWhere()` take `=`, `!=`, `<>`, `<`, `>`, `<=`, `>=`, `LIKE`, `NOT LIKE`, `IN`,
`NOT IN` (any case; `IN` / `NOT IN` take a list); `where($field, $value)` is `=`. Field names in
`where()`, `orWhere()`, `whereAny()`, `orderBy()` and `search()` are identifiers
(`[A-Za-z_][A-Za-z0-9_]*`). Anything else throws `\InvalidArgumentException` before any SQL is
built: never pass a request's operator or column name unchecked.

### Storage drivers

| Class | Backend |
|---|---|
| `JsonFileDriver` | One `.json` file per record at `{basePath}/{table}/{id}.json` |
| `SqliteDriver` | PDO-based SQLite via `PdoDriver` + `QueryCompiler` |
| `MysqlDriver` | PDO-based MySQL / MariaDB via `PdoDriver` + `QueryCompiler` (see [MySQL and MariaDB](#mysql-and-mariadb)) |

**Lazy connection (0.59).** `SqliteDriver`, `MysqlDriver` and any `PdoDriver` subclass take a
`\PDO` or a `\Closure(): \PDO`. Given a closure, the driver opens the connection on its first
query and runs its session setup (`configure()`) then; `connected()` tells whether it has.
Core builds the drivers from `config/data.php` with closures, so a request that runs no query
opens no database connection, and `preflow optimize` doesn't need the database. What that
changes for site code:

- A database outage now surfaces at the first query, inside the request (the error page), not
  at boot. A failed connect is retried on the next query.
- `\PDO` in the container is still the driver's own connection (the migrator, transactions and
  the driver share it), but it is a lazy singleton: getting it from the container opens the
  connection.
- A `PdoDriver` subclass that overrides the constructor must pass the `\PDO|\Closure` on and
  must not touch the connection in the constructor; per-connection setup belongs in
  `configure()`. Read the connection through `$this->pdo` (or `pdo()`), never keep your own `\PDO`.

### Your own storage driver (0.65)

A `config/data.php` entry names its driver by its key (`sqlite`, `mysql`, `json`) or by a
`'driver'` key: one of those names, a built-in class, or the class of your own
`Preflow\Data\StorageDriver`. Core builds that class through the container: the entry's other
keys are passed to its constructor by parameter name, the other parameters are autowired. An
unknown driver, a class that is no `StorageDriver` or a key the constructor doesn't take throws at
boot.

```php
'drivers' => [
    'sqlite' => ['path' => __DIR__ . '/../storage/data/app.sqlite'],
    'archive' => ['driver' => 'sqlite', 'path' => __DIR__ . '/../storage/data/archive.sqlite'],
    'pg' => ['driver' => App\Data\PgsqlDriver::class, 'dsn' => getenv('PG_DSN') ?: ''],
],
```

The class is built on its first use (the DataManager takes a closure for it): after the service
providers ran, so its constructor may take services a provider binds. The class and the option
keys are checked at boot.

For another SQL database, a `PdoDriver` subclass supplies a `Dialect` (every method of the interface, see "Raw writes"; `likeOperator()` decides
the case-insensitive match `where(…, 'like', …)`, `search()` and `CONTAINS` use, e.g. `ILIKE`;
`numericOrder()` the numeric sort; `missingColumn(\PDOException)` reads the column a failed write
names, so the "run `preflow migrate`" message works on that database) and registers its schema grammar for its PDO driver name, so
`Schema`, `RecordTableSync` and `preflow migrate` work:

```php
Grammars::register('pgsql', new PgsqlGrammar()); // a SchemaGrammar; in the driver or a provider's register()
```

Check a driver with `Preflow\Testing\Contract\StorageDriverContract` (preflow/testing).

### Migrations

```php
abstract class Migration
{
    abstract public function up(Schema $schema): void;
    public function down(Schema $schema): void {}
}
```

`Schema` methods: `create(string $table, callable $callback)`, `drop(string $table)`.

`Table` builder: `uuid`, `string`, `text`, `integer`, `boolean`, `json`, `timestamps`, `nullable()`, `primary()`, `index()`.

**Schema introspection** (SQLite and MySQL/MariaDB; any other PDO driver throws a `LogicException`):

```php
$schema->hasTable('post');                 // bool
$schema->columns('post');                  // ['id', 'title', ...] in table order
$schema->addColumn('post', 'subtitle');    // nullable column; type TEXT on SQLite, LONGTEXT on MySQL
```

`addColumn()` is what lets a re-runnable step add what a newer model needs without a
migration file.

**Changing a table in a migration** (since 0.65; SQLite 3.25+ and MySQL 8, through the storage's
`SchemaGrammar`, identifiers quoted as everywhere else):

```php
$schema->renameColumn('staff', 'passwordHash', 'password_hash'); // data, type and indexes kept
$schema->addColumns('staff', function (Table $table) {           // the create() builder; every column added nullable
    $table->boolean('active');
    $table->string('auth_epoch')->index();
});
$schema->fillNulls('staff', 'active', true);                     // backfill: rows where it is NULL; returns the count
$schema->copyColumn('staff', 'createdAt', 'created_at');         // old into new where new is NULL; returns the count
$schema->dropIndex('staff', 'idx_staff_email');                  // an index by name; refuses a missing one and SQLite's sqlite_autoindex_*
$schema->dropColumn('staff', 'createdAt');                       // SQLite 3.35+, MySQL; refuses an indexed column
```

All checks run before any SQL and throw `LogicException` (nothing changed): `renameColumn()`
refuses a column the table doesn't have and a new name it already has; `addColumns()` refuses a
column it already has, a primary key, and an index on a column that is neither added nor in the
table; `copyColumn()` and `dropColumn()` refuse a missing column; `dropColumn()` also refuses a column that
belongs to any index (a LogicException naming the index: drop it first with `dropIndex()`, as SQLite would fail and
MySQL would silently narrow a composite UNIQUE index) and SQLite older than 3.35 ("dropColumn needs SQLite 3.35 or newer"). Column names are compared without
case, as SQLite and MySQL do. Check with `columns()` first to write a migration that converges from
any shape of the table (preflow/starter-folio's staff upgrade merges an old column into its new one
with `copyColumn()` + `dropColumn()`). A registered `SchemaGrammar` implements `renameColumn()`,
`copyColumn()`, `dropColumn()` and `dropIndex()` (each returns the SQL), `indexColumns()` (index name => its columns) and
`assertCanDropColumn()` (throws a LogicException when the server can't drop a column).

**Converting a column** (since 0.75): `convertColumn()` changes an existing column's type,
length, collation or nullability in place and keeps its data, its default, its indexes and the
other columns. On MySQL/MariaDB it is one `ALTER TABLE … MODIFY` (NULL / NOT NULL and DEFAULT
carried over unless changed); SQLite cannot change a column, so the table is rebuilt in one
savepoint (new table, rows copied, old one dropped, new one renamed, indexes and triggers made
again; views keep working). The new column is a record-table `ColumnSpec`, typed as
`RecordTableSync` makes it (nullability kept), or one column declared with the `create()` builder
(NOT NULL unless `nullable()`, as in `create()`):

```php
use Preflow\Data\Schema\ColumnKind;
use Preflow\Data\Schema\ColumnSpec;

// An indexed record field made LONGTEXT before 0.72: VARCHAR(191) COLLATE utf8mb4_bin on MySQL.
$schema->convertColumn('object', new ColumnSpec('state', ColumnKind::Short));      // length: 191 by default
$schema->convertColumn('object', new ColumnSpec('price', ColumnKind::Number));     // DOUBLE / NUMERIC
$schema->convertColumn('staff', fn (Table $t) => $t->string('name', 80)->nullable());
$schema->convertColumn('staff', fn (Table $t) => $t->boolean('active'));            // NOT NULL now
```

It refuses (LogicException, nothing changed) a column the table doesn't have, a value longer than
a bounded new type (`string`, `uuid`, a Short spec; counted in characters), NULLs in a column that
becomes NOT NULL (`fillNulls()` first), and a builder callback with no or several columns, a primary
key or an index (convert, then `addColumns()`/`create()` the index). A value the new type cannot
hold otherwise (text into a number) is the server's call: MySQL in strict mode refuses it, SQLite
keeps it. On SQLite it also refuses a table whose definition has what the rebuild cannot carry
over (`REFERENCES`, `CHECK`, `UNIQUE`, `COLLATE`, `CONSTRAINT`, `AUTOINCREMENT`, a generated column,
`WITHOUT ROWID`, `STRICT`); tables made by `create()` and `RecordTableSync` have none. With foreign
keys on, the rebuild switches them off and checks them before it commits; inside an open
transaction it cannot, so there it refuses unless they are off. Check `columnTypes()` first to skip
a column that is converted already. A registered `SchemaGrammar` implements
`convertColumn(\PDO $pdo, string $table, string $column, string $type, ?bool $nullable): void`
(it runs the statements itself; `$nullable` null keeps the column's nullability).

**Several migration paths.** `new Migrator($pdo, [$dir1, $dir2])` takes one path or a list
and runs the files of all of them in name order; the same file name in two paths is one
migration. `preflow migrate` (see `preflow/devtools`) uses the paths of
`Preflow\Data\Migration\MigrationRegistry`, a container singleton that packages fill from
their service provider:

```php
$registry = $container->get(\Preflow\Data\Migration\MigrationRegistry::class);
$registry->addPath(__DIR__ . '/../migrations');        // a missing directory is ignored
$registry->afterMigrate('mypkg.tables', fn (): array => ['created table x']); // lines to print
```

The app's `migrations/` and core's own (the `preflow_throttle` table) are registered by
the application. A post-migrate step runs on **every** `preflow migrate`, so it must be
idempotent (`hasTable()` / `columns()` before changing anything); registering the same step
name twice throws.

Related behaviour: a storage name that is not configured is an error, never a silent
fallback to the default; a record key with no column fails with a message naming
`preflow migrate`; and `orderBy($field, $direction, numeric: true)` compares as numbers even
where the column stores text.

### Auto-increment IDs

Place `#[Id]` on an `int` property and leave it at `0` (or unset). `save()` / `insert()` will issue an INSERT and read the generated ID back via `lastInsertId()`.

```php
#[Entity(table: 'comments', storage: 'sqlite')]
final class Comment extends Model
{
    #[Id]
    public int $id = 0;

    #[Field]
    public string $body = '';
}

$comment = new Comment();
$comment->body = 'Great post!';
$dm->save($comment);
echo $comment->id; // e.g. 42 — populated after insert
```

### Raw SQL

Use `raw()` to run a query that the fluent builder cannot express. Results are returned as plain arrays.

```php
$rows = $dm->raw(
    'SELECT p.*, COUNT(c.id) AS comment_count FROM posts p LEFT JOIN comments c ON c.post_id = p.id GROUP BY p.id',
    [],
    'sqlite',
);
```

### Raw writes

`execute()` runs a write the builder cannot express (a conditional `UPDATE`, a `DELETE`,
an upsert) and returns the affected rows. Only SQL (PDO) storages run it; any other driver
throws a `LogicException`. Ints, bools and nulls are bound with their type, so arithmetic and
comparisons in SQL work on numbers.

```php
$taken = $dm->execute(
    'UPDATE seats SET free = free - 1 WHERE id = ? AND free > 0',
    [$id],
    'sqlite',
);   // 1 if a seat was taken, 0 if none was left
```

One conditional statement decides by its affected rows, so concurrent requests cannot both
take the last seat. A package writing its own SQL asks the driver for `PdoDriver::dialect()` and
lets the `Dialect` write what differs between databases, never checking for `MysqlDialect` or
`SqliteDialect`, so the SQL also runs on a third-party dialect (PostgreSQL, say):
`quoteIdentifier()`; `insertOrUpdateSql($table, $columns, $idField, $set)` for one statement that
inserts a row or updates the one with that id with SQL expressions, which read the existing row
through table-qualified columns (`"table"."column"`: PostgreSQL rejects a bare name there), null
where the dialect has none (then read and write under a lock; preflow/core's throttle does); `wholeNumber($expr)` for a
column that may hold a number or text such as `''` or `'3.0'`, safe inside an `UPDATE` under
MySQL's strict mode (preflow/codes' use counting); `numericValue()`, `lockingRead()` and the others
in `Dialect`. A custom `Dialect` must provide `insertOrUpdateSql()` and `wholeNumber()` since
0.66.1; preflow/testing's `DialectContract` checks them on its database.

### Observing reads and writes (the page cache)

`DataManager::observe()` attaches a `Preflow\Data\Observe\DataObserver`: it is told about every
read and write through the manager (`read`, `wrote`, `rawRead`, `rawWrote`). preflow/core's page
cache attaches one when it is on, to learn what a page read and what a write changed; nothing
is observed otherwise (`observe(null)` stops it). Writes are reported after they ran, also inside
a transaction that may still roll back. The tables of raw SQL are read conservatively
(`SqlTables`): a statement it can't be sure of counts as the whole storage.

```php
// Code that reads or writes around the manager (straight through driver(), an import):
$dm->reportRead('default', 'member', 42);     // a read of one record (null: a listing)
$dm->reportWrite('default', 'member', 42);    // a write of one record (null: many or unknown)

// A memo that answers later calls from memory: capture the reads once, replay them on each hit,
// so every page using the memo still depends on what it holds.
[$value, $reads] = $dm->captureReads(fn () => $this->load());
$dm->replayReads($reads);                     // on every memo hit

// Queries:
$dm->queryType('asset')->byIds(['a1', 'a2'])->get();   // reported as one record read per id
$dm->queryType('page')->where('slug', $slug)->unreported()->first(); // reported by the caller
```

- `byIds($ids)` filters on the id field and is reported as one record read per id (found or
  not), not as a listing of the table: a page showing these records depends on them alone.
  Another filter added to the query makes it a listing again.
- `unreported()` keeps the query's runs from the observer, for a lookup whose caller reports
  what it really depends on (Folio finds a page by its address and reports the record and the
  address, not the whole table).
- `reportedStorage($name)` is the name a storage is reported under: `default` for the default
  driver, whatever it is configured as.

### Version-checked saves (compare-and-set)

Reading a record, comparing its version and then saving it leaves a window: two requests
that both read version 3 both pass the check, and the later save overwrites the earlier one.
`saveTypeIfVersion()` closes it. The write itself carries the version it expects:

```php
$record = $dm->findType('job', $id);
$loaded = (int) ($record->get('_version') ?? 0);   // what the editor loaded
$record->set('title', $title);

if (!$dm->saveTypeIfVersion($record, $loaded)) {
    // someone else saved in between: nothing was written
}
// written with _version = $loaded + 1
```

- Of two writers holding the same version exactly one gets through; the other gets `false`
  and its record keeps the version it had (a record that had none gets none back).
- The record is written with `$next` (default `$expected + 1`) in `$versionField` (default
  `_version`). `next: $expected` writes without moving the version on.
- A missing or empty stored version counts as 0. A record that is not stored yet is
  inserted when `$expected` is 0; of two such inserts one fails.
- Validation runs as in `saveType()` (`validate: false` skips it).
- SQLite and MySQL check in the write's own `UPDATE … WHERE id = ? AND _version = ?`, in a
  transaction (one the caller opened is used as it is). The JSON driver checks and writes
  under a lock file (`.lock` in the type's folder). All three implement
  `Preflow\Data\VersionedStorage::saveIfVersion()`; a driver that does not gets a plain
  read, compare and write (and one `E_USER_NOTICE` per driver class saying so).
- SQL compares the stored version as text: only a canonical integer string matches (`'5'`,
  not `'05'` or `'5.0'`, which JSON reads as 5); `NULL` and `''` match 0. Versions are
  written canonical.
- Inside your own transaction: a save committed by another connection after your first read
  gives `false` on MySQL; MariaDB 11.6+ (`innodb_snapshot_isolation`) and SQLite in WAL mode
  refuse the write with an error instead, and your transaction has to roll back. In the
  driver's own transaction a deadlock or that error is a lost race (`false`).

Folio's `Publisher::saveDraft()` uses it, and throws `VersionConflict` instead of returning
`false`.

### Saving null clears a column

`save()` writes every key it is given, `null` included: `$record->set('expires_at', null)`
clears the column on SQLite, MySQL and JSON alike (before 0.42 SQL drivers dropped nulls, so a
clear needed `''`). A key the caller does not pass is left alone: a partial save no longer
nulls the other columns on SQLite (it used `INSERT OR REPLACE`, which replaced the whole row;
it is an upsert on the primary key now, and on MySQL an `UPDATE` with an `INSERT` for a new
record). A save never touches another record: a value colliding with a unique index is an error
(below), not an overwrite.

A migration-made column that is `NOT NULL` now fails on a `null` instead of silently keeping
its old value.

## Unique fields and indexes

A field is declared unique in the model JSON or with the attribute, optionally scoped by other
fields (unique within them), and may carry an index and a length. A type may also declare
composite indexes, each over several of its fields in order:

```json
"slug":   { "type": "string", "unique": true, "length": 191 },
"code":   { "type": "string", "unique": ["purpose"] },
"city":   { "type": "string", "index": true }
```

```json
{ "key": "job", "table": "jobs", "fields": { … }, "indexes": [["state", "updated_at"]] }
```

```php
#[Field(unique: true, length: 320)] public ?string $email = null;
#[Field(unique: ['purpose'])] public ?string $code = null;

#[Index('state', 'updated_at')]          // Preflow\Data\Attributes\Index, repeatable
#[Entity(table: 'jobs', type: 'job')]
final class Job extends Model { … }
```

- **Validation.** `save()`, `insert()`, `update()`, `saveType()` and `saveTypeIfVersion()` add the
  `Preflow\Data\Validation\Unique` rule for every unique field (per locale for a translatable
  field). A duplicate is a `ValidationException` with `validation.unique` ("already taken") on the
  field, not an exception from the database. `DataManager::validateUnique($record)` checks only
  the unique fields; `DataManager::validateStorable($record)` checks what the storage itself
  would refuse, the unique fields and the length of every bounded column (`validation.max.string`),
  for a save that skips the other rules (Folio uses it for draft saves and restored revisions). Empty values (`null`, `''`) and values with
  a `null` scope are never duplicates. Comparison is exact: case and accents count, on every
  database. App forms use the rule directly:
  `new Unique($dm, 'job', 'slug', scope: ['company'], exceptId: $id)` (the scope is read from the
  validated data, or given as `['company' => 'acme']`).
- **Database guard.** On SQL the unique index is the last line: two requests passing the
  validation together, one gets a `ValidationException` on the field from the index, and both
  rows stay as they were. A save with `validate: false` can therefore throw `ValidationException`
  too. Translatable unique fields (stored as JSON, no index) and every unique field on JSON
  storage are checked and written under the type's lock; the JSON check reads every record of
  the type, so unique fields on large JSON types are slow.
- **Plain models need the index in their migration.** Tables of types (model JSON files,
  `#[Entity(type: …)]` classes) are made by sync, unique indexes included. A plain `#[Entity]`
  model's table comes only from the app's migration: `#[Field(unique: true)]` there adds the
  validation rule, not the index, so put `->unique()` on the column in the migration. Without
  it the rule alone does not stop two concurrent saves of the same value on SQL storage.
- **Empty is NULL.** An empty value in a unique field is stored as `NULL` (a unique index would
  refuse a second `''`). For a `Model`, the property must accept `null`.
- **Index and length.** `unique` and `index` make a short text column: `VARCHAR(length)` (default
  191) with `utf8mb4_bin` on MySQL, so the index compares exactly; validation then limits the
  value to `length` characters, also for the scope fields of a unique field and the fields of a
  composite index. Free text, blocks and translatable fields get no index. `__revisions` tables
  are never checked or indexed; `__published` tables are typed like their working table and carry
  its declared indexes (field and composite), plain ones only (never unique).
- **Composite indexes.** `"indexes"` (or `#[Index]`) lists the fields of each index in order; the
  index is `idx_{table}_{field1}_{field2}…` (shortened past 64 characters like every index name).
  Refused when the type is defined, with a message naming the type and the index or field: an
  index without fields, over a name that is not one of the type's fields (the id is not one),
  over a translatable field (free text gets no index), with a field twice, and two indexes with
  one name (`["company"]` beside `index: true` on `company`, or `["a", "b"]` beside a field `a_b`
  that is indexed or unique). An index over just a unique field (`["slug"]` beside `unique` on
  `slug`) is refused too: its unique index serves. A unique field and its scope fields are
  indexed plain in `__published` tables, so a composite named like one of them is refused; one
  over just a scope field is that field's index there. A class's admin file may not set
  `"indexes"` (it is schema).
- **What sync does.** `preflow migrate` makes every declared index that is missing, on new and
  existing columns alike: `idx_{table}_{field}` for `index`, the composite ones, and
  `uniq_{table}_{field}` (over the field and its scope) for `unique`. An index is found by its
  name or by its columns: any index over the same columns in the same order (one a migration
  made under its own name) does for a plain one, a unique one over them for a unique one, so a
  second migrate changes nothing. A unique index is made on an existing column only when every
  column it covers has its expected type and no value is there twice (empty values never count);
  otherwise migrate prints
  `warning: post.slug has no unique index (2 values are there more than once); remove the duplicates (tools/convert-typed-columns.php --check lists them), the next migrate adds it`
  (or the drift warning when a column has another type). If a save writes a duplicate while the
  index is being made, the database refuses it and migrate warns the same way. On MySQL a plain
  index over an existing TEXT column (made before it was indexed) covers its first `length`
  characters, at most 191. If the server refuses a plain index, migrate prints a warning naming
  it and the server's reason and goes on. A declared plain or composite index over a free-text column (a `text` or `blocks`
  field, a multiple relation, a custom kind stored as text) can't be made: migrate prints
  `warning: post: no index over state, body (body is free text); see the data README` on every
  run; take the field out of the index or make it a string. MySQL caps a key at 3072 bytes
  (counted as `length` or 191 × 4 per short text field, 8 per number, date or yes/no): there an
  index whose key may be longer, plain or unique, is not made, and migrate prints
  `warning: post: no index over a, b, n (a key of up to 3208 bytes; MySQL allows 3072); give its text fields a shorter "length"`
  on every run. SQLite has no such limit.
- **Upgrading to 0.72.** Before 0.72 an index came only with a new column.
  - The first `preflow migrate` after the upgrade adds every declared index an existing table
    lacks (the working and `__published` tables); on big tables that can take a while, so run it
    when the site is quiet. On existing `__published` tables that includes the plain indexes of
    unique fields and their scope fields.
  - Declaring `index` on an existing string, select or relation field that was stored as free
    text, or naming it in a composite index, changes its expected kind to short: migrate prints
    the drift warning until the table is converted (Converting record tables below), the index
    over a TEXT column covers its first 191 characters on MySQL, and validation limits the value
    to 191 characters (its `length`).
  - `SchemaGrammar::createRecordIndex()` takes two more parameters, `array $columnTypes = []`
    and `array $lengths = []` (the declared lengths of short-text columns: MySQL's prefix over a
    TEXT column is that length, at most 191), and the interface has a new
    `uniqueIndexes(\PDO $pdo, string $table): array` (the names of the unique indexes). A grammar
    of your own (`Grammars::register()`) must add them.
- **Upgrading to 0.75.** `SchemaGrammar` has a new method,
  `convertColumn(\PDO $pdo, string $table, string $column, string $type, ?bool $nullable): void`
  (behind `Schema::convertColumn()`). A grammar of your own (`Grammars::register()`) must add it.
- Custom field types tell the data package their column with `Preflow\Folio\Field\HasColumnKind`
  (`columnKind(array $config): ColumnKind`) or `FieldKinds::column($type, ColumnKind|Closure)`.

## Slugs

`Slugs::unique()` makes a slug no other record has, from `Preflow\Validation\Rules\Slug::clean()`
("Müller" becomes `mueller`): the cleaned text, then `-2`, `-3`... until it is free.

```php
use Preflow\Data\Slugs;

$slug = Slugs::unique($data, 'job', $title);                       // a type key, or a model class
$slug = Slugs::unique($data, Job::class, $title, exceptId: $job->uuid); // the record being renamed keeps its slug
```

The signature is `unique(DataManager $data, string $type, string $text, string $field = 'slug',
?string $exceptId = null, int $max = 80, string $fallback = 'item')`. The result is at most `$max`
characters, suffix included: a long text is cut at a hyphen when one is in the last half of
`$max`, otherwise hard-cut, and a suffix shortens the base rather than the total. A text without
letters or digits gives `$fallback`. `$exceptId` (a string or an int) is left out of the lookup, so a record renamed to its own slug
keeps it even when other records share it. A field the type does not have, or a translatable
one (its value is a map per locale), is an `InvalidArgumentException`.

For an import that holds its slugs in memory, `Slugs::among($text, $taken)` does the same against
`$taken` (`array<string, true>`, by reference) and adds what it returns.

## MySQL and MariaDB

`preflow migrate`, `Schema` introspection and Folio's table sync run on MariaDB 10.6+ (tested;
MySQL 8 expected to work, not yet tested) exactly as on SQLite; a schema grammar picked from the
PDO driver name writes the SQL. SQLite output is unchanged (same DDL, no new pending migrations
for existing sites). Any other PDO driver throws `No schema grammar for PDO driver "<name>".`

**Config** (`config/data.php`). `host`, `port`, `database`, `username` and `password` fall back to
the `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` and `DB_PASS` environment variables:

```php
return [
    'default' => 'mysql',
    'drivers' => [
        'mysql' => [
            'host'     => '127.0.0.1',     // or DB_HOST
            'port'     => 3306,            // or DB_PORT
            'database' => 'myapp',         // or DB_NAME
            'username' => 'myapp',         // or DB_USER
            'password' => '',              // or DB_PASS
        ],
        'json' => ['path' => 'storage/data'],
    ],
    'aliases' => ['codes' => 'default', 'throttle' => 'default'],
];
```

`default` names the driver for models without a `storage`; `aliases` map purpose names to a
configured driver (see Aliases below). `preflow migrate` migrates the site's SQL storage; with
several SQL drivers pass `--storage`.

**Column types.** SQLite stays TEXT/INTEGER; MySQL gets sized types:

| `Table` method | SQLite | MySQL |
|---|---|---|
| `uuid()` | TEXT | VARCHAR(64) COLLATE utf8mb4_bin |
| `string($n = 255)` | TEXT | VARCHAR($n) (`COLLATE utf8mb4_bin` when `primary()`) |
| `text()` | TEXT | TEXT |
| `json()` | TEXT | LONGTEXT (not `JSON`: MySQL's type rewrites key order and spacing) |
| `integer()` | INTEGER | BIGINT |
| `boolean()` | INTEGER | TINYINT(1) |
| `timestamps()` | TEXT, nullable | VARCHAR(32), nullable |
| `addColumn()` default | TEXT | LONGTEXT |

**Record tables** (the tables `RecordTableSync` makes from a type definition: Folio's types,
codes, members, media assets) use `id VARCHAR(191) COLLATE utf8mb4_bin PRIMARY KEY` (TEXT on
SQLite), and every other column is nullable and typed by its field's column kind
(`FieldKinds::columnKindOf()`):

| Kind | Field types | SQLite | MySQL |
|---|---|---|---|
| text | text, blocks, translatable fields, JSON, strings that are neither unique nor indexed | TEXT | LONGTEXT |
| short | a string, select or single relation that is unique or indexed | TEXT | VARCHAR(`length`, default 191) COLLATE utf8mb4_bin |
| number | number | NUMERIC (`'12'` stays 12, sorts numerically) | DOUBLE |
| boolean | boolean | INTEGER | TINYINT(1) |
| date | date | TEXT | VARCHAR(32) (ISO text) |

Columns are typed only when sync creates them (with the table, or added later). The unique
(`uniq_{table}_{field}`, over the field and its scope), plain (`idx_{table}_{field}`) and composite
(`idx_{table}_{field1}_{field2}…`) indexes are made whenever they are missing (see
[Unique fields and indexes](#unique-fields-and-indexes)); names past 64 characters are shortened
with a crc32 hash. An existing column is never altered: a column whose type differs from its kind,
or a unique field without its index, is printed as a warning (`warning: post.seats is text, expected number;
convert the table (see the data README)`; see Converting record tables below). Packages register
their tables with `RecordTables`: it lists every record table, whichever package syncs it.

**Converting record tables.** Sync never changes a column it did not create, so a column the
warning names is converted on purpose:

- An untyped column on an existing site (a table made before typed columns) is converted once
  with the monorepo's one-time script `tools/convert-typed-columns.php`, which finds the tables
  through `RecordTables`. `php tools/convert-typed-columns.php <site-dir> --check` lists every
  value that does not fit (a number field holding text, a yes/no other than `''`, `0`, `1`,
  `true`, `false`, a date in no stored format, a short text over its length, a duplicate in a
  unique field) with table, record id, field and reason, and changes nothing. Without `--check`
  it backs the database up to `storage/backups/` (SQLite: a file copy; MySQL: a dump), converts
  table by table (a rerun continues after an interruption and keeps the first backup), and checks
  again. Empty strings in number, yes/no and date columns, and in unique fields, become `NULL`.
  `--sqlite=<file>` runs against a copy instead of the configured SQLite file (its backup then goes
  to `backups/` beside the copy), `--backup-dir=<dir>` puts the backup elsewhere, and
  `--storage=<driver>` picks the SQL storage when several are configured. A MySQL backup needs
  `mysqldump` or `mariadb-dump` on the machine running the script. The site needs `preflow/core`
  0.42 or later too (it binds `RecordTables`; the script refuses otherwise). Upload the whole
  `tools/` directory to a site, run it with writes stopped, remove it afterwards. To restore: copy
  the SQLite backup back, or load the dump with the site's own database credentials
  (`mysql --defaults-extra-file=<option file> <database> < <backup>.sql`). Until a site is
  converted, `preflow migrate` prints the drift warnings on every run.
- `RecordTableSync::specs()` and `RecordTableSync::indexes()` are public (the script builds its columns
  and indexes with them, so it makes the indexes migrate would).
- A column whose kind changed later (a field turned translatable, a relation became `multiple`,
  a field's type was edited or it became unique) is converted with a migration in the project,
  with `Schema::convertColumn()` (since 0.75; see "Converting a column"), for example
  `$schema->convertColumn('object', new ColumnSpec('state', ColumnKind::Short))` for a field that
  became indexed.

`preflow_migrations` is `migration VARCHAR(191) COLLATE utf8mb4_bin PRIMARY KEY, ran_at VARCHAR(32) NOT NULL`.

**64-character names.** MySQL caps table and index names at 64 characters. The index name is
`idx_{table}_{column}` and Folio adds `{table}__published` and `{table}__revisions`, so long
table names can cross it; the grammar throws a `LogicException` naming the identifier instead of
sending SQL MySQL would refuse. SQLite has no such limit.

**TEXT columns can't be indexed.** `$t->text('body')->index()` throws a `LogicException`
(`Cannot index TEXT column "posts.body" on MySQL: use string() for indexed columns.`); use
`string()` for anything indexed or unique. SQLite does not care, so a migration that indexes
`text()` passes there and fails on MySQL.

**Composite and unique indexes.** `$t->unique('company_id', 'slug')` declares one unique index over
the columns, in that order; `$t->index('a', 'b')` a plain one. Without arguments both mark the
column just defined (`$t->string('slug')->unique()`). Names are `uniq_{table}_{columns}` and
`idx_{table}_{columns}` (columns joined by `_`), shortened with a crc32 past 64 characters; a
one-column `index()` keeps its name `idx_{table}_{column}`. Every column must exist and not be
`text()`, checked before anything is created. Empty (`NULL`) values never collide in a unique index.
On MySQL, `string()` columns compare case-insensitively (`utf8mb4_unicode_ci`), so `Ab` and `ab`
collide there but not on SQLite.

**Charset and collation.** Tables are created `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci` (MariaDB 10.6 has no `utf8mb4_0900_*`), identifiers in backticks.
Ids and keys (`uuid()` columns, primary `string()` columns, Folio record ids,
migration names) are `utf8mb4_bin`, so they compare exactly, as on SQLite and JSON: `A`, `a` and
`ä` are three records. **Known difference from SQLite:** value columns stay
`utf8mb4_unicode_ci`, which compares case-, accent- and trailing-space-insensitively, so values
that differ only in case, accents or trailing spaces (`Abc` and `abc`, `cafe` and `café`) are
equal in `WHERE` and collide in a unique index on MySQL while SQLite keeps them apart. Slugs are
values: on MySQL a page with slug `cafe` can shadow one with `café`. (`utf8mb4_bin` pads too, so
an id with a trailing space still equals the one without.)

**Connection.** `createMysqlDriver` builds the `PDO` with `MysqlDriver::connectionOptions()`, and
`MysqlDriver` sets the session; together they give:

- `PDO::ATTR_ERRMODE => ERRMODE_EXCEPTION`, so failures throw instead of returning false.
- `Pdo\Mysql::ATTR_FOUND_ROWS => true`: an `UPDATE` that matches a row but changes nothing still
  counts as 1 affected row, as on SQLite, so rowCount-based code (codes, tokens, throttle) behaves
  the same. This can only be set when the connection is opened: when you build the `PDO`
  yourself, pass `MysqlDriver::connectionOptions()` as its options.
- Session `sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'`:
  too-long values are an error, not silently cut, and the result does not depend on the server's
  default mode. No `ANSI_QUOTES`, since identifiers use backticks.
- `utf8mb4` for the connection.
- PHP booleans are written as `1`/`0` (strict mode refuses `''` for `false` in a `TINYINT(1)`).

The upsert keeps `VALUES(col)` because MariaDB has no row-alias syntax.

**Choosing storage.** Storage is chosen per type: `"storage"` in a model's JSON, or
`#[Entity(storage: '...')]` on a class. Aliases map purposes (`codes`, `auth`, `throttle`) to a
driver. Moving a type to another storage means: change its storage, `preflow migrate`, then
`folio:export` and `folio:import`. Two limits today:

- `config/data.php` selects the driver class by its key (`sqlite`, `mysql`, `json`), so there is
  one connection per driver kind; two MySQL databases at once are not possible yet (aliases only
  add names).
- There are no SQL joins across storages; relations between types on different storages resolve
  by id.

PostgreSQL would need a driver, a dialect and a schema grammar (`Grammars::for` refuses unknown
PDO drivers today).

**Running the MySQL tests.** They run when `MYSQL_HOST` and `MYSQL_DATABASE` are set (plus
`MYSQL_USER`, `MYSQL_PASS`) and are skipped otherwise, so `vendor/bin/phpunit` stays green without
a server. Put the values in a gitignored `.env.mysql-test` at the repo root and run:

```bash
composer test:mysql     # sources .env.mysql-test, runs phpunit --group mysql
```

The tests share one database and drop the tables they create.

## Relations and `onDelete`

A dynamic type declares a relation on a field of a relation kind (`relation` by default;
`$dm->fieldKinds()->relation('mytype')` registers more, `->text('mytype')` registers extra
free-text kinds). The pointer is stored as today: an id string, or a JSON array string when
`multiple` is true.

```json
"company": {
  "type": "relation",
  "relation": { "to": "company", "multiple": true, "onDelete": "restrict", "labelField": "name" }
}
```

| Key | Meaning |
|---|---|
| `to` | Target type. A field without it is no relation. |
| `multiple` | Several targets (JSON array) instead of one. Default `false`. |
| `onDelete` | What deleting a *target* does to the pointing records (see [Delete plans](#delete-plans)): `detach` (default) removes the id from the pointer, `cascade` deletes the pointing record too, `restrict` refuses the delete. Any other value throws `InvalidArgumentException`. |
| `onSelfDelete` | The same choices, applied when the owner deletes their own record (`deletePlan(..., DeletedBy::Self)`) instead of staff. Unset follows `onDelete`; any other value throws `InvalidArgumentException`. |
| `labelField` | The target field shown and searched as its label. |
| `metaField` | (since 0.75) The target field a picker shows under the label, such as an email under a name. Read as `Relation::$metaField`; `#[Relation(metaField: 'email')]` on a model class. |

```php
$dm->relationsOf('job');       // list<Relation>: what 'job' declares
$dm->relationsTo('company');   // list<Relation>: every relation, across all types, pointing at 'company'
$dm->getTypeRegistry()->keys(); // every type key, sorted
```

A site without a models directory has no type registry. A package that defines its types in code
calls `$dm->ensureTypeRegistry(new TypeRegistry($path))`: it sets that registry only when there is
none and returns the one in use (the members core does this for its `member` type).

Relations work across storages: a SQL type may point at a JSON type and back.

## Aggregates

`aggregate()` on a query builder groups the records its filters select and counts, sums, averages or finds the
smallest or largest value per group. It works on typed models and on dynamic types, on SQLite, MySQL/MariaDB and
JSON storage, and gives the same rows on all of them.

```php
$rows = $dm->queryIn($answerType)->where('correct', true)->aggregate()
    ->groupBy('member')
    ->count()                         // result name 'count' unless you pass another
    ->countDistinct('step', 'steps')
    ->sum('seconds', 'total')
    ->avg('seconds', 'average')
    ->min('seconds', 'fastest')
    ->max('seconds', 'slowest')
    ->having('count', '>=', 3)        // on a result name, a number
    ->orderBy('total', SortDirection::Desc)
    ->limit(10)->offset(0)
    ->get();
// [['member' => 'm1', 'count' => 3, 'steps' => 2, 'total' => 22.5, ...], ...]
```

The builder's `where()`, `whereAny()`, `search()`, `searchLoose()` and locale decide which records are grouped,
as they are when `aggregate()` is called (later changes to the builder do not reach it). The builder's own
`orderBy()`, `limit()` and `offset()` do not apply; the aggregate query has its own. Without `orderBy()` the rows come
back by the group fields, ascending, and the group fields also break ties. Without `groupBy()` you get one row of
totals, also when no record matches. Translatable group fields use the builder's locale.

Rows are arrays keyed by the group fields and the result names. Fields are the ones of the type (or the model's
`#[Field]` properties and its id); a field name is never SQL.

**Null rules.**

- `count()` counts rows, whatever their values.
- `countDistinct`, `sum`, `avg`, `min` and `max` of a field skip null (and `''` for number fields).
- `avg`, `min` and `max` of no values are `null`; `sum` of no values is `0`. Records with no value in a group field
  form one group with `null`.
- `having()` never matches a `null` result. In ordering, null comes first ascending and last descending.

**Types.** `count` and `countDistinct` are `int`. Other numbers are `int` when integral (within 2^53), else `float`.
Group values and `min`/`max` of boolean fields are PHP `bool` on every storage; those of text and date fields are
strings as stored.

**Case.** Text group values are case-sensitive everywhere: `'a'` and `'A'` are two groups. On MySQL/MariaDB the
group fields (and `countDistinct`, `min`, `max`, `orderBy`, `having` on text) use a binary collation
(`utf8mb4_bin`), so the rows match SQLite and JSON. The `where()` filters keep the table's collation, so a
`where('grade', 'a')` still matches `'A'` on a `_ci` table. The binary comparison keeps MySQL from using an index
for the GROUP BY; aggregates scan the filtered rows anyway.

**Refused** (`\InvalidArgumentException`, when the method is called unless noted):

- a field that does not exist, in `groupBy()` or a measure (this also refuses SQL fragments);
- result names that are not `[A-Za-z_][A-Za-z0-9_]*`, are used twice, or (at `get()`) equal a group field;
- `groupBy()` on a field that holds multiple relations (grouping by several relations is a later item);
- `sum()` and `avg()` of a field that is not a number field;
- `having()` on an unknown result name, on `min`/`max` of a non-number field, with an unknown operator (`=`, `!=`,
  `<`, `<=`, `>`, `>=`) or a non-finite value;
- at `get()`: an `orderBy()` name that is neither a result name nor a group field, and a query with neither group
  nor measure;
- negative limit or offset.

A storage driver that does not implement `AggregatingStorage` gives a `\LogicException` naming the driver. A custom
`Dialect` implementation must now provide `numericValue()` and `exactText()` (see the 0.44 notes).

## `whereAny` operators

`whereAny()` takes a list of conditions of which at least one must hold (an OR-group).
Several calls are AND-ed with each other and with `where()`.

```php
$dm->queryIn($typeDef)->whereAny([
    ['field' => 'title',    'operator' => 'CONTAINS', 'value' => 'engineer'],
    ['field' => 'company', 'operator' => 'IN',       'value' => ['a1', 'b2']],
    ['field' => 'tags',     'operator' => 'HAS',      'value' => ['x']],
])->get();
```

| Operator | Matches when |
|---|---|
| `=` | The field equals the value. |
| `CONTAINS` | The field contains the value as a substring, case-insensitively. `%`, `_` and the escape character match literally. |
| `IN` | The field equals one of the listed values. An empty list matches nothing. |
| `HAS` | The field, an id string or a JSON array string, holds at least one of the listed ids. |

Driver differences: SQLite's `LIKE` folds case for ASCII letters only (umlauts and other
non-ASCII letters match case-sensitively); the JSON driver folds with `mb_stripos` and
compares full Unicode. On MySQL/MariaDB `CONTAINS` and `IN` follow the column's collation,
so a case- or accent-insensitive collation matches more than the other drivers do.

## `searchLoose`

```php
$dm->queryIn($typeDef)->searchLoose('senior engineer')->get();
$dm->queryIn($typeDef)->searchLoose('engineer', fields: ['title', 'company'])->get();
```

The term is split on whitespace. Every word must match (AND); a word matches when it is a
case-insensitive substring of at least one field (OR). `%`, `_` and quotes in the term match
literally. Unlike `search()`, which keeps using exactly the `searchable: true` fields:

- By default it searches every text-kind field (`string`, `text`, plus kinds registered with
  `fieldKinds()->text()`) and every relation field, *unless* the field declares
  `"searchable": false` (or `#[Field(searchable: false)]` on a typed model, where only
  `string` properties count). A field without a `searchable` key is included — except a
  hidden one (`"hidden": true`), which is searched only when it says `"searchable": true`.
  Mark secrets (password hashes, tokens) `"searchable": false` as well.
- `fields:` restricts the search to the named fields; names the type does not have are dropped,
  and so are `searchable: false` fields and hidden fields without `searchable: true` — naming a
  field never makes it searchable. A type with nothing searchable matches no record.
- A relation field is searched one level deep: the word is matched against the target's
  `labelField` (or its first `string` field that is not hidden) and its text fields, by the
  same rules (hidden fields left out), and the records pointing at a matching target match.
  Relations are not followed further.
- A word matching more than `QueryBuilder::RELATION_MATCH_LIMIT` (1000) records of a related
  type is too unspecific for that relation: the relation does not count for that word (it can
  still match the type's own fields or other relations). All words of a query together bind at
  most `QueryBuilder::RELATION_ID_BUDGET` (10000) related ids; a relation that would go over
  it does not count for that word either.
- Repeated words count once; words after the first `QueryBuilder::MAX_LOOSE_WORDS` (32) are
  ignored.
- Relations are resolved when `get()` runs, so `locale()` applies to translatable fields on
  both sides. They need a `DataManager`; a builder made without one searches the type's own
  fields only.

## JSON storage cache

`JsonFileDriver` takes an optional second argument, a cache directory. With it, a query
reads one cache file per type and data path (`json-{type}-{key}.json`, where `{key}` is a short hash
of the resolved data path, the same for every spelling of it) instead of every record file. Drivers
of different data paths (each test's own data, parallel workers) can share one cache directory. The cache is
checked on every query with one directory stat and one small file read: the type's generation
and the type directory's mtime. A record added or removed changes the directory's mtime, and a
save or delete through any driver (this or another process: Folio, publishing, transfer
imports included) writes a new generation (`json-{type}-{key}.gen`, kept by `clearCache()`) and
drops the type's cache. A directory mtime has whole-second resolution, so a cache built in the
second the directory last changed is checked against every record file (count, newest mtime,
total size) until it is rebuilt or found unchanged in a later second. The generation is read
before the records, so a reader that read records before a write can never leave a cache that
is used after it; a cache is not written when the signature moved while the records were read.

Edge case: a record file edited by hand in place, outside the driver (an editor, `sed`, a deploy that
overwrites a file), changes neither the generation nor the directory's mtime and is not noticed until the
next save of that type or `preflow cache:clear`. Adding or removing a file by hand is noticed.
A cache that cannot be written only costs
speed. Core's `Application` passes `storage/cache/data`.

```php
$driver = new JsonFileDriver($basePath, cacheDir: $cacheDir);
$driver->clearCache('job');   // one type of this data path
$driver->clearCache();         // every type of this data path
```

`clearCache()` leaves other data paths' caches in the same directory alone; `preflow cache:clear`
(the registry's "JSON storage query cache") removes every data path's cache and temp files, but keeps
the `.gen` generation files (a few bytes each) that guard a cache write racing a save.

Without a cache directory the driver behaves as before.

## Delete plans

`deletePlan()` computes what deleting records would do across the declared relations, without
changing anything; `executeDeletePlan()` carries it out.

```php
$plan = $dm->deletePlan('company', [$id]);
$plan->deletes();      // ['company' => [...ids], 'job' => [...ids]]  roots plus cascaded records
$plan->detaches();     // [['type' => 'job', 'id' => '7', 'field' => 'company', 'remove' => ['3']], ...]
$plan->restrictions(); // ['job' => 2]  records still pointing at a deleted one
$plan->restricted();   // true when any restriction blocks the delete
$plan->counts();       // ['deleted' => [...], 'detached' => [...], 'restricted' => [...]] for a confirmation
$dm->executeDeletePlan($plan);
```

- `cascade` follows recursively; `detach` loads the pointing record, removes the id and saves
  it without validation; `restrict` blocks the whole plan. A restricted plan is refused with a
  `RuntimeException` before anything changes.
- The optional third argument, `fn (TypeDefinition $t): array`, names extra definitions that
  hold copies of a type's records (for example a published shadow table). They count toward
  restrictions only.
- **Who deletes.** The fourth argument, `DeletedBy::Staff` (default) or `DeletedBy::Self`,
  picks each relation's rule: `Self` applies `onSelfDelete` (unset: `onDelete`), for a person
  deleting their own record (the members package's account deletion and `members:prune`). Folio's
  staff deletes pass `Staff`.
- **The plan is a snapshot.** Records added or changed between `deletePlan()` and
  `executeDeletePlan()` are not part of it; plan and execute close together, and plan again
  if the user confirms later.
- **Transactions.** SQL writes run in one transaction per PDO connection, committed at the end
  and rolled back on any error (a transaction the caller already opened is left to the caller).
  **JSON-file writes cannot be rolled back**: if a later step fails, the JSON changes made
  before it stay, and so does a SQL/JSON mix that fails halfway on the JSON side.

## Aliases

`config/data.php` may map purpose names to configured drivers, so a package can store on its
own name and the app decides where that lives:

```php
return [
    'default' => 'json',
    'drivers' => ['json' => [...], 'sqlite' => [...]],
    'aliases' => ['codes' => 'sqlite', 'throttle' => 'sqlite'],
];
```

An alias is the same driver instance as its target; `default` is a valid target (`'codes' => 'default'`). A target that is not a configured driver,
or an alias named like a driver (or `default`), throws a `RuntimeException` at boot.

## Translatable fields

Dynamic types can mark fields `"translatable": true`. When the app has several content
locales, `config/i18n.php` builds a `Preflow\Data\Locales(available, default, fallback)`,
which is bound in the container and carried on every `TypeDefinition`. Those fields then
store a locale map, `{"en": "…", "de": "…"}`: native objects in JSON files, JSON text in
SQL columns.

```php
$record->localized('de')->get('title');   // value with fallback (de → fallback → default)
$record->localized('de')->isFallback('title');
$record->availableLocales();              // from the _locales system key; the default is always included

$dm->queryType('page')->locale('de')->where('slug', 'ueber-uns')->first();
```

- **`locale()`** makes `where`, `search` and `orderBy` compare a translatable field's
  value in that locale. It works on JSON files, SQLite (`json_extract`) and MySQL
  (`JSON_EXTRACT`).
- Without `locale()`, the default locale is used.
- A plain value stored before the field became translatable counts as the default
  locale's value.
- **`LocaleValue`** has the helpers: `map`, `pick` (exact), `resolve` (with fallback),
  `default` and `resolveTree` (nested data).
- **Validation:** `required` binds the default locale; other rules run for every locale
  with a value, keyed `field.locale`.

## Model classes as types

Content without code is a JSON file; the app's own models are PHP classes; Folio edits both.
A class is the single definition of its model: there is no second file that repeats its fields.

**Opt in** with a type key on the entity. Nothing changes for a class without one (like the
skeleton's `Post`), and an existing JSON type keeps working as it is:

```php
use Preflow\Data\Attributes\{Entity, Field, Id, Relation};
use Preflow\Data\Model;
use Preflow\Validation\Attributes\Validate;

#[Entity(table: 'jobs', storage: 'sqlite', type: 'job')]
final class Job extends Model
{
    #[Id] public ?string $uuid = null;

    #[Field(label: 'Title', help: 'Shown in the list', translatable: true, type: 'string')]
    #[Validate('required')]
    public array|string|null $title = null;

    #[Field(type: 'select', config: ['options' => ['open' => 'Open', 'closed' => 'Closed']], default: 'open')]
    public ?string $state = null;

    #[Relation(to: 'company', onDelete: 'restrict', labelField: 'name')]
    public ?string $company = null;
}
```

The registry finds marked classes in `app/Models` (set `data.model_paths` in `config/data.php` for
other directories; a relative entry is under the app's base path). `preflow optimize` stores the
list in the boot manifest, so a class costs a request no more than a JSON type. A class and a JSON
file that both define the schema of one key (fields, table, storage, id field, `matrixable`,
`views`) is a boot error (the registry checks it when it is built), never a silent override. A
class marked with a key the registry does not know (it is outside `data.model_paths`) is a
`LogicException` on its first read or write, never a silent fall back to a plain model.

**Tables.** Folio creates a type's tables (and its published copies) for the types it lists,
which for a class means one with an admin file. A class-backed type without an admin file gets
no table from Folio: the app creates it, for example with a migration.

**Field kinds** come from the property type (`?string` is a string, `?bool` a boolean, `int` and
`float` a number, `#[Relation]` a relation) or from `#[Field(type: …)]`. A property that needs a
kind and has none (an array, an object, an untyped one) is an error naming it. Dates are strings
with `type: 'date'`. `#[Field]` takes `type`, `label`, `help` (text or a locale map), `config`
(kind settings written at field level, as in a JSON file: `options`, `accept`, `time`, `blocks`,
`fields`, `multiple`), `default`, `translatable`, `unique` (or a scope list), `index`, `length`,
`searchable` and `transform`. `#[Relation(to:, onDelete:, onSelfDelete:, labelField:, metaField:)]` writes the
relation the JSON file would, and `#[Index('state', 'updated_at')]` on the class its `"indexes"`.
`ModelSchema::of(Job::class)` shows the type derived from a class.

**What goes where.** On the class: everything with data meaning, plus labels and help, which forms
outside Folio use too. In the admin-only file `config/models/job.json`: presentation (the type's
label, icon, section, list columns, filters, sort, `publishing`; per field `readonly`, `hidden`,
`placeholder`, …). The file may not set schema: `table`, `storage`, `id_field`, `matrixable`,
`views`, `indexes`, a field's `type`, `validate`, `translatable`, `unique`, `index`, `length`, `searchable`,
`transform`, `default`, `relation`, `options`, `accept`, `time`, `blocks`, `fields` or `multiple`
is refused with a message naming the file. A site without Folio never sees these files.
Folio's columns (`_version`, `_publish_at`, …) are invisible to the class.

**Typed reads and writes** do what `findType()`, `queryType()` and `saveType()` do for the type:

- `find()` and `query()` read as the type reads: `query(Job::class)->locale('de')` compares
  translatable fields in that locale, `searchLoose()` reaches related records. They give
  `Job` objects, with stored numbers and yes/no values (text in JSON files) as the
  properties' types.
- `save()`, `insert()` and `update()` validate and store as `saveType()` does (the type's
  rules, per locale, unique fields, bounded columns; the class's `on:` rules and `rules()`
  on top), and write only the id and the type's fields: columns the class does not declare
  (`_version`, `_publish_at`, …) stay as stored, also in JSON files.
- A new object with an empty string id gets the id Folio gives a record
  (`bin2hex(random_bytes(16))`) before it is written.
- Reads and writes report the same page cache tags as the type's.

A **translatable** field's property holds the locale map, `['en' => '…', 'de' => '…']`, on
a multilingual site (a plain value on a single-locale one, or one stored before the field
became translatable); give it the type `array|string|null` and the field type explicitly. The
union names no single kind (an array could be blocks, a string text or a date), so the kind
can't be read from the property:

```php
#[Field(type: 'string', translatable: true)]
public array|string|null $title = null;
```

**Another table of the same shape** (a published copy) is read with `findAs()` and
`queryAs()`. The objects are read-only: every write of one throws a `LogicException`.

```php
$job = $dm->findAs(Job::class, $id, 'job__published');
$dm->queryAs(Job::class, 'job__published')->where('open', true)->get();
$dm->isReadOnly($job); // true
```

**Array fields** (several assets, blocks, a matrix, a multiple relation) are `?array`
properties: the JSON text SQL storage holds is read as the array. Values that cannot be read
as the property's type (an unreadable stored value) leave the property's default in place,
and a save keeps the stored value until the code sets that property. Public `readonly` properties are never filled. Ids are the type's:
the id field keeps its name and kind, strings are generated on insert, integers auto-increment.

**Not yet on classes:** a type-level `matrixable` or `views`. The generator in
`preflow/devtools` refuses a type that has them.

## Type families (since 0.75)

`Preflow\Data\TypeFamily` names what kind of content a type or a block holds: `page`, `reference`
(lookup data: categories, companies), `asset`, `member` (accounts), `shop` or `live` (tools that act
at once). A type declares it as `"family"` at the top of its model file (or of a model class's admin
file); a block declares it in its attribute, `#[Block('quote', family: TypeFamily::Reference)]`
(see the [preflow/blocks README](../blocks/README.md)).

```json
{ "key": "company", "table": "company", "family": "reference", "fields": { … } }
```

The enum only names the kinds: nothing in this package maps a family to a colour or an icon, which
is an admin's business. Folio tints its type tiles with it, takes `page` for a type with a
`"frontend"` and `member` for a user type when none is declared, and stops at boot on a value that
is not a case (see [Type tiles and families](../folio/README.md#type-tiles-and-families)).
`TypeFamily::tryFrom($value)` reads one.

## Display texts and their keys

A model file's display texts (the type's label, its singular, field labels and help, select
options, a sidebar section) can be translated with keys in a language file
(`lang/{locale}/{group}.php`: `site` for a site's own types, a package's group for the types it
ships). `Preflow\Data\ModelLabelKeys` names them; a key wins over the file's text, which is a plain
string or a map per language:

| Key | Text |
|---|---|
| `{group}.types.{type}.label` | the type's label ("Apps"; the file's `"label"`) |
| `{group}.types.{type}.singular` | one record ("App"; the file's `"label_singular"`) |
| `{group}.fields.{type}.{field}.label` / `.help` | a field's label and help |
| `{group}.options.{type}.{field}.{value}` | a select option's label |
| `{group}.option_helps.{type}.{field}.{value}` | (since 0.75) the description of an option given as `{"label": …, "help": …}` |
| `{group}.sections.{section}` | a sidebar section a type names |

Since 0.75 a select option may carry a description: `"options": {"301": {"label": "Moved for good",
"help": "Search engines move the ranking"}}` (each a text or a map per language).
`ModelLabelKeys::isDescribedOption($option)` tells such an option (only `label` and `help` keys) from
a label map, and `ModelLabelKeys::optionHelp($group, $type, $field, $value)` gives its key (null for
a value a key can't name: empty, or with a dot). `ModelLabelKeys::derive($schema, $group)` lists
every key of a model file with the file's text, the descriptions included (Folio registers them
with i18n's `DerivedKeys`, so `preflow translations:check` reports the missing ones).

## Usage

**Model:**

```php
use Preflow\Data\{Model, ModelMetadata};
use Preflow\Data\Attributes\{Entity, Id, Field, Timestamps};

#[Entity(table: 'posts', storage: 'sqlite')]
#[Timestamps]
final class Post extends Model
{
    #[Id]
    public string $id;

    #[Field(searchable: true)]
    public string $title;

    #[Field(searchable: true)]
    public string $body;

    public string $status = 'draft';

    public ?string $created_at = null; // set by #[Timestamps] (createdAt / updatedAt work too)
    public ?string $updated_at = null;
}
```

**Querying:**

```php
// Find by ID
$post = $dm->find(Post::class, 'abc-123');

// Filtered query
$posts = $dm->query(Post::class)
    ->where('status', 'published')
    ->orderBy('created_at', SortDirection::Desc)
    ->paginate(perPage: 10, currentPage: 1);

// Full-text search across searchable fields
$results = $dm->query(Post::class)->search('preflow')->get();
```

**Saving:**

```php
$post = new Post();
$post->fill(['id' => 'abc-123', 'title' => 'Hello', 'body' => '...', 'status' => 'published']);
$dm->save($post);
```

**Migration:**

```php
use Preflow\Data\Migration\{Migration, Schema};

final class CreatePostsTable extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('posts', function (Table $t) {
            $t->uuid('id')->primary();
            $t->string('title')->index();
            $t->text('body');
            $t->string('status');
            $t->timestamps(); // created_at, updated_at
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('posts');
    }
}
```

**Multi-storage setup:**

```php
$dm = new DataManager([
    'default' => new JsonFileDriver(basePath: '/storage/data'),
    'sqlite'  => new SqliteDriver(new \PDO('sqlite:/storage/db.sqlite')),
]);
```

Models with `#[Entity(storage: 'sqlite')]` use SQLite; all others fall back to `'default'`.
