# BGGenius → Preflow Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrate BGGenius (Slim 4 board game teaching tool) to Preflow, converting the JS-driven player into server-rendered HTMX components and rebuilding the admin panel as Preflow components.

**Architecture:** Server-rendered HTML-over-the-wire. PlayerShell component orchestrates step navigation via HTMX actions. Each step type is a Preflow component. Admin CRUD uses the same component/action pattern. Flow editor preserved as standalone JS app.

**Tech Stack:** Preflow (all packages), Twig, SQLite, HTMX, League Glide, Symfony Mailer, league/commonmark

**Design Spec:** `docs/superpowers/specs/2026-04-14-bggenius-preflow-migration-design.md`
**Source Project:** `/Users/smyr/Sites/gbits/bggenius/`
**Target Project:** `/Users/smyr/Sites/gbits/bggenius-preflow/`

---

## Task 1: Fix Preflow PdoDriver to Support Configurable ID Fields

**Context:** The `PdoDriver` hardcodes `WHERE uuid = ?` in `findOne`, `delete`, `exists`, and `save`. The bggenius schema uses integer `id` columns. `ModelMetadata` already reads the `#[Id]` field name correctly — the driver just ignores it. This is a framework fix in the Preflow monorepo.

**Files:**
- Modify: `/Users/smyr/Sites/gbits/flopp/packages/data/src/StorageDriver.php`
- Modify: `/Users/smyr/Sites/gbits/flopp/packages/data/src/Driver/PdoDriver.php`
- Modify: `/Users/smyr/Sites/gbits/flopp/packages/data/src/DataManager.php`
- Modify: `/Users/smyr/Sites/gbits/flopp/packages/data/src/Driver/JsonFileDriver.php`
- Modify: `/Users/smyr/Sites/gbits/flopp/packages/data/tests/` (any existing tests that call these methods)

- [ ] **Step 1: Update StorageDriver interface to accept idField**

In `/Users/smyr/Sites/gbits/flopp/packages/data/src/StorageDriver.php`, add `string $idField = 'uuid'` parameter to `findOne`, `save`, `delete`, `exists`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data;

interface StorageDriver
{
    /**
     * @return array<string, mixed>|null
     */
    public function findOne(string $type, string|int $id, string $idField = 'uuid'): ?array;

    public function findMany(string $type, Query $query): ResultSet;

    /**
     * @param array<string, mixed> $data
     */
    public function save(string $type, string|int $id, array $data, string $idField = 'uuid'): void;

    public function delete(string $type, string|int $id, string $idField = 'uuid'): void;

    public function exists(string $type, string|int $id, string $idField = 'uuid'): bool;
}
```

- [ ] **Step 2: Update PdoDriver to use idField parameter**

In `/Users/smyr/Sites/gbits/flopp/packages/data/src/Driver/PdoDriver.php`, replace hardcoded `uuid` references:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

use Preflow\Data\Query;
use Preflow\Data\ResultSet;
use Preflow\Data\StorageDriver;

abstract class PdoDriver implements StorageDriver
{
    public function __construct(
        protected readonly \PDO $pdo,
        protected readonly Dialect $dialect,
        protected readonly QueryCompiler $compiler,
        protected readonly ?\Preflow\Core\Debug\DebugCollector $collector = null,
    ) {}

    protected function executeWithLogging(\PDOStatement $stmt, string $sql, array $bindings = []): void
    {
        $start = hrtime(true);
        $stmt->execute($bindings);
        if ($this->collector !== null) {
            $durationMs = (hrtime(true) - $start) / 1_000_000;
            $this->collector->logQuery($sql, $bindings, $durationMs);
        }
    }

    public function findOne(string $type, string|int $id, string $idField = 'uuid'): ?array
    {
        $table = $this->dialect->quoteIdentifier($type);
        $quotedId = $this->dialect->quoteIdentifier($idField);
        $sql = "SELECT * FROM {$table} WHERE {$quotedId} = ? LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $this->executeWithLogging($stmt, $sql, [$id]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $result !== false ? $result : null;
    }

    public function findMany(string $type, Query $query): ResultSet
    {
        [$countSql, $countBindings] = $this->compiler->compileCount($type, $query);
        $countStmt = $this->pdo->prepare($countSql);
        $this->executeWithLogging($countStmt, $countSql, $countBindings);
        $total = (int) $countStmt->fetchColumn();

        [$sql, $bindings] = $this->compiler->compile($type, $query);
        $stmt = $this->pdo->prepare($sql);
        $this->executeWithLogging($stmt, $sql, $bindings);
        $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return new ResultSet($items, $total);
    }

    public function save(string $type, string|int $id, array $data, string $idField = 'uuid'): void
    {
        if (!isset($data[$idField])) {
            $data[$idField] = $id;
        }

        $data = array_filter($data, fn ($v) => $v !== null);

        $columns = array_keys($data);
        $sql = $this->dialect->upsertSql($type, $columns, $idField);
        $stmt = $this->pdo->prepare($sql);
        $this->executeWithLogging($stmt, $sql, array_values($data));
    }

    public function delete(string $type, string|int $id, string $idField = 'uuid'): void
    {
        $table = $this->dialect->quoteIdentifier($type);
        $quotedId = $this->dialect->quoteIdentifier($idField);
        $sql = "DELETE FROM {$table} WHERE {$quotedId} = ?";
        $stmt = $this->pdo->prepare($sql);
        $this->executeWithLogging($stmt, $sql, [$id]);
    }

    public function exists(string $type, string|int $id, string $idField = 'uuid'): bool
    {
        $table = $this->dialect->quoteIdentifier($type);
        $quotedId = $this->dialect->quoteIdentifier($idField);
        $sql = "SELECT 1 FROM {$table} WHERE {$quotedId} = ? LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $this->executeWithLogging($stmt, $sql, [$id]);

        return $stmt->fetchColumn() !== false;
    }
}
```

- [ ] **Step 3: Update JsonFileDriver signature to match interface**

