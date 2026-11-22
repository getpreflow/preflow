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
| `#[Seed(debugOnly: true)]` | Class | Marks a seeder in `app/Seeds/` as demo data: `preflow db:seed` skips it while `app.debug` is off (since 0.80.0); see `db:seed` in `preflow/devtools`. |
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
$dm->insert(Model $model): void         // explicit INSERT; an int id reads back lastInsertId(); throws for an id that exists
$dm->insertIgnoring(Model $model): bool // INSERT unless the id exists (0.79); true = written
$dm->insertMany(iterable $models, bool $ignoreDuplicates = false): int // many inserts in one transaction (0.79)
$dm->update(Model $model): void         // explicit UPDATE
$dm->delete(Post::class, $id): void     // delete by class + ID
$dm->delete($model): void               // delete by model instance
$dm->raw(string $sql, array $bindings, string $storage): array  // raw SQL query
$dm->saveTypeIfVersion(DynamicRecord $record, int $expected): bool // compare-and-set, see below
```

`save()` detects an empty ID field and automatically issues an INSERT, then reads back `lastInsertId()` into the model — no need to pre-generate IDs for auto-increment tables. A `string` id that is empty (or unset) gets a UUID v4 before the write instead; `insert()` refuses an id that exists (see "Inserting" below).

### Inserting (0.79)

`insert()` writes a new record and never writes over a stored one. Giving an id that exists throws a
`RuntimeException` ("a record with this id exists"), and so does losing a race: when another request
stores the same id between `insert()`'s check and its write, your `insert()` throws and the first row
stays as it was. (Before 0.79 the second writer silently overwrote the first.) On the built-in drivers
the check and the write are one step (`InsertsNew`: SQL a plain `INSERT` that fails on the primary
key, JSON files a create under the type's lock). A storage without `InsertsNew` is checked, then
written, as before, and a second writer can still get in between there.

For rows that may repeat, such as an event log or a visit that counts once, skip the duplicate
instead of catching the error:

```php
// true: written. false: a record with this id exists; it is left as it is, and no event or
// observer report is made for the skipped model.
$written = $dm->insertIgnoring($visit);

// Many at once, in one transaction on a storage that has transactions (the SQL drivers):
$count = $dm->insertMany($events);          // a duplicate throws; nothing of the batch is kept
$count = $dm->insertMany($events, true);    // duplicates are skipped; $count is the number written
```

```php
#[Entity(table: 'events', storage: 'sqlite')]
final class Event extends Model
{
    #[Id]
    public string $uuid = '';   // empty: a UUID is given, as insert() does

    #[Field]
    public string $name = '';
}

// An event delivered twice (a retry, a double click) is stored once:
$event = new Event();
$event->uuid = $deliveryId;
$event->name = 'signup';
$dm->insertIgnoring($event);
```

Validation, the generated id, `#[Timestamps]` and events run as for `insert()`; a model skipped as a
duplicate runs no write. `insertMany()` inserts the models in the order given; where the storage has
no transactions (JSON files) the models written before a duplicate stay. A plain model whose `int` id is
left at `0` (or `null`) gets a new id from the storage and is always written. A plain model or a
class-backed type with a given id (since 0.80.0 also a non-zero `int` id) returns `false` for a duplicate.

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
->search('php')          // searches all #[Field(searchable: true)] fields; none declared: refused (0.84)
->get(): ResultSet       // all results
->first(): ?Model
->paginate(perPage: 15, currentPage: 2): PaginatedResult
```

`where()` / `orWhere()` take `=`, `!=`, `<>`, `<`, `>`, `<=`, `>=`, `LIKE`, `NOT LIKE`, `IN`,
`NOT IN` (any case; `IN` / `NOT IN` take a list); `where($field, $value)` is `=`. Field names in
`where()`, `orWhere()`, `whereAny()`, `orderBy()` and `search()` are identifiers
(`[A-Za-z_][A-Za-z0-9_]*`). Anything else throws `\InvalidArgumentException` before any SQL is
built: never pass a request's operator or column name unchecked. Since 0.78 a typed query (a type
or a model class) also refuses a field the type or class does not have (its fields, the id field
and system keys with a leading `_` are allowed): `Unknown field "titel": type "post" has no such field to query by.`,
the same on every driver, where SQL would name the missing column and JSON would match nothing.
SQLite quotes identifiers with backticks, so a hand-built query on a missing column is an error
there too (it read a double-quoted unknown name as a string).

#### `pluck()` and `whereIn()` with a subquery (0.79)

`pluck($field)` returns one field of each record the query selects, as a list, in the query's
order and within its limit and offset:

```php
$slow = $dm->query(Visit::class)->where('ms', '>', 500)->orderBy('ms')->limit(10)->pluck('ms');
```

`whereIn($field, $values)` and `whereNotIn()` take a list, or another query: the records whose
`$field` is (not) among the values the other query selects. The third argument names the field of
the subquery (default: the same name as `$field`):

```php
// visits of installs that are on the web
$visits = $dm->query(Visit::class)
    ->whereIn('install', $dm->query(Install::class)->where('kind', 'web'), 'uuid')
    ->get();
