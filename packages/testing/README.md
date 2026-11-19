# Preflow Testing

Test utilities for Preflow applications — components, routes, and data layers.

## Installation

```bash
composer require preflow/testing --dev
```

## What it does

- PHPUnit base cases for components, routes, and data
- Fluent HTTP response assertions wrapping PSR-7 responses
- In-memory SQLite and temporary JSON storage for isolated data tests
- Minimal `TestApplication` bootstrap (container, renderer, token service)
- Contract suites for your own template engine, hypermedia driver or storage driver, and one
  that proves internal renders leave no trace

## WebApplication

Boots a site's app as the web serves it (`setRunningInConsole(false)`), so the page cache
serves and stores, with `page_cache.verify` set to `'always'`: every page the test stores is
rendered a second time as another anonymous visitor, and a page that differs outside its
`{% live %}` parts is not stored and is logged
(`page cache: {url} differs per visitor outside live parts`).

```php
use Preflow\Testing\WebApplication;

$response = WebApplication::boot($basePath)->handle($request); // one app per request, as on the server
```

## ComponentTestCase

Extend to test Preflow components. `TestApplication` is wired up automatically in `setUp()`.

```php
use Preflow\Testing\ComponentTestCase;

final class ExampleCardTest extends ComponentTestCase
{
    public function test_renders_title(): void
    {
        $html = $this->renderComponent(ExampleCard::class, [
            'title' => 'Hello',
        ]);

        $this->assertStringContainsString('Hello', $html);
    }

    public function test_initial_state(): void
    {
        $card = $this->createComponent(ExampleCard::class, ['title' => 'Hi']);
        $card->resolveState();

        $this->assertSame('Hi', $card->title);
    }
}
```

## RouteTestCase + TestResponse

Wrap any PSR-7 `ResponseInterface` for fluent assertions.

```php
use Preflow\Testing\RouteTestCase;

final class HealthTest extends RouteTestCase
{
    public function test_health_endpoint(): void
    {
        $psrResponse = $this->app->handle($request); // your PSR-7 response

        $this->createTestResponse($psrResponse)
            ->assertOk()
            ->assertJson()
            ->assertSee('"status":"ok"');
    }
}
```

### TestResponse assertions

| Method | Description |
|---|---|
| `assertOk()` | Status 200 |
| `assertStatus(int $status)` | Exact status code |
| `assertSee(string $text)` | Body contains text |
| `assertNotSee(string $text)` | Body does not contain text |
| `assertRedirect(string $uri)` | 3xx + matching Location header |
| `assertHeader(string $name, string $value)` | Exact header value |
| `assertHeaderContains(string $name, string $sub)` | Header contains substring |
| `assertJson()` | Content-Type contains "json" + valid JSON body |
| `assertForbidden()` | Status 403 |
| `assertNotFound()` | Status 404 |
| `assertUnauthorized()` | Status 401 |
| `assertMethodNotAllowed()` | Status 405 |
| `json(?string $path = null)` | Decoded body, or the value at a dot path (`data.0.title`); a missing path fails the test |
| `assertJsonPath(string $path, mixed $expected)` | Value at a dot path equals `$expected` (strict) |
| `assertJsonValidationErrors(string ...$fields)` | Status 422 and each field present under `errors` |

All assertion methods return `$this` for chaining.

## DataTestCase

Provides an in-memory SQLite connection and a temporary JSON directory, both torn down after each test.

```php
use Preflow\Testing\DataTestCase;
use Preflow\Data\Migration\Table;

final class PostRepositoryTest extends DataTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTable('posts', function (Table $table) {
            $table->uuid('uuid')->primary();
            $table->string('title');
            $table->timestamps();
        });
    }

    public function test_save_and_find(): void
    {
        $driver = $this->getSqliteDriver();
        // use $driver or $this->dataManager() for ORM operations

        $pdo = $this->getPdo();
        $pdo->exec("INSERT INTO posts (uuid, title) VALUES ('1', 'Hello')");

        $row = $pdo->query("SELECT * FROM posts WHERE uuid = '1'")->fetch();
        $this->assertSame('Hello', $row['title']);
    }
}
```

### DataTestCase methods