In `/Users/smyr/Sites/gbits/flopp/packages/data/src/Driver/JsonFileDriver.php`, update method signatures (behavior unchanged — file driver doesn't use SQL):

```php
public function findOne(string $type, string|int $id, string $idField = 'uuid'): ?array
public function save(string $type, string|int $id, array $data, string $idField = 'uuid'): void
public function delete(string $type, string|int $id, string $idField = 'uuid'): void
public function exists(string $type, string|int $id, string $idField = 'uuid'): bool
```

- [ ] **Step 4: Update DataManager to pass idField from ModelMetadata**

In `/Users/smyr/Sites/gbits/flopp/packages/data/src/DataManager.php`, pass `$meta->idField` to driver methods:

```php
public function find(string $modelClass, string|int $id): ?Model
{
    $meta = ModelMetadata::for($modelClass);
    $driver = $this->resolveDriver($meta->storage);

    $data = $driver->findOne($meta->table, $id, $meta->idField);

    if ($data === null) {
        return null;
    }

    $model = new $modelClass();
    $model->fill($data);

    return $model;
}

public function save(Model $model): void
{
    $meta = ModelMetadata::for($model::class);
    $driver = $this->resolveDriver($meta->storage);
    $data = $model->toArray();
    $id = $data[$meta->idField] ?? throw new \RuntimeException("Model missing ID field [{$meta->idField}].");

    $driver->save($meta->table, $id, $data, $meta->idField);
}

public function delete(string $modelClass, string|int $id): void
{
    $meta = ModelMetadata::for($modelClass);
    $driver = $this->resolveDriver($meta->storage);

    $driver->delete($meta->table, $id, $meta->idField);
}
```

Also update `findType`, `saveType`, `deleteType` to pass `$typeDef->idField`.

- [ ] **Step 5: Run existing tests**

Run: `cd /Users/smyr/Sites/gbits/flopp && composer test`
Expected: All 540 tests pass (or fix any that break due to the new parameter)

- [ ] **Step 6: Commit**

```bash
cd /Users/smyr/Sites/gbits/flopp
git add packages/data/src/
git commit -m "feat(data): support configurable ID field in StorageDriver

PdoDriver previously hardcoded 'uuid' as the ID column. Now accepts
idField parameter, enabling models with non-uuid primary keys (e.g.,
auto-increment integer 'id' columns)."
```

---

## Task 2: Scaffold BGGenius-Preflow Project

**Context:** Create the new project at `/Users/smyr/Sites/gbits/bggenius-preflow/` with Preflow's structure, configuration, and the existing SQLite database/content files.

**Files:**
- Create: `bggenius-preflow/composer.json`
- Create: `bggenius-preflow/.env`
- Create: `bggenius-preflow/.gitignore`
- Create: `bggenius-preflow/public/index.php`
- Create: `bggenius-preflow/public/.htaccess`
- Create: `bggenius-preflow/config/app.php`
- Create: `bggenius-preflow/config/auth.php`
- Create: `bggenius-preflow/config/data.php`
- Create: `bggenius-preflow/config/i18n.php`
- Create: `bggenius-preflow/config/providers.php`
- Create: `bggenius-preflow/config/middleware.php`
- Copy: `bggenius/database/schema.sql` → `bggenius-preflow/database/schema.sql`
- Copy: `bggenius/database/bggenius.sqlite` → `bggenius-preflow/database/bggenius.sqlite`
- Copy: `bggenius/content/` → `bggenius-preflow/content/`

- [ ] **Step 1: Create project directory and initialize git**

```bash
mkdir -p /Users/smyr/Sites/gbits/bggenius-preflow
cd /Users/smyr/Sites/gbits/bggenius-preflow
git init
```

- [ ] **Step 2: Create composer.json**

```json
{
    "name": "bggenius/app",
    "description": "BG Genius - Interactive Board Game Teaching Tool (Preflow)",
    "type": "project",
    "license": "proprietary",
    "require": {
        "php": ">=8.4",
        "preflow/core": "dev-main",
        "preflow/routing": "dev-main",
        "preflow/view": "dev-main",
        "preflow/twig": "dev-main",
        "preflow/components": "dev-main",
        "preflow/auth": "dev-main",
        "preflow/data": "dev-main",
        "preflow/htmx": "dev-main",
        "preflow/i18n": "dev-main",
        "league/glide": "^3.2",
        "symfony/mailer": "^7.0",
        "league/commonmark": "^2.0",
        "nyholm/psr7": "^1.8",
        "nyholm/psr7-server": "^1.1"
    },
    "require-dev": {
        "preflow/testing": "dev-main",
        "preflow/devtools": "dev-main"
    },
    "repositories": [
        {
            "type": "path",
            "url": "/Users/smyr/Sites/gbits/flopp/packages/*"
        }
    ],
    "autoload": {
        "psr-4": {
            "App\\": "app/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Tests\\": "tests/"
        }
    }
}
```

Note: The `repositories` block points to the local Preflow monorepo packages for development. Remove this when Preflow is released.

- [ ] **Step 3: Create .env**

```
APP_NAME="BG Genius"
APP_DEBUG=1
APP_KEY=base64:GENERATE_THIS
APP_ENGINE=twig

DB_DRIVER=sqlite

ADMIN_EMAIL=admin@example.com
SMTP_HOST=
SMTP_PORT=587
SMTP_USER=
SMTP_PASS=
SMTP_FROM_NAME="BG Genius"
```

- [ ] **Step 4: Create .gitignore**

```
/vendor/
/.env
/storage/cache/
/storage/logs/
/storage/data/
/database/bggenius.sqlite
/public/uploads/
.DS_Store
.idea/
```

- [ ] **Step 5: Create public/index.php**

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = Preflow\Core\Application::create(__DIR__ . '/..');
$app->boot();
$app->run();
```

- [ ] **Step 6: Create public/.htaccess**

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
```

- [ ] **Step 7: Create config files**

`config/app.php`:
```php
<?php

return [
    'name' => getenv('APP_NAME') ?: 'BG Genius',
    'debug' => (int) (getenv('APP_DEBUG') ?: 0),
    'timezone' => 'UTC',
    'locale' => 'en',
    'key' => getenv('APP_KEY') ?: '',
    'engine' => getenv('APP_ENGINE') ?: 'twig',
];
```

`config/auth.php`:
```php
<?php

return [
    'default_guard' => 'session',
    'guards' => [
        'session' => [
            'class' => Preflow\Auth\SessionGuard::class,
            'provider' => 'data_manager',
        ],
    ],
    'providers' => [
        'data_manager' => [
            'class' => Preflow\Auth\DataManagerUserProvider::class,
            'model' => App\Models\User::class,
        ],
    ],
    'password_hasher' => Preflow\Auth\NativePasswordHasher::class,
    'session' => [
        'lifetime' => 7200,
        'cookie' => 'bggenius_session',
        'path' => '/',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ],
];
```

`config/data.php`:
```php
<?php

return [
    'drivers' => [
        'sqlite' => [
            'driver' => \Preflow\Data\Driver\SqliteDriver::class,
            'path' => __DIR__ . '/../database/bggenius.sqlite',
        ],
    ],
    'default' => 'sqlite',
];
```

`config/i18n.php`:
```php
<?php

return [
    'default' => 'en',
    'available' => ['en'],
    'fallback' => 'en',
    'url_strategy' => 'none',
];
```

`config/providers.php`:
```php
<?php

return [
    App\Providers\AppServiceProvider::class,
];
```

`config/middleware.php`:
```php
<?php

return [
    App\Middleware\AdminAuthMiddleware::class,
];
```

- [ ] **Step 8: Copy database, content, and uploads from original project**

```bash
cp -r /Users/smyr/Sites/gbits/bggenius/database /Users/smyr/Sites/gbits/bggenius-preflow/
cp -r /Users/smyr/Sites/gbits/bggenius/content /Users/smyr/Sites/gbits/bggenius-preflow/
mkdir -p /Users/smyr/Sites/gbits/bggenius-preflow/storage/{cache,logs}
mkdir -p /Users/smyr/Sites/gbits/bggenius-preflow/storage/cache/{flows,images}
cp -r /Users/smyr/Sites/gbits/bggenius/storage/uploads /Users/smyr/Sites/gbits/bggenius-preflow/public/uploads 2>/dev/null || true
```

- [ ] **Step 9: Run composer install**

```bash
cd /Users/smyr/Sites/gbits/bggenius-preflow
composer install
```

Expected: Dependencies install successfully, autoloader generated.

- [ ] **Step 10: Create directory structure**

```bash
cd /Users/smyr/Sites/gbits/bggenius-preflow
mkdir -p app/{Components/{Player,Public,Admin,Shared},Controllers/Admin,Models,Services,Middleware,Providers}
mkdir -p app/pages/play
mkdir -p app/pages/admin/{games,users,contact}
mkdir -p templates
mkdir -p tests
mkdir -p public/js/admin
mkdir -p bin
```

- [ ] **Step 11: Commit scaffold**

```bash
cd /Users/smyr/Sites/gbits/bggenius-preflow
git add -A
git commit -m "chore: scaffold bggenius-preflow project

Preflow-based project structure with config, database schema,
content files, and directory layout for migration."
```

---

## Task 3: Data Models

**Context:** Create all 8 typed Preflow models mapping to the existing SQLite schema. Models use `int $id` as the primary key (not uuid) — enabled by the Task 1 framework fix. Port query logic from the original static model classes into Preflow's `DataManager::query()` pattern.

**Files:**
- Create: `app/Models/Game.php`
- Create: `app/Models/User.php`
- Create: `app/Models/TeachingFlow.php`
- Create: `app/Models/UserProgress.php`
- Create: `app/Models/Feedback.php`
- Create: `app/Models/ContactMessage.php`
- Create: `app/Models/Asset.php`
- Create: `app/Models/FlowRevision.php`

- [ ] **Step 1: Create Game model**

`app/Models/Game.php`:
```php
<?php

declare(strict_types=1);

namespace App\Models;

use Preflow\Data\Model;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Transform\JsonTransformer;
use Preflow\Data\Transform\DateTimeTransformer;

#[Entity(table: 'games', storage: 'sqlite')]
final class Game extends Model
{
    #[Id]
    public int $id = 0;

    #[Field(searchable: true)]
    public string $slug = '';

    #[Field(searchable: true)]
    public string $name = '';

    #[Field(searchable: true)]
    public string $description = '';

    #[Field]
    public string $designer = '';

    #[Field]
    public string $publisher = '';

    #[Field]
    public ?int $year_published = null;

    #[Field]
    public int $min_players = 1;

    #[Field]
    public int $max_players = 4;

    #[Field]
    public ?int $play_time_minutes = null;

    #[Field]
    public ?float $complexity = null;

    #[Field]
    public ?int $teach_time_minutes = null;

    #[Field]
    public ?string $cover_image = null;

    #[Field]
    public ?string $bgg_id = null;

    #[Field(transform: JsonTransformer::class)]
    public array $tags = [];

    #[Field]
    public string $status = 'draft';

    #[Field]
    public ?int $created_by = null;

    #[Field]
    public ?string $created_at = null;

    #[Field]
    public ?string $updated_at = null;
}
```

- [ ] **Step 2: Create User model**

`app/Models/User.php`:
```php
<?php

declare(strict_types=1);

namespace App\Models;

use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthenticatableTrait;
use Preflow\Data\Model;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Attributes\Field;

#[Entity(table: 'admin_users', storage: 'sqlite')]
final class User extends Model implements Authenticatable
{
    use AuthenticatableTrait;

    #[Id]
    public int $id = 0;

    #[Field(searchable: true)]
    public string $username = '';

    #[Field(searchable: true)]
    public string $email = '';

    #[Field]
    public string $password_hash = '';

    #[Field]
    public string $role = 'editor';

    #[Field]
    public ?string $reset_code = null;

    #[Field]
    public ?string $reset_code_expires_at = null;

    #[Field]
    public ?string $created_at = null;

    #[Field]
    public ?string $last_login_at = null;

    public function getAuthIdentifier(): string|int
    {
        return $this->id;
    }

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }
}
```

- [ ] **Step 3: Create TeachingFlow model**

`app/Models/TeachingFlow.php`:
```php
<?php

declare(strict_types=1);

namespace App\Models;

use Preflow\Data\Model;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Transform\JsonTransformer;

#[Entity(table: 'teaching_flows', storage: 'sqlite')]
final class TeachingFlow extends Model
{
    #[Id]
    public int $id = 0;

    #[Field]
    public int $game_id = 0;

    #[Field(transform: JsonTransformer::class)]
    public array $flow_json = [];

    #[Field]
    public int $version = 0;

    #[Field]
    public ?string $created_at = null;

    #[Field]
    public ?string $updated_at = null;
}
```

- [ ] **Step 4: Create UserProgress model**

`app/Models/UserProgress.php`:
```php
<?php

declare(strict_types=1);

namespace App\Models;

use Preflow\Data\Model;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Transform\JsonTransformer;

#[Entity(table: 'user_progress', storage: 'sqlite')]
final class UserProgress extends Model
{
    #[Id]
    public int $id = 0;

    #[Field]
    public string $player_token = '';

    #[Field]
    public int $game_id = 0;

    #[Field]
    public int $current_layer = 0;

    #[Field]
    public int $current_step = 0;

    #[Field(transform: JsonTransformer::class)]
    public array $completed_steps = [];

    #[Field(transform: JsonTransformer::class)]
    public array $quiz_answers = [];

    #[Field]
    public ?string $started_at = null;

    #[Field]
    public ?string $last_activity_at = null;

    #[Field]
    public ?string $completed_at = null;
}
```

- [ ] **Step 5: Create Feedback model**

`app/Models/Feedback.php`:
```php
<?php

declare(strict_types=1);

namespace App\Models;

use Preflow\Data\Model;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Attributes\Field;

#[Entity(table: 'feedback', storage: 'sqlite')]
final class Feedback extends Model
{
    #[Id]
    public int $id = 0;

    #[Field]
    public int $game_id = 0;

    #[Field]
    public string $player_token = '';

    #[Field]
    public int $rating = 0;

    #[Field]
    public ?string $comment = null;

    #[Field]
    public ?string $name = null;

    #[Field]
    public ?string $email = null;

    #[Field]
    public ?string $created_at = null;
}
```

- [ ] **Step 6: Create ContactMessage model**

`app/Models/ContactMessage.php`:
```php
<?php

declare(strict_types=1);

namespace App\Models;

use Preflow\Data\Model;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Attributes\Field;

#[Entity(table: 'contact_messages', storage: 'sqlite')]
final class ContactMessage extends Model
{
    #[Id]
    public int $id = 0;

    #[Field(searchable: true)]
    public string $name = '';

    #[Field]
    public string $email = '';

    #[Field(searchable: true)]
    public string $subject = '';

    #[Field(searchable: true)]
    public string $message = '';

    #[Field]
    public int $is_read = 0;

    #[Field]
    public int $is_spam = 0;

    #[Field]
    public ?string $ip_address = null;

    #[Field]
    public ?string $created_at = null;
}
```

- [ ] **Step 7: Create Asset model**

`app/Models/Asset.php`:
```php
<?php

declare(strict_types=1);

namespace App\Models;

use Preflow\Data\Model;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Attributes\Field;

#[Entity(table: 'assets', storage: 'sqlite')]
final class Asset extends Model
{
    #[Id]
    public int $id = 0;

    #[Field(searchable: true)]
    public string $path = '';

    #[Field]
    public ?int $game_id = null;

    #[Field(searchable: true)]
    public string $filename = '';

    #[Field]
    public ?string $usage_type = null;

    #[Field]
    public ?int $file_size = null;

    #[Field]
    public ?string $mime_type = null;

    #[Field]
    public ?int $width = null;

    #[Field]
    public ?int $height = null;

    #[Field]
    public ?string $created_at = null;

    #[Field]
    public ?string $updated_at = null;
}
```

- [ ] **Step 8: Create FlowRevision model**

`app/Models/FlowRevision.php`:
```php
<?php

declare(strict_types=1);

namespace App\Models;

use Preflow\Data\Model;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Transform\JsonTransformer;

#[Entity(table: 'flow_revisions', storage: 'sqlite')]
final class FlowRevision extends Model
{
    #[Id]
    public int $id = 0;

    #[Field]
    public int $game_id = 0;

    #[Field(transform: JsonTransformer::class)]
    public array $flow_json = [];

    #[Field]
    public int $version = 0;

    #[Field]
    public ?string $saved_at = null;
}
```

- [ ] **Step 9: Commit models**

```bash
cd /Users/smyr/Sites/gbits/bggenius-preflow
git add app/Models/
git commit -m "feat: add all 8 typed data models

Game, User, TeachingFlow, UserProgress, Feedback, ContactMessage,
Asset, FlowRevision — mapped to existing SQLite schema with integer
primary keys."
```

---

## Task 4: Services & Provider

**Context:** Port FlowService, ProgressService, ImageService from the original project. Create MailService wrapping Symfony Mailer. Create AppServiceProvider to register everything.

**Files:**
- Create: `app/Services/FlowService.php`
- Create: `app/Services/ProgressService.php`
- Create: `app/Services/ImageService.php`
- Create: `app/Services/MailService.php`
- Create: `app/Providers/AppServiceProvider.php`

- [ ] **Step 1: Create FlowService**

`app/Services/FlowService.php` — Port from `bggenius/src/Services/FlowService.php`. Adapt to use Preflow's DataManager instead of raw Database queries. Key methods: `loadForGame(DataManager, int $gameId, string $contentPath): ?array`, `validate(array $flowData): array`, `getStats(array $flowData): array`, `getStep(array $flowData, int $layerIndex, int $stepIndex): ?array`, `isLastStep(...)`, `getTotalStepCount(...)`.

The FlowService loads flow from TeachingFlow model first, falls back to JSON file in `/content/`, and caches to `storage/cache/flows/`.

- [ ] **Step 2: Create ProgressService**

`app/Services/ProgressService.php` — Port from `bggenius/src/Services/ProgressService.php`. Pure logic, no DB dependency. Methods: `mergeStepCompletion(array $completed, string $stepId): array`, `mergeQuizAnswer(array $answers, string $stepId, string $answer, bool $correct): array`, `calculatePercent(array $completed, array $flowData): float`, `isFlowComplete(array $completed, array $flowData): bool`.

- [ ] **Step 3: Create ImageService**

`app/Services/ImageService.php` — Port from `bggenius/src/Services/ImageService.php`. Wraps League Glide with presets. Takes `$cachePath`, `$sourcePath`, `$basePath` via constructor (injected by provider). Methods: `url(string $path, string $preset): string`, `serve(string $preset, string $path): ResponseInterface`, `clearCache(?string $path = null): void`.

Presets:
```php
private const PRESETS = [
    'cover-thumb' => ['w' => 200, 'h' => 200, 'fit' => 'crop'],
    'cover-card' => ['w' => 400, 'h' => 300, 'fit' => 'crop'],
    'cover-hero' => ['w' => 1200, 'h' => 400, 'fit' => 'crop'],
    'step-side' => ['w' => 500, 'fit' => 'max'],
    'step-full' => ['w' => 1000, 'fit' => 'max'],
    'glossary' => ['w' => 100, 'h' => 100, 'fit' => 'crop'],
    'original' => [],
];
```

- [ ] **Step 4: Create MailService**

`app/Services/MailService.php` — New service wrapping Symfony Mailer:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

final class MailService
{
    private ?Mailer $mailer = null;

    public function __construct(
        private readonly string $smtpHost,
        private readonly int $smtpPort,
        private readonly string $smtpUser,
        private readonly string $smtpPass,
        private readonly string $fromName,
        private readonly string $fromEmail,
    ) {}

    public function send(string $to, string $subject, string $body): void
    {
        if ($this->smtpHost === '' || $this->smtpUser === '') {
            return; // SMTP not configured, silently skip
        }

        $email = (new Email())
            ->from("{$this->fromName} <{$this->fromEmail}>")
            ->to($to)
            ->subject($subject)
            ->text($body);

        $this->getMailer()->send($email);
    }

    public function sendContactNotification(string $adminEmail, string $name, string $fromEmail, string $subjectType, string $message): void
    {
        $subjectLabels = [
            'feedback' => 'General feedback',
            'bug' => 'Bug report',
            'game-request' => 'Game request',
            'other' => 'Other',
        ];
        $subjectLabel = $subjectLabels[$subjectType] ?? 'Feedback';

        $this->send(
            $adminEmail,
            "[BG Genius] {$subjectLabel} from {$name}",
            "From: {$name} <{$fromEmail}>\nSubject: {$subjectLabel}\n"
            . str_repeat('-', 40) . "\n\n{$message}"
        );
    }

    public function sendPasswordResetCode(string $to, string $code): void
    {
        $this->send(
            $to,
            'BG Genius - Password Reset Code',
            "Your password reset code is: {$code}\n\nThis code expires in 15 minutes.\n\nIf you did not request this, you can ignore this email."
        );
    }

    private function getMailer(): Mailer
    {
        if ($this->mailer === null) {
            $scheme = $this->smtpPort === 465 ? 'smtps' : 'smtp';
            $dsn = "{$scheme}://{$this->smtpUser}:{$this->smtpPass}@{$this->smtpHost}:{$this->smtpPort}";
            $this->mailer = new Mailer(Transport::fromDsn($dsn));
        }

        return $this->mailer;
    }
}
```

- [ ] **Step 5: Create AppServiceProvider**

`app/Providers/AppServiceProvider.php`:

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\FlowService;
use App\Services\ImageService;
use App\Services\MailService;
use App\Services\ProgressService;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\View\TemplateFunctionDefinition;

final class AppServiceProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $container->singleton(FlowService::class, function (Container $c) {
            return new FlowService();
        });

        $container->singleton(ProgressService::class, function () {
            return new ProgressService();
        });

        $container->singleton(ImageService::class, function (Container $c) {
            $app = $c->get(Application::class);
            $basePath = $app->basePath();

            return new ImageService(
                sourcePath: $basePath . '/public/uploads',
                cachePath: $basePath . '/storage/cache/images',
            );
        });

        $container->singleton(MailService::class, function () {
            return new MailService(
                smtpHost: (string) getenv('SMTP_HOST'),
                smtpPort: (int) (getenv('SMTP_PORT') ?: 587),
                smtpUser: (string) getenv('SMTP_USER'),
                smtpPass: (string) getenv('SMTP_PASS'),
                fromName: (string) (getenv('SMTP_FROM_NAME') ?: 'BG Genius'),
                fromEmail: (string) (getenv('SMTP_USER') ?: 'noreply@localhost'),
            );
        });
    }

    public function boot(Container $container): void
    {
        // Register template functions
        $engine = $container->get(\Preflow\View\TemplateEngineInterface::class);
        $imageService = $container->get(ImageService::class);

        $engine->addFunction(new TemplateFunctionDefinition(
            name: 'img_url',
            callable: fn (string $path, string $preset = 'original') => $imageService->url($path, $preset),
            isSafe: true,
        ));
    }
}
```

- [ ] **Step 6: Verify composer autoload and boot**

```bash
cd /Users/smyr/Sites/gbits/bggenius-preflow
composer dump-autoload
php -r "require 'vendor/autoload.php'; echo 'Autoload OK\n';"
```

- [ ] **Step 7: Commit services and provider**

```bash
git add app/Services/ app/Providers/
git commit -m "feat: add services and AppServiceProvider

FlowService, ProgressService, ImageService (League Glide),
MailService (Symfony Mailer), and AppServiceProvider with
img_url() template function."
```

---

## Task 5: Middleware

**Context:** Port AdminAuthMiddleware and AdminRoleMiddleware from the original project, adapted to use Preflow's session and auth systems. CORS middleware only needed if we keep any API endpoints.

**Files:**
- Create: `app/Middleware/AdminAuthMiddleware.php`
- Create: `app/Middleware/AdminRoleMiddleware.php`

- [ ] **Step 1: Create AdminAuthMiddleware**

`app/Middleware/AdminAuthMiddleware.php` — Path-based global middleware. Checks if path starts with `/admin` (excluding `/admin/login`, `/admin/forgot-password`, `/admin/reset-password`). Uses Preflow's `AuthManager` to check session guard. Redirects unauthenticated users to `/admin/login`, returns 401 JSON for HTMX/AJAX requests.

```php
<?php

declare(strict_types=1);

namespace App\Middleware;

use Preflow\Auth\AuthManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AdminAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthManager $auth,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if (!str_starts_with($path, '/admin')) {
            return $handler->handle($request);
        }

        $publicPaths = ['/admin/login', '/admin/forgot-password', '/admin/reset-password'];
        if (in_array($path, $publicPaths, true)) {
            return $handler->handle($request);
        }

        $user = $this->auth->guard('session')->user($request);
        if ($user === null) {
            $isAjax = $request->getHeaderLine('HX-Request') === 'true'
                || $request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest';

            if ($isAjax) {
                $response = new \Nyholm\Psr7\Response(401);
                $response->getBody()->write(json_encode(['error' => 'Unauthorized']));
                return $response->withHeader('Content-Type', 'application/json');
            }

            return new \Nyholm\Psr7\Response(302, ['Location' => '/admin/login']);
        }

        return $handler->handle($request->withAttribute('auth_user', $user));
    }
}
```

- [ ] **Step 2: Create AdminRoleMiddleware**

`app/Middleware/AdminRoleMiddleware.php` — Applied via `#[Middleware]` on specific controller routes. Checks user role is `admin`.

```php
<?php

declare(strict_types=1);

namespace App\Middleware;

use Preflow\Core\Http\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AdminRoleMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute('auth_user');

        if (!$user || $user->role !== 'admin') {
            $session = $request->getAttribute(SessionInterface::class);
            $session?->flash('error', 'You do not have permission to access that page.');
            return new \Nyholm\Psr7\Response(302, ['Location' => '/admin']);
        }

        return $handler->handle($request);
    }
}
```

- [ ] **Step 3: Commit middleware**

```bash
git add app/Middleware/
git commit -m "feat: add admin auth and role middleware

AdminAuthMiddleware (global, path-based /admin/* protection),
AdminRoleMiddleware (per-route, admin-only restriction)."
```

---

## Task 6: Layouts & Shared Components

**Context:** Create the base public and admin Twig layouts, plus shared components (Navigation, Header, Footer, ThemeToggle, FlashMessage). Port CSS from the original project's `variables.css` and `base.css` into co-located styles.

**Files:**
- Create: `templates/_base.twig` (public layout)
- Create: `templates/_admin.twig` (admin layout)
- Create: `app/Components/Shared/Navigation/Navigation.php` + `.twig`
- Create: `app/Components/Shared/Header/Header.php` + `.twig`
- Create: `app/Components/Shared/Footer/Footer.php` + `.twig`
- Create: `app/Components/Shared/ThemeToggle/ThemeToggle.php` + `.twig`
- Create: `app/Components/Shared/FlashMessage/FlashMessage.php` + `.twig`
- Create: `app/Components/Shared/Pagination/Pagination.php` + `.twig`

- [ ] **Step 1: Create _base.twig**

Port from `bggenius/templates/base.twig`. Use `{{ head() }}` and `{{ assets() }}` Preflow functions for CSS/JS injection. Include theme toggle script, FontAwesome CDN, and CSS custom properties from `variables.css` + `base.css` in `{% apply css %}` block.

Key structure:
```twig
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{% block title %}BG Genius{% endblock %}</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://unpkg.com/htmx.org@2.0.4"></script>
    {{ head() }}
</head>
<body>
    {% block body %}{% endblock %}
    {{ assets() }}
</body>
</html>
```

Place ALL the CSS custom properties from `bggenius/public/css/variables.css` and base resets from `base.css` in a `{% apply css %}` block in this layout.

- [ ] **Step 2: Create _admin.twig**

Port from `bggenius/admin/templates/layout.php`. Sidebar navigation with links, unread contact badge, flash messages, user info. Uses `{% apply css %}` for admin-specific styles.

- [ ] **Step 3: Create shared components**

Create each component as a PHP class + Twig template pair. Each component carries its CSS in `{% apply css %}`:

**Navigation** — Renders public nav bar (logo, links with active state detection, theme toggle).
**Header** — Site header with logo and tagline.
**Footer** — Site footer with links and copyright.
**ThemeToggle** — Button that toggles `data-theme` attribute. Co-located JS reads/writes `bggenius_theme` cookie.
**FlashMessage** — Reads session flash messages (`error`, `success`) and renders styled alerts. Self-dismissing.
**Pagination** — Reusable pagination component with page numbers and prev/next, using HTMX actions.

Pattern for each (example: FlashMessage):

`app/Components/Shared/FlashMessage/FlashMessage.php`:
```php
<?php

declare(strict_types=1);

namespace App\Components\Shared\FlashMessage;

use Preflow\Components\Component;
use Preflow\Core\Http\Session\SessionInterface;

final class FlashMessage extends Component
{
    public ?string $error = null;
    public ?string $success = null;

    public function __construct(
        private readonly SessionInterface $session,
    ) {}

    public function resolveState(): void
    {
        $this->error = $this->session->getFlash('error');
        $this->success = $this->session->getFlash('success');
    }
}
```

`app/Components/Shared/FlashMessage/FlashMessage.twig`:
```twig
{% apply css %}
.flash { padding: 0.75rem 1rem; border-radius: 0.5rem; margin-bottom: 1rem; }
.flash--error { background: var(--color-error-bg, #2d1518); color: var(--color-error, #ff6b6b); border: 1px solid var(--color-error, #ff6b6b); }
.flash--success { background: var(--color-success-bg, #152d1a); color: var(--color-success, #64ffda); border: 1px solid var(--color-success, #64ffda); }
{% endapply %}

{% if error %}
<div class="flash flash--error">{{ error }}</div>
{% endif %}
{% if success %}
<div class="flash flash--success">{{ success }}</div>
{% endif %}
```

- [ ] **Step 4: Commit layouts and shared components**

```bash
git add templates/ app/Components/Shared/
git commit -m "feat: add layouts and shared components

Public (_base.twig) and admin (_admin.twig) layouts with ported CSS.
Shared: Navigation, Header, Footer, ThemeToggle, FlashMessage, Pagination."
```

---

## Task 7: Public Pages & Components

**Context:** Create the public-facing pages: game grid landing, about, contact. The GameGrid and ContactForm are Preflow components with HTMX actions.

**Files:**
- Create: `app/pages/index.twig`
- Create: `app/pages/about.twig`
- Create: `app/pages/contact.twig`
- Create: `app/Components/Public/GameGrid/GameGrid.php` + `.twig`
- Create: `app/Components/Public/GameCard/GameCard.php` + `.twig`
- Create: `app/Components/Public/ContactForm/ContactForm.php` + `.twig`
- Create: `app/Components/Public/ThemeToggle/ThemeToggle.php` + `.twig`

- [ ] **Step 1: Create GameGrid component**

Port from `bggenius/templates/index.twig` + `bggenius/public/js/player.js` (game loading). The GameGrid is now server-rendered. `resolveState()` queries published games via DataManager. Enriches with flow stats (layer/step counts). Actions: `filter` (complexity, player count), `search` (text search). Port CSS from `bggenius/public/css/games.css`.

- [ ] **Step 2: Create GameCard component**

Pure display component. Props: game data array. Renders card with cover image (via `img_url()`), title, designer, complexity badge, player count, teach time. Port CSS from `bggenius/public/css/games.css` card styles.

- [ ] **Step 3: Create ContactForm component**

Port from `bggenius/src/Controllers/PageController.php::contactSubmit()`. Actions: `submit`. Validates input, checks honeypot, rate limits by IP, saves to DB, sends email via MailService. Re-renders with success/error state. Port form styles.

- [ ] **Step 4: Create page templates**

`app/pages/index.twig` — extends `_base.twig`, renders Navigation + GameGrid + Footer.
`app/pages/about.twig` — extends `_base.twig`, renders about content.
`app/pages/contact.twig` — extends `_base.twig`, renders Navigation + ContactForm + Footer.

Example `app/pages/index.twig`:
```twig
{% extends "../templates/_base.twig" %}

{% block title %}BG Genius — Learn Board Games Interactively{% endblock %}

{% block body %}
    {{ component('Navigation') }}
    {{ component('Header') }}
    <main>
        {{ component('GameGrid') }}
    </main>
    {{ component('Footer') }}
{% endblock %}
```

- [ ] **Step 5: Verify the landing page loads**

```bash
cd /Users/smyr/Sites/gbits/bggenius-preflow
php -S localhost:8080 -t public/ public/index.php
```

Open `http://localhost:8080` — should render the game grid with data from SQLite.

- [ ] **Step 6: Commit public pages**

```bash
git add app/pages/ app/Components/Public/
git commit -m "feat: add public pages and components

Game grid with search/filter (HTMX), game cards, contact form
with honeypot + rate limiting. About page."
```

---

## Task 8: Player Shell & Navigation Components

**Context:** The core of the migration — PlayerShell orchestrates the tutorial player. It loads the flow, manages progress state, and renders the current step. Navigation happens via HTMX component actions that swap only the step content area.

**Files:**
- Create: `app/pages/play/[slug].twig`
- Create: `app/Components/Player/PlayerShell/PlayerShell.php` + `.twig`
- Create: `app/Components/Player/ProgressBar/ProgressBar.php` + `.twig`
- Create: `app/Components/Player/StepNavigation/StepNavigation.php` + `.twig`
- Create: `app/Components/Player/GlossaryPanel/GlossaryPanel.php` + `.twig`

- [ ] **Step 1: Create PlayerShell component**

This is the most important component. Key design:

```php
<?php

declare(strict_types=1);

namespace App\Components\Player\PlayerShell;

use App\Models\Game;
use App\Models\TeachingFlow;
use App\Models\UserProgress;
use App\Services\FlowService;
use App\Services\ProgressService;
use Preflow\Components\Component;
use Preflow\Data\DataManager;

final class PlayerShell extends Component
{
    // Public properties (template context)
    public ?array $game = null;
    public array $flow = [];
    public array $progress = [];
    public int $currentLayer = 0;
    public int $currentStep = 0;
    public ?array $currentStepData = null;
    public array $glossary = [];
    public bool $isComplete = false;
    public int $totalSteps = 0;
    public int $completedStepCount = 0;
    public ?string $error = null;

    public function __construct(
        private readonly DataManager $data,
        private readonly FlowService $flowService,
        private readonly ProgressService $progressService,
    ) {}

    public function resolveState(): void
    {
        $slug = $this->props['slug'] ?? '';

        // Load game
        $game = $this->data->query(Game::class)
            ->where('slug', $slug)
            ->first();

        if ($game === null) {
            $this->error = 'Game not found.';
            return;
        }

        $this->game = $game->toArray();

        // Load flow
        $flowModel = $this->data->query(TeachingFlow::class)
            ->where('game_id', $game->id)
            ->first();

        $this->flow = $flowModel?->flow_json ?? $this->flowService->loadFromFile($slug) ?? [];

        if (empty($this->flow['layers'])) {
            $this->error = 'No teaching flow available for this game.';
            return;
        }

        $this->glossary = $this->flow['glossary'] ?? [];
        $this->totalSteps = $this->flowService->getTotalStepCount($this->flow);

        // Load/create player progress (from cookie)
        $playerToken = $_COOKIE['bggenius_player'] ?? null;
        if ($playerToken === null) {
            $playerToken = bin2hex(random_bytes(16));
            setcookie('bggenius_player', $playerToken, time() + 86400 * 365, '/');
        }

        $progressModel = $this->data->query(UserProgress::class)
            ->where('player_token', $playerToken)
            ->where('game_id', $game->id)
            ->first();

        if ($progressModel !== null) {
            $this->currentLayer = $progressModel->current_layer;
            $this->currentStep = $progressModel->current_step;
            $this->progress = [
                'completed_steps' => $progressModel->completed_steps,
                'quiz_answers' => $progressModel->quiz_answers,
            ];
        } else {
            $this->progress = ['completed_steps' => [], 'quiz_answers' => []];
        }

        $this->completedStepCount = count($this->progress['completed_steps']);
        $this->currentStepData = $this->flowService->getStep($this->flow, $this->currentLayer, $this->currentStep);
        $this->isComplete = $this->progressService->isFlowComplete(
            $this->progress['completed_steps'],
            $this->flow
        );
    }

    public function actions(): array
    {
        return ['next', 'prev', 'jump', 'answer', 'submitInteractive', 'submitFeedback', 'restart'];
    }

    public function handleAction(string $action, array $params = []): void
    {
        // Each action updates state and saves progress
        // Implementation per action handles navigation, answer validation, etc.
    }
}
```

- [ ] **Step 2: Create PlayerShell template**

`app/Components/Player/PlayerShell/PlayerShell.twig` — Port from `bggenius/templates/player.twig`. Key structure: header, progress bar, step content area (HTMX swap target), step navigation, glossary panel. CSS from `bggenius/public/css/player.css` and `bggenius/public/css/layout.css`.

The template renders sub-components inline:
```twig
{% if error %}
    <div class="player-error">{{ error }}</div>
{% else %}
    <div class="player" id="player-shell">
        {# Header #}
        <header class="player-header">
            <a href="/" class="player-back"><i class="fas fa-arrow-left"></i></a>
            <h1 class="player-title">{{ game.name }}</h1>
            <span class="player-counter">{{ completedStepCount }} / {{ totalSteps }}</span>
        </header>

        {# Progress Bar #}
        {{ component('ProgressBar', { layers: flow.layers, completedSteps: progress.completed_steps }) }}

        {# Step Content - HTMX swap target #}
        <div id="step-content" class="player-content">
            {% if isComplete %}
                {{ component('CompletionScreen', { game: game, progress: progress, flow: flow }) }}
            {% elseif currentStepData %}
                {% include '_step_dispatch.twig' %}
            {% endif %}
        </div>

        {# Navigation #}
        {{ component('StepNavigation', {
            componentClass: componentClass,
            componentId: componentId,
            currentLayer: currentLayer,
            currentStep: currentStep,
            flow: flow,
            isComplete: isComplete
        }) }}

        {# Glossary Panel #}
        {{ component('GlossaryPanel', { terms: glossary }) }}
    </div>
{% endif %}
```

- [ ] **Step 3: Create ProgressBar component**

Port from `bggenius/public/js/components/progress.js`. Pure server-rendered — segments for each layer, filled based on completed steps. CSS from `bggenius/public/css/components/progress.css`.

- [ ] **Step 4: Create StepNavigation component**

Port from `bggenius/templates/player.twig` footer. Prev/next buttons with HTMX actions. Buttons use `hx-post`, `hx-target="#step-content"`, `hx-swap="innerHTML"`. Disable prev on first step, next on completion.

- [ ] **Step 5: Create GlossaryPanel component**

Port from `bggenius/public/js/components/glossary-panel.js`. Server-rendered list of terms. HTMX action `search` filters the list. CSS-only tooltip on term hover in step content.

- [ ] **Step 6: Create player page template**

`app/pages/play/[slug].twig`:
```twig
{% extends "../../templates/_base.twig" %}

{% block title %}{{ route.path.slug | default('Play') }} — BG Genius{% endblock %}

{% block body %}
    {{ component('PlayerShell', { slug: route.path.slug }) }}
{% endblock %}
```

- [ ] **Step 7: Commit player shell**

```bash
git add app/pages/play/ app/Components/Player/PlayerShell/ app/Components/Player/ProgressBar/ app/Components/Player/StepNavigation/ app/Components/Player/GlossaryPanel/
git commit -m "feat: add PlayerShell with navigation and progress

Server-rendered player orchestrator with HTMX step navigation,
progress bar, glossary panel. Core paradigm shift from JS to
server-driven rendering."
```

---

## Task 9: Player Step Components (Server-Rendered)

**Context:** Create the 4 step types that are pure server-rendered HTML (no client JS needed): TextStep, QuizStep, RevealStep, ComparisonStep. Also CompletionScreen.

**Files:**
- Create: `app/Services/MarkdownService.php` (commonmark + GlossaryExtension)
- Create: `app/Components/Player/TextStep/TextStep.php` + `.twig`
- Create: `app/Components/Player/QuizStep/QuizStep.php` + `.twig`
- Create: `app/Components/Player/RevealStep/RevealStep.php` + `.twig`
- Create: `app/Components/Player/ComparisonStep/ComparisonStep.php` + `.twig`
- Create: `app/Components/Player/CompletionScreen/CompletionScreen.php` + `.twig`

- [ ] **Step 1: Create MarkdownService with GlossaryExtension**

`app/Services/MarkdownService.php` — Wraps league/commonmark with a custom inline parser that converts `{{glossary:term_key}}` to `<span class="glossary-term" data-tooltip="definition">Term Name</span>`. Takes the glossary terms array from the flow. Registered as a singleton in AppServiceProvider. Used by TextStep and any other component that renders markdown content.

```php
<?php

declare(strict_types=1);

namespace App\Services;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

final class MarkdownService
{
    public function render(string $markdown, array $glossary = []): string
    {
        // Pre-process: replace {{glossary:key}} with tooltip spans
        $markdown = preg_replace_callback(
            '/\{\{glossary:(\w+)\}\}/',
            function (array $matches) use ($glossary) {
                $key = $matches[1];
                foreach ($glossary as $term) {
                    if (($term['key'] ?? '') === $key) {
                        $name = htmlspecialchars($term['term'] ?? $key);
                        $def = htmlspecialchars($term['definition'] ?? '');
                        return "<span class=\"glossary-term\" data-tooltip=\"{$def}\">{$name}</span>";
                    }
                }
                return $matches[0]; // Unknown key, leave as-is
            },
            $markdown
        );

        $environment = new Environment();
        $environment->addExtension(new CommonMarkCoreExtension());
        $converter = new MarkdownConverter($environment);

        return $converter->convert($markdown)->getContent();
    }
}
```

- [ ] **Step 2: Create TextStep component**

Port from `bggenius/public/js/steps/text.js`. Server-side rendering using MarkdownService. Props: step data (blocks, media, layout, callouts, hint), glossary. Supports layouts: default, media_left, media_right, two_column. Port CSS from `bggenius/public/css/steps/text.css`.

- [ ] **Step 2: Create QuizStep component**

Port from `bggenius/public/js/steps/quiz.js`. Pure server-rendered. Props: step data (question, questionType, options, explanation). Renders radio buttons (MC/TF) or checkboxes (select_many). Before answer: interactive form. After answer (stored in progress.quiz_answers): shows correct/incorrect state per option, feedback text, explanation. No JS needed — HTMX action `answer` on PlayerShell handles submission. Port CSS from `bggenius/public/css/steps/quiz.css`.

- [ ] **Step 3: Create RevealStep component**

Port from `bggenius/public/js/steps/reveal.js`. Uses HTML `<details>/<summary>` — pure server HTML, zero JS. Props: step data (summary, sections). Each section gets a colored accent strip (cycling 6-color palette). Port CSS from `bggenius/public/css/steps/reveal.css`.

- [ ] **Step 4: Create ComparisonStep component**

Port from `bggenius/public/js/steps/comparison.js`. Server-rendered grid. Props: step data (intro, columns with headings and attributes). Highlight support via `attr.highlight` flag. Port CSS from `bggenius/public/css/steps/comparison.css`.

- [ ] **Step 5: Create CompletionScreen component**

Port from `bggenius/public/js/player.js` completion screen section. Shows stats (time, quiz score, steps completed), dice rating (1-6) with HTMX submit, comment form. Port completion CSS.

- [ ] **Step 6: Wire step dispatch in PlayerShell**

Create `templates/_step_dispatch.twig` (or inline in PlayerShell.twig):
```twig
{% if currentStepData.type == 'text' %}
    {{ component('TextStep', { step: currentStepData, glossary: glossary }) }}
{% elseif currentStepData.type == 'quiz' %}
    {{ component('QuizStep', { step: currentStepData, answers: progress.quiz_answers }) }}
{% elseif currentStepData.type == 'reveal' %}
    {{ component('RevealStep', { step: currentStepData }) }}
{% elseif currentStepData.type == 'comparison' %}
    {{ component('ComparisonStep', { step: currentStepData }) }}
{% elseif currentStepData.type == 'interactive' %}
    {{ component('InteractiveStep', { step: currentStepData }) }}
{% elseif currentStepData.type == 'flowchart' %}
    {{ component('FlowchartStep', { step: currentStepData }) }}
{% endif %}
```

- [ ] **Step 7: Test player with a real game**

```bash
cd /Users/smyr/Sites/gbits/bggenius-preflow
php -S localhost:8080 -t public/ public/index.php
```

Open `http://localhost:8080/play/cuvee` (or whatever slug exists in the DB). Verify:
- Page loads with game title and first step
- Next/prev buttons navigate steps via HTMX
- Quiz answer submission works
- Progress persists (refresh page, still on same step)

- [ ] **Step 8: Commit server-rendered step components**

```bash
git add app/Components/Player/
git commit -m "feat: add server-rendered step components

TextStep (commonmark markdown + glossary tooltips),
QuizStep (MC/TF/select-many with HTMX answer submission),
RevealStep (native details/summary accordion),
ComparisonStep (side-by-side grid),
CompletionScreen (stats + dice rating feedback)."
```

---

## Task 10: Player Step Components (JS-Enhanced)

**Context:** InteractiveStep (drag-drop) and FlowchartStep (Mermaid.js) need co-located client JS for functionality that can't be server-rendered.

**Files:**
- Create: `app/Components/Player/InteractiveStep/InteractiveStep.php` + `.twig`
- Create: `app/Components/Player/FlowchartStep/FlowchartStep.php` + `.twig`

- [ ] **Step 1: Create InteractiveStep component**

Port from `bggenius/public/js/steps/interactive.js`. Server renders items in initial order. Co-located `{% apply js %}` block initializes pointer-event drag-drop on the rendered DOM elements. Port the drag logic from `bggenius/public/js/lib/drag.js` into the component's JS block (simplified for this specific use case). Submit button uses HTMX to send the rearranged order to PlayerShell's `submitInteractive` action for server-side validation.

Interaction types: `sort_order` (drag reorder), `click_to_select` (multi-select grid).

Port CSS from `bggenius/public/css/steps/interactive.css`.

- [ ] **Step 2: Create FlowchartStep component**

Port from `bggenius/public/js/steps/flowchart.js`. Server renders Mermaid diagram definition in a `<pre class="mermaid">` block. Co-located `{% apply js %}` block loads Mermaid.js from CDN (if not already loaded) and calls `mermaid.run()` to render the SVG.

The Mermaid definition is generated server-side from the step's `nodes`/`edges` data, converting to Mermaid flowchart syntax:
```
graph TD
    n1["Start Turn"]:::start --> n2["Roll Dice"]:::action
    n2 --> n3{{"Choose Action"}}:::decision
```

Port CSS from `bggenius/public/css/steps/flowchart.css`. Theme-aware Mermaid config via CSS variables.

- [ ] **Step 3: Test interactive and flowchart steps**

Navigate to a game that has interactive and flowchart steps. Verify drag-drop works and Mermaid diagrams render.

- [ ] **Step 4: Commit JS-enhanced step components**

```bash
git add app/Components/Player/InteractiveStep/ app/Components/Player/FlowchartStep/
git commit -m "feat: add JS-enhanced step components

InteractiveStep (co-located drag-drop JS, HTMX answer submit),
FlowchartStep (co-located Mermaid.js initialization)."
```

---

## Task 11: Admin Auth

**Context:** Admin authentication: login form, login/logout controllers, password reset flow. Uses Preflow's SessionGuard.

**Files:**
- Create: `app/Components/Admin/LoginForm/LoginForm.php` + `.twig`
- Create: `app/Controllers/Admin/AuthController.php`
- Create: `app/pages/admin/login.twig` (if using file-based, or controller renders)

Note: Login/logout/forgot/reset are controller routes (Action mode) because they handle POST redirects, not HTMX component actions.

- [ ] **Step 1: Create AuthController**

`app/Controllers/Admin/AuthController.php` — Port from `bggenius/src/Controllers/Admin/AuthController.php`. Adapt to use Preflow's AuthManager, SessionGuard, DataManager, MailService. Methods: `loginForm()`, `login()`, `logout()`, `forgotForm()`, `forgotSubmit()`, `resetForm()`, `resetSubmit()`.

Key pattern (login):
```php
#[Route('/admin')]
final class AuthController
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly DataManager $data,
        private readonly MailService $mail,
    ) {}

    #[Get('/login')]
    public function loginForm(ServerRequestInterface $request): ResponseInterface
    {
        // Render login template
    }

    #[Post('/login')]
    public function login(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        $user = $this->data->query(User::class)
            ->where('username', $username)
            ->first();

        if (!$user || !password_verify($password, $user->password_hash)) {
            $session = $request->getAttribute(SessionInterface::class);
            $session?->flash('error', 'Invalid username or password.');
            return new Response(302, ['Location' => '/admin/login']);
        }

        $this->auth->guard('session')->login($user, $request);

        return new Response(302, ['Location' => '/admin']);
    }
}
```

- [ ] **Step 2: Create LoginForm component**

`app/Components/Admin/LoginForm/LoginForm.php` + `.twig` — Renders login form with CSRF token. Port admin login styles.

- [ ] **Step 3: Create login page template and auth page templates**

`app/pages/admin/login.twig` — Note: this is a special case. Admin login page doesn't go through AdminAuthMiddleware (it's excluded). It extends `_base.twig` (not `_admin.twig`) since user isn't logged in yet.