```

On the SQL drivers (SQLite, MySQL) a subquery on the same driver runs in the database as
`IN (SELECT ...)` / `NOT IN (SELECT ...)` (capability `SelectsSubqueries`): no values travel
through PHP, so there is no limit on bindings and no large list to build, and the inner records
are read when the outer query runs. A subquery with a `limit()` or `offset()` (MySQL refuses them
in `IN`), on another storage, or on a driver without the capability (JSON storage, a third-party
driver) is run at the `whereIn()` call and its values are passed as a list; the conditions are
fixed at the call either way, but a write in between can change the rows. Otherwise both ways give
the same rows: the values are compared as stored (a type's transformer does not run), by the outer
field's rule (a text field ignores case, an exact one does not), and a null among them is left
out. An empty list or subquery matches nothing for `whereIn()` and everything for `whereNotIn()`.
In the list form the values are bound, so a list of tens of thousands meets the database's limit
on bindings (SQLite 32766, MySQL 65535).

`pluck()` returns the values a record reads: a type's transformer runs, a class-backed type's
arrays are decoded and numbers converted. On the SQL drivers (capability `SelectsColumns`) it is a
one-column `SELECT` where that cannot change a value (a plain type without a transformer on the
field, a class field typed `string`); any other field reads the records. The result is the same
either way.

A driver author implements either or both capabilities: `Preflow\Data\SelectsColumns`
(`pluck(type, field, Query)`, the column as stored) and `Preflow\Data\SelectsSubqueries` (accept a
`Subselect` as the value of an IN / NOT IN condition, the inner column compared by the outer field's
rule; a driver without SQL may run its own `pluck()` and use the result as a list). The contract
cases "the column and subquery capabilities give the same rows as the fallback" and "a subquery
compares by the outer field's rule" check them, skipped without the capability.

A median is the middle of an ordered `pluck()`:

```php
$values = $dm->query(Visit::class)->where('page', '/home')->orderBy('ms')->pluck('ms');
$n = count($values);
$median = $n === 0 ? null : ($n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2);
```

### Storage drivers

| Class | Backend |
|---|---|
| `JsonFileDriver` | One `.json` file per record at `{basePath}/{table}/{id}.json` |
| `SqliteDriver` | PDO-based SQLite via `PdoDriver` + `QueryCompiler` |
| `MysqlDriver` | PDO-based MySQL / MariaDB via `PdoDriver` + `QueryCompiler` (see [MySQL and MariaDB](#mysql-and-mariadb)) |

**One format for JSON record files (0.78).** Every save through `DataManager` (and so through
Folio, a CLI command or a script) writes a record in one canonical form, so an unchanged record
is written to the same bytes: the type's fields in their order, then the id and the system keys
(`_version`, …) in their order, then any other key (none is dropped); a list field
(`fieldKinds()->list('tags')`; Folio registers its field types marked `StoresList`) as a list,
also when it was given as JSON text or a comma string; a number field's numeric text as a number
and a yes/no field's `'1'`/`'0'`/`'true'`/`'false'` as `true`/`false`. A value that does not
convert stays as it is. `JsonFileDriver` encodes with `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
| JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION` and a trailing newline. A hand-written
file gets this form on its next save; nothing is migrated. SQL storage keeps lists as JSON text in
their columns. A direct `driver()->save()` keeps its caller's key order, and the stored keys it does
not name follow in their stored order (a class-backed model's save names every key of its type, so
a property removed from the class or a key added by hand ends up last); `storageValues()` gives
the canonical row to callers that write rows themselves (an import). `updateFieldsIfVersion()`
writes its fields into the stored file as it is: the stored key order stays, a new key comes last;
the next full save (`saveType()`, a model's `save()`) writes the canonical order.

Since JSON files now hold numbers and `true`/`false`, the JSON driver compares them as SQL compares
its number and 1/0 columns: `=`, `!=`/`<>`, `IN`/`NOT IN` and `whereAny()`'s `=` match a number given
as numeric text of the same value (`'2'`, `'02'`, `'2.5'`) and a yes/no value given as `1`/`'1'`
(or `'true'`) and `0`/`'0'` (or `''`, `'false'`). Text never equals text that differs, and text that
is no number never equals a number. A NULL field is never `!=` a value, as in SQL.

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
the match `where(…, 'like', …)`, `search()` and `CONTAINS` use, e.g. `ILIKE`, or,
since 0.77, the optional `PatternMatching::likeSql()` writes it per pattern, which a dialect whose
operator alone cannot give the text-matching rule (see "Text matching") implements;
since 0.80.0, the optional `InsertIfNew::insertNewSql()` gives the statement `insertNew()` runs
for a database whose failed statement aborts the transaction (PostgreSQL:
`INSERT … ON CONFLICT ("id") DO NOTHING`; `PdoDriver` then counts the rows written, 0 meaning the
id exists), where without it a plain `INSERT` fails and `isPrimaryKeyViolation()` reads the failure;
a database whose generated ids do not move past an id written by hand (PostgreSQL serial or identity
columns) must advance its sequence after such a write, or a later generated id collides (the
storage-driver contract checks it);
`numericOrder()` the numeric sort; `missingColumn(\PDOException)` reads the column a failed write
names, so the "run `preflow migrate`" message works on that database) and registers its schema grammar for its PDO driver name, so
`Schema`, `RecordTableSync` and `preflow migrate` work:

```php
Grammars::register('pgsql', new PgsqlGrammar()); // a SchemaGrammar; in the driver or a provider's register()
```

Check a driver with `Preflow\Testing\Contract\StorageDriverContract` (preflow/testing). Since 0.78 its schema
grammar may implement `TextCollations` and its dialect `TextComparison`, so text fields compare by
the text rule in the database (see "Exact comparisons"); a driver on SQLite calls
`SqliteDialect::prepare()` on its connection. `TextCollations` is what new tables need (six
methods: bind to the connection, name the collation, the text, exact and record column types,
`collate()`). Converting tables made before is a second, optional interface,
`ConvertsCollations` (it extends `TextCollations`: read and change column collations, refusals,
unique prefixes, row keys, and where the application supplies the collation its version and
the index rebuild). A grammar for a database whose tables get the rule from the start implements
the first alone; `preflow migrate` then says once per storage that existing columns stay.

#### Driver-author checklist

**Must:** implement `Preflow\Data\StorageDriver` (`findOne`, `findMany`, `save`, `delete`,
`exists`, `lastInsertId`; since 0.78 no `rawQuery`, which moved to `RunsSql`) with the query
semantics `StorageDriverContract` states (the text-matching rule for `LIKE`, `CONTAINS` and
`search()`: case, accents and width ignored; `CONTAINS` in list values, null handling, numbers as numbers;
`save()` writes the fields it is given and keeps every other stored field, a field given as null
is stored as null, since 0.78), and pass that contract. That is enough for a driver
to work everywhere: DataManager, or the capability's consumer (`StoredActivityLog` for `Savepoints`
and `ChangeTokens`), has a fallback for every capability below except aggregates and raw SQL, and
those throw rather than give a different result.

Since 0.78 nothing in the framework checks a driver's class (an architecture guard keeps it so):
every ability is asked for by its interface below, so a new driver gets exactly what a built-in
one with the same interfaces gets. `Preflow\Testing\PlainDriver` wraps any driver and hides every
capability, for tests of the bare-minimum path.

**Should, for production:** `VersionedStorage`, `ConditionalUpdates` and `ExclusiveWrites`. Without
them the fallbacks work but are not atomic, so two concurrent writers can both pass a check (an
`E_USER_NOTICE` says so for the version checks, once per driver class). Implement
`AggregatingStorage` if the site uses Folio or any aggregate query. The rest is optional.

| Capability | What it gives | Without it… | Atomic? |
|---|---|---|---|
| `VersionedStorage::saveIfVersion()` | A save only while the stored version is the expected one (`saveTypeIfVersion()`: editors' conflict checks, publishing). | reads, compares and saves; an `E_USER_NOTICE` says it is not atomic. | Yes with it; the fallback is not. |
| `ConditionalUpdates::updateIfVersion()` (0.77) | Writes only the named fields while a version field matches (`updateFieldsIfVersion()`: version bumps, a snapshot's published version, system keys). The contract has three tests for it, skipped without it. | reads, compares and saves the whole record; an `E_USER_NOTICE` says it is not atomic. | Yes with it; the fallback is not. |
| `InsertsNew::insertNew()` (0.79) | Writes a record only while no record has its id, in one step: `insert()` never overwrites a record another writer stored in between, and `insertIgnoring()` and `insertMany()` skip duplicates without a race. The contract tests it (also a duplicate behind another unique index, and inside a transaction), skipped without it. | checks that the id is free, then saves; a second writer in between is overwritten (`insert()`) or both write (`insertIgnoring()`). | Yes with it; the fallback is not. |
| `SelectsColumns::pluck()` (0.79) | Reads one column of the records a query selects, in the query's order and within its limit: `pluck()` is one `SELECT` of that column where its values are what a record reads. The contract compares it with the fallback, skipped without it. | reads the records and takes the field. | Yes, same values. |
| `SelectsSubqueries` (0.79) | Accepts a `Subselect` as the value of an IN / NOT IN condition in every query it runs: `whereIn()` / `whereNotIn()` with a subquery on the same driver become `IN (SELECT ...)`, compared by the outer field's rule, nulls left out. The contract compares it with the fallback and checks the rule, skipped without it. | the subquery runs first and its stored values are an `IN` list (also for another driver or a limited subquery). | Yes, same rows (a long list meets the binding limit). |
| `ExclusiveWrites::exclusive()` | Runs a check and a write of one type while no other writer of the type runs one: unique values no index guards (translatable fields, JSON storage), the activity log's coalescing. | runs the check and the write without a lock: two writers may both pass the check (a duplicate unique value, rarely two activity rows for one session). | Yes with it; the fallback is not. |
| `Transactions::transaction()`, `inTransaction()` (0.78) | Writes that commit together or not at all; inside an open transaction (whoever opened it) `transaction()` joins it. `DataManager::transaction()` spans storages: it begins on each storage with it once (aliases of one driver count once), commits the innermost first, and throws `PartialCommit` (a `RuntimeException`, the failed commit as previous) when a commit fails after another storage committed (publishing, deletes, the permission sync). | `DataManager::transaction()` runs the writes as they come: a failure part-way leaves the earlier writes stored (JSON files). | Yes with it. |
| `UniqueIndexes` (0.78, a marker) | Says `save()` refuses a duplicate in every unique index the table sync made (`UniqueViolation`, nothing written). DataManager then checks before the write only the unique fields no index can hold (translatable ones). | DataManager checks every unique field before the write, under the type's lock (`ExclusiveWrites`) where the driver has one, else without. | Yes with it or `ExclusiveWrites`; the plain check is not. |
| `AtomicCounters::increment()` (0.78) | Counts on a row in one step: inserts it, adds to it, or restarts it when a `CounterReset` field is below a bound (`incrementIn()`: the throttle's fixed windows). The contract tests it, also with four processes at once. | reads, adds and writes under the type's lock (`ExclusiveWrites`) when the driver has one, else plainly. | Yes with it or `ExclusiveWrites`; the plain fallback is not. |
| `AggregatingStorage::aggregate()` | Group, count, sum, avg, min, max (`AggregateQuery`): Folio's dashboard and not-found counts, publish states, the activity people filter. | `AggregateQuery::get()` throws (never wrong numbers); `Publisher::states()` and `StoredActivityLog::people()` read the records instead. | Read only. |
| `ConditionalMeasures` (0.79, a marker) | Says `aggregate()` honours a measure's own `where:` condition (each `measures()` entry then has a `condition`, a `Query` of only that measure's conditions). | `AggregateQuery::get()` throws for a measure with a condition (never counts every record); measures without one still run. | Read only. |
| `BulkDeletes::deleteMany()` (0.77) | Many deletes in a few statements (`deleteManyIn()`: activity prune and erasure). | deletes one by one. | No either way (a failure part-way leaves the deleted ones deleted); inside a SQL transaction it rolls back with it. |
| `Savepoints::savepoint()` (0.77) | Writes inside a caller's open transaction that commit or roll back with it and whose failure leaves it usable. | DataManager does not use it; its consumer, `StoredActivityLog`, runs its writes and reads as they are (fine for storage without transactions). | Yes with it, within the caller's transaction. |
| `DescribesColumns::hasColumn()` (0.77), `hasTable()` (0.78) | Says whether a table has a column (`hasColumn()`: publishing works on tables not migrated yet; since 0.84 the change dates, written only where it says the table has the column) and whether a type's table exists (`hasTable()`: the staff accounts, published copies). | takes any column and any table as present (right for schemaless storage; a SQL driver should implement it); its records get no change dates (0.84). | Read only. |
| `RankedSearch::canScore()`, `score()` (0.78) | Scores the hits loose search found, so ⌘K puts a type's best matches first (`DataManager::canScore()`, `scoreIn()`; only for a type with `"fullText": true`). It orders, it never finds: the hits are the same on every driver. `MysqlDriver` scores with `MATCH … AGAINST` over the FULLTEXT index `migrate` adds. | hits stay in storage order; `scoreIn()` throws `UnsupportedStorage`. | Read only. |
| `RunsSql::rawQuery()`, `execute()`, `dialect()` (0.78) | Raw SQL through `DataManager::raw()` and `execute()`; the auth tokens, remember-me and codes use it for their conditional single-statement writes. | `raw()` and `execute()` throw `UnsupportedStorage` (a `LogicException`), never a different result; tokens, remember-me and codes read and write under their own lock instead. | As the SQL runs. |
| `PdoConnection::pdo()` (0.78, extends `RunsSql`) | The PDO connection itself, for `preflow migrate`, `Migration\Schema`, the table sync and the convert tool. | `migrate` runs only the steps registered for any storage; the table sync skips the type. | — |
| `Maintenance::check()`, `compact()` (0.78) | Checks the whole store (structure, indexes, references) and compacts it, through the driver's own prepared connection (`preflow db:check`, `db:vacuum`). For storage whose database tools can't do it once Preflow owns it (SQLite: the `preflow_text` collation). | the commands refuse the storage and point to the database's own tools. `MysqlDriver` implements it since 0.84 (`CHECK TABLE`, `OPTIMIZE TABLE`). | — |
| `MaintenanceReport::report()` (0.84, extends `Maintenance`) | Lines for the operator that are no problems: `db:check` prints them after the problems. MySQL: its text columns by collation and `not applicable: …` for what it has no check for (references between records), so a skipped check never reads as a passed one. | `db:check` prints the problems only. | — |
| `Dumps::dump()`, `restore()` (0.78) | The whole store as text the same driver restores into an empty store (`preflow db:dump`, `db:restore`). | the commands refuse the storage and point to the database's own tools (MySQL/MariaDB: `mysqldump`; JSON: copy the folder). | `restore()` is all or nothing. |
| `StatesIdKinds::idKindOf()` (0.82) | Says what kind of id the storage holds for a type: `PdoDriver` reads the table's key column and its schema grammar classifies the type (`Migration\Grammar\ClassifiesIdColumns`, 0.82: SQLite by its affinity rules, MySQL a number for every numeric type, as it compares text with them as numbers; a missing table or a grammar without it nothing). `DataManager::idKind()` asks it after the type's model class (a disagreement with the class throws) and before the declared id field. A driver that wraps another must implement it and forward the call. | `idKind()` follows the type: its class, else its declared id field, else a string id. | Read only. |
| `ChangeTokens::changeToken()` (0.77) | A token that moves with every write of a type, so readers reuse what they computed. | `DataManager::changeToken()` only passes it on (null); its consumer, `StoredActivityLog::people()`, reuses nothing and computes every time. | Read only. |

A SQL driver built on `PdoDriver` gets `VersionedStorage`, `ConditionalUpdates`, `InsertsNew`, `BulkDeletes`,
`Savepoints`, `DescribesColumns`, `AtomicCounters`, `AggregatingStorage`, `PdoConnection` (so
`RunsSql`), `Transactions`, `UniqueIndexes`, `SelectsColumns` and `SelectsSubqueries` from it and implements `exclusive()` itself (as
`SqliteDriver` and `MysqlDriver` do); its `Dialect` may add `PatternMatching` and `InsertIfNew` (0.80). `MysqlDriver` adds
`RankedSearch`, `SqliteDriver` adds `Maintenance` and `Dumps`. Since 0.82 `PdoDriver` also implements `StatesIdKinds` and `RemembersColumns` (a subclass that overrides `forgetColumns()` calls the parent), and both grammars implement `ClassifiesIdColumns`. The JSON file driver implements `VersionedStorage`, `ConditionalUpdates`, `InsertsNew`,
`ExclusiveWrites`, `BulkDeletes`, `ChangeTokens`, `DescribesColumns`, `AtomicCounters` and
`AggregatingStorage`.

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
$registry->afterMigrate('mypkg.marks', fn (): array => [], sqlOnly: false); // runs on JSON-only sites too
```

