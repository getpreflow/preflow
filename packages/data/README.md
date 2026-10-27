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
| `#[Timestamps]` | Class | Adds `created_at` / `updated_at` handling. |

### `Model`

```php
$model->fill(array $data): void   // hydrate from associative array
$model->toArray(): array          // export public properties
```

### `DataManager`

```php
$dm->find(Post::class, $id): ?Post
$dm->query(Post::class): QueryBuilder<Post>
$dm->save(Model $model): void           // INSERT when ID is empty, UPDATE otherwise
$dm->insert(Model $model): void         // explicit INSERT; reads back lastInsertId()
$dm->update(Model $model): void         // explicit UPDATE
$dm->delete(Post::class, $id): void     // delete by class + ID
$dm->delete($model): void               // delete by model instance
$dm->raw(string $sql, array $bindings, string $storage): array  // raw SQL query
$dm->saveTypeIfVersion(DynamicRecord $record, int $expected): bool // compare-and-set, see below
```

`save()` detects an empty ID field and automatically issues an INSERT, then reads back `lastInsertId()` into the model — no need to pre-generate IDs for auto-increment tables.

### `QueryBuilder`

All methods return `$this` for chaining except the terminal methods.

```php
->where('status', 'published')
->where('views', '>', 100)
->orWhere('featured', true)
->orderBy('created_at', SortDirection::Desc)
->limit(10)->offset(20)
->search('php')          // searches all #[Field(searchable: true)] fields
->get(): ResultSet       // all results
->first(): ?Model
->paginate(perPage: 15, currentPage: 2): PaginatedResult
```

### Storage drivers