Similarly create forgot-password and reset-password pages as controller-rendered views (the AuthController renders them directly since they need specific form handling).

- [ ] **Step 4: Test auth flow**

Start dev server. Navigate to `/admin` → redirected to `/admin/login`. Log in with existing credentials. Verify session persists. Log out.

- [ ] **Step 5: Commit admin auth**

```bash
git add app/Controllers/Admin/AuthController.php app/Components/Admin/LoginForm/ app/pages/admin/
git commit -m "feat: add admin authentication

AuthController (login/logout/forgot/reset), LoginForm component,
session-based auth via Preflow's SessionGuard."
```

---

## Task 12: Admin Dashboard & Game Management

**Context:** Dashboard stats, game list with HTMX actions, game create/edit form, flow editor wrapper, file upload controller, flow API controller.

**Files:**
- Create: `app/Components/Admin/Dashboard/Dashboard.php` + `.twig`
- Create: `app/Components/Admin/GameList/GameList.php` + `.twig`
- Create: `app/Components/Admin/GameForm/GameForm.php` + `.twig`
- Create: `app/Components/Admin/FlowEditor/FlowEditor.php` + `.twig`
- Create: `app/Controllers/Admin/FlowApiController.php`
- Create: `app/Controllers/Admin/UploadController.php`
- Create: `app/pages/admin/index.twig`
- Create: `app/pages/admin/games.twig`
- Create: `app/pages/admin/games/create.twig`
- Create: `app/pages/admin/games/[slug].twig`
- Create: `app/pages/admin/games/[slug]/flow.twig`
- Copy: `bggenius/admin/assets/` → `bggenius-preflow/public/js/admin/` (flow editor JS)