| Method | Returns |
|---|---|
| `getSqliteDriver()` | `SqliteDriver` (in-memory SQLite) |
| `getJsonDriver()` | `JsonFileDriver` (temp directory) |
| `dataManager()` | `DataManager` with both drivers registered |
| `createTable(string $name, callable $cb)` | Creates table via Schema builder |
| `getPdo()` | Raw `\PDO` instance |

## Contract suites

Abstract PHPUnit test cases that check an adapter against the interface it implements. Write your
own template engine, hypermedia driver, storage driver or SQL dialect, extend the matching suite, implement its
hooks, and run it with PHPUnit like any other test. The built-in adapters run the same suites
(`TwigContractTest`, `BladeContractTest`, `HtmxContractTest`, `SqliteContractTest`,
`JsonContractTest`, `MysqlContractTest`, `SqliteDialectContractTest`, `MysqlDialectContractTest`).

| Suite | Interface | Hooks |
|---|---|---|
| `Preflow\Testing\Contract\TemplateEngineContract` | `Preflow\View\TemplateEngineInterface` | `createEngine(string $templateDir)`, `fixture(string $name)` |
| `Preflow\Testing\Contract\HypermediaDriverContract` | `Preflow\Htmx\HypermediaDriver` | `createDriver()`, `fragmentRequest(ServerRequestInterface $request)`, `targetRequest(ServerRequestInterface $request, string $targetId)`; optional `effects()`, `historyRestoreRequest($request)`, `pageNonceRequest($request, $nonce)` |
| `Preflow\Testing\Contract\StorageDriverContract` | `Preflow\Data\StorageDriver` | `createDriver()`, `createType(string $type, array $fields)`; optional `supportsAutoIds()`, `reopenDriver()`, `emptyDriver()`, `declareFullText(string $type, array $fields)`, `declareUnique(string $type, string $field)`, `textRuleMissing()` (the last five since 0.78); capability tests run when the driver implements the interface |
| `Preflow\Testing\Contract\DialectContract` (0.66.1) | `Preflow\Data\Driver\Dialect` | `createPdo()` (an empty database), `createDialect()`; optional `numberColumnType()` |

```php
use Preflow\Data\StorageDriver;
use Preflow\Testing\Contract\StorageDriverContract;

final class PostgresDriverContractTest extends StorageDriverContract
{
    protected function createDriver(): StorageDriver
    {
        return new PostgresDriver(new \PDO(getenv('PG_DSN')));
    }

    /** @param array<string, 'exact'|'text'|'short_text'|'number'|'boolean'|'json'> $fields the id field first */
    protected function createType(string $type, array $fields): void
    {
        // create the table for $type (a "number" id is generated by the database);
        // "text" and "short_text" columns follow the text rule, "exact" ones compare exactly
    }
}
```

What each hook must return, and how values may come back (a stored number as a numeric string,
a boolean as 1/0, a JSON field as text), is in each suite's class docblock:

- **TemplateEngineContract** writes seven fixture templates in your engine's syntax (`variable`,
  `escaped`, `raw_function`, `escaped_function`, `global`, `namespaced`, `part`) and checks
  rendering with context, default escaping, safe and unsafe functions, globals, `exists()`,
  namespaces (first registered path wins) and that a missing template throws.
- **HypermediaDriverContract** asserts no attribute or header names: the URL, method, target and
  event must appear in the attributes, effects (`triggerEvent`, `redirect`, `pushUrl`) in what
  `effects()` lists and in the response `applyEffects()` returns (which keeps its status, headers
  and content), `isFragmentRequest()` must recognise a request marked by `fragmentRequest()`, and
  `fragmentTarget()` must return the id `targetRequest()` sent (null for a plain request). Since
  0.65 `effects()` is optional: by default it lists the headers and body `applyEffects()` gives an
  empty response. The core side (`HypermediaProtocol`): a plain request is no history restore and
  sends no page nonce; a history restore is also a fragment request; `varyHeaders()` are header
  names; `navigate()` puts the URL in a header or the body and changes the response for a reload;
  `announceNonce()` keeps the content and writes only headers `nonceHeaders()` lists;
  `pageNonce()` returns what `pageNonceRequest()` sent (the two optional hooks skip their test when
  they return null).