The app's `migrations/` and core's own (the `preflow_throttle` table) are registered by
the application. A post-migrate step runs on **every** `preflow migrate`, so it must be
idempotent (`hasTable()` / `columns()` before changing anything); registering the same step
name twice throws. A step is SQL-only by default: on a site whose storage is JSON only,
migrate runs just the steps registered with `sqlOnly: false` (work through `DataManager`, no
SQL; since 0.77.1). `steps(sql: false)` lists those, `isSqlOnly($name)` tells one step.

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

An id you set yourself is kept (since 0.80.0): `insert()` of a model whose int id is not `0`
writes it under that id, only while no record has it. A second `insert()` of the same id throws,
as for a string id, and `insertIgnoring()` skips it. Ids the database numbers later go on past
the ones you set (MySQL/MariaDB and SQLite do this themselves).

```php
$comment = new Comment();
$comment->id = 1000;
$comment->body = 'Imported';
$dm->insert($comment);   // stored as 1000
$dm->insert($comment);   // RuntimeException: a record with this id exists
```

The table needs a key the database numbers. In a migration declare it with `increments()`
(since 0.79.0):

```php
$schema->create('comments', function (Table $t) {
    $t->increments();            // `id`; pass another name to change it
    $t->text('body');
});
```

On MySQL/MariaDB that is `BIGINT AUTO_INCREMENT PRIMARY KEY`; on SQLite `INTEGER PRIMARY KEY`,
which numbers itself (without the `AUTOINCREMENT` keyword, so the table can still be rebuilt by
`convertColumn()`). `integer('id')->primary()` is **not** numbered on MySQL: it is a plain key
and every insert must set the id itself. `convertColumn()` on an `increments()` column keeps its
`AUTO_INCREMENT`.

### String or number ids: `idKind()`

`$dm->idKind($typeDefinition)` (since 0.82.0) says whether a type's records have string ids or
number ids, as an `IdKind` (`IdKind::String`, `IdKind::Int`), the way the data layer stores them:

1. A type's model class decides (`TypeDefinition::$idKind`, `IdKind::ofModel()`): an id property
   typed `string` (or `?string`) is a string id the data layer makes for a new record; any other
   (`int`, untyped, a union) is a number the storage gives.
   A table key of the other kind (an `int` id property over a text key) makes `idKind()` throw a
   `LogicException`: the records cannot be written as the class says.
2. Else the storage says (`StatesIdKinds`, optional): a SQL table's key column as its grammar
   classifies it (`ClassifiesIdColumns`): on SQLite a type containing "int" a number and one
   containing "char", "clob" or "text" a string; on MySQL every numeric type a number (it compares
   a text value with them as a number) and char, varchar and text a string. A table the sync made has a text key, so its type has string
   ids whatever its declared id field says.
3. Else the declared id field (no table yet, JSON files): one stored as a number
   (`"type": "number"`, or a field type an extension stores as a number) is a number id.
4. Else a string id: an undeclared id (a model file's `uuid`) or one declared as any other field.

Code that puts a text id of its own into a type's records asks it first (members' view-as-role
member refuses a number id).

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

### Version fields: a content change bumps the version (0.77)

A `VersionFields` set with `$dm->useVersionFields($fields)` names the types whose records keep a
version (`$fields->of($def)`: the field, or null) and their keys that are no content
(`nonContent($def)`). preflow/publishing sets one: the working copies of the types that publish
keep `_version`, and the publishing keys (schedule, position, share token…) are no content.

- A `saveType()`, or `save()` / `insert()` / `update()` of a class-backed type, that writes a value
  differing from the stored one writes that field as the stored version + 1 (the record holds the
  version written). A save that changes nothing, or only non-content keys, keeps the stored version,
  so an import or sync job re-saving unchanged records moves nothing on. Values are compared as
  stored, the same on every driver: JSON text decoded, empty values (null, '', an empty list)
  alike, numbers of number fields by value, yes/no fields by their truth, other values as text;
  only the keys the save writes count. The write is checked:
  a save landing in between makes it try again on the newer version, and a record busy on every
  try is written anyway. So an app's own save or an import never stores new content under an old
  version, and an editor holding the old one conflicts.
- The checked writes write the version they are given: `saveTypeIfVersion()` (its `$next`) and
  `updateFieldsIfVersion()` (below).
- Out of reach: raw SQL through `execute()` and drivers called directly. Seed or restore rows that
  way when a version must stay as it is.
- `$dm->versionFieldOf($def)` tells the field.

### Writing some fields while the version matches (0.77)

`$dm->updateFieldsIfVersion($def, $id, ['_order' => 3], '_version', $expected)` writes only those
fields, and only while `$versionField` holds `$expected`: a whole number, or `null` for none yet
(`NULL`, `''`; never 0 for that). It returns `false` when the record is missing or holds another
version. The fields may set the version field itself; nothing is bumped. Drivers implement
`ConditionalUpdates::updateIfVersion()` the same way: SQL as one `UPDATE` of the named columns
`WHERE id = ? AND version = ?` (in a transaction; a match that changes no value is still a match),
JSON as a read, compare and write under the type's lock. Without it, DataManager reads, compares and
writes (with the `E_USER_NOTICE` above). `StorageDriverContract` tests it.

`$dm->hasColumn($def, $column)` asks a storage with a schema (`DescribesColumns`, the SQL drivers)
whether the type's table has the column; a storage without one (JSON) takes any key (true). A
missing column is asked again on the next call, as a migrate may add it.

### Saving null clears a column

`save()` writes every key it is given, `null` included: `$record->set('expires_at', null)`
clears the column on SQLite, MySQL and JSON alike (before 0.42 SQL drivers dropped nulls, so a
clear needed `''`). A key the caller does not pass is left alone, on every driver (the JSON driver
merges the save into the stored file under the type's lock since 0.78; before, a JSON save
replaced the file). A key leaves a JSON record only by deleting the record and saving it again,
as a column stays on SQL; a property removed from a model class leaves its key in the files. A
partial save no longer
nulls the other columns on SQLite (it used `INSERT OR REPLACE`, which replaced the whole row;
it is an upsert on the primary key now, and on MySQL an `UPDATE` with an `INSERT` for a new
record). A save never touches another record: a value colliding with a unique index is an error
(below), not an overwrite.