- [ ] **Step 1: Create Dashboard component**

Port from `bggenius/src/Controllers/Admin/DashboardController.php` + `bggenius/admin/templates/dashboard.php`. `resolveState()` queries stats: total games, published/draft counts, total steps, recent games. Port dashboard CSS.

- [ ] **Step 2: Create GameList component**

Port from `bggenius/admin/templates/games/index.php`. Table with cover thumbnail, name, designer, complexity, status badge, actions (edit, flow, delete). HTMX actions: `delete` (with confirmation), `filter` (status tabs: all/published/draft). Port game list CSS.

- [ ] **Step 3: Create GameForm component**

Port from `bggenius/admin/templates/games/form.php` + `bggenius/src/Controllers/Admin/GameCrudController.php`. Props: `{slug?}` for edit mode. `resolveState()` loads game if editing. HTMX action `save`: validates, generates slug (if creating), handles cover image path, saves via DataManager. Port form CSS.

- [ ] **Step 4: Create FlowEditor wrapper component**

Port from `bggenius/src/Controllers/Admin/FlowEditorController.php`. Renders admin chrome + game info header. Embeds the existing JS flow editor via a script tag pointing to `public/js/admin/flow-editor.js`. The editor communicates with FlowApiController for save/validate.

- [ ] **Step 5: Copy flow editor JS**