- **StorageDriverContract** checks the round trip of each field kind (null and Unicode too; since
  0.78 the kinds are `exact`, an exact text such as an id or code, called `string` before;
  `text`, a text field; `short_text`, a text field in a bounded, indexed column; `number`,
  `boolean` and `json`),
  updates, `exists`, `delete`, a custom id field, generated ids, and `findMany()` with every
  `where` operator, `IN` / `NOT IN` (empty lists too), `where(field, null)`, `orWhere`, ordering,
  limit, offset and `total()`. A capability's tests run when the driver implements its interface
  and are skipped otherwise: `RunsSql` (`rawQuery()`, `execute()`, `dialect()`), `Transactions`
  (commit, rollback, joining an open one), `DescribesColumns::hasTable()`, `ConditionalUpdates`,
  `AtomicCounters` (also from four processes at once), `RankedSearch`, `Maintenance` (`check()`,
  `compact()`) and `Dumps` (`dump()` restored by `restore()`). Optional hooks serve them:
  `reopenDriver()` returns a second driver on the same store, made in a forked child process
  (null, the default, skips the tests that write from several processes); `emptyDriver()` a driver
  on a second, empty store, which a dump is restored into; `declareFullText()` makes a type's
  fields scorable together as the store needs it (MySQL: a FULLTEXT index), called after
  `createType()`; `declareUnique()` makes a unique index over one field, for a store whose own
  indexes refuse duplicates (the default skips those tests: DataManager checks unique fields on
  other stores); `textRuleMissing()` gives a reason the store cannot follow the text rule (a
  MySQL server without the collation), and the exact-comparison tests skip with it. Since 0.78
  there is no `supportsRawQuery()` hook: remove an override of it; the raw SQL tests run for
  `RunsSql` drivers only. `search()` is checked in text and list values.
- **Exact comparisons (0.78):** `=`, `!=`, `IN`, `NOT IN`, ranges and `ORDER BY` on a `text`
  field, and grouping, `COUNT(DISTINCT)`, `MIN` and `MAX` over it (with `whereAny()` groups and
  `AggregatingStorage`), follow the text rule (ignore case, respect accents: "müller" is
  "MÜLLER", "muller" is not); an `exact` field compares exactly; a unique index on a `text` field
  refuses a case variant. The tests name the text fields with `Query::compareAsText()`, as
  `QueryBuilder` does from a type, and that is the only text hint a query carries: a store
  without columns compares by it, an SQL store by its columns (whatever else it needs, such as
  which columns are bounded, it reads from its tables). `VersionedStorage` and `ExclusiveWrites`
  are not part of the contract yet.
  `Preflow\Testing\PlainDriver` (0.78) wraps any driver and hides its capabilities, to test what
  a caller does on a storage with the bare minimum.
- **DialectContract** runs a `Dialect`'s SQL on its real database: `wholeNumber()` inside an `UPDATE` on a number
  column and on a text column holding numbers, `''` and other text, and `insertOrUpdateSql()` inserting a row and then
  updating it by its id with expressions that read the existing row through table-qualified columns (as preflow/core's
  throttle does; PostgreSQL rejects bare names there). A dialect whose `insertOrUpdateSql()` returns null skips that
  test. The rest of `Dialect` is covered through StorageDriverContract.

Each test starts from a fresh adapter (`createEngine()` / `createDriver()` run in `setUp()`), so
a storage driver needs an empty store per test.

## Render isolation (0.81)

A site renders pages for itself: the SEO audit, GEO's Markdown views, page-cache rebuilds and
verify renders, previews, mails and PDFs. Such a render must leave no trace: it must not see the
visitor's cookies, session, signed-in user or language, and it must not change what the request
that asked for it queued or collected. `Preflow\Testing\Contract\RenderIsolationContract` checks
this for every render path you give it. It needs no Folio: extend it in a site, a package or an
adapter that renders internally.