A migration-made column that is `NOT NULL` now fails on a `null` instead of silently keeping
its old value.

### Change dates (0.84)

Every record of a type keeps when it was last changed (`Preflow\Data\ChangeDates`), on every
driver:

- **Where:** a type with an updated field of its own (`updatedAt`, else `updated_at`) keeps it
  there, so no record holds it twice; every other type in the system key `_updated_at` (beside
  `_version`, `_published_at`). `ChangeDates::of($def)` names the key.
- **What:** ISO 8601 in UTC to the second (`2026-11-22T18:59:29+00:00`), so ordering by it is a
  plain text comparison on every driver (`->orderBy('_updated_at', SortDirection::Desc)`); a
  declared field stored as a number gets the Unix time.
- **When:** a save that changes the record sets it, from the render's clock: `saveType()`,
  `saveTypeIfVersion()`, and `save()` / `insert()` / `insertIgnoring()` / `insertMany()` /
  `update()` of a class-backed type (its updated property gets the value written). A save that
  changes nothing keeps it (as a version field), so an unchanged record is written to the same
  bytes; a versioned save moves it with the version, and `saveTypeIfVersion()` with `$next` equal
  to `$expected` keeps the date the record was loaded with. It is never content: versions,
  publish states and `changes()` ignore it. A read never touches it.
- **Not moved:** writes that name their fields write only those: `updateIf()`,
  `updateFieldsIfVersion()`, `incrementIn()` (counters), raw SQL, a driver called directly. A caller
  that wants the date moved includes it: `[ChangeDates::of($def) => ChangeDates::value($def, $now)]`.
- **Keeping dates:** `$dm->keepingChangeDates(fn () => …)` writes the change date a record carries
  as it is (one without gets now): for imports and copies. Folio's content import writes an
  archive's rows as they are (their dates too); an archive from before 0.84 is compared without
  dates and what it writes is dated with the import.
- **Published copies** keep their own `_updated_at`: the publish that wrote their content (a
  publish of the same content keeps it). A type's own updated field is content, published as the
  working copy holds it.
- **Storage:** JSON records take the key. On SQL, `preflow migrate` adds the column to every table
  the data layer makes (`RecordTableSync`, a date column compared exactly); until then saves are
  written as before. `DataManager::changeDateOf($def)` says whether the storage holds it (a driver
  that does not implement `DescribesColumns` is given none). A driver needs nothing new: it stores
  the key as it stores the fields; `StorageDriverContract` pins it on every driver.
- **Plain models** (no type) keep `#[Timestamps]` for this; their tables are their migrations'.

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
  a `null` scope are never duplicates. Since 0.78 a text field compares by the text rule on every
  database (see "Exact comparisons"): case is ignored, accents count, so "Müller" is taken once
  "MÜLLER" is, "Muller" is not; a field marked `"exact": true` (and an id, a relation) compares
  exactly. App forms use the rule directly:
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
  191) on MySQL, in the text rule's collation for a text field and `utf8mb4_bin` for an exact one
  or a relation, so the index compares as the field does; validation then limits the
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
| `string($n = 255)` | TEXT COLLATE preflow_text (TEXT when `primary()` or `exact()`) | VARCHAR($n) COLLATE the text rule's collation (`utf8mb4_bin` when `primary()` or `exact()`) |
| `text()` | TEXT COLLATE preflow_text (TEXT when `exact()`) | TEXT COLLATE the text rule's collation (`utf8mb4_bin` when `exact()`) |
| `json()` | TEXT | LONGTEXT COLLATE utf8mb4_bin (not `JSON`: MySQL's type rewrites key order and spacing) |
| `integer()` | INTEGER | BIGINT |
| `boolean()` | INTEGER | TINYINT(1) |
| `timestamps()` | TEXT, nullable | VARCHAR(32) COLLATE utf8mb4_bin, nullable |
| `addColumn()` default | TEXT | LONGTEXT |

Since 0.78 `string()` and `text()` compare by the text rule (see "Exact comparisons"); `->exact()`
after one marks a machine value that compares exactly (an API key, a hash, base64, an id of
another table). On a MySQL server without the rule's collation `string()` and `text()` keep the
table default (`utf8mb4_unicode_ci`), as before. `addColumns()` and `convertColumn()` type their
columns the same way.

**Record tables** (the tables `RecordTableSync` makes from a type definition: Folio's types,
codes, members, media assets) use `id VARCHAR(191) COLLATE utf8mb4_bin PRIMARY KEY` (TEXT on
SQLite), and every other column is nullable and typed by its field's column kind
(`FieldKinds::columnKindOf()`):

| Kind | Field types | SQLite | MySQL |
|---|---|---|---|
| text | text, blocks, translatable fields, JSON, strings that are neither unique nor indexed | TEXT | LONGTEXT |
| short | a string, select or single relation that is unique or indexed | TEXT | VARCHAR(`length`, default 191) |
| number | number | NUMERIC (`'12'` stays 12, sorts numerically) | DOUBLE |
| boolean | boolean | INTEGER | TINYINT(1) |
| date | date | TEXT | VARCHAR(32) COLLATE utf8mb4_bin (ISO text) |

Since 0.78 the column of a text field (`TextColumns`: string, text, rich text, select, translatable
ones too; not a list, not `"exact": true`) declares the text rule: `COLLATE preflow_text` on SQLite,
the rule's collation on MySQL (also the table default there). Every other text column compares
exactly: `utf8mb4_bin` on MySQL (ids, relations, JSON, lists, dates, exact fields), no COLLATE
(BINARY) on SQLite. See "Exact comparisons".

Columns are typed only when sync creates them (with the table, or added later). The unique
(`uniq_{table}_{field}`, over the field and its scope), plain (`idx_{table}_{field}`) and composite
(`idx_{table}_{field1}_{field2}…`) indexes are made whenever they are missing (see
[Unique fields and indexes](#unique-fields-and-indexes)); names past 64 characters are shortened
with a crc32 hash. An existing column's type is never altered (since 0.78 its collation is: see
"Existing tables (`preflow migrate`)"): a column whose type differs from its kind,
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
  and indexes with them, so it makes the indexes migrate would). Since 0.78 its text fields' columns
  take the text rule's collation, and `--check` counts a unique text field's duplicates by the rule
  ("Cafe" is a duplicate of "cafe", "café" is not).
- A column's collation (since 0.78) is converted by `preflow migrate` itself: see "Existing tables
  (`preflow migrate`)" under "Exact comparisons".
- A column whose kind changed later (a field turned translatable, a relation became `multiple`,
  a field's type was edited or it became unique) is converted with a migration in the project,
  with `Schema::convertColumn()` (since 0.75; see "Converting a column"), for example
  `$schema->convertColumn('object', new ColumnSpec('state', ColumnKind::Short))` for a field that
  became indexed.

`preflow_migrations` is `migration VARCHAR(191) COLLATE utf8mb4_bin PRIMARY KEY, ran_at VARCHAR(32) COLLATE utf8mb4_bin NOT NULL`.

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
Since 0.78 `string()` columns compare by the text rule on SQLite and MySQL alike, so `Ab` and `ab`
collide on both (an `exact()` column keeps them apart).

**Charset and collation.** Tables are created `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=…`
with the text rule's collation as the default (`utf8mb4_uca1400_nopad_as_ci` on MariaDB 10.10+,
`utf8mb4_0900_as_ci` on MySQL 8; `utf8mb4_unicode_ci` on a server without either), identifiers in
backticks. Since 0.78 the columns of text fields and a migration's `string()` and `text()` name the
rule's collation, so `=`, `IN`, `ORDER BY`, `GROUP BY` and unique indexes ignore case and respect
accents there as on SQLite and JSON (see "Exact comparisons"). Ids and keys (`uuid()` columns,
primary and `exact()` `string()` columns, Folio record ids, migration names) and every record-table
column that is not a text field's are `utf8mb4_bin`, so they compare exactly: `A`, `a` and `ä` are
three records. (`utf8mb4_bin` pads, so an id with a trailing space still equals the one without;
the rule's collations do not pad.) Tables made before 0.78 keep their columns' collations until
`migrate` converts them (see "Existing tables (`preflow migrate`)" under "Exact comparisons").

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

**A condition on one measure (0.79).** Every measure (`count`, `countDistinct`, `sum`, `avg`, `min`, `max`) takes an
optional `where:` list that only that measure applies, so one query can count different slices of each group. A
condition is `[field, value]` (equals) or `[field, operator, value]`, with the operators and the null rule of `where()`;
several are all required.

```php
$rows = $dm->queryIn($visitType)->aggregate()
    ->groupBy('page')
    ->count('visits')
    ->countDistinct('visitor', 'today', where: [['day', '=', $today]])
    ->sum('seconds', 'long_stay', where: [['seconds', '>=', 60], ['bot', '=', false]])
    ->get();
```

The group and the query's filters are decided first; the condition then picks which of the group's records the measure
looks at. A text field in a condition compares by the text rule, an exact field exactly, a translatable field in the
builder's locale, the same as in `where()` and on every storage. A group where nothing meets the condition gives `0`
for `count`, `countDistinct` and `sum`, and `null` for `avg`, `min` and `max`. A result with a condition can be used in
`having()` and `orderBy()`. A measure without one runs exactly as in 0.78.

**Null rules.**

- `count()` counts rows, whatever their values.
- `countDistinct`, `sum`, `avg`, `min` and `max` of a field skip null (and `''` for number fields).
- `avg`, `min` and `max` of no values are `null`; `sum` of no values is `0`. Records with no value in a group field
  form one group with `null`.
- `having()` never matches a `null` result. In ordering, null comes first ascending and last descending.

**Types.** `count` and `countDistinct` are `int`. Other numbers are `int` when integral (within 2^53), else `float`.
Group values and `min`/`max` of boolean fields are PHP `bool` on every storage; those of text and date fields are
strings as stored.

**Case.** Since 0.78 a text field's group values, `countDistinct`, `min` and `max` follow the text rule on
every storage (see "Exact comparisons"): `'Über'` and `'über'` are one group (which of the two spellings the
row shows is not promised), `'Über'` and `'Uber'` two. Any other field's values are exact: on an exact field
(`"exact": true`, an id, a relation) `'a'` and `'A'` are two groups; on SQL they are compared with
`exactText()` (`COLLATE BINARY` on SQLite, `utf8mb4_bin` on MySQL/MariaDB), which keeps the database from
using an index for the GROUP BY; aggregates scan the filtered rows anyway.

**Refused** (`\InvalidArgumentException`, when the method is called unless noted):

- a field that does not exist, in `groupBy()` or a measure (this also refuses SQL fragments);
- result names that are not `[A-Za-z_][A-Za-z0-9_]*`, are used twice, or (at `get()`) equal a group field;
- `groupBy()` on a field that holds multiple relations (grouping by several relations is a later item);
- `sum()` and `avg()` of a field that is not a number field;
- `having()` on an unknown result name, on `min`/`max` of a non-number field, with an unknown operator (`=`, `!=`,
  `<`, `<=`, `>`, `>=`) or a non-finite value;
- at `get()`: an `orderBy()` name that is neither a result name nor a group field, and a query with neither group
  nor measure;
- a condition with a field that does not exist, an unknown operator, a shape other than the two above, or a subquery as its value;
- negative limit or offset.

A storage driver that does not implement `AggregatingStorage` gives a `\LogicException` naming the driver; one that does
not implement `ConditionalMeasures` (the three built-in drivers do) gives one at `get()` when a measure has a condition,
never counting every record instead. A custom
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
| `CONTAINS` | The field contains the value as a substring, under the text-matching rule (case, accents and width ignored). `%`, `_` and the escape character match literally. A list value (an array field; JSON text on SQL) matches when one of its values contains it. |
| `IN` | The field equals one of the listed values. An empty list matches nothing. |
| `HAS` | The field, an id string or a JSON array string, holds at least one of the listed ids. |

Since 0.78 `=`, `IN` and `HAS` on a type's text fields (a single value: `string`, `text`, rich
text, select; not `"exact": true`) ignore case and respect accents, as `where()` does. List fields
(tags) are not text fields: their values, and `HAS` on them, compare exactly on every driver
("Folio" does not find the tag "folio"). A query built without a `DataManager`
(`QueryBuilder::forType($driver, $def)`) knows only the built-in text kinds (`string`, `text`);
text kinds an extension registers need the `DataManager`'s field kinds (`$dm->queryIn()`).

`LIKE`, `CONTAINS` and `search()` follow one text-matching rule on every driver: case, accents and
width are ignored (see "Text matching"), where `=` and `IN` respect accents.
`CONTAINS` and `search()` find a term in a list value (an array field) when one of its values
holds it: SQL storage keeps the list as JSON text, written with umlauts and slashes as they are
since 0.77 (rows written before keep `a\/b`, which a search for "a/b" misses until resaved).

## Text matching

Since 0.78 `LIKE`, `NOT LIKE`, `CONTAINS`, `search()`, `searchLoose()` and so ⌘K's loose-search set
compare text **character by character, as MySQL/MariaDB's `LIKE` does under an
accent-insensitive, case-insensitive collation**: case and accents are ignored, and so are width
and compatibility forms. The same on JSON, SQLite and MySQL/MariaDB (`StorageDriverContract` pins
it). Ranked search only orders the loose-search set, so it follows. On MySQL its FULLTEXT score
runs on the text columns, which respect accents: a record that matches only by ignoring accents
("uber" for "Über") is still found, but scores 0 and comes last. Since 0.78.1 matching ignores
accents (0.78 respected them): almost nobody types them in a search box. Exact comparisons (`=`,
`IN`, unique checks, sorting) keep respecting them (see "Exact comparisons"): `where('name',
'muller')` finds "Muller" only, `where('name', 'LIKE', '%muller%')` finds "Müller" too.

| Search | Stored | Found? | Why |
|---|---|---|---|
| "über", "ÜBER" | "Über" | yes | case |
| "uber" / "ete" / "elise" | "Über" / "été" / "Élise" | yes | accents are ignored |
| "Über" | "uber", "UBER" | yes | both sides are folded |
| "ore" / "lodz" | "Øre" / "Łódź" | yes | ø is an o, ł an l |
| "abc", "1" | "ＡＢＣ" (fullwidth), "①" | yes, both ways | width and compatibility forms |
| "STRASSE", "strasse" | "Straße" | **no** | ß is one letter; LIKE compares letter by letter |
| "STRAẞE" (capital ẞ) | "Straße" | yes | ẞ is ß |
| "aegir" | "Ægir" | **no** | æ is one letter |
| "kelvin" / "K" (Kelvin sign, U+212A) | "Kelvin" / "Kelvin" with the sign | yes, both ways | the sign is a k |
| "super" / "ſuper" | "ſuper" (long s) / "super" | yes, both ways | ſ is an s |
| "istanbul", "İSTANBUL" | "İstanbul" | yes | the dot of İ is an accent |
| "ILIK", "ilik" | "ılık" | **no** | ı is a letter of its own (no Turkish locale rules) |

`%` matches any text and `_` one character (an umlaut is one); a fullwidth ％ or ＿ in a pattern
is a literal character, never a wildcard. A ligature is one character ("ﬁ" does not find "fi").
Case pairs of letters outside the Basic Multilingual Plane (Deseret "𐐀" and "𐐨") match, as in
MariaDB's `LIKE` under that collation. Text is compared as stored: a decomposed "Ü" (U followed by
a combining diaeresis) is two characters, so "uber" does not find it. Browsers and Folio send
composed (NFC) text, so this only matters for text imported from elsewhere.