```bash
cp -r /Users/smyr/Sites/gbits/bggenius/admin/assets/js/* /Users/smyr/Sites/gbits/bggenius-preflow/public/js/admin/
cp -r /Users/smyr/Sites/gbits/bggenius/admin/assets/css/* /Users/smyr/Sites/gbits/bggenius-preflow/public/css/admin/ 2>/dev/null || true
```

- [ ] **Step 6: Create FlowApiController**

`app/Controllers/Admin/FlowApiController.php` — Port from `bggenius/src/Controllers/Admin/FlowEditorController.php`. JSON API for the JS editor.

```php
#[Route('/admin/games', middleware: [AdminAuthMiddleware::class])]
final class FlowApiController
{
    #[Put('/{slug}/flow')]
    public function save(ServerRequestInterface $request): ResponseInterface { ... }

    #[Post('/{slug}/flow/validate')]
    public function validate(ServerRequestInterface $request): ResponseInterface { ... }
}
```

- [ ] **Step 7: Create UploadController**

`app/Controllers/Admin/UploadController.php` — Handles file uploads for game cover images and step media.

```php
#[Route('/admin', middleware: [AdminAuthMiddleware::class])]
final class UploadController
{
    #[Post('/upload/{slug}')]
    public function upload(ServerRequestInterface $request): ResponseInterface { ... }
}
```