| Hook | What it returns |
|---|---|
| `app()` | a booted `Application`; called once per test, so return a new one each time |
| `paths()` | path name => `Closure(Application, RenderContext): RenderResult`, the render under the context it is given; names from `RenderIsolationContract::PATHS`: `audit`, `geo`, `rebuild`, `verify`, `preview`, `mail`, `pdf` (cover the ones your app has) |
| `pending()` (optional) | paths that do not render under their context yet: they still run, and each test is marked incomplete (not skipped) with why each pending path fails, or that it passes |
| `marker()` (optional) | the text planted in the ambient state, `zz-ambient-marker` by default |
| `context()` (optional) | the context every render starts from, `ContextFactory::forTest()` by default; give your site's origin and locales |

```php
use Nyholm\Psr7\ServerRequest;
use Preflow\Core\Application;
use Preflow\Core\Context\Purpose;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\RenderResult;
use Preflow\Core\Context\Site;
use Preflow\Testing\ContextFactory;
use Preflow\Testing\Contract\RenderIsolationContract;

final class SiteRenderIsolationTest extends RenderIsolationContract
{
    protected function app(): Application
    {
        $app = Application::create(dirname(__DIR__));
        $app->boot();

        return $app;
    }

    protected function context(): RenderContext
    {
        return ContextFactory::forTest(new Site('https://example.com', ['en', 'de'], 'en'));
    }

    protected function paths(): array
    {
        return [
            'audit' => static fn (Application $app, RenderContext $ctx): RenderResult => $app->render(
                RenderContext::internal(Purpose::Audit, $ctx->site, $ctx->clock, '/')->in($ctx->locale),
                new ServerRequest('GET', '/'),
            ),
        ];
    }
}
```

Every render runs inside an outer frame of the test's own, as an internal render runs inside the
request that asks for it. The four tests:

- **Poisoned ambient state never reaches the render.** In the outer frame the contract sets the
  cookie `m`, the header `X-Marker` and `$_SESSION['m']` to the marker, calls the deprecated
  `Translator::setLocale('xx')`, `AuthManager::setUser()` (a user whose id is the marker) and
  `LiveContent::showWorkingCopies()` where the app has them, and queues one cookie and one mail.
  The render's body, headers and captured items must not contain the marker, and its status and
  body must equal the same render's without the poison.
- **The outer frame is byte-equal after each render.** With the same poison, the current context
  (serialized, without the PSR request), the frame's queued cookies and mail, the `CookieQueue`,
  the asset registry and the SEO page meta are the same before and after the render.
- **Interleaved renders are independent.** A, B, A in one process, B with another locale, a
  signed-in viewer and working copies: both A renders give the same status and body.
- **A frozen clock renders identically.** Two renders of a context frozen with `at()` give the
  same status and body.

`Preflow\Testing\ContextFactory::forTest(?Site $site = null)` builds the context these tests start
from: a visitor's GET of the home page (`http://localhost/` with the locale `en` without a site),
purpose `Test`, on a `FrozenClock` at `2026-01-01T00:00:00Z`. Derive what a test needs from it
(`->in('de')->workingCopies()`) and run code under it with `Contexts::run()`.

## Fast suites

Most of a site's test time goes into set-up, not into testing: migrate, seed, build a database. `Preflow\Testing\Fast` runs
each set-up step once and gives every later test that starts from the same state what the step left behind. Nothing is
skipped and no test is weakened. A test that starts from a different state runs the step for real.

Two parts: `SetupSnapshots` (run a step once, restore its result) and `TestWorker` (own databases and directories per
paratest worker).

### The snapshot rule

`$snapshots->step($name, $callable)` restores only when the state is exactly the same as when the step ran. The state is:

- the step's name
- every environment variable (`getenv()` and `$_ENV`)
- the content of every file under the registered roots
- every table's definition and checksum, for each registered database

- whether each root exists (a missing root and an empty root are different states)

If any of these differs, the step runs for real. That includes an environment variable a test set, a file a test wrote and
a row a test inserted. A restored step leaves what the real step left: files the step did not leave are removed, a root the
step made or removed is made or removed, and the environment variables the step set or removed (`putenv()` and `$_ENV`) are
set or removed again, before `onRestore` runs. A root's path in such a variable becomes the current test's root.

A file's mode and modification time are not part of the state. A restored file gets the current time, and the mode new files
get. A step whose outcome depends on either is not suited to snapshots.

A restore that cannot remove, make or write a file throws a `RuntimeException` naming the path. Symlinks under a root are
not supported: the step throws a `LogicException` before it runs, and the link is never followed.