### The fold table

`Preflow\Data\Text\TextMatch` is the rule in PHP: `matchFold()` (since 0.78.1) replaces each
character with the one matching takes it as (Ü → u, É → e, ＡＢＣ → abc, ẞ → ß; ß, æ and ı stay),
one character for one, and `like()` and `contains()` compare those folds. Which characters are
taken as which is the matching table (`src/Text/search-fold-table.php`, about 7800 entries).
Exact comparisons use `fold()` and its own table (`src/Text/fold-table.php`, about 5000 entries,
accents kept; see "Exact comparisons"). Both are derived from the server by `bin/text-fold-table`
(in the framework repository; `--search` for the matching table, from the first of
`MysqlDialect::SEARCH_COLLATIONS` the server has): every assigned code point but controls,
surrogates and private use is grouped by its weights, the groups are split until `LIKE` agrees,
and each group folds to the member most of them reach by case folding after compatibility
(NFKC) mapping. The MySQL test suite's `TextMatchServerProbeTest` checks both against the server:
each character paired with its fold, its compatibility fold, its lower and upper case, and the
characters of equal weights, asked both ways; it fails listing every disagreement. The matching
table was derived from MariaDB 13.0's `utf8mb4_uca1400_nopad_ai_ci`, and MariaDB 11.4 gives the
same table; the fold table from MariaDB's `utf8mb4_uca1400_as_ci` (its `utf8mb4_0900_as_ci` gives
the same answers). MySQL 8's own `utf8mb4_0900_ai_ci` and `utf8mb4_0900_as_ci` have not been
probed yet.

### On JSON and SQLite

The JSON driver matches with `TextMatch`. `SqliteDriver` registers it as `ulike()` on its
connection (`SqliteDialect::prepare()`; it registers on a `Pdo\Sqlite`, `PDO::connect('sqlite:…')`,
as the app opens it, and on a plain `PDO`). SQLite's native `LIKE` folds ASCII case only, so the
dialect keeps it only for a pattern `TextMatch::asciiSafe()` accepts: ASCII characters that no
other character folds into. With the accented letters and the fullwidth forms that is no letter,
digit or other printable character (only control characters), so every `LIKE`, `CONTAINS` and
search on SQLite costs a PHP call per row compared (about 7× slower on a scan than native `LIKE`).
That was so before 0.78.1 too: ignoring accents adds no cost.

### On MySQL and MariaDB