- [ ] **Step 8: Create admin page templates**

`app/pages/admin/index.twig`:
```twig
{% extends "../../templates/_admin.twig" %}
{% block title %}Dashboard — BG Genius Admin{% endblock %}
{% block content %}
    {{ component('Dashboard') }}
{% endblock %}
```

Same pattern for `games.twig`, `games/create.twig`, `games/[slug].twig`, `games/[slug]/flow.twig`.

- [ ] **Step 9: Test admin dashboard and game CRUD**

Start dev server. Log in as admin. Verify dashboard shows stats. Create a game, edit it, delete it. Open flow editor, verify it loads.

- [ ] **Step 10: Commit admin dashboard and games**

```bash
git add app/Components/Admin/Dashboard/ app/Components/Admin/GameList/ app/Components/Admin/GameForm/ app/Components/Admin/FlowEditor/
git add app/Controllers/Admin/ app/pages/admin/ public/js/admin/ public/css/admin/
git commit -m "feat: add admin dashboard and game management

Dashboard (stats), GameList (HTMX filter/delete), GameForm (create/edit),
FlowEditor (preserved JS editor), FlowApiController, UploadController."
```

---

## Task 13: Admin — Users, Contact, Feedback, Assets, Settings

**Context:** Remaining admin CRUD components. All follow the same pattern as GameList/GameForm.