| Class | Backend |
|---|---|
| `JsonFileDriver` | One `.json` file per record at `{basePath}/{table}/{id}.json` |
| `SqliteDriver` | PDO-based SQLite via `PdoDriver` + `QueryCompiler` |
| `MysqlDriver` | PDO-based MySQL / MariaDB via `PdoDriver` + `QueryCompiler` (see [MySQL and MariaDB](#mysql-and-mariadb)) |

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
take the last seat. A package writing its own SQL for both SQLite and MySQL asks the driver
for `PdoDriver::dialect()` (`quoteIdentifier()`, `MysqlDialect`).

### Version-checked saves (compare-and-set)

Reading a record, comparing its version and then saving it leaves a window: two requests
that both read version 3 both pass the check, and the later save overwrites the earlier one.
`saveTypeIfVersion()` closes it. The write itself carries the version it expects:

```php
$record = $dm->findType('game', $id);
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
fields (unique within them), and may carry an index and a length:

```json
"slug":   { "type": "string", "unique": true, "length": 191 },
"code":   { "type": "string", "unique": ["purpose"] },
"city":   { "type": "string", "index": true }
```

```php
#[Field(unique: true, length: 320)] public string $email = '';
#[Field(unique: ['purpose'])] public string $code = '';
```

- **Validation.** `save()`, `insert()`, `update()`, `saveType()` and `saveTypeIfVersion()` add the
  `Preflow\Data\Validation\Unique` rule for every unique field (per locale for a translatable
  field). A duplicate is a `ValidationException` with `validation.unique` ("already taken") on the
  field, not an exception from the database. `DataManager::validateUnique($record)` checks only
  the unique fields (Folio uses it for draft saves). Empty values (`null`, `''`) and values with
  a `null` scope are never duplicates. Comparison is exact: case and accents count, on every
  database. App forms use the rule directly:
  `new Unique($dm, 'game', 'slug', scope: ['purpose'], exceptId: $id)` (the scope is read from the
  validated data, or given as `['purpose' => 'invite']`).
- **Database guard.** On SQL the unique index is the last line: two requests passing the
  validation together, one gets a `ValidationException` on the field from the index, and both
  rows stay as they were. A save with `validate: false` can therefore throw `ValidationException`
  too. Translatable unique fields (stored as JSON, no index) and every unique field on JSON
  storage are checked and written under the type's lock; the JSON check reads every record of
  the type, so unique fields on large JSON types are slow.
- **Empty is NULL.** An empty value in a unique field is stored as `NULL` (a unique index would
  refuse a second `''`). For a `Model`, the property must accept `null`.
- **Index and length.** `unique` and `index` make a short text column: `VARCHAR(length)` (default
  191) with `utf8mb4_bin` on MySQL, so the index compares exactly; validation then limits the
  value to `length` characters, also for the scope fields of a unique field. Free text, blocks
  and translatable fields get no index. `__revisions` tables are never checked or indexed;
  `__published` tables are typed like their working table but carry plain indexes only.
- **What sync does.** A new unique field's index is made with its column (`uniq_{table}_{field}`
  over the field and its scope); on an existing column migrate never adds it (it prints the
  drift warning below). MySQL caps a composite key at 3072 bytes, so keep scopes short.
- Custom field types tell the data package their column with `Preflow\Folio\Field\HasColumnKind`
  (`columnKind(array $config): ColumnKind`) or `FieldKinds::column($type, ColumnKind|Closure)`.

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

Columns are typed only when sync creates them (with the table, or added later), and so are the
unique (`uniq_{table}_{field}`, over the field and its scope) and plain (`idx_{table}_{field}`)
indexes; names past 64 characters are shortened with a crc32 hash. An existing column is never
altered and never gets an index: a column whose type differs from its kind, or a unique field
without its index, is printed as a warning (`warning: post.seats is text, expected number;
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
  `--sqlite=<file>` runs against a copy instead of the configured SQLite file. Upload the whole
  `tools/` directory to a site, run it with writes stopped, remove it afterwards. To restore: copy
  the SQLite backup back, or `mysql < dump`. Until a site is converted, `preflow migrate` prints
  the drift warnings on every run.
- `RecordTableSync::specs()` is public (the script builds its columns with it).
- A column whose kind changed later (a field turned translatable, a relation became `multiple`,
  a field's type was edited or it became unique) is converted with a migration in the project.

`preflow_migrations` is `migration VARCHAR(191) COLLATE utf8mb4_bin PRIMARY KEY, ran_at VARCHAR(32) NOT NULL`.

**64-character names.** MySQL caps table and index names at 64 characters. The index name is
`idx_{table}_{column}` and Folio adds `{table}__published` and `{table}__revisions`, so long
table names can cross it; the grammar throws a `LogicException` naming the identifier instead of
sending SQL MySQL would refuse. SQLite has no such limit.

**TEXT columns can't be indexed.** `$t->text('body')->index()` throws a `LogicException`
(`Cannot index TEXT column "posts.body" on MySQL: use string() for indexed columns.`); use
`string()` for anything indexed or unique. SQLite does not care, so a migration that indexes
`text()` passes there and fails on MySQL.

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
"designer": {
  "type": "relation",
  "relation": { "to": "designer", "multiple": true, "onDelete": "restrict", "labelField": "name" }
}
```

| Key | Meaning |
|---|---|
| `to` | Target type. A field without it is no relation. |
| `multiple` | Several targets (JSON array) instead of one. Default `false`. |
| `onDelete` | What deleting a *target* does to the pointing records (see [Delete plans](#delete-plans)): `detach` (default) removes the id from the pointer, `cascade` deletes the pointing record too, `restrict` refuses the delete. Any other value throws `InvalidArgumentException`. |
| `onSelfDelete` | The same choices, applied when the owner deletes their own record (`deletePlan(..., DeletedBy::Self)`) instead of staff. Unset follows `onDelete`; any other value throws `InvalidArgumentException`. |
| `labelField` | The target field shown and searched as its label. |

```php
$dm->relationsOf('game');       // list<Relation>: what 'game' declares
$dm->relationsTo('designer');   // list<Relation>: every relation, across all types, pointing at 'designer'
$dm->getTypeRegistry()->keys(); // every type key, sorted
```

A site without a models directory has no type registry. A package that defines its types in code
calls `$dm->ensureTypeRegistry(new TypeRegistry($path))`: it sets that registry only when there is
none and returns the one in use (the members core does this for its `member` type).

Relations work across storages: a SQL type may point at a JSON type and back.

## `whereAny` operators

`whereAny()` takes a list of conditions of which at least one must hold (an OR-group).
Several calls are AND-ed with each other and with `where()`.

```php
$dm->queryIn($typeDef)->whereAny([
    ['field' => 'title',    'operator' => 'CONTAINS', 'value' => 'wood'],
    ['field' => 'designer', 'operator' => 'IN',       'value' => ['a1', 'b2']],
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
$dm->queryIn($typeDef)->searchLoose('wood table')->get();
$dm->queryIn($typeDef)->searchLoose('wood', fields: ['title', 'designer'])->get();
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
reads one cache file per type (`json-{type}.json`) instead of every record file. The cache is
checked on every query against a signature of the type's directory (file count, newest mtime,
total size, directory mtime), so records changed outside the driver are noticed; writes and
deletes through the driver drop the type's cache and write a new generation
(`json-{type}.gen`, kept by `clearCache()`). The generation is part of the signature and is read
before the records, so a reader that read records before a write can never leave a cache that
is used after it; a cache is not written when the signature moved while the records were read. A cache that cannot be written only costs
speed. Core's `Application` passes `storage/cache/data`.

```php
$driver = new JsonFileDriver($basePath, cacheDir: $cacheDir);
$driver->clearCache('game');   // one type
$driver->clearCache();         // all types
```

Without a cache directory the driver behaves as before.

## Delete plans

`deletePlan()` computes what deleting records would do across the declared relations, without
changing anything; `executeDeletePlan()` carries it out.

```php
$plan = $dm->deletePlan('designer', [$id]);
$plan->deletes();      // ['designer' => [...ids], 'game' => [...ids]]  roots plus cascaded records
$plan->detaches();     // [['type' => 'game', 'id' => '7', 'field' => 'designer', 'remove' => ['3']], ...]
$plan->restrictions(); // ['game' => 2]  records still pointing at a deleted one
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
            $t->timestamps();
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