On a miss the step runs and its outcome is kept for later tests. Nothing is kept when the outcome names a root's real path
(in a row or in the return value), when a root's placeholder appears in a file, when the return value cannot be serialized
or when the step throws. Those steps run for real every time.

### JSON caches of a test's data path

The JSON storage keeps a query cache per data path in `storage/cache/data` (`json-{type}-{pathKey}.json` and `.gen`). A
scaffold that gives every test a random `DATA_PATH` must give it a cache folder under the same temp root, or the site's
shared `storage/cache/data` gets a new set of files per test and never shrinks. Set `DATA_CACHE_PATH` next to `DATA_PATH`
(the application uses it instead of `storage/cache/data`), or let the helper do both.

`DATA_CACHE_PATH` is meant for tests only. Give it an absolute path (a relative one is resolved against the application's
base path) to a folder used for nothing else: `preflow cache:clear` deletes every `json-*` file in it.

```php
use Preflow\Testing\Fast\TestDataPaths;

[$dataPath, $cachePath] = TestDataPaths::use($this->siteTmp);   // {tmp}/data and {tmp}/cache/data, made, env set
// ... in the teardown, before the temp root is removed:
TestDataPaths::release();                                       // unsets DATA_PATH and DATA_CACHE_PATH
```

A scaffold that sets `DATA_PATH` by hand adds `putenv('DATA_CACHE_PATH=' . $this->siteTmp . '/cache/data');` and the name
to the list it unsets. If `{tmp}` is a snapshot root, the cache folder is part of the state like any file there.
`preflow cache:clear` empties the JSON caches and temp files of every data path, including those of data paths that no
longer exist (use it once on a site whose test runs piled up files); the tiny `.gen` generation files stay. Other per-app caches (`storage/cache/twig`, `assets`, `composer`)
are keyed by content or by the installed packages, so a test run reuses them and they do not grow per test. A test that creates an
application from a temp base path keeps them under it. `Application::create([...])` (an array) uses the working
directory as its base path: `chdir()` to a temp folder first.

### Effects outside the roots