**Files:**
- Create: `app/Components/Admin/UserList/UserList.php` + `.twig`
- Create: `app/Components/Admin/UserForm/UserForm.php` + `.twig`
- Create: `app/Components/Admin/ContactList/ContactList.php` + `.twig`
- Create: `app/Components/Admin/ContactDetail/ContactDetail.php` + `.twig`
- Create: `app/Components/Admin/FeedbackList/FeedbackList.php` + `.twig`
- Create: `app/Components/Admin/AssetManager/AssetManager.php` + `.twig`
- Create: `app/Components/Admin/Settings/Settings.php` + `.twig`
- Create: `app/pages/admin/users.twig`, `users/create.twig`, `users/[id].twig`
- Create: `app/pages/admin/contact.twig`, `contact/[id].twig`
- Create: `app/pages/admin/feedback.twig`
- Create: `app/pages/admin/assets.twig`
- Create: `app/pages/admin/settings.twig`

- [ ] **Step 1: Create UserList and UserForm components**

Port from `bggenius/src/Controllers/Admin/UserController.php` + `bggenius/admin/templates/users/`. UserList: table of users with role badges, edit/delete actions. UserForm: create/edit with username, email, password, role select. Actions: `save`, `delete`. Prevent deletion of last admin.

- [ ] **Step 2: Create ContactList and ContactDetail components**

