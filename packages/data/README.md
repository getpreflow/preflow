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
| `#[Field(searchable: true)]` | Property | Marks a field; `searchable: true` includes it in full-text search. |
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

## MySQL and MariaDB

`preflow migrate`, `Schema` introspection and Folio's table sync run on MySQL 8 and MariaDB 10.6+
exactly as on SQLite; a schema grammar picked from the PDO driver name writes the SQL. SQLite
output is unchanged (same DDL, no new pending migrations for existing sites). Any other PDO
driver throws `No schema grammar for PDO driver "<name>".`

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
| `uuid()` | TEXT | VARCHAR(64) |
| `string($n = 255)` | TEXT | VARCHAR($n) |
| `text()` | TEXT | TEXT |
| `json()` | TEXT | LONGTEXT (not `JSON`: MySQL's type rewrites key order and spacing) |
| `integer()` | INTEGER | BIGINT |
| `boolean()` | INTEGER | TINYINT(1) |
| `timestamps()` | TEXT, nullable | VARCHAR(32), nullable |
| `addColumn()` default | TEXT | LONGTEXT |

Folio's synced tables use `id VARCHAR(191) PRIMARY KEY` and nullable `LONGTEXT` for every other
column, so a field holding 100 KB of blocks JSON fits. `preflow_migrations` is
`migration VARCHAR(191) PRIMARY KEY, ran_at VARCHAR(32) NOT NULL`.

**TEXT columns can't be indexed.** `$t->text('body')->index()` throws a `LogicException`
(`Cannot index TEXT column "posts.body" on MySQL: use string() for indexed columns.`); use
`string()` for anything indexed or unique. SQLite does not care, so a migration that indexes
`text()` passes there and fails on MySQL.

**Charset and collation.** Tables are created `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci` (MariaDB 10.6 has no `utf8mb4_0900_*`), identifiers in backticks.
**Known difference from SQLite:** `utf8mb4_unicode_ci` compares case- and accent-insensitively, so
string values (ids included) that differ only in case or accents (`Abc` and `abc`, `e` and `é`)
are equal in `WHERE`, and collide in a primary or unique key on MySQL while SQLite keeps them
apart. Generated ids are lowercase hex, so they are not affected; custom ids and unique
slugs/emails are.

**Connection.** `createMysqlDriver` (and `MysqlDriver` itself, for a hand-made `PDO`) sets:

- `PDO::ATTR_ERRMODE => ERRMODE_EXCEPTION`, so failures throw instead of returning false.
- `MYSQL_ATTR_FOUND_ROWS => true`: an `UPDATE` that matches a row but changes nothing still
  counts as 1 affected row, as on SQLite, so rowCount-based code (codes, tokens, throttle) behaves
  the same.
- Session `sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'`:
  too-long values are an error, not silently cut, and the result does not depend on the server's
  default mode. No `ANSI_QUOTES`, since identifiers use backticks.
- `utf8mb4` for the connection.

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