A step may only change files under its roots and the registered databases. Anything else it writes must be a derived
cache, rebuilt from code, config and that state (Folio's extension and block caches are). If your step writes anything
else, register that directory as a root too. Then its content is part of the state.

### Setting it up

```php
// tests/bootstrap.php
require __DIR__ . '/../vendor/autoload.php';

\Preflow\Testing\Fast\SetupSnapshots::bootstrap();
```

`bootstrap()` makes one run id per phpunit or paratest run (`PREFLOW_TEST_RUN`). Separate-process tests inherit it. Only
the process that made the id removes the snapshots at the end. Without a run id, snapshots are off.

If `PREFLOW_TEST_RUN` is already exported in your shell, `bootstrap()` does nothing: the snapshots are kept across runs and
never removed. Code outside the roots is not part of the state, so a later run would restore what an edited migration or
seeder did before the edit. Unset it (`unset PREFLOW_TEST_RUN`) and let each run make its own.

In your test scaffold, keep one `SetupSnapshots` per test:

```php
$snapshots = (new SetupSnapshots())
    ->root('{siteTmp}', $this->siteTmp)             // this test's own temp dir
    ->ignore('{siteTmp}/storage/logs/error.log')    // nothing depends on it
    ->database('main', new MysqlSnapshotDatabase(fn () => $this->pdo(), TestWorker::name('site_test')))
    ->onRestore(function (): void {
        $this->app = null;                           // drop what you booted before the step
    });

$snapshots->step('migrate', fn () => $this->runMigrate());
$report = $snapshots->step('seed', fn () => ['users' => $this->runSeed()]);
```

- **`root($placeholder, $dir)`**: a directory that is this test's own, with a random name. The name is replaced by the
  placeholder in the state, so another test's equal state under another name matches. The placeholder is a name in braces
  (`{siteTmp}`). `$dir` is a path or a closure that returns one. The closure is called every time the roots are used (state,
  capture, restore, placeholders in the environment), so it suits a directory your scaffold makes later:
  `->root('{images}', fn (): string => $this->imageDir)`. A closure that returns `''` means there is no such root right now.
  An empty string path throws. Roots must not overlap: one root inside another throws a `LogicException` when the step runs.
  A step that changes which directory a root is (a closure that returns another path afterwards) is not kept.
- **`ignore($path)`**: a path under a root, written like `{siteTmp}/error.log`. It is left out of the state and kept on
  restore. Use it for logs, and for a link to a folder outside the root (a test copy's `vendor/` that links to the
  project's): an ignored symlink is kept and never followed, while any other symlink under a root throws (since 0.66.1).
  Its placeholder must be a registered root when the step runs (it may be registered after `ignore()`); otherwise the step
  throws.
- **`onRestore(Closure)`**: called after a restored step's files are in place. Drop your booted app, PDO handles and
  in-process caches here. Do not hold an open SQLite connection across a step. The `-wal` and `-shm` files are files under
  the root like any other. SQLite needs no adapter: its database is a file under a root.

### Return values

A hit returns the value the real step returned, as its own copy. The value must survive `serialize()`. Return scalars or
arrays: what a test needs from an import report, not the report object. A step that returns a closure or an object holding
your container runs for real every time and nothing is stored.

### Databases and lazy writing

`database($name, SnapshotDatabase $db)` registers a database. Call it more than once for several databases. Every
registered database is part of the state and restored. `MysqlSnapshotDatabase(\Closure $pdo, string $database)` is the
adapter for MySQL; it refuses a connection whose current database is not `$database`, so it never touches a real one.

A restored step does not write its tables at once. They are written when something next touches the database:
`$snapshots->flush()`. Call it from your own `app()` or PDO helper. A restored migrate followed by a restored seed then
writes the tables once. `$snapshots->reset()` marks the databases to be emptied the same lazy way; call it when your
scaffold boots a fresh site.

Call `flush()` before you start any process that touches a registered database: the built-in server for browser or HTTP
tests, a CLI sub-process (`preflow migrate`), a queue worker. That process does not know about the pending tables and would
see the database as it was before the restore.

Limits of the MySQL adapter: views and generated columns are not supported. A table with more than about 327 columns hits
the 65535-placeholder limit of one 200-row `INSERT`.

You can write your own adapter for another database: implement `SnapshotDatabase` (`state()`, `capture()`, `restore()`,
`empty()`).

### Turning it off

`PREFLOW_TEST_SNAPSHOTS=0 vendor/bin/phpunit` runs every step for real. Do that when you suspect a snapshot.

### Workers

paratest numbers its workers with `TEST_TOKEN` (1, 2, ...). `TestWorker` turns that into names:

```php
TestWorker::token();                  // 3, or null in a plain phpunit run
TestWorker::suffix();                 // '_3' or ''
TestWorker::name('site_test');        // 'site_test_3' or 'site_test'
TestWorker::dir('site_folio_cache');  // sys_get_temp_dir() . '/site_folio_cache_3', created
TestWorker::createMysqlDatabase($serverPdo, TestWorker::name('site_test'), $user);
```

`createMysqlDatabase()` runs `CREATE DATABASE IF NOT EXISTS`. If the user lacks the rights, the exception names the
`GRANT` a database admin must run once, for example ``GRANT ALL PRIVILEGES ON `site\_test\_%`.* TO 'user'@'localhost';``.

Workers can share one snapshot directory (they inherit the run id) or make their own. Both are correct. The key holds
every environment variable (so a worker's own `DB_DATABASE` or paths differ per worker when the site puts them in the
environment), the table definitions and checksums, and the file contents. Equal keys mean equal state, so a snapshot
written by one worker is correct for any worker that reaches the same key. Writes are atomic renames.

### Running with paratest

```
composer require --dev brianium/paratest
```

```json
"scripts": {
    "test": [
        "Composer\\Config::disableProcessTimeout",
        "@php vendor/bin/paratest --processes=6"
    ],
    "test:serial": [
        "Composer\\Config::disableProcessTimeout",
        "@php vendor/bin/phpunit"
    ]
},
"scripts-descriptions": {
    "test": "All tests, 6 workers in parallel (each its own databases)",
    "test:serial": "All tests in one process"
}
```

`composer test` is the fast run. `composer test:serial` is the same tests in one process. A test must pass alone, in
any order, serial or parallel.
