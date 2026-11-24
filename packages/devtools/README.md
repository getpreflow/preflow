# Preflow DevTools

CLI commands for Preflow development. Requires the `preflow` entry point in your project root (provided by `preflow/starter` and `preflow/starter-folio`) or the package binary at `vendor/bin/preflow`.

## Installation

```bash
composer require preflow/devtools --dev
```

## Commands

### `serve` — start the dev server

Starts PHP's built-in server rooted at `public/`.

```
php preflow serve
# Preflow dev server started on http://localhost:8080
# Press Ctrl+C to stop.

php preflow serve 0.0.0.0 9000
# Binds to 0.0.0.0:9000
```

### `migrate` — run pending migrations and post-migrate steps

Boots the application (so packages register their migration paths and steps), then:

1. runs every pending file from `migrations/` and from the paths packages registered
   (core's `preflow_throttle` table among them) on a SQL storage;
2. runs every registered post-migrate step, such as Folio's table sync for SQL-stored
   content types and the activity log's tables (`activity.table`, since 0.77).

```
php preflow migrate
# 2 pending migration(s).
# [folio.tables] created table post
# Done.
php preflow migrate --pretend          # list the pending migrations, change nothing
php preflow migrate --storage=sqlite   # the storage to migrate (default: sqlite)
```

- Steps run on **every** run, not only when a migration was pending, so they must be
  idempotent. Folio's step is SQLite-only for now (a Folio type on MySQL makes it throw),
  and creates and adds only; it never drops or renames.
- `--pretend` skips the steps. The command exits 1 with `Error: ...` on stderr when it fails.
- A site whose storage is JSON only has no schema to migrate: it prints
  `No SQL storage configured: no schema migrations.`, then runs the steps registered for any
  storage (`sqlOnly: false`, such as `publishing.published_versions` and `rbac.seed`) and exits 0;
  the SQL-only steps (table and column syncs) do not run (since 0.77.1). Without
  `config/data.php` it prints `Nothing to migrate: no SQL storage configured.` and exits 0, so a
  shared deploy step can always run it. An explicit `--storage=<key>` that is unknown or not SQL
  still exits 1.

### `throttle:prune` — delete attempt-limit rows whose window has ended (since 0.78)

Deletes the rows of `preflow_throttle` whose window has ended (`Throttle::prune()`) and prints
how many. The throttle does this itself on the first hit of a window (so calling `prune()` on
every request is unneeded); run the command from cron when a site promises how long it keeps
them. A row lives at most its window plus the interval between runs; an expired row never
counts, so this is about privacy and size, not correctness.

```
*/5 * * * * cd /path/to/site/current && php preflow throttle:prune
```

`--every=<seconds>` (1 to 59, since 0.79.0) prunes, waits and prunes again until the next run
would start at or after 55 seconds from the start. One cron line a minute then gives a bound
under a minute (here: window + 30 seconds); other values are refused with exit code 1:

```
* * * * * cd /path/to/site/current && php preflow throttle:prune --every=30
```

### `db:check`, `db:vacuum`, `db:dump`, `db:restore` — the database through Preflow's connection (since 0.78)

A SQLite database's text columns use the collation `preflow_text`, which only Preflow's
connection has, so the `sqlite3` tool can't check, compact or restore it. These commands can:

```
php preflow db:check                  # SQLite: PRAGMA integrity_check + foreign_key_check; MySQL/MariaDB (0.84): CHECK TABLE
                                      # + a collation report; exit 1 on a problem
php preflow db:vacuum                 # SQLite: VACUUM; MySQL/MariaDB (0.84): OPTIMIZE TABLE
php preflow db:dump backup.sql        # the database as SQL (no file: standard output; an existing file is refused)
php preflow db:restore backup.sql --storage=copy   # into an empty database only ("-" reads standard input)
```

Each takes `--storage=<name>`; without it, the default storage when it can, else the one storage
that can. They ask the storage's driver for `Preflow\Data\Maintenance` (`check`, `vacuum`) and
`Preflow\Data\Dumps` (`dump`, `restore`); `SqliteDriver` implements both, `MysqlDriver` `Maintenance` (since 0.84; what MySQL
has no check for, such as references between records, is listed as "not applicable", never faked).
On MySQL/MariaDB dump and restore, and on JSON storage, they exit 1 naming the tools to use
(`mysqldump`, a copy of the folder). Messages go to standard error.

### `activity:prune` — apply the activity log's retention (since 0.77)

Boots the application and deletes the activity log rows past `config/activity.php`
`keep_days` and `keep_rows` (see the data README, "Activity log storage"), then prints how
many went. Run it nightly; `members:prune` runs it too:

```
23 3 * * * cd /path/to/site/current && php preflow activity:prune
```

### `db:seed [--class=<Name>] [--force]` — run the seeders

Boots the application (like `migrate`), then runs the classes in `app/Seeds/` in filename order. Each seeder is built by the container, so its constructor can take any service, and gets the container's `DataManager`:

```php
namespace App\Seeds;

use Preflow\Data\DataManager;
use Preflow\Data\Seed;

#[Seed(debugOnly: true)]   // demo data: never on a server with app.debug off
final class DemoSeeder
{
    public function run(DataManager $data): void
    {
        $job = new Job();
        $job->uuid = md5('demo-job-1');   // fixed id: rerunning updates, never duplicates
        $job->title = 'Barista';
        $data->save($job);
    }
}
```

```
php preflow db:seed                          # here with app.debug on
# Running seeders...
#   Seeded: DemoSeeder
# Done.
php preflow db:seed --class=DemoSeeder      # one seeder; a short name under App\Seeds, or fully qualified
```

- `.env` is loaded, so a seeder can read `$_ENV` / `getenv()`.
- `--class=<Name>` runs one seeder.
- `#[Seed(debugOnly: true)]` (from `preflow/data`, since 0.80.0) marks a seeder for demo or test data. With `app.debug` off, a full `db:seed` skips it and prints one line for each seeder it skipped:

  ```
  php preflow db:seed
  # Running seeders...
  #   Seeded: CountrySeeder
  #   Skipped: DemoSeeder (debug only; app.debug is off)
  # Done.
  ```

  `--class=DemoSeeder` with `app.debug` off refuses it (`Seeder DemoSeeder is marked debug only and app.debug is off; pass --force to run it anyway.`, exit 1); `--class=DemoSeeder --force` runs it. `--force` only applies to `--class`: a full run with `--force` still skips such seeders. With `app.debug` on, they run like any other seeder. Seeders without the attribute (or with `#[Seed]` alone) run as before.
- `save()` validates: the container's `DataManager` has the validator, so `#[Validate]` rules apply.
- An unknown `--class` prints `Seeder <Name> not found.` and exits 1; a seeder that throws prints `Seeder <Name> failed: <message>` and exits 1.
- A project without `app/Seeds/` prints `No seeders directory found (app/Seeds/).` and exits 0.

**Upgrading:** seeders used to run on a hand-built SQLite connection, with no container, no validation and no `.env`. They now run through the booted app, so `#[Validate]` rules apply and seed data that breaks them now fails. Seeders with a constructor that needs arguments now get them from the container instead of failing.

### `make:component Name` — scaffold a component

Creates `app/Components/Name/Name.php` and `app/Components/Name/Name.twig`.

```
php preflow make:component ProductCard
# Component created: app/Components/ProductCard/
#   - ProductCard.php
#   - ProductCard.twig
```

The generated PHP class extends `Component` with empty `resolveState()` and `actions()`. The Twig template includes `{% apply css %}` and `{% apply js %}` blocks.

### `make:model Name` — scaffold a model

Creates `app/Models/Name.php` with `#[Entity]`, `#[Id]`, `#[Field]`, and `#[Timestamps]` attributes. The table name is derived by snake-casing the class name and appending `s`.

```
php preflow make:model BlogPost
# Model created: app/Models/BlogPost.php
# (maps to table: blog_posts)
```

#### `make:model Name --from-type=key` — turn a JSON type into a model class

Reads `config/models/<key>.json` and writes `app/Models/Name.php`: `#[Entity(table, storage, type)]`,
the id, one property per field, with `#[Field]`, `#[Relation]` and `#[Validate]` carrying the data
parts and labels. The JSON file is rewritten to its admin-only part (presentation: label, icon,
list columns, per field `readonly`, `hidden`, …), so nothing is defined twice. Same table, same
data, same ids: nothing in the database changes.

```
php preflow make:model Job --from-type=job
# Model created: app/Models/Job.php
# Admin settings kept in: config/models/job.json
# Check the class, then run your tests: nothing in the database changes.
```

- Every property is nullable and takes the JSON `default`; `required` stays a validation rule, so
  a stored NULL is not rewritten by a later save. A translatable property is `array|string|null`;
  several assets, blocks, a matrix and a multiple relation are `?array`; one asset is its id (`?string`).
- A relative entry in `data.model_paths` is under the app's base path.
- Before writing, the command loads the generated class and compares the type it derives with the
  type from the JSON file. Any difference is an error and nothing is written.
- It refuses a class that exists, a key that is already class-backed, a class name PHP reserves
  (`Object`, `List`, …) and a type with `matrixable` or `views`, which a class cannot carry yet.
  Each error names the key.

### `make:controller Name` — scaffold a controller

Creates `app/Controllers/NameController.php` with a `#[Route]` class attribute and a `#[Get]` index action. Appends `Controller` to the name if not already present.

```
php preflow make:controller Article
# Controller created: app/Controllers/ArticleController.php
# (route prefix: /api/article)
```

### `make:migration name` — create a migration file

Creates a timestamped file in `migrations/` with empty `up()` and `down()` stubs.

```
php preflow make:migration create_comments
# Migration created: migrations/2026_04_10_143012_create_comments.php
```

### `routes:list` — print the route table

Boots the app and prints its routes in the order they match: the component endpoint (answered
before routing), then file pages, controllers and the routes packages add (media, Folio). What
middleware answers (redirects, sitemap, robots.txt, llms.txt) isn't listed. If the app doesn't
boot, it lists the file pages only and says why.

```
php preflow routes:list
# Registered Routes
# ──────────────────────────────────────────────────────────────────────
# Method  Pattern                            Mode        Handler
# ──────────────────────────────────────────────────────────────────────
# ANY     /--component/*                     endpoint    Preflow\Htmx\ComponentEndpoint
# GET     /                                  component   index.twig
# GET     /api/places                        action      App\Controllers\Api\PlacesController@index
# ──────────────────────────────────────────────────────────────────────
```

### `cache:list` — list registered caches

Shows every cache registered with the core cache registry: key, label,
number of entries and size.

```
php preflow cache:list
# Key        Label                     Entries  Size
# data.json  JSON storage query cache  12       3.1 KB
```

### `cache:clear [KEY]` — clear framework caches

`cache:clear <key>` clears one registered cache; `cache:clear --all` clears
every registered cache. An unknown key exits with 1 and lists the known keys.
Files outside the registered caches are never deleted. The bare form
(`cache:clear` without arguments) still clears all caches but prints a note;
it will require `--all` in a later release. An unknown option (anything starting
with `--` other than `--all`) exits with 1 and clears nothing.

`cache:list` and `cache:clear` boot the application to read the registry, so
they need what the app needs to boot (`.env`, config, a reachable database for
SQL drivers). When the boot fails they print the error and exit with 1.

```
php preflow cache:clear --all
# Cleared data.json: 12 file(s), 3.1 KB.
```

### `pages:work [--budget=50]` — rebuild queued pages (cron)

With the page cache on (preflow/core's README, "Page cache"), writes forget the pages that
showed what they changed and queue the full pages among them for rebuilding; stale pages and a
deploy's warm-up are queued too. The request that queued them rebuilds for `rebuild_budget`
seconds after its response; `pages:work` takes the rest, for up to `--budget` seconds (50 by
default). Run it every minute from the cron:

```
* * * * * cd /path/to/site/current && php preflow pages:work >/dev/null 2>&1
```

The queue's leases keep it and the after-response work from building one page twice. With
the cache off, or `app.debug` on, it rebuilds nothing.

```
php preflow pages:work
# Rebuilt 12 page(s) in 1.4 s; 0 queued.
```

### `pages:refresh <url>` / `pages:refresh --all` — refresh cached pages

`pages:refresh <url>` forgets every cached copy of one URL (each locale and htmx fragment
variant; a path such as `/about` is taken against `app.url`) and rebuilds the page.
`pages:refresh --all` forgets every page and queues every known full page without a query.
Both then rebuild for up to `--budget` seconds (50) and leave the rest to `pages:work`. A URL of
a host the cache doesn't cache exits with 1. Folio's Settings → Cache has the same two actions.

```
php preflow pages:refresh /about
# Forgot 1 cached copy of /about.
# Rebuilt 1 page(s); 0 queued.
php preflow pages:refresh --all
# Forgot every page; 240 queued for rebuilding.
# Rebuilt 240 page(s); 0 queued.
```

`cache:clear pages` empties the page cache without rebuilding anything (and drops the queue).

### `pages:why <path> [--locale=de]` — why is this page not cached? (since 0.85)

The page cache stores a page only when it renders the same for every visitor. A page that shows
the visitor's name, a random number or a cookie is left out, and until now the only sign was a
slow page. `pages:why` renders the page as the cache does, renders it once more as another
anonymous visitor, and compares the two as `page_cache.verify` does. Nothing is stored or served,
and it works with `app.debug` on, where the cache itself does not run.

```
php preflow pages:why /club
# /club would not be cached: differs per visitor outside live parts (body at byte 1204 in component StampCard-3fa91c2e)
# Hint: Move the per-visitor content into a live block ({% live %} in a template, @live in Blade) or into a #[Live] component: ...
php preflow pages:why /about --locale=de
# /about would be cached.
```

| Exit | Meaning |
|---|---|
| 0 | The page would be cached. |
| 1 | It would not be: the reason is printed, with a hint when there is a usual fix (move the part into a `{% live %}` block or a `#[Live]` component; a refusal such as a cookie, a session, a status other than 200 or a signed-in user says that instead). Also when the page cache is off. |
| 2 | Usage error, an unknown locale, the page answers 404, or the app fails to boot. |

The same log line the cache writes when it refuses a page now names the part that differed. In a
test, `WebApplication::assertPageCacheable($app, '/club')` (preflow/testing) fails with the same
reason.

### `optimize [--check]` — write the boot manifest

Writes `bootstrap/cache/boot.php` (atomically): the merged config, the package discovery data
and the catalogs (components, blocks, extensions, models, ...), so web requests with
`APP_DEBUG=0` read them instead of scanning. See preflow/core's README, "Boot manifest", for
when a request uses it. Run it on every deploy after `composer install` and migrations /
`folio:extensions:apply`, before the release goes live. With `APP_DEBUG` on it writes none
(and removes an existing one). `cache:clear` (key `boot`) removes it.

`optimize --check` lists the sources changed since the manifest was written and exits with 1;
it exits with 0 when the manifest is current and with 1 when there is none.

```
php preflow optimize
# Boot manifest written: /srv/site/releases/42/bootstrap/cache/boot.php
#   config: 18 file(s); catalogs: core.components, core.controllers, folio.extensions, …
php preflow optimize --check
# Boot manifest is current.
```

### `auth:token <email-or-id>` — issue, list and revoke API tokens

Needs `preflow/auth`. Creates a bearer token for a user of the token guard (the `auth.api_guard`
guard, else the first `TokenGuard`) and prints it once; only its hash is stored. Use it as
`Authorization: Bearer <token>` on routes with `TokenAuthMiddleware`.

```
php preflow auth:token editor@example.com --name=deploy-bot
php preflow auth:token --list editor@example.com
php preflow auth:token --revoke=<uuid>
```

`--name` is a label (default `cli`).

### `auth:user <PREFIX>` — set a login in `.env`

Asks for an email and a password (hidden, twice, at least 8 characters) and
writes `<PREFIX>_EMAIL` and `<PREFIX>_PASSWORD_HASH` to `.env`, replacing the
lines when they exist. The prefix is required (capital letters, digits and
underscores); the password and its hash are never printed.
`config/auth.php` reads the two values, e.g. for a `ConfigUserProvider` user.

```
php preflow auth:user ADMIN
php preflow auth:user EDITOR --email=editor@example.com
# Set EDITOR_EMAIL=editor@example.com and EDITOR_PASSWORD_HASH in .env.
```

## Debug toolbar

In debug mode `DebugToolbarMiddleware` adds the toolbar to HTML responses. Since 0.82.0:

- A "Request limits" section shows what the request frame asked of PHP (`RequestLimits`): each
  time limit and what came of it, and whether the request goes on when the browser closes the
  tab. A render in a frame of its own records on that frame, which the toolbar does not show.
- The toolbar's `<script>` and `<style>` carry the nonces of the response's own policy
  (`Preflow\Core\Http\ContentSecurityPolicy::ofResponse()`, enforced before Report-Only), else
  the request's nonce. So a whole-page preview, which brings its own policy, passes it, and the
  CSP inspector checks the page against its real nonces. `DebugToolbar::render()` and
  `CspInspector::issues()` take an optional `$styleNonce`; `render()` also an optional
  `RequestLimits`.

## Editing config files: `ConfigFileEditor` (since 0.85)

An installer that adds a provider or a guard to a developer's config file must not destroy what
the developer wrote. `Preflow\DevTools\Config\ConfigFileEditor` is the one way to do it. It
works on files that `return [ ... ];`.

```php
$editor = new ConfigFileEditor($basePath . '/config');

$editor->addToList('providers.php', ['\Vendor\Pkg\PkgServiceProvider::class'], 'start'); // or 'end'
$editor->addKey('auth.php', ['guards'], 'staff', "[\n    'class' => ...,\n]");
$editor->addToNestedList('auth.php', ['session', 'paths'], "'/admin'");
$editor->create('folio.php', $contents);   // never overwrites
$config = $editor->read('folio.php');      // the evaluated array, or null
```

Each call returns a `ConfigEdit` with a `status` (`written`, `changed`, `already` or `refused`), a
`message` and, when refused, the `snippet` to add by hand (`isRefused()`).

- **One insertion, nothing else.** The editor adds lines at a spot it recognises with the PHP
  tokenizer; every other byte, comments included, stays. A value that is already there
  (compared by evaluated value) is not added twice, and an existing key is never changed.
- **It checks its own work.** After writing, the file is evaluated again in a separate PHP
  process (with the app's `vendor/autoload.php`) and must equal the old values plus exactly the
  addition. If not, the original bytes are put back and the edit is refused.
- **It refuses what it cannot read safely:** `array()` syntax, a computed return, a `match`, a file
  that does not parse, a missing path, and a file that is a symbolic or hard link. The expressions
  you pass are evaluated: give it code you wrote, never user input.

`folio:install` is built on it.

## Commands from packages and the app

A package brings its own console commands by listing the classes in its `composer.json`
(constructed without arguments, so they implement `Command\CommandInterface`):

```json
"extra": { "preflow": { "commands": ["Vendor\\Pkg\\Console\\PruneCommand"] } }
```

`Console` reads them from `vendor/composer/installed.json`, so the site needs no
`config/commands.php` line (e.g. `members:prune` from `preflow/members`). A site's own
`config/commands.php` is registered after and wins on a name clash. A class that is missing or
not a command is skipped with a warning on stderr.