Port from `bggenius/src/Controllers/Admin/ContactController.php`. ContactList: filterable (all/unread/spam), marks as read on view. HTMX actions: `markSpam`, `delete`. ContactDetail: shows full message, mark spam/delete buttons.

- [ ] **Step 3: Create FeedbackList component**

Port from `bggenius/src/Controllers/Admin/FeedbackController.php`. Lists feedback grouped by game with average ratings. Filter by game dropdown. Read-only (no actions).

- [ ] **Step 4: Create AssetManager component**

Port from `bggenius/src/Controllers/Admin/AssetController.php`. Grid/list of uploaded images with thumbnails, metadata. HTMX actions: `delete`, `edit` (usage type). Search/filter by game or filename.

- [ ] **Step 5: Create Settings component**

Port from `bggenius/src/Controllers/Admin/SettingsController.php`. Cache management: clear Twig cache, route cache, flow cache, image cache. Shows cache stats.

- [ ] **Step 6: Create page templates for all admin sections**

Each follows the same pattern:
```twig
{% extends "../../templates/_admin.twig" %}
{% block title %}[Section] — BG Genius Admin{% endblock %}
{% block content %}
    {{ component('[ComponentName]') }}
{% endblock %}
```

For detail/edit pages, pass route params:
```twig
{{ component('UserForm', { id: route.path.id }) }}
```

- [ ] **Step 7: Test all admin sections**

Walk through each section: users, contact, feedback, assets, settings. Verify CRUD operations work, flash messages show, role restrictions apply.

- [ ] **Step 8: Commit remaining admin components**

```bash
git add app/Components/Admin/ app/pages/admin/
git commit -m "feat: add remaining admin components

UserList/UserForm, ContactList/ContactDetail, FeedbackList,
AssetManager, Settings. All with HTMX CRUD actions."
```

---

## Task 14: Image Controller

**Context:** Serve resized images via League Glide, matching the original `/img/{preset}/{path}` URL pattern.

**Files:**
- Create: `app/Controllers/ImageController.php`

- [ ] **Step 1: Create ImageController**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ImageService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Preflow\Routing\Attributes\Get;
use Preflow\Routing\Attributes\Route;

#[Route('/img')]
final class ImageController
{
    public function __construct(
        private readonly ImageService $imageService,
    ) {}

    #[Get('/{preset}/{path}')]
    public function serve(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getAttribute('route.params', []);
        $preset = $params['preset'] ?? '';
        $path = $params['path'] ?? '';

        return $this->imageService->serve($preset, $path);
    }
}
```

Note: The `{path}` parameter needs to be a catch-all to support nested paths like `games/cuvee/cover.jpg`. Check if Preflow's attribute router supports catch-all params in attribute routes. If not, handle via a workaround (query param, or custom route).

- [ ] **Step 2: Test image serving**

Navigate to `/img/cover-thumb/games/cuvee/cover.jpg` (adjust path to match existing uploads). Verify resized image is served.

- [ ] **Step 3: Commit**

```bash
git add app/Controllers/ImageController.php
git commit -m "feat: add image controller with League Glide

Serves resized images via presets (cover-thumb, cover-card, etc.)
at /img/{preset}/{path}."
```

---

## Task 15: Smoke Tests

**Context:** Minimal test suite using Preflow's testing package. Verifies the critical paths work.

**Files:**
- Create: `tests/PlayerNavigationTest.php`
- Create: `tests/QuizAnswerTest.php`
- Create: `tests/AdminAuthTest.php`
- Create: `tests/GameCrudTest.php`
- Create: `tests/ProgressPersistenceTest.php`
- Create: `phpunit.xml`

- [ ] **Step 1: Create phpunit.xml**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="vendor/autoload.php" colors="true">
    <testsuites>
        <testsuite name="Smoke Tests">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 2: Create PlayerNavigationTest**

```php
<?php

declare(strict_types=1);

namespace Tests;

use App\Components\Player\PlayerShell\PlayerShell;
use Preflow\Testing\ComponentTestCase;

final class PlayerNavigationTest extends ComponentTestCase
{
    public function testPlayerShellRendersFirstStep(): void
    {
        $html = $this->renderComponent(PlayerShell::class, ['slug' => 'cuvee']);
        $this->assertStringContainsString('player-content', $html);
        $this->assertStringContainsString('step-content', $html);
    }

    public function testNextActionAdvancesStep(): void
    {
        $component = $this->createComponent(PlayerShell::class, ['slug' => 'cuvee']);
        $component->resolveState();
        $initialStep = $component->currentStep;

        $component->handleAction('next');
        $this->assertGreaterThanOrEqual($initialStep, $component->currentStep + $component->currentLayer);
    }
}
```

- [ ] **Step 3: Create QuizAnswerTest**

Test that submitting a correct/incorrect answer updates quiz_answers state and renders feedback.

- [ ] **Step 4: Create AdminAuthTest**

Test login with valid credentials → session set. Test access to `/admin` without auth → redirect. Test logout → session cleared.

- [ ] **Step 5: Create GameCrudTest**

Test creating a game via GameForm save action. Test editing. Test deleting via GameList delete action.

- [ ] **Step 6: Create ProgressPersistenceTest**

Test saving and loading UserProgress via DataManager. Verify JSON fields round-trip correctly.

- [ ] **Step 7: Run tests**

```bash
cd /Users/smyr/Sites/gbits/bggenius-preflow
./vendor/bin/phpunit
```

Expected: All smoke tests pass.

- [ ] **Step 8: Commit tests**

```bash
git add tests/ phpunit.xml
git commit -m "test: add smoke tests for critical paths

PlayerNavigation, QuizAnswer, AdminAuth, GameCrud,
ProgressPersistence — minimal coverage for core workflows."
```

---

## Task 16: Install Script & Final Polish

**Context:** Create the install script for fresh setups, dev serve script, and do final cleanup.

**Files:**
- Create: `bin/install.php`
- Create: `bin/serve`

- [ ] **Step 1: Create install script**

`bin/install.php` — Port from `bggenius/bin/install.php`. Uses Preflow's Application for bootstrapping:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = Preflow\Core\Application::create(__DIR__ . '/..');

$dbPath = __DIR__ . '/../database/bggenius.sqlite';
$schemaPath = __DIR__ . '/../database/schema.sql';

// Create database directory
$dir = dirname($dbPath);
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

// Execute schema
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA journal_mode=WAL');
$pdo->exec('PRAGMA foreign_keys=ON');

$schema = file_get_contents($schemaPath);
$pdo->exec($schema);

// Create default admin user
$username = getenv('ADMIN_USER') ?: 'admin';
$password = getenv('ADMIN_PASS') ?: 'admin';
$email = getenv('ADMIN_EMAIL') ?: 'admin@example.com';

$stmt = $pdo->prepare(
    "INSERT OR IGNORE INTO admin_users (username, email, password_hash, role)
     VALUES (?, ?, ?, 'admin')"
);
$stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT)]);

echo "Database initialized.\n";
echo "Admin user: {$username}\n";
```

- [ ] **Step 2: Create serve script**

`bin/serve`:
```bash
#!/bin/bash
php -S localhost:8080 -t public/ public/index.php
```

```bash
chmod +x bin/serve
```

- [ ] **Step 3: Final verification — full walkthrough**

```bash
cd /Users/smyr/Sites/gbits/bggenius-preflow
bin/serve
```

Walk through:
1. `http://localhost:8080/` — game grid loads, search/filter works
2. Click a game → player loads, navigate through steps
3. Answer a quiz, verify feedback renders
4. Complete the flow, verify completion screen
5. `http://localhost:8080/admin/login` → log in
6. Dashboard shows stats
7. Create/edit/delete a game
8. Open flow editor
9. Check users, contact, feedback, assets, settings sections
10. Log out

- [ ] **Step 4: Commit install script and final cleanup**

```bash
git add bin/ 
git commit -m "chore: add install script and dev serve helper

bin/install.php for fresh database setup,
bin/serve for local development."
```

- [ ] **Step 5: Final commit — complete migration**

```bash
git add -A
git commit -m "chore: BGGenius Preflow migration complete

Full migration from Slim 4 to Preflow. JS-driven player converted
to server-rendered HTMX components. Admin panel rebuilt as Preflow
components. Flow editor preserved as standalone JS app."
```