The query compares in an accent-insensitive, case-insensitive collation:
`utf8mb4_uca1400_nopad_ai_ci` (MariaDB 10.10+) or `utf8mb4_0900_ai_ci` (MySQL 8, MariaDB 11.4.5+),
the first of `MysqlDialect::SEARCH_COLLATIONS` the server accepts; columns never take it (the
columns of text fields use the accent-sensitive one, see "Exact comparisons"). It is found once
per connection by probing (`SELECT _utf8mb4'a' COLLATE …` for each name until one does not fail
with "unknown collation", `MysqlGrammar::on($pdo)->searchCollation()`;
`MysqlDriver::searchCollation()` returns it), not from the version string or
`information_schema.COLLATIONS`, which does not list MariaDB's `utf8mb4_uca1400_*` names. The
column is cast to utf8mb4 and given the collation in the query, so a prefix `LIKE` (`'abc%'`) does
not use an index (none of Preflow's own do: they start with `%`). `new MysqlDriver($pdo,
searchCollation: false)` takes no search collation; a string names one. `matchCollation: false`
(as in 0.78.0) takes the `LOWER()` fallback below for `LIKE` too, unless `searchCollation` names a
collation. A server with the text
rule's collation (`MysqlDialect::MATCH_COLLATIONS`, `matchCollation:`) but without a search
collation compares `LIKE` in the text rule's collation, so accents count there; MariaDB 10.10+ and
MySQL 8 have both.

#### Without the search and text collations

On a server without any of them (MariaDB 10.6 to 10.9) both sides are lowered
with `LOWER()` and compared by code point (`utf8mb4_bin`). It follows the rule except for these
kinds of difference (`MysqlFallbackMatchingTest` runs the matching table's probe through this SQL
and checks that every kind it finds is listed; a kind that is version-specific says where it
occurs, and a server need not show every kind):

- Accents and other marks count, as in exact comparisons: "uber" does not find "Über", "super" does not find "ſuper".
- Only case is lowered: width and compatibility forms ("ａ" and "a", "①" and "1"), the other characters the collation takes as one (some marks and symbols), and the characters it ignores match only themselves.
- Case pairs outside the Basic Multilingual Plane ("𐐀" and "𐐨") match only where `LOWER()` folds them; where it does not (MariaDB 11.4), they do not match, unlike the rule.
- The capital sharp s "ẞ" and "ß" match only where `LOWER()` turns ẞ into ß; where it leaves ẞ as it is (MariaDB 11.4) "STRAẞE" does not find "Straße", unlike the rule.

## Exact comparisons

Since 0.78 `=`, `!=`, `IN`, `NOT IN`, ranges, `ORDER BY`, `GROUP BY`, `COUNT(DISTINCT)`, `MIN`/`MAX`,
`HAS` on a single value, unique checks and unique indexes compare a type's **text fields** by the
rule over the whole text: **case is ignored, accents count** (matching, since 0.78.1, ignores them;
see "Text matching"). The rule exactly:
the Unicode Collation Algorithm, root order, secondary strength, no padding (ICU's `Collator` at
`SECONDARY`, MariaDB's `utf8mb4_uca1400_nopad_as_ci`, MySQL's `utf8mb4_0900_as_ci`;
`Preflow\Data\Text\TextMatch::equals()`, `compare()` and `key()` in PHP).

| Values | Equal? | Why |
|---|---|---|
| "Müller", "MÜLLER" | yes | case |
| "Müller", "Muller" | **no** | accents count |
| "Straße", "STRAẞE" / "strasse" | yes / **no** | ẞ is ß; ß is not ss |
| "Kelvin" with the Kelvin sign, "kelvin" | yes | the sign is a k |
| "İstanbul", "istanbul" | **no** | İ is not i |
| "abc", "abc " | **no** | no padding |

Texts sort as "Apfel", "äpfel", "eagle", "Émile", "Zebra"; rows equal under the rule come in no
promised order among themselves. Text fields are those `TextColumns` names: `string`, `text`
and the text types extensions register (rich text, select), translatable ones too, except list
fields (tags, which compare exactly), the id field and fields marked `"exact": true`
(`#[Field(exact: true)]`). Slugs and e-mail addresses are text: "About" and "about" are one slug.
Everything else compares exactly: ids, relations, assets, numbers, dates, yes/no values, exact
fields ("ab" is not "Ab"). A migration's `string()` and `text()` are text unless `->exact()`.

**Why a plain model says it twice.** A plain `#[Entity]` model's table comes from your own
migration, not from the model, so the same fact lives in two places that nothing else connects:

- the model's `#[Field(exact: true)]` is the driver-neutral truth: JSON storage, loose search and
  `Validation\Unique` follow it, whatever database is behind the model;
- the migration's `->exact()` states the SQL column: the database's `=`, `IN`, `ORDER BY` and the
  unique index follow the column's collation, not the model.

Write both. `preflow migrate` checks them (the step `data.model-exactness`) and warns for every text
field of a plain model where they disagree, for example `exact: true` on the model but no `->exact()`
in the migration. It only warns, never changes a column; fix a column with `Schema::convertColumn()`.
It checks the storage the migrations ran on, and only tables a migration made since 0.78 (their
recorded intent); older tables are covered by the text-rule conversion below. Id fields and list
fields (tags) are not checked.

A class-backed type (`#[Entity(type: …)]`) states it once: its table is made by sync from the
type's definition, so `#[Field(exact: true)]` decides the column as well as JSON, search and
`Unique`, and there is nothing to disagree.

Where the rule lives:

- **SQLite:** the columns of text fields declare `COLLATE preflow_text`, a collation backed by
  `TextMatch::compare()` that `SqliteDialect::prepare()` registers on every connection
  `SqliteDriver` opens. A unique index on such a column refuses a case variant. Code that opens
  its own PDO on a Preflow SQLite file calls `SqliteDialect::prepare($pdo)` before it compares or
  writes indexed text columns (`Grammars::for()`, so `Schema` and `Migrator`, refuse a connection
  without it; so does a third-party driver's connection on SQLite: call it in its `configure()`).
  The `sqlite3` command-line tool does not have it: it reads such a table, but cannot filter, sort
  or write an indexed `preflow_text` column ("no such collation sequence"), nor restore a `.dump`
  of the database (it fails at the first `CREATE TABLE`, so nothing is restored), `VACUUM` it or
  run `PRAGMA integrity_check`. Use the commands that run through `SqliteDriver`'s connection:
  `preflow db:check` (integrity and foreign keys), `db:vacuum`, `db:dump [file]` and
  `db:restore <file>` (into an empty database; `Maintenance`, `Dumps`). A copy of the file taken
  while the site writes nothing stays a backup. The order of a `preflow_text` index comes from the
  ICU of the PHP that built it: run `migrate` with the PHP binary the site serves with (it rebuilds
  the indexes when `TextMatch::version()` changes, as that PHP sees it). Measured on a table of
  10,000 rows (PHP 8.5, ICU 79): each comparison is a PHP call (about 2 µs), so
  work that compares many values costs more than with BINARY: `ORDER BY` over all rows 107 ms
  instead of 7, `GROUP BY` 143 ms instead of 7, `=` without an index (a scan) 19 ms instead of
  0.4, making a unique index 107 ms instead of 5, inserting 10,000 rows into a table with one
  156 ms instead of 21. With an index the lookup (`=` 0.01 ms) and `ORDER BY … LIMIT` (0.01 ms)
  cost the same as before. Index the text fields a site filters or sorts large types by.
- **MySQL/MariaDB:** the columns of text fields name the rule's collation (see "On MySQL and
  MariaDB" above for how it is found); the rest are `utf8mb4_bin`. `ORDER BY` on a text field in a
  long column (TEXT, LONGTEXT: a field that is neither unique nor indexed) or on a translatable
  one sorts by `WEIGHT_STRING()` of the value: MariaDB sorts a long text by its first-level
  weights only (so "äpfel" and "Apfel" in storage order), and the weight string holds every level;
  such an `ORDER BY` cannot use an index, which a long column has none of anyway. A text field in
  a `VARCHAR` column (a unique or indexed field of a type; a plain model's migration `string()`)
  sorts by its column and keeps its index. `MysqlDriver` reads which columns are `VARCHAR` from
  the table itself (`information_schema`, once per table and request), so this holds for every
  query, also one built by hand.
- **Full-text types on MySQL:** a FULLTEXT index takes columns of one collation, so the tags a
  `"fullText": true` type's index covers (`TextColumns::exactInFullText()`) get the rule's
  collation too; queries still compare them exactly: `MysqlDriver` sees the column's collation,
  and a query that names its text fields (`Query::compareAsText()`, as `QueryBuilder` does) names
  `utf8mb4_bin` for every other field whose column carries the rule (`=`, `IN`, `ORDER BY`,
  grouping and `HAS`).
- **JSON:** the query names its text fields (`Query::compareAsText()`, set by `QueryBuilder` from
  the type, as `localize()` is) and the driver compares them with `TextMatch`. `Validation\Unique`
  decides with `TextMatch::equals()`.
- **A query built by hand** (`new Query()` handed to a driver's `findMany()`) carries what its
  author gives it, and the only text hint a query has is `compareAsText()`. Name the text fields
  with it, and the query compares the same on every driver: those by the rule, every other field
  exactly. A query that names none is compared as each store keeps its fields: JSON exactly, SQL by
  each column's collation (the rule's for a type's text fields, exact for the rest, in tables
  Preflow made). Everything else a database needs (MySQL: which columns are `VARCHAR`, which carry
  the rule) its driver reads from the table, not from the query.
- **Translatable fields:** a locale's value is a JSON value without the column's collation; the
  dialect compares it by the rule (`TextComparison::textValue()`: `COLLATE preflow_text`, or a cast
  into the rule's collation).

A field marked exact is a machine value, not searchable text, on every driver: no word search,
`CONTAINS` or full-text index looks into it, so its column stays exact (`utf8mb4_bin`, BINARY) and
a unique exact field keeps case everywhere. Loose search (Folio's lists, ⌘K, a relation's target)
finds a record when a word of the term equals the field's whole value, compared exactly: a code
"EXAMPLECLUB2026" is found by "EXAMPLECLUB2026", not by "EXAMPLECLUB" or "exampleclub2026"
(`LooseFields::wholeValue()`, `conditions()`).

A plain column keeps its own collation in the query, so its indexes stay usable. `StorageDriverContract`
pins the rule on every driver: its field kind `text` is a text field (`exact`, called `string` before 0.78, is exact), and
a store that cannot follow the rule says why in `textRuleMissing()`.

**Servers without the collation** (MariaDB before 10.10, MySQL before 8.0): text columns stay
exact (`utf8mb4_bin`), translatable values compare exactly, a migration's `string()` keeps the
table default `utf8mb4_unicode_ci`, and the contract's exact-comparison tests skip. `preflow
migrate` changes no collation there and says so once (`warning: this server has no
accent-sensitive, case-insensitive collation (MariaDB 10.10+ or MySQL 8.0+); text columns keep
their comparison`). A known difference, documented here.

### Existing tables (`preflow migrate`)

Tables made before 0.78 get the rule when `preflow migrate` runs (back the database up first, as
for every upgrade). Nothing in a row is ever changed or removed, every change is one statement per
table (all of its columns or none), and every step is safe to run again: a second run finds
nothing to do. A column gets the collation of its **intent**: text (the rule's) or exact
(`utf8mb4_bin` on MySQL, `BINARY` on SQLite), the same on every storage. Where the intent comes
from:

- **Record tables** (the types' tables, their `__published` snapshots and `__revisions` history,
  as `RecordTables` lists them): from the type, text fields the rule and its other columns exact.
  A column the entry does not list is converted too (`RecordTableSync`'s column plan): the id
  becomes exact, one a migration added gets its recorded intent, and any other (a system column
  such as `_share_token` an earlier version made, a removed field's) keeps the rule or the exact
  collation it has, while an older one becomes exact.
  Their sync step (`folio.tables`,
  `publishing.tables`, `codes.table`, …, `RecordTableSync::collations()`) converts them, on any
  storage: MySQL one `ALTER TABLE … MODIFY …, MODIFY …` (it copies the table and blocks writes
  while it runs; a FULLTEXT index refuses columns of mixed collations, so they change together),
  SQLite one table rebuild that keeps every column's collation, index and trigger.
- **The framework's tables** (`preflow_migrations`, `preflow_throttle`, `auth_tokens`,
  `auth_remember`, `code_redemption`, the bookkeeping below): declared with each column's intent in
  `Schema\DeclaredTables` (as `RecordTables` lists types' tables). Their machine values (purposes,
  subjects, hashes, ids) become exact. A package or an app declares its own the same way:

  ```php
  $container->get(DeclaredTables::class)->add('user_tokens', ['tokenHash' => 'exact', 'userId' => 'exact', 'name' => 'text']);
  ```
- **Tables a migration makes since 0.78**: `Migrator` runs each migration with a `Schema` that
  records every table it creates or drops and each column's intent (`string()`/`text()` text
  unless `->exact()`, everything else exact) in `preflow_migration_columns`, beside
  `preflow_migrations` (`Migration\MigrationColumns`). A later conversion reads it; no migration
  is ever run again.

Declared and recorded tables are converted by the step `data.text-rule`, on the storage the
migrations run on only (a table on another storage only when its declaration names that storage).

A line says what changed: `converted collations of people: title, body`. Converting a table also
makes the rule's collation its default where the database has one (MySQL: `ALTER TABLE …
DEFAULT CHARACTER SET utf8mb4 COLLATE …`, metadata only, no row rewritten), as a table made since
0.78 has it: `converted the default collation of people to utf8mb4_uca1400_nopad_as_ci`. A column
added without a type (`Schema::addColumn()`) is free text and compares exactly on every storage.

The last step of `migrate`, `data.collations` (`TextRuleConversion::unconverted()`), lists as a
warning every text column of a table Preflow manages (record, declared and recorded tables) whose
collation is still neither the rule's nor the exact one, and every such table default, with the
fix; `migrate` still succeeds. `preflow db:check` lists them too. Tables Preflow does not know are
not listed there (see below).

- **Duplicates under the rule.** Before a column under a unique index changes collation, whatever
  its old one, its values are counted under the new one (`TextDuplicates::sql()`, the unique
  field with its scope, by the index's prefix where it has one): a `utf8mb4_bin` column may hold
  "Müller" and "MÜLLER", and an older case-insensitive one (`utf8mb4_unicode_ci`) "Straße" and
  "STRAẞE", which it tells apart and the rule does not. When some are there more than once, that
  column is left as it is (with the other columns of a full-text index it shares), the rest of the
  table is converted, and every group is listed with its records, up to 20 and then a count:

  ```
  [folio.tables] refused: people.title: 1 value is there more than once when case is ignored; the column keeps its collation until they differ
  [folio.tables]   "Müller" in r1, r2
  [folio.tables] converted collations of people: body
  Some values are there more than once under the text rule (see "refused:" above); nothing was changed or removed. Rename one record of each group, then run migrate again.
  ```

  `migrate` then exits 1, after every step has run. Rename one record of each group (in Folio) and
  run `migrate` again: it converts the column. `preflow migrate --pretend` prints the same list
  without changing anything. A save that adds a duplicate between the count and the change makes
  the server refuse the change; nothing changes, and the line ends `run migrate again`. A table
  SQLite cannot rebuild as it is (a `CHECK`, `REFERENCES` or another collation in its definition)
  is left as it is with `refused: people: Cannot convert …; convert the table (see the data
  README)`: convert it with a migration (`Schema::convertColumn()` cannot either; rebuild it by
  hand).
- **A column in another character set** (MySQL `latin1`, `utf8mb3`) or an `ENUM`/`SET` is never
  converted (that would convert its values): `warning: people.title is in another character set
  (latin1_swedish_ci); it keeps its collation`. Convert the table on purpose.
- **Tables without known intent** (made by a site's migration before 0.78, or by another
  application in a shared database) are left as they are. `migrate` lists each once, on the storage
  the migrations run on: `warning: notes has no recorded text rule (made by a migration before
  0.78, or not by Preflow): its columns id, title keep their comparison. Adopt it with a migration
  (Schema::convertColumn(), see the data README) or list it in text_rule.skip`. A table made
  before 0.78 that a later migration extended (`addColumns()`) has intent for the new columns
  only: those are converted, and its older text columns are listed once the same way (`warning:
  notes has columns without a recorded text rule (made before 0.78): title keep their
  comparison. …`; its key column is not listed). To adopt a table, write a migration that converts its columns; `Migrator`
  records their intent as it runs it:

  ```php
  $schema->convertColumn('notes', fn (Table $t) => $t->string('title'));           // the rule
  $schema->convertColumn('notes', fn (Table $t) => $t->string('code', 40)->exact()); // a machine value
  ```

  Tables never to touch (and never to list) go in `config/data.php`:

  ```php
  'text_rule' => ['skip' => ['legacy_orders', 'wp_posts']],
  ```
- **SQLite after a PHP or ICU upgrade.** SQLite keeps the indexes over `preflow_text` columns in
  the order PHP's ICU and `TextMatch`'s tables give (`TextMatch::version()`). The step
  `data.text-rule` records the version in the table `preflow_text_collation` (in a transaction)
  and, when it is another or none is recorded, rebuilds each of those indexes (`REINDEX`), and
  says so when a version was recorded before: `rebuilt the indexes over text columns for icu 79.1,
  tables 3f9c0e1a2b4d6c8e (was icu 78.2, tables 3f9c0e1a2b4d6c8e)` (the first run records the
  version quietly). `migrate --pretend` lists the refusals below without rebuilding. A unique index whose
  values the new version takes as equal is left as it was, its groups are listed as `refused:`
  lines and the version is not recorded, so the next `migrate` tries again once they differ.
  Until then that index **stays in use** in its old order: a lookup of one of those values may
  miss a row, and the index may let in a value the rule takes as a duplicate. Fix the listed
  values first.
- **JSON** (the step `data.text-duplicates`, also on a site without SQL storage): nothing to
  convert, since the rule applies in PHP. Case-variant duplicates of a unique text field saved
  before 0.78 are listed in the same form (`refused: people.title: 1 value is there more than once
  when case is ignored; saving one of these records is refused until they differ`), and `migrate`
  exits 1: saving either record is refused (`validation.unique`) until they differ. The same
  check covers translatable unique fields on every storage (no index holds them), per locale
  (`people.slug (en)`).
- **Servers without the collation** change nothing (see above).

`GROUP BY`, `COUNT(DISTINCT)` and `MIN`/`MAX` on a text field follow the column's collation
after the change, so they group and compare by the rule as `=` does (values equal under `=`
form one group).

**A grammar of your own** (`Grammars::register()`) that implements
`Migration\Grammar\TextCollations` gets the same for new tables and columns: `Grammars::for($pdo)`
binds it to the connection (`on()`), and the table sync gives text fields its `textColumnType()`.
The conversion of existing tables above needs `Migration\Grammar\ConvertsCollations` too; without
it `migrate` prints one warning line per storage and leaves those columns as they are. Without
either, its columns keep its own comparison. A dialect implements `Driver\TextComparison` for translatable values and
the sort order.

## `searchLoose`

```php
$dm->queryIn($typeDef)->searchLoose('senior engineer')->get();
$dm->queryIn($typeDef)->searchLoose('engineer', fields: ['title', 'company'])->get();
```

The term is split on whitespace. Every word must match (AND); a word matches when it is a
substring of at least one field (OR), under the text-matching rule (see "Text matching"). `%`, `_` and quotes in the term match
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
  `labelField` (else the target type's `titleField`, else its first `string` field that is not hidden) and its text fields, by the
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

## Read-only and no-create types (0.78)

A type says what editors and APIs may do with its records. The core reads it; every adapter
(Folio, an API, one of your own) asks the same question and refuses the same things:

```json
{ "key": "faq",     "table": "faq",      "readonly": true,   "fields": { … } }
{ "key": "message", "table": "messages", "creatable": false, "fields": { … } }
```

```php
#[Entity(table: 'faq', type: 'faq', readonly: true)]
#[Entity(table: 'messages', type: 'message', creatable: false)]

use Preflow\Data\TypeAction;
$def = $data->getTypeRegistry()->get('faq');
$def->allows(TypeAction::Create);        // false
$def->assertAllows(TypeAction::Update);  // throws TypeActionRefused, a 403
```

| Option | Create | Update | Delete | For |
|---|---|---|---|---|
| (none) | yes | yes | yes | every type, as before |
| `"readonly": true` | no | no | no | records that come from files or code (an FAQ kept in git and seeded on every deploy): listed and viewed only |
| `"creatable": false` | no | yes | yes | an inbox of what visitors sent: no "New", but its flags are changed and its records deleted |

- **Reading is never restricted**, and **code is not either**: seeders, migrations and the app's own
  `DataManager` calls (`saveType()`, `deleteType()`, …) write as before. The option is about editors
  and adapters, not storage.
- **Machine translation is an adapter**, not code: it writes for editors. A read-only type is never
  offered for translation (Folio's text source asks `allows(TypeAction::Update)`), and a job queued
  before the type became read-only stops without sending a text to the model.
- `TypeDefinition::allows(TypeAction)` answers; `assertAllows()` throws
  `Preflow\Data\TypeActionRefused` (a `ForbiddenHttpException`: `$type`, `$action`), so an API
  controller that lets it through answers a JSON 403 under its API prefixes (and a page the error
  page). No built-in adapter throws it yet: Folio asks `allows()` and answers its own 403 page;
  `assertAllows()` is there for an API or an adapter of your own. `TypeDefinition::$readonly`,
  `$creatable` (false for a read-only type).
- **Checked at boot:** a value other than `true` or `false`, or `"readonly": true` together with
  `"creatable": true`, stops the boot naming the type and the option (with a boot manifest, when
  `preflow optimize` builds it). A model class sets them on `#[Entity]` or in its admin file; both
  may set one only to the same value (so may `fullText`).

## Record titles and cross-type search (0.77)

**A record's title** is the text that names it in lists, search hits and activity rows:

```json
{ "key": "game", "table": "games", "titleField": "title", "fields": { … } }
```

```php
#[Entity(table: 'games', type: 'game', titleField: 'title')]

use Preflow\Data\RecordTitle;
RecordTitle::of($record);        // in the type's default locale
RecordTitle::of($record, 'de');  // a translatable title in German (with the locale fallback)
```

`TypeDefinition::$titleField` is the field. Without one, the title is the first field of type
`string` that is not hidden (`"hidden": true`) and holds no personal data, read as a string only
(since 0.78; titles end up in the activity log and in lists): a field named `email` or ending in
`_email`, one with `"format": "email"` or an `email` rule, and one marked `"personal": true` are
skipped. Name it as `titleField` to use one anyway. A declared `titleField` may
also be a number field (its value is cast to text). An empty title (or none) falls back to the
record id. A `titleField` the type does not have is an error naming the type when the type
registry is built (at boot; with a boot manifest, when `preflow optimize` builds it). A model class sets it on `#[Entity(titleField: …)]` or in its admin
file; both may name it only when they name the same field, else boot fails naming the type and
both values. `searchLoose()` matches a relation without its own `labelField` against its target's
`titleField` (then the first string field, as before).

**Cross-type search** (`Preflow\Data\Search\RecordSearch`, bound in the container):

```php
$hits = $container->get(RecordSearch::class)->search('lisboa', ['game', 'designer'], perType: 5);
foreach ($hits as $hit) {
    echo "{$hit->type} {$hit->id} {$hit->title}";  // RecordHit, the title from RecordTitle
}
```

Each type gets `queryType($type)->searchLoose($term)->limit($perType)` (working copies), and
hits are grouped by type in the order the types are given. Within a type, hits come in storage
order (not ranked by relevance). A term shorter than 2 characters
after trimming finds nothing, and an unknown type is skipped. The search checks no
permission: pass only the types the person may read.

## Activity log storage (0.77)

`Preflow\Data\Activity\StoredActivityLog` is the core's `ActivityLog` (core README, "Activity
log") kept through the `DataManager`, so it works on every storage driver. It is bound when
`config/data.php` exists, on the storage alias `activity` when one is configured, else on the
default storage. `preflow migrate` creates its two tables on SQL storage (the step
`activity.table`): `preflow_activity` (the rows; indexes on the sort key, on
(subject, record_id), which `forget()` uses, and on the person, which `recent(by:)` uses) and `preflow_activity_last` (each record's newest
row, for coalescing). JSON storage needs no tables. Until a SQL site is migrated, `recent()`
reads an empty log, `prune()` and `forget()` do nothing, and the logger is told once.

```php
// config/activity.php (optional)
return [
    'keep_days' => 90,     // 0: no age limit
    'keep_rows' => 10000,  // 0: no row limit
];
```

- **Order:** by time (to the microsecond, UTC), then row id, through one sort key column
  (`seq`), so a plain text comparison pages the same on every driver.
- **Coalescing, one row per editing session:** a `Saved` by the same person on the same record
  updates that record's newest row when it is such a `Saved` and its last save was at most 10
  minutes ago (changed fields merged, time moved, the row goes to the top). The window slides:
  each save restarts it, so the session's row closes after 10 minutes without a save. Any other
  event in between starts a new row. A recording reads and writes single rows by id, so it costs
  the same however long the log is. The read and write run inside the storage's
  `ExclusiveWrites::exclusive()` (all built-in drivers), so concurrent saves never open two rows
  for one session; a third-party driver without it may, rarely.
- **Transactions:** inside a caller's open transaction, on a storage with `Savepoints` (the SQL
  drivers), a recording (and `recent()`, `people()`, `prune()`, `forget()`) runs under a
  savepoint: its rows commit or roll back with the caller's transaction, and a failure leaves
  that transaction usable (PostgreSQL-safe). The lock does not cover the caller's commit: two
  sessions saving one record inside long MySQL transactions may get two rows. A log on another
  storage (the `activity` alias on a different connection) does not join the caller's
  transaction.
- **Erasure:** `forget()` deletes the record's rows (and its newest-row entry) and clears its
  id where it stands as the person, ending that person's open sessions.
- **Titles by key:** `Activity::$titleKey` is stored (`title_key`) next to `title`.
- **People:** `people()` groups the rows by person (`AggregatingStorage`, every built-in
  driver); on a storage without aggregates it reads them 1000 at a time. On JSON storage the
  grouping itself reads every row of the log (as `recent()` does there), so an admin asks for it
  on its own screen (Folio's All activity), never on every page. There the answer is kept with the
  log's change token (`ChangeTokens`, the JSON driver's cache generation) and reused while nothing
  writes the log, in the same process.
- **JSON-stored sites:** every page of the log, the people filter and `prune()` read the whole log
  on JSON storage, so keep it short there: `'keep_rows' => 2000` (or less on a busy site) in
  `config/activity.php`, and the nightly `php preflow activity:prune`. SQL storage reads by
  index and is fine with the default 10000.
- **Retention:** `prune()` deletes rows older than `keep_days` and beyond the newest
  `keep_rows`, 1000 at a time with one `DELETE … IN (…)` each (`DataManager::deleteManyIn()`),
  and the newest-row entries pointing at them. It runs from `php preflow activity:prune` (and
  `members:prune`), never while recording or reading.

### Capabilities a driver may offer (0.77)

The full list, with what DataManager does without each, is the driver-author checklist under
"Your own storage driver".

- `BulkDeletes::deleteMany($type, $ids, $idField)`: `DataManager::deleteManyIn($def, $ids)`
  uses it (SQL: `DELETE … WHERE id IN (…)` in chunks of 500; JSON: one cache generation for
  all) and deletes one by one on a driver without it.
- `Savepoints::savepoint(\Closure $fn)`: inside an open transaction, `$fn` under a savepoint
  (released, or rolled back to when it throws); without one, `$fn` as it is. The SQL drivers
  implement it; a storage without transactions need not.
- `ChangeTokens::changeToken($type)`: a token that moves with every write of the type through a
  driver, so a reader may reuse what it computed while it stays (`DataManager::changeToken($def)`;
  `StoredActivityLog::people()` does). The JSON driver gives its cache generation (null without a
  cache directory); SQL drivers need not.

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
For a site whose files change outside the app (a `git pull` of the data folder), turn the cache off
on that storage in `config/data.php`: `'json' => ['path' => ..., 'cache' => false]`. Every query then
reads the record files; the default is on.
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
`views`) is a boot error (the registry checks it when it is built; with a boot manifest, when
`preflow optimize` builds it, so a production boot reads no admin file for it), never a silent
override. A
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

**Array fields** (several assets, blocks, a matrix, a multiple relation, Folio's tags) are `?array`
properties without a `transform`: the JSON text SQL storage holds is read as the array. Text an
`array` property cannot take (no JSON list or map, such as a legacy comma string "Folio, members")
throws `Preflow\Data\UnreadableValue` naming the type, the field and the record (since 0.77):
convert such rows to JSON lists with a migration before declaring the property as an array.
Other values that cannot be read as the property's type leave the property's default in place,
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
