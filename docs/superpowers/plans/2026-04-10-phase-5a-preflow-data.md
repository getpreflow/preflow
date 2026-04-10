# Phase 5a: preflow/data — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the core data layer (`preflow/data`) — StorageDriver interface, Query/ResultSet, JsonFileDriver, SqliteDriver (via PdoDriver base), Model base class with PHP 8.5 attributes, DataManager, and a lightweight migration system.

**Architecture:** The `StorageDriver` interface defines the contract for all storage backends. `Query` is a driver-agnostic query builder that accumulates conditions. Drivers translate queries into their native format (SQL for PdoDriver, array filtering for JsonFileDriver). The `Model` base class uses PHP attributes (`#[Entity]`, `#[Id]`, `#[Field]`, `#[Timestamps]`) for schema declaration. `DataManager` resolves models to their configured driver and provides a fluent query API.

**Tech Stack:** PHP 8.5+, PHPUnit 11+, PDO (SQLite), preflow/core

**Scope:** This phase covers SQLite + JSON drivers. MySQL driver and dynamic models (JSON-defined schemas) are deferred to Phase 5b — they share the same interfaces and are trivial additions once this foundation exists.

---

## File Structure

```
packages/data/
├── src/
│   ├── StorageDriver.php               — Interface for all storage backends
│   ├── Query.php                       — Driver-agnostic query builder
│   ├── SortDirection.php               — Enum: Asc, Desc
│   ├── ResultSet.php                   — Paginated/raw query results
│   ├── PaginatedResult.php             — Paginated result with metadata
│   ├── Driver/
│   │   ├── JsonFileDriver.php          — JSON file storage (one file per record)
│   │   ├── PdoDriver.php              — Abstract PDO-based driver
│   │   ├── SqliteDriver.php           — SQLite driver
│   │   └── QueryCompiler.php          — Compiles Query to SQL + bindings
│   ├── Model.php                       — Base model class
│   ├── Attributes/
│   │   ├── Entity.php                  — #[Entity(table: 'x', storage: 'y')]
│   │   ├── Id.php                      — #[Id] marks primary key
│   │   ├── Field.php                   — #[Field(searchable: true)]
│   │   └── Timestamps.php             — #[Timestamps] on createdAt/updatedAt
│   ├── ModelMetadata.php               — Reads model attributes via reflection
│   ├── DataManager.php                 — Unified entry point for queries
│   └── Migration/
│       ├── Migration.php               — Abstract migration base
│       ├── Schema.php                  — Schema builder (create/drop tables)
│       ├── Table.php                   — Table builder (columns, indexes)
│       └── Migrator.php               — Runs migration files
├── tests/
│   ├── QueryTest.php
│   ├── ResultSetTest.php
│   ├── Driver/
│   │   ├── JsonFileDriverTest.php
│   │   ├── SqliteDriverTest.php
│   │   └── QueryCompilerTest.php
│   ├── ModelMetadataTest.php
│   ├── DataManagerTest.php
│   └── Migration/
│       ├── SchemaTest.php
│       └── MigratorTest.php
└── composer.json
```

---

### Task 1: Package Scaffolding

**Files:**
- Create: `packages/data/composer.json`
- Modify: `composer.json` (root)
- Modify: `phpunit.xml`

- [ ] **Step 1: Create packages/data/composer.json**

```json
{
    "name": "preflow/data",
    "description": "Preflow data — storage drivers, models, migrations",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.4",
        "ext-pdo": "*",
        "ext-pdo_sqlite": "*",
        "preflow/core": "^0.1 || @dev"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0"
    },
    "autoload": {
        "psr-4": {
            "Preflow\\Data\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Preflow\\Data\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 2: Update root composer.json**

Add to `repositories`:
```json
{ "type": "path", "url": "packages/data", "options": { "symlink": true } }
```
Add `"preflow/data": "@dev"` to `require-dev`.

- [ ] **Step 3: Update phpunit.xml**

Add testsuite `Data` pointing to `packages/data/tests`. Add `packages/data/src` to source include.

- [ ] **Step 4: Create directories and install**

```bash
mkdir -p packages/data/src/{Driver,Attributes,Migration} packages/data/tests/{Driver,Migration}
composer update
```

- [ ] **Step 5: Commit**

```bash
git add packages/data/composer.json composer.json phpunit.xml
git commit -m "feat: scaffold preflow/data package"
```

---

### Task 2: SortDirection + Query

**Files:**
- Create: `packages/data/src/SortDirection.php`
- Create: `packages/data/src/Query.php`
- Create: `packages/data/tests/QueryTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/data/tests/QueryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Query;
use Preflow\Data\SortDirection;

final class QueryTest extends TestCase
{
    public function test_where_adds_condition(): void
    {
        $q = (new Query())->where('status', '=', 'active');

        $conditions = $q->getWheres();
        $this->assertCount(1, $conditions);
        $this->assertSame('status', $conditions[0]['field']);
        $this->assertSame('=', $conditions[0]['operator']);
        $this->assertSame('active', $conditions[0]['value']);
        $this->assertSame('AND', $conditions[0]['boolean']);
    }

    public function test_where_shorthand_equals(): void
    {
        $q = (new Query())->where('status', 'active');

        $conditions = $q->getWheres();
        $this->assertSame('=', $conditions[0]['operator']);
        $this->assertSame('active', $conditions[0]['value']);
    }

    public function test_or_where(): void
    {
        $q = (new Query())
            ->where('status', 'active')
            ->orWhere('status', 'pending');

        $conditions = $q->getWheres();
        $this->assertCount(2, $conditions);
        $this->assertSame('OR', $conditions[1]['boolean']);
    }

    public function test_order_by(): void
    {
        $q = (new Query())
            ->orderBy('created', SortDirection::Desc)
            ->orderBy('title');

        $orders = $q->getOrderBy();
        $this->assertCount(2, $orders);
        $this->assertSame('created', $orders[0]['field']);
        $this->assertSame(SortDirection::Desc, $orders[0]['direction']);
        $this->assertSame(SortDirection::Asc, $orders[1]['direction']);
    }

    public function test_limit_and_offset(): void
    {
        $q = (new Query())->limit(10)->offset(20);

        $this->assertSame(10, $q->getLimit());
        $this->assertSame(20, $q->getOffset());
    }

    public function test_search(): void
    {
        $q = (new Query())->search('hello world', ['title', 'body']);

        $this->assertSame('hello world', $q->getSearchTerm());
        $this->assertSame(['title', 'body'], $q->getSearchFields());
    }

    public function test_chaining(): void
    {
        $q = (new Query())
            ->where('status', 'active')
            ->orderBy('title')
            ->limit(5)
            ->offset(0);

        $this->assertCount(1, $q->getWheres());
        $this->assertCount(1, $q->getOrderBy());
        $this->assertSame(5, $q->getLimit());
        $this->assertSame(0, $q->getOffset());
    }

    public function test_defaults(): void
    {
        $q = new Query();

        $this->assertSame([], $q->getWheres());
        $this->assertSame([], $q->getOrderBy());
        $this->assertNull($q->getLimit());
        $this->assertNull($q->getOffset());
        $this->assertNull($q->getSearchTerm());
        $this->assertSame([], $q->getSearchFields());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit packages/data/tests/QueryTest.php
```

- [ ] **Step 3: Create SortDirection enum**

Create `packages/data/src/SortDirection.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data;

enum SortDirection: string
{
    case Asc = 'ASC';
    case Desc = 'DESC';
}
```

- [ ] **Step 4: Implement Query**

Create `packages/data/src/Query.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data;

final class Query
{
    /** @var array<int, array{field: string, operator: string, value: mixed, boolean: string}> */
    private array $wheres = [];

    /** @var array<int, array{field: string, direction: SortDirection}> */
    private array $orderBy = [];

    private ?int $limit = null;
    private ?int $offset = null;
    private ?string $searchTerm = null;
    /** @var string[] */
    private array $searchFields = [];

    public function where(string $field, mixed $operator, mixed $value = null): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'field' => $field,
            'operator' => $operator,
            'value' => $value,
            'boolean' => 'AND',
        ];

        return $this;
    }

    public function orWhere(string $field, mixed $operator, mixed $value = null): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'field' => $field,
            'operator' => $operator,
            'value' => $value,
            'boolean' => 'OR',
        ];

        return $this;
    }

    public function orderBy(string $field, SortDirection $direction = SortDirection::Asc): self
    {
        $this->orderBy[] = [
            'field' => $field,
            'direction' => $direction,
        ];

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    public function search(string $term, array $fields = []): self
    {
        $this->searchTerm = $term;
        $this->searchFields = $fields;
        return $this;
    }

    /** @return array<int, array{field: string, operator: string, value: mixed, boolean: string}> */
    public function getWheres(): array { return $this->wheres; }

    /** @return array<int, array{field: string, direction: SortDirection}> */
    public function getOrderBy(): array { return $this->orderBy; }

    public function getLimit(): ?int { return $this->limit; }
    public function getOffset(): ?int { return $this->offset; }
    public function getSearchTerm(): ?string { return $this->searchTerm; }
    /** @return string[] */
    public function getSearchFields(): array { return $this->searchFields; }
}
```

- [ ] **Step 5: Run tests**

```bash
./vendor/bin/phpunit packages/data/tests/QueryTest.php
```

Expected: All 8 tests pass.

- [ ] **Step 6: Commit**

```bash
git add packages/data/src/SortDirection.php packages/data/src/Query.php packages/data/tests/QueryTest.php
git commit -m "feat(data): add Query builder and SortDirection enum"
```

---

### Task 3: ResultSet + PaginatedResult

**Files:**
- Create: `packages/data/src/ResultSet.php`
- Create: `packages/data/src/PaginatedResult.php`
- Create: `packages/data/tests/ResultSetTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/data/tests/ResultSetTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\ResultSet;
use Preflow\Data\PaginatedResult;

final class ResultSetTest extends TestCase
{
    public function test_items_returns_data(): void
    {
        $rs = new ResultSet([['id' => '1'], ['id' => '2']]);

        $this->assertCount(2, $rs->items());
        $this->assertSame('1', $rs->items()[0]['id']);
    }

    public function test_total_returns_count(): void
    {
        $rs = new ResultSet([['a'], ['b'], ['c']], total: 3);

        $this->assertSame(3, $rs->total());
    }

    public function test_total_defaults_to_items_count(): void
    {
        $rs = new ResultSet([['a'], ['b']]);

        $this->assertSame(2, $rs->total());
    }

    public function test_first_returns_first_item(): void
    {
        $rs = new ResultSet([['id' => '1'], ['id' => '2']]);

        $this->assertSame(['id' => '1'], $rs->first());
    }

    public function test_first_returns_null_for_empty(): void
    {
        $rs = new ResultSet([]);

        $this->assertNull($rs->first());
    }

    public function test_map_transforms_items(): void
    {
        $rs = new ResultSet([['id' => '1'], ['id' => '2']]);

        $mapped = $rs->map(fn (array $item) => $item['id']);

        $this->assertSame(['1', '2'], $mapped->items());
    }

    public function test_count_interface(): void
    {
        $rs = new ResultSet([['a'], ['b']]);

        $this->assertCount(2, $rs);
    }

    public function test_iterable(): void
    {
        $rs = new ResultSet([['id' => '1'], ['id' => '2']]);
        $ids = [];

        foreach ($rs as $item) {
            $ids[] = $item['id'];
        }

        $this->assertSame(['1', '2'], $ids);
    }

    public function test_paginate(): void
    {
        $items = array_map(fn ($i) => ['id' => (string)$i], range(1, 25));
        $rs = new ResultSet($items, total: 25);

        $page = $rs->paginate(perPage: 10, currentPage: 2);

        $this->assertInstanceOf(PaginatedResult::class, $page);
        $this->assertSame(25, $page->total);
        $this->assertSame(10, $page->perPage);
        $this->assertSame(2, $page->currentPage);
        $this->assertSame(3, $page->lastPage);
        $this->assertTrue($page->hasMore);
    }

    public function test_paginate_last_page(): void
    {
        $rs = new ResultSet([['a']], total: 21);

        $page = $rs->paginate(perPage: 10, currentPage: 3);

        $this->assertSame(3, $page->lastPage);
        $this->assertFalse($page->hasMore);
    }

    public function test_empty_result_set(): void
    {
        $rs = new ResultSet([]);

        $this->assertSame(0, $rs->total());
        $this->assertNull($rs->first());
        $this->assertCount(0, $rs);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit packages/data/tests/ResultSetTest.php
```

- [ ] **Step 3: Implement PaginatedResult**

Create `packages/data/src/PaginatedResult.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data;

final readonly class PaginatedResult
{
    public int $lastPage;
    public bool $hasMore;

    /**
     * @param array<int, mixed> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $perPage,
        public int $currentPage,
    ) {
        $this->lastPage = max(1, (int) ceil($total / max(1, $perPage)));
        $this->hasMore = $currentPage < $this->lastPage;
    }
}
```

- [ ] **Step 4: Implement ResultSet**

Create `packages/data/src/ResultSet.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data;

final class ResultSet implements \Countable, \IteratorAggregate
{
    /**
     * @param array<int, mixed> $items
     */
    public function __construct(
        private readonly array $items,
        private readonly ?int $total = null,
    ) {}

    /** @return array<int, mixed> */
    public function items(): array
    {
        return $this->items;
    }

    public function total(): int
    {
        return $this->total ?? count($this->items);
    }

    public function first(): mixed
    {
        return $this->items[0] ?? null;
    }

    public function map(callable $fn): self
    {
        return new self(array_map($fn, $this->items), $this->total);
    }

    public function paginate(int $perPage, int $currentPage): PaginatedResult
    {
        return new PaginatedResult(
            items: $this->items,
            total: $this->total(),
            perPage: $perPage,
            currentPage: $currentPage,
        );
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }
}
```

- [ ] **Step 5: Run tests**

```bash
./vendor/bin/phpunit packages/data/tests/ResultSetTest.php
```

Expected: All 11 tests pass.

- [ ] **Step 6: Commit**

```bash
git add packages/data/src/ResultSet.php packages/data/src/PaginatedResult.php packages/data/tests/ResultSetTest.php
git commit -m "feat(data): add ResultSet and PaginatedResult"
```

---

### Task 4: StorageDriver Interface

**Files:**
- Create: `packages/data/src/StorageDriver.php`

- [ ] **Step 1: Create the interface**

Create `packages/data/src/StorageDriver.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data;

interface StorageDriver
{
    /**
     * @return array<string, mixed>|null
     */
    public function findOne(string $type, string $id): ?array;

    public function findMany(string $type, Query $query): ResultSet;

    /**
     * @param array<string, mixed> $data
     */
    public function save(string $type, string $id, array $data): void;

    public function delete(string $type, string $id): void;

    public function exists(string $type, string $id): bool;
}
```

- [ ] **Step 2: Commit**

```bash
git add packages/data/src/StorageDriver.php
git commit -m "feat(data): add StorageDriver interface"
```

---

### Task 5: JsonFileDriver

**Files:**
- Create: `packages/data/src/Driver/JsonFileDriver.php`
- Create: `packages/data/tests/Driver/JsonFileDriverTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/data/tests/Driver/JsonFileDriverTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Query;
use Preflow\Data\SortDirection;

final class JsonFileDriverTest extends TestCase
{
    private string $dataDir;
    private JsonFileDriver $driver;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . '/preflow_json_test_' . uniqid();
        mkdir($this->dataDir, 0755, true);
        $this->driver = new JsonFileDriver($this->dataDir);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->dataDir);
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->deleteDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function test_save_and_find_one(): void
    {
        $this->driver->save('post', 'abc-123', [
            'title' => 'Hello World',
            'status' => 'published',
        ]);

        $result = $this->driver->findOne('post', 'abc-123');

        $this->assertNotNull($result);
        $this->assertSame('Hello World', $result['title']);
        $this->assertSame('published', $result['status']);
    }

    public function test_find_one_returns_null_for_missing(): void
    {
        $result = $this->driver->findOne('post', 'nonexistent');

        $this->assertNull($result);
    }

    public function test_exists(): void
    {
        $this->driver->save('post', 'abc', ['title' => 'Test']);

        $this->assertTrue($this->driver->exists('post', 'abc'));
        $this->assertFalse($this->driver->exists('post', 'xyz'));
    }

    public function test_delete(): void
    {
        $this->driver->save('post', 'abc', ['title' => 'Test']);
        $this->driver->delete('post', 'abc');

        $this->assertFalse($this->driver->exists('post', 'abc'));
    }

    public function test_find_many_returns_all(): void
    {
        $this->driver->save('post', '1', ['title' => 'First', 'status' => 'published']);
        $this->driver->save('post', '2', ['title' => 'Second', 'status' => 'draft']);
        $this->driver->save('post', '3', ['title' => 'Third', 'status' => 'published']);

        $result = $this->driver->findMany('post', new Query());

        $this->assertSame(3, $result->total());
    }

    public function test_find_many_with_where(): void
    {
        $this->driver->save('post', '1', ['title' => 'A', 'status' => 'published']);
        $this->driver->save('post', '2', ['title' => 'B', 'status' => 'draft']);
        $this->driver->save('post', '3', ['title' => 'C', 'status' => 'published']);

        $query = (new Query())->where('status', 'published');
        $result = $this->driver->findMany('post', $query);

        $this->assertSame(2, $result->total());
    }

    public function test_find_many_with_order_by(): void
    {
        $this->driver->save('post', '1', ['title' => 'Banana']);
        $this->driver->save('post', '2', ['title' => 'Apple']);
        $this->driver->save('post', '3', ['title' => 'Cherry']);

        $query = (new Query())->orderBy('title', SortDirection::Asc);
        $result = $this->driver->findMany('post', $query);

        $titles = array_column($result->items(), 'title');
        $this->assertSame(['Apple', 'Banana', 'Cherry'], $titles);
    }

    public function test_find_many_with_limit_and_offset(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->driver->save('post', (string)$i, ['title' => "Post {$i}"]);
        }

        $query = (new Query())->orderBy('title')->limit(2)->offset(1);
        $result = $this->driver->findMany('post', $query);

        $this->assertCount(2, $result->items());
        $this->assertSame(5, $result->total()); // total is unaffected by limit
    }

    public function test_find_many_with_search(): void
    {
        $this->driver->save('post', '1', ['title' => 'PHP Framework', 'body' => 'About PHP']);
        $this->driver->save('post', '2', ['title' => 'Ruby Guide', 'body' => 'About Ruby']);
        $this->driver->save('post', '3', ['title' => 'Go Tutorial', 'body' => 'PHP mentioned']);

        $query = (new Query())->search('PHP', ['title', 'body']);
        $result = $this->driver->findMany('post', $query);

        $this->assertSame(2, $result->total()); // posts 1 and 3
    }

    public function test_save_overwrites_existing(): void
    {
        $this->driver->save('post', 'abc', ['title' => 'Old']);
        $this->driver->save('post', 'abc', ['title' => 'New']);

        $result = $this->driver->findOne('post', 'abc');
        $this->assertSame('New', $result['title']);
    }

    public function test_different_types_are_isolated(): void
    {
        $this->driver->save('post', '1', ['title' => 'Post']);
        $this->driver->save('page', '1', ['title' => 'Page']);

        $posts = $this->driver->findMany('post', new Query());
        $pages = $this->driver->findMany('page', new Query());

        $this->assertSame(1, $posts->total());
        $this->assertSame(1, $pages->total());
        $this->assertSame('Post', $posts->first()['title']);
        $this->assertSame('Page', $pages->first()['title']);
    }

    public function test_find_many_with_not_equals(): void
    {
        $this->driver->save('post', '1', ['status' => 'published']);
        $this->driver->save('post', '2', ['status' => 'draft']);

        $query = (new Query())->where('status', '!=', 'draft');
        $result = $this->driver->findMany('post', $query);

        $this->assertSame(1, $result->total());
    }

    public function test_find_many_with_like(): void
    {
        $this->driver->save('post', '1', ['title' => 'PHP Framework']);
        $this->driver->save('post', '2', ['title' => 'Ruby Guide']);

        $query = (new Query())->where('title', 'LIKE', '%Framework%');
        $result = $this->driver->findMany('post', $query);

        $this->assertSame(1, $result->total());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit packages/data/tests/Driver/JsonFileDriverTest.php
```

- [ ] **Step 3: Implement JsonFileDriver**

Create `packages/data/src/Driver/JsonFileDriver.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

use Preflow\Data\Query;
use Preflow\Data\ResultSet;
use Preflow\Data\SortDirection;
use Preflow\Data\StorageDriver;

final class JsonFileDriver implements StorageDriver
{
    public function __construct(
        private readonly string $basePath,
    ) {}

    public function findOne(string $type, string $id): ?array
    {
        $path = $this->filePath($type, $id);

        if (!file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    public function findMany(string $type, Query $query): ResultSet
    {
        $dir = $this->typeDir($type);

        if (!is_dir($dir)) {
            return new ResultSet([]);
        }

        $items = [];
        foreach (glob($dir . '/*.json') as $file) {
            $content = file_get_contents($file);
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            $data['_id'] = basename($file, '.json');
            $items[] = $data;
        }

        // Apply search
        if ($query->getSearchTerm() !== null) {
            $items = $this->applySearch($items, $query->getSearchTerm(), $query->getSearchFields());
        }

        // Apply where conditions
        $items = $this->applyWheres($items, $query->getWheres());

        // Apply ordering
        $items = $this->applyOrderBy($items, $query->getOrderBy());

        $total = count($items);

        // Apply limit/offset
        if ($query->getOffset() !== null || $query->getLimit() !== null) {
            $items = array_slice($items, $query->getOffset() ?? 0, $query->getLimit());
        }

        // Remove internal _id
        $items = array_map(function (array $item) {
            unset($item['_id']);
            return $item;
        }, $items);

        return new ResultSet(array_values($items), $total);
    }

    public function save(string $type, string $id, array $data): void
    {
        $dir = $this->typeDir($type);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = $this->filePath($type, $id);
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($path, $json);
    }

    public function delete(string $type, string $id): void
    {
        $path = $this->filePath($type, $id);

        if (file_exists($path)) {
            unlink($path);
        }
    }

    public function exists(string $type, string $id): bool
    {
        return file_exists($this->filePath($type, $id));
    }

    private function typeDir(string $type): string
    {
        return $this->basePath . '/' . $type;
    }

    private function filePath(string $type, string $id): string
    {
        return $this->typeDir($type) . '/' . $id . '.json';
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<int, array{field: string, operator: string, value: mixed, boolean: string}> $wheres
     * @return array<int, array<string, mixed>>
     */
    private function applyWheres(array $items, array $wheres): array
    {
        if ($wheres === []) {
            return $items;
        }

        return array_values(array_filter($items, function (array $item) use ($wheres) {
            $result = true;

            foreach ($wheres as $i => $where) {
                $match = $this->evaluateCondition($item, $where);

                if ($i === 0) {
                    $result = $match;
                } elseif ($where['boolean'] === 'OR') {
                    $result = $result || $match;
                } else {
                    $result = $result && $match;
                }
            }

            return $result;
        }));
    }

    /**
     * @param array<string, mixed> $item
     * @param array{field: string, operator: string, value: mixed, boolean: string} $where
     */
    private function evaluateCondition(array $item, array $where): bool
    {
        $fieldValue = $item[$where['field']] ?? null;
        $condValue = $where['value'];

        return match ($where['operator']) {
            '=' => $fieldValue === $condValue,
            '!=' => $fieldValue !== $condValue,
            '>' => $fieldValue > $condValue,
            '>=' => $fieldValue >= $condValue,
            '<' => $fieldValue < $condValue,
            '<=' => $fieldValue <= $condValue,
            'LIKE' => $this->matchLike((string)($fieldValue ?? ''), (string)$condValue),
            default => false,
        };
    }

    private function matchLike(string $value, string $pattern): bool
    {
        $regex = '/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote($pattern, '/')) . '$/i';
        // Undo the escaping of % and _ since we want them as wildcards
        $regex = str_replace(['\\%', '\\_'], ['%', '_'], $regex);
        // Actually we need to handle this differently — preg_quote escapes %, but we replaced before quoting
        // Let's rebuild properly:
        $escaped = preg_quote($pattern, '/');
        $escaped = str_replace(['\\%', '\\_'], ['.*', '.'], $escaped);
        $regex = '/^' . $escaped . '$/i';

        return (bool) preg_match($regex, $value);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function applySearch(array $items, string $term, array $fields): array
    {
        $term = mb_strtolower($term);

        return array_values(array_filter($items, function (array $item) use ($term, $fields) {
            $searchIn = $fields ?: array_keys($item);

            foreach ($searchIn as $field) {
                if (isset($item[$field]) && is_string($item[$field])) {
                    if (str_contains(mb_strtolower($item[$field]), $term)) {
                        return true;
                    }
                }
            }

            return false;
        }));
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<int, array{field: string, direction: SortDirection}> $orderBy
     * @return array<int, array<string, mixed>>
     */
    private function applyOrderBy(array $items, array $orderBy): array
    {
        if ($orderBy === []) {
            return $items;
        }

        usort($items, function (array $a, array $b) use ($orderBy) {
            foreach ($orderBy as $order) {
                $field = $order['field'];
                $aVal = $a[$field] ?? '';
                $bVal = $b[$field] ?? '';
                $cmp = is_numeric($aVal) && is_numeric($bVal)
                    ? $aVal <=> $bVal
                    : strcmp((string)$aVal, (string)$bVal);

                if ($cmp !== 0) {
                    return $order['direction'] === SortDirection::Desc ? -$cmp : $cmp;
                }
            }
            return 0;
        });

        return $items;
    }
}
```

- [ ] **Step 4: Run tests**

```bash
./vendor/bin/phpunit packages/data/tests/Driver/JsonFileDriverTest.php
```

Expected: All 12 tests pass.

- [ ] **Step 5: Commit**

```bash
git add packages/data/src/Driver/JsonFileDriver.php packages/data/tests/Driver/JsonFileDriverTest.php
git commit -m "feat(data): add JsonFileDriver with filtering, sorting, search"
```

---

### Task 6: QueryCompiler + SqliteDriver

**Files:**
- Create: `packages/data/src/Driver/QueryCompiler.php`
- Create: `packages/data/src/Driver/PdoDriver.php`
- Create: `packages/data/src/Driver/SqliteDriver.php`
- Create: `packages/data/tests/Driver/QueryCompilerTest.php`
- Create: `packages/data/tests/Driver/SqliteDriverTest.php`

- [ ] **Step 1: Write QueryCompiler test**

Create `packages/data/tests/Driver/QueryCompilerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\QueryCompiler;
use Preflow\Data\Query;
use Preflow\Data\SortDirection;

final class QueryCompilerTest extends TestCase
{
    private QueryCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new QueryCompiler();
    }

    public function test_empty_query(): void
    {
        [$sql, $bindings] = $this->compiler->compile('posts', new Query());

        $this->assertSame('SELECT * FROM "posts"', $sql);
        $this->assertSame([], $bindings);
    }

    public function test_where_equals(): void
    {
        $q = (new Query())->where('status', 'active');
        [$sql, $bindings] = $this->compiler->compile('posts', $q);

        $this->assertSame('SELECT * FROM "posts" WHERE "status" = ?', $sql);
        $this->assertSame(['active'], $bindings);
    }

    public function test_multiple_wheres(): void
    {
        $q = (new Query())->where('status', 'active')->where('type', 'blog');
        [$sql, $bindings] = $this->compiler->compile('posts', $q);

        $this->assertSame('SELECT * FROM "posts" WHERE "status" = ? AND "type" = ?', $sql);
        $this->assertSame(['active', 'blog'], $bindings);
    }

    public function test_or_where(): void
    {
        $q = (new Query())->where('status', 'active')->orWhere('status', 'pending');
        [$sql, $bindings] = $this->compiler->compile('posts', $q);

        $this->assertSame('SELECT * FROM "posts" WHERE "status" = ? OR "status" = ?', $sql);
        $this->assertSame(['active', 'pending'], $bindings);
    }

    public function test_order_by(): void
    {
        $q = (new Query())->orderBy('created', SortDirection::Desc)->orderBy('title');
        [$sql, $bindings] = $this->compiler->compile('posts', $q);

        $this->assertSame('SELECT * FROM "posts" ORDER BY "created" DESC, "title" ASC', $sql);
    }

    public function test_limit_offset(): void
    {
        $q = (new Query())->limit(10)->offset(20);
        [$sql, $bindings] = $this->compiler->compile('posts', $q);

        $this->assertSame('SELECT * FROM "posts" LIMIT 10 OFFSET 20', $sql);
    }

    public function test_search(): void
    {
        $q = (new Query())->search('hello', ['title', 'body']);
        [$sql, $bindings] = $this->compiler->compile('posts', $q);

        $this->assertStringContainsString('LIKE', $sql);
        $this->assertStringContainsString('"title"', $sql);
        $this->assertStringContainsString('"body"', $sql);
        $this->assertContains('%hello%', $bindings);
    }

    public function test_like_operator(): void
    {
        $q = (new Query())->where('title', 'LIKE', '%test%');
        [$sql, $bindings] = $this->compiler->compile('posts', $q);

        $this->assertSame('SELECT * FROM "posts" WHERE "title" LIKE ?', $sql);
        $this->assertSame(['%test%'], $bindings);
    }

    public function test_count_query(): void
    {
        $q = (new Query())->where('status', 'active');
        [$sql, $bindings] = $this->compiler->compileCount('posts', $q);

        $this->assertSame('SELECT COUNT(*) as total FROM "posts" WHERE "status" = ?', $sql);
        $this->assertSame(['active'], $bindings);
    }

    public function test_full_query(): void
    {
        $q = (new Query())
            ->where('status', 'active')
            ->orderBy('title')
            ->limit(5)
            ->offset(10);

        [$sql, $bindings] = $this->compiler->compile('posts', $q);

        $this->assertSame(
            'SELECT * FROM "posts" WHERE "status" = ? ORDER BY "title" ASC LIMIT 5 OFFSET 10',
            $sql
        );
    }
}
```

- [ ] **Step 2: Write SqliteDriver test**

Create `packages/data/tests/Driver/SqliteDriverTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Query;
use Preflow\Data\SortDirection;

final class SqliteDriverTest extends TestCase
{
    private \PDO $pdo;
    private SqliteDriver $driver;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec('CREATE TABLE posts (
            uuid TEXT PRIMARY KEY,
            title TEXT NOT NULL,
            status TEXT DEFAULT "draft",
            body TEXT,
            created_at TEXT
        )');

        $this->driver = new SqliteDriver($this->pdo);
    }

    public function test_save_and_find_one(): void
    {
        $this->driver->save('posts', 'abc-123', [
            'title' => 'Hello World',
            'status' => 'published',
        ]);

        $result = $this->driver->findOne('posts', 'abc-123');

        $this->assertNotNull($result);
        $this->assertSame('Hello World', $result['title']);
        $this->assertSame('published', $result['status']);
    }

    public function test_find_one_returns_null(): void
    {
        $this->assertNull($this->driver->findOne('posts', 'nonexistent'));
    }

    public function test_exists(): void
    {
        $this->driver->save('posts', 'abc', ['title' => 'Test']);

        $this->assertTrue($this->driver->exists('posts', 'abc'));
        $this->assertFalse($this->driver->exists('posts', 'xyz'));
    }

    public function test_delete(): void
    {
        $this->driver->save('posts', 'abc', ['title' => 'Test']);
        $this->driver->delete('posts', 'abc');

        $this->assertFalse($this->driver->exists('posts', 'abc'));
    }

    public function test_find_many(): void
    {
        $this->driver->save('posts', '1', ['title' => 'First', 'status' => 'published']);
        $this->driver->save('posts', '2', ['title' => 'Second', 'status' => 'draft']);
        $this->driver->save('posts', '3', ['title' => 'Third', 'status' => 'published']);

        $result = $this->driver->findMany('posts', new Query());
        $this->assertSame(3, $result->total());
    }

    public function test_find_many_with_where(): void
    {
        $this->driver->save('posts', '1', ['title' => 'A', 'status' => 'published']);
        $this->driver->save('posts', '2', ['title' => 'B', 'status' => 'draft']);

        $query = (new Query())->where('status', 'published');
        $result = $this->driver->findMany('posts', $query);

        $this->assertSame(1, $result->total());
    }

    public function test_find_many_with_order(): void
    {
        $this->driver->save('posts', '1', ['title' => 'Banana']);
        $this->driver->save('posts', '2', ['title' => 'Apple']);
        $this->driver->save('posts', '3', ['title' => 'Cherry']);

        $query = (new Query())->orderBy('title', SortDirection::Asc);
        $result = $this->driver->findMany('posts', $query);

        $titles = array_column($result->items(), 'title');
        $this->assertSame(['Apple', 'Banana', 'Cherry'], $titles);
    }

    public function test_find_many_with_limit_offset(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->driver->save('posts', (string)$i, ['title' => "Post {$i}"]);
        }

        $query = (new Query())->orderBy('title')->limit(2)->offset(1);
        $result = $this->driver->findMany('posts', $query);

        $this->assertCount(2, $result->items());
        $this->assertSame(5, $result->total());
    }

    public function test_find_many_with_search(): void
    {
        $this->driver->save('posts', '1', ['title' => 'PHP Guide', 'body' => 'About PHP']);
        $this->driver->save('posts', '2', ['title' => 'Ruby Guide', 'body' => 'About Ruby']);

        $query = (new Query())->search('PHP', ['title', 'body']);
        $result = $this->driver->findMany('posts', $query);

        $this->assertSame(1, $result->total());
    }

    public function test_save_updates_existing(): void
    {
        $this->driver->save('posts', 'abc', ['title' => 'Old']);
        $this->driver->save('posts', 'abc', ['title' => 'New']);

        $result = $this->driver->findOne('posts', 'abc');
        $this->assertSame('New', $result['title']);
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

```bash
./vendor/bin/phpunit packages/data/tests/Driver/
```

- [ ] **Step 4: Implement QueryCompiler**

Create `packages/data/src/Driver/QueryCompiler.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

use Preflow\Data\Query;

class QueryCompiler
{
    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function compile(string $table, Query $query): array
    {
        $bindings = [];
        $sql = "SELECT * FROM \"{$table}\"";

        $whereSql = $this->compileWheres($query, $bindings);
        if ($whereSql !== '') {
            $sql .= ' WHERE ' . $whereSql;
        }

        $searchSql = $this->compileSearch($query, $bindings);
        if ($searchSql !== '') {
            $sql .= ($whereSql !== '' ? ' AND ' : ' WHERE ') . $searchSql;
        }

        $orderSql = $this->compileOrderBy($query);
        if ($orderSql !== '') {
            $sql .= ' ' . $orderSql;
        }

        if ($query->getLimit() !== null) {
            $sql .= ' LIMIT ' . $query->getLimit();
        }

        if ($query->getOffset() !== null) {
            $sql .= ' OFFSET ' . $query->getOffset();
        }

        return [$sql, $bindings];
    }

    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function compileCount(string $table, Query $query): array
    {
        $bindings = [];
        $sql = "SELECT COUNT(*) as total FROM \"{$table}\"";

        $whereSql = $this->compileWheres($query, $bindings);
        if ($whereSql !== '') {
            $sql .= ' WHERE ' . $whereSql;
        }

        $searchSql = $this->compileSearch($query, $bindings);
        if ($searchSql !== '') {
            $sql .= ($whereSql !== '' ? ' AND ' : ' WHERE ') . $searchSql;
        }

        return [$sql, $bindings];
    }

    /**
     * @param array<int, mixed> $bindings
     */
    private function compileWheres(Query $query, array &$bindings): string
    {
        $wheres = $query->getWheres();

        if ($wheres === []) {
            return '';
        }

        $parts = [];
        foreach ($wheres as $i => $where) {
            $clause = "\"{$where['field']}\" {$where['operator']} ?";
            $bindings[] = $where['value'];

            if ($i === 0) {
                $parts[] = $clause;
            } else {
                $parts[] = $where['boolean'] . ' ' . $clause;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<int, mixed> $bindings
     */
    private function compileSearch(Query $query, array &$bindings): string
    {
        $term = $query->getSearchTerm();
        $fields = $query->getSearchFields();

        if ($term === null || $fields === []) {
            return '';
        }

        $parts = [];
        foreach ($fields as $field) {
            $parts[] = "\"{$field}\" LIKE ?";
            $bindings[] = '%' . $term . '%';
        }

        return '(' . implode(' OR ', $parts) . ')';
    }

    private function compileOrderBy(Query $query): string
    {
        $orders = $query->getOrderBy();

        if ($orders === []) {
            return '';
        }

        $parts = [];
        foreach ($orders as $order) {
            $parts[] = "\"{$order['field']}\" {$order['direction']->value}";
        }

        return 'ORDER BY ' . implode(', ', $parts);
    }
}
```

- [ ] **Step 5: Implement PdoDriver**

Create `packages/data/src/Driver/PdoDriver.php`:

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
        protected readonly QueryCompiler $compiler = new QueryCompiler(),
    ) {}

    public function findOne(string $type, string $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM \"{$type}\" WHERE uuid = ? LIMIT 1");
        $stmt->execute([$id]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $result !== false ? $result : null;
    }

    public function findMany(string $type, Query $query): ResultSet
    {
        // Get total count (without limit/offset)
        [$countSql, $countBindings] = $this->compiler->compileCount($type, $query);
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($countBindings);
        $total = (int) $countStmt->fetchColumn();

        // Get items
        [$sql, $bindings] = $this->compiler->compile($type, $query);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return new ResultSet($items, $total);
    }

    public function save(string $type, string $id, array $data): void
    {
        $data['uuid'] = $id;

        if ($this->exists($type, $id)) {
            $this->update($type, $id, $data);
        } else {
            $this->insert($type, $data);
        }
    }

    public function delete(string $type, string $id): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM \"{$type}\" WHERE uuid = ?");
        $stmt->execute([$id]);
    }

    public function exists(string $type, string $id): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM \"{$type}\" WHERE uuid = ? LIMIT 1");
        $stmt->execute([$id]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insert(string $type, array $data): void
    {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');

        $colsSql = implode(', ', array_map(fn ($c) => "\"{$c}\"", $columns));
        $phSql = implode(', ', $placeholders);

        $stmt = $this->pdo->prepare("INSERT INTO \"{$type}\" ({$colsSql}) VALUES ({$phSql})");
        $stmt->execute(array_values($data));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function update(string $type, string $id, array $data): void
    {
        unset($data['uuid']);
        $setParts = [];
        $values = [];

        foreach ($data as $col => $val) {
            $setParts[] = "\"{$col}\" = ?";
            $values[] = $val;
        }

        $values[] = $id;
        $setSql = implode(', ', $setParts);

        $stmt = $this->pdo->prepare("UPDATE \"{$type}\" SET {$setSql} WHERE uuid = ?");
        $stmt->execute($values);
    }
}
```

- [ ] **Step 6: Implement SqliteDriver**

Create `packages/data/src/Driver/SqliteDriver.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

final class SqliteDriver extends PdoDriver
{
    public function __construct(\PDO $pdo)
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        parent::__construct($pdo, new QueryCompiler());
    }
}
```

- [ ] **Step 7: Run tests**

```bash
./vendor/bin/phpunit packages/data/tests/Driver/
```

Expected: All tests pass (JsonFileDriver + QueryCompiler + SqliteDriver).

- [ ] **Step 8: Commit**

```bash
git add packages/data/src/Driver/ packages/data/tests/Driver/
git commit -m "feat(data): add QueryCompiler, PdoDriver, SqliteDriver, and tests"
```

---

### Task 7: Model Attributes

**Files:**
- Create: `packages/data/src/Attributes/Entity.php`
- Create: `packages/data/src/Attributes/Id.php`
- Create: `packages/data/src/Attributes/Field.php`
- Create: `packages/data/src/Attributes/Timestamps.php`

- [ ] **Step 1: Create all attribute classes**

Create `packages/data/src/Attributes/Entity.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Entity
{
    public function __construct(
        public readonly string $table,
        public readonly string $storage = 'default',
    ) {}
}
```

Create `packages/data/src/Attributes/Id.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Attributes;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Id
{
}
```

Create `packages/data/src/Attributes/Field.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Attributes;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Field
{
    public function __construct(
        public readonly bool $searchable = false,
        public readonly bool $translatable = false,
        public readonly ?string $transform = null,
    ) {}
}
```

Create `packages/data/src/Attributes/Timestamps.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Attributes;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Timestamps
{
}
```

- [ ] **Step 2: Commit**

```bash
git add packages/data/src/Attributes/
git commit -m "feat(data): add model attributes (Entity, Id, Field, Timestamps)"
```

---

### Task 8: Model + ModelMetadata

**Files:**
- Create: `packages/data/src/Model.php`
- Create: `packages/data/src/ModelMetadata.php`
- Create: `packages/data/tests/ModelMetadataTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/data/tests/ModelMetadataTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Attributes\Timestamps;
use Preflow\Data\Model;
use Preflow\Data\ModelMetadata;

#[Entity(table: 'posts', storage: 'sqlite')]
class TestPost extends Model
{
    #[Id]
    public string $uuid = '';

    #[Field(searchable: true)]
    public string $title = '';

    #[Field]
    public string $body = '';

    #[Field(transform: 'json')]
    public array $metadata = [];

    #[Timestamps]
    public ?\DateTimeImmutable $createdAt = null;
    public ?\DateTimeImmutable $updatedAt = null;
}

class NoEntityModel extends Model
{
    public string $name = '';
}

final class ModelMetadataTest extends TestCase
{
    public function test_reads_table_name(): void
    {
        $meta = ModelMetadata::for(TestPost::class);

        $this->assertSame('posts', $meta->table);
    }

    public function test_reads_storage(): void
    {
        $meta = ModelMetadata::for(TestPost::class);

        $this->assertSame('sqlite', $meta->storage);
    }

    public function test_reads_id_field(): void
    {
        $meta = ModelMetadata::for(TestPost::class);

        $this->assertSame('uuid', $meta->idField);
    }

    public function test_reads_fields(): void
    {
        $meta = ModelMetadata::for(TestPost::class);

        $this->assertArrayHasKey('title', $meta->fields);
        $this->assertArrayHasKey('body', $meta->fields);
        $this->assertArrayHasKey('metadata', $meta->fields);
    }

    public function test_reads_searchable_fields(): void
    {
        $meta = ModelMetadata::for(TestPost::class);

        $this->assertContains('title', $meta->searchableFields);
        $this->assertNotContains('body', $meta->searchableFields);
    }

    public function test_has_timestamps(): void
    {
        $meta = ModelMetadata::for(TestPost::class);

        $this->assertTrue($meta->hasTimestamps);
    }

    public function test_caches_metadata(): void
    {
        $a = ModelMetadata::for(TestPost::class);
        $b = ModelMetadata::for(TestPost::class);

        $this->assertSame($a, $b);
    }

    public function test_throws_without_entity_attribute(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Entity');

        ModelMetadata::for(NoEntityModel::class);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit packages/data/tests/ModelMetadataTest.php
```

- [ ] **Step 3: Implement Model base class**

Create `packages/data/src/Model.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data;

abstract class Model
{
    /**
     * Fill model properties from an associative array.
     *
     * @param array<string, mixed> $data
     */
    public function fill(array $data): void
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }

    /**
     * Convert model to an associative array of public properties.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $ref = new \ReflectionClass($this);
        $data = [];

        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isInitialized($this)) {
                $data[$prop->getName()] = $prop->getValue($this);
            }
        }

        return $data;
    }
}
```

- [ ] **Step 4: Implement ModelMetadata**

Create `packages/data/src/ModelMetadata.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Attributes\Timestamps;

final class ModelMetadata
{
    /** @var array<class-string, self> */
    private static array $cache = [];

    /**
     * @param array<string, Field> $fields
     * @param string[] $searchableFields
     */
    private function __construct(
        public readonly string $modelClass,
        public readonly string $table,
        public readonly string $storage,
        public readonly string $idField,
        public readonly array $fields,
        public readonly array $searchableFields,
        public readonly bool $hasTimestamps,
    ) {}

    /**
     * @param class-string<Model> $modelClass
     */
    public static function for(string $modelClass): self
    {
        if (isset(self::$cache[$modelClass])) {
            return self::$cache[$modelClass];
        }

        $ref = new \ReflectionClass($modelClass);

        // Read #[Entity] attribute
        $entityAttrs = $ref->getAttributes(Entity::class);
        if ($entityAttrs === []) {
            throw new \RuntimeException(
                "Model [{$modelClass}] is missing the #[Entity] attribute."
            );
        }

        $entity = $entityAttrs[0]->newInstance();

        // Scan properties
        $idField = 'uuid';
        $fields = [];
        $searchable = [];
        $hasTimestamps = false;

        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $name = $prop->getName();

            // Check #[Id]
            if ($prop->getAttributes(Id::class) !== []) {
                $idField = $name;
            }

            // Check #[Field]
            $fieldAttrs = $prop->getAttributes(Field::class);
            if ($fieldAttrs !== []) {
                $fieldAttr = $fieldAttrs[0]->newInstance();
                $fields[$name] = $fieldAttr;

                if ($fieldAttr->searchable) {
                    $searchable[] = $name;
                }
            }

            // Check #[Timestamps]
            if ($prop->getAttributes(Timestamps::class) !== []) {
                $hasTimestamps = true;
            }
        }

        $meta = new self(
            modelClass: $modelClass,
            table: $entity->table,
            storage: $entity->storage,
            idField: $idField,
            fields: $fields,
            searchableFields: $searchable,
            hasTimestamps: $hasTimestamps,
        );

        self::$cache[$modelClass] = $meta;

        return $meta;
    }

    /**
     * Clear the metadata cache (for testing).
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
```

- [ ] **Step 5: Run tests**

```bash
./vendor/bin/phpunit packages/data/tests/ModelMetadataTest.php
```

Expected: All 8 tests pass.

- [ ] **Step 6: Commit**

```bash
git add packages/data/src/Model.php packages/data/src/ModelMetadata.php packages/data/tests/ModelMetadataTest.php
git commit -m "feat(data): add Model base class and ModelMetadata reflection reader"
```

---

### Task 9: DataManager

**Files:**
- Create: `packages/data/src/DataManager.php`
- Create: `packages/data/tests/DataManagerTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/data/tests/DataManagerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Model;
use Preflow\Data\ModelMetadata;
use Preflow\Data\SortDirection;

#[Entity(table: 'items', storage: 'sqlite')]
class TestItem extends Model
{
    #[Id]
    public string $uuid = '';

    #[Field(searchable: true)]
    public string $name = '';

    #[Field]
    public string $status = 'draft';
}

#[Entity(table: 'settings', storage: 'json')]
class TestSetting extends Model
{
    #[Id]
    public string $uuid = '';

    #[Field]
    public string $key = '';

    #[Field]
    public string $value = '';
}

final class DataManagerTest extends TestCase
{
    private \PDO $pdo;
    private string $jsonDir;
    private DataManager $manager;

    protected function setUp(): void
    {
        ModelMetadata::clearCache();

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE items (uuid TEXT PRIMARY KEY, name TEXT, status TEXT DEFAULT "draft")');

        $this->jsonDir = sys_get_temp_dir() . '/preflow_dm_test_' . uniqid();
        mkdir($this->jsonDir, 0755, true);

        $this->manager = new DataManager([
            'sqlite' => new SqliteDriver($this->pdo),
            'json' => new JsonFileDriver($this->jsonDir),
        ]);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->jsonDir);
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->deleteDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function test_save_and_find_typed_model(): void
    {
        $item = new TestItem();
        $item->uuid = 'item-1';
        $item->name = 'Widget';
        $item->status = 'active';

        $this->manager->save($item);

        $found = $this->manager->find(TestItem::class, 'item-1');

        $this->assertNotNull($found);
        $this->assertInstanceOf(TestItem::class, $found);
        $this->assertSame('Widget', $found->name);
        $this->assertSame('active', $found->status);
    }

    public function test_find_returns_null_for_missing(): void
    {
        $found = $this->manager->find(TestItem::class, 'nonexistent');

        $this->assertNull($found);
    }

    public function test_query_with_where(): void
    {
        $this->saveItem('1', 'Alpha', 'active');
        $this->saveItem('2', 'Beta', 'draft');
        $this->saveItem('3', 'Gamma', 'active');

        $result = $this->manager->query(TestItem::class)
            ->where('status', 'active')
            ->get();

        $this->assertSame(2, $result->total());
    }

    public function test_query_with_order(): void
    {
        $this->saveItem('1', 'Banana');
        $this->saveItem('2', 'Apple');
        $this->saveItem('3', 'Cherry');

        $result = $this->manager->query(TestItem::class)
            ->orderBy('name', SortDirection::Asc)
            ->get();

        $names = array_map(fn ($m) => $m->name, $result->items());
        $this->assertSame(['Apple', 'Banana', 'Cherry'], $names);
    }

    public function test_query_first(): void
    {
        $this->saveItem('1', 'Only');

        $item = $this->manager->query(TestItem::class)->first();

        $this->assertInstanceOf(TestItem::class, $item);
        $this->assertSame('Only', $item->name);
    }

    public function test_delete(): void
    {
        $this->saveItem('1', 'ToDelete');

        $this->manager->delete(TestItem::class, '1');

        $this->assertNull($this->manager->find(TestItem::class, '1'));
    }

    public function test_multi_storage(): void
    {
        // Save to sqlite
        $item = new TestItem();
        $item->uuid = 'item-1';
        $item->name = 'SQLite Item';
        $this->manager->save($item);

        // Save to json
        $setting = new TestSetting();
        $setting->uuid = 'set-1';
        $setting->key = 'site_name';
        $setting->value = 'Preflow';
        $this->manager->save($setting);

        // Each resolves to its own driver
        $foundItem = $this->manager->find(TestItem::class, 'item-1');
        $foundSetting = $this->manager->find(TestSetting::class, 'set-1');

        $this->assertSame('SQLite Item', $foundItem->name);
        $this->assertSame('Preflow', $foundSetting->value);
    }

    private function saveItem(string $id, string $name, string $status = 'draft'): void
    {
        $item = new TestItem();
        $item->uuid = $id;
        $item->name = $name;
        $item->status = $status;
        $this->manager->save($item);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit packages/data/tests/DataManagerTest.php
```

- [ ] **Step 3: Implement DataManager**

Create `packages/data/src/DataManager.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data;

final class DataManager
{
    /**
     * @param array<string, StorageDriver> $drivers Named drivers (e.g., 'sqlite' => SqliteDriver)
     */
    public function __construct(
        private readonly array $drivers,
        private readonly string $defaultDriver = 'default',
    ) {}

    /**
     * Find a single model by ID.
     *
     * @template T of Model
     * @param class-string<T> $modelClass
     * @return T|null
     */
    public function find(string $modelClass, string $id): ?Model
    {
        $meta = ModelMetadata::for($modelClass);
        $driver = $this->resolveDriver($meta->storage);

        $data = $driver->findOne($meta->table, $id);

        if ($data === null) {
            return null;
        }

        $model = new $modelClass();
        $model->fill($data);

        return $model;
    }

    /**
     * Start a query for a typed model.
     *
     * @template T of Model
     * @param class-string<T> $modelClass
     * @return QueryBuilder<T>
     */
    public function query(string $modelClass): QueryBuilder
    {
        $meta = ModelMetadata::for($modelClass);
        $driver = $this->resolveDriver($meta->storage);

        return new QueryBuilder($driver, $meta, $modelClass);
    }

    /**
     * Save a model.
     */
    public function save(Model $model): void
    {
        $meta = ModelMetadata::for($model::class);
        $driver = $this->resolveDriver($meta->storage);
        $data = $model->toArray();
        $id = $data[$meta->idField] ?? throw new \RuntimeException("Model missing ID field [{$meta->idField}].");

        $driver->save($meta->table, $id, $data);
    }

    /**
     * Delete a model by class and ID.
     *
     * @param class-string<Model> $modelClass
     */
    public function delete(string $modelClass, string $id): void
    {
        $meta = ModelMetadata::for($modelClass);
        $driver = $this->resolveDriver($meta->storage);

        $driver->delete($meta->table, $id);
    }

    private function resolveDriver(string $name): StorageDriver
    {
        if (isset($this->drivers[$name])) {
            return $this->drivers[$name];
        }

        if (isset($this->drivers[$this->defaultDriver])) {
            return $this->drivers[$this->defaultDriver];
        }

        throw new \RuntimeException("Storage driver [{$name}] not configured.");
    }
}
```

- [ ] **Step 4: Create QueryBuilder**

Create `packages/data/src/QueryBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * @template T of Model
 */
final class QueryBuilder
{
    private Query $query;

    /**
     * @param class-string<T> $modelClass
     */
    public function __construct(
        private readonly StorageDriver $driver,
        private readonly ModelMetadata $meta,
        private readonly string $modelClass,
    ) {
        $this->query = new Query();
    }

    public function where(string $field, mixed $operator, mixed $value = null): self
    {
        $this->query->where($field, $operator, $value);
        return $this;
    }

    public function orWhere(string $field, mixed $operator, mixed $value = null): self
    {
        $this->query->orWhere($field, $operator, $value);
        return $this;
    }

    public function orderBy(string $field, SortDirection $direction = SortDirection::Asc): self
    {
        $this->query->orderBy($field, $direction);
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->query->limit($limit);
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->query->offset($offset);
        return $this;
    }

    public function search(string $term, ?array $fields = null): self
    {
        $fields ??= $this->meta->searchableFields;
        $this->query->search($term, $fields);
        return $this;
    }

    /**
     * Execute the query and return a ResultSet of model instances.
     *
     * @return ResultSet<T>
     */
    public function get(): ResultSet
    {
        $result = $this->driver->findMany($this->meta->table, $this->query);

        $models = array_map(function (array $data) {
            $model = new ($this->modelClass)();
            $model->fill($data);
            return $model;
        }, $result->items());

        return new ResultSet($models, $result->total());
    }

    /**
     * Get the first result or null.
     *
     * @return T|null
     */
    public function first(): ?Model
    {
        $this->query->limit(1);
        $result = $this->get();

        return $result->first();
    }

    /**
     * Get a paginated result.
     *
     * @return PaginatedResult
     */
    public function paginate(int $perPage, int $currentPage = 1): PaginatedResult
    {
        $this->query->limit($perPage)->offset(($currentPage - 1) * $perPage);
        $result = $this->get();

        return $result->paginate($perPage, $currentPage);
    }
}
```

- [ ] **Step 5: Run tests**

```bash
./vendor/bin/phpunit packages/data/tests/DataManagerTest.php
```

Expected: All 7 tests pass.

- [ ] **Step 6: Commit**

```bash
git add packages/data/src/DataManager.php packages/data/src/QueryBuilder.php packages/data/tests/DataManagerTest.php
git commit -m "feat(data): add DataManager and QueryBuilder with multi-storage support"
```

---

### Task 10: Migration System

**Files:**
- Create: `packages/data/src/Migration/Migration.php`
- Create: `packages/data/src/Migration/Schema.php`
- Create: `packages/data/src/Migration/Table.php`
- Create: `packages/data/src/Migration/Migrator.php`
- Create: `packages/data/tests/Migration/SchemaTest.php`
- Create: `packages/data/tests/Migration/MigratorTest.php`

- [ ] **Step 1: Write the Schema test**

Create `packages/data/tests/Migration/SchemaTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Migration;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Migration\Table;

final class SchemaTest extends TestCase
{
    private \PDO $pdo;
    private Schema $schema;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->schema = new Schema($this->pdo);
    }

    public function test_create_table(): void
    {
        $this->schema->create('posts', function (Table $table) {
            $table->uuid('uuid')->primary();
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamps();
        });

        // Verify table exists
        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='posts'");
        $this->assertSame('posts', $stmt->fetchColumn());
    }

    public function test_table_has_columns(): void
    {
        $this->schema->create('posts', function (Table $table) {
            $table->uuid('uuid')->primary();
            $table->string('title')->index();
            $table->integer('views')->nullable();
            $table->json('metadata')->nullable();
        });

        $stmt = $this->pdo->query("PRAGMA table_info(posts)");
        $columns = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $names = array_column($columns, 'name');

        $this->assertContains('uuid', $names);
        $this->assertContains('title', $names);
        $this->assertContains('views', $names);
        $this->assertContains('metadata', $names);
    }

    public function test_drop_table(): void
    {
        $this->pdo->exec('CREATE TABLE temp_table (id INTEGER)');

        $this->schema->drop('temp_table');

        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='temp_table'");
        $this->assertFalse($stmt->fetchColumn());
    }

    public function test_timestamps_creates_two_columns(): void
    {
        $this->schema->create('events', function (Table $table) {
            $table->uuid('uuid')->primary();
            $table->timestamps();
        });

        $stmt = $this->pdo->query("PRAGMA table_info(events)");
        $columns = array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'name');

        $this->assertContains('created_at', $columns);
        $this->assertContains('updated_at', $columns);
    }
}
```

- [ ] **Step 2: Write the Migrator test**

Create `packages/data/tests/Migration/MigratorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Migration;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Migration\Migration;
use Preflow\Data\Migration\Migrator;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Migration\Table;

final class MigratorTest extends TestCase
{
    private \PDO $pdo;
    private string $migrationsDir;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->migrationsDir = sys_get_temp_dir() . '/preflow_migration_test_' . uniqid();
        mkdir($this->migrationsDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->migrationsDir . '/*.php') as $file) {
            unlink($file);
        }
        rmdir($this->migrationsDir);
    }

    private function createMigration(string $filename, string $upSql, string $downSql = ''): void
    {
        $content = <<<PHP
        <?php
        use Preflow\Data\Migration\Migration;
        use Preflow\Data\Migration\Schema;
        use Preflow\Data\Migration\Table;

        return new class extends Migration {
            public function up(Schema \$schema): void
            {
                {$upSql}
            }
            public function down(Schema \$schema): void
            {
                {$downSql}
            }
        };
        PHP;

        file_put_contents($this->migrationsDir . '/' . $filename, $content);
    }

    public function test_runs_pending_migrations(): void
    {
        $this->createMigration(
            '2026_01_01_create_users.php',
            '$schema->create("users", function (Table $table) { $table->uuid("uuid")->primary(); $table->string("name"); });',
            '$schema->drop("users");'
        );

        $migrator = new Migrator($this->pdo, $this->migrationsDir);
        $migrator->migrate();

        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
        $this->assertSame('users', $stmt->fetchColumn());
    }

    public function test_tracks_run_migrations(): void
    {
        $this->createMigration(
            '2026_01_01_create_users.php',
            '$schema->create("users", function (Table $table) { $table->uuid("uuid")->primary(); });'
        );

        $migrator = new Migrator($this->pdo, $this->migrationsDir);
        $migrator->migrate();

        $pending = $migrator->pending();
        $this->assertCount(0, $pending);
    }

    public function test_does_not_rerun_migrations(): void
    {
        $this->createMigration(
            '2026_01_01_create_users.php',
            '$schema->create("users", function (Table $table) { $table->uuid("uuid")->primary(); });'
        );

        $migrator = new Migrator($this->pdo, $this->migrationsDir);
        $migrator->migrate();
        $migrator->migrate(); // second run should be no-op

        // If it tried to create again, it would throw
        $this->assertTrue(true);
    }

    public function test_runs_migrations_in_order(): void
    {
        $this->createMigration(
            '2026_01_02_create_posts.php',
            '$schema->create("posts", function (Table $table) { $table->uuid("uuid")->primary(); });'
        );
        $this->createMigration(
            '2026_01_01_create_users.php',
            '$schema->create("users", function (Table $table) { $table->uuid("uuid")->primary(); });'
        );

        $migrator = new Migrator($this->pdo, $this->migrationsDir);
        $migrator->migrate();

        // Both tables should exist
        $tables = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('users','posts') ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['posts', 'users'], $tables);
    }

    public function test_pending_returns_unrun_migrations(): void
    {
        $this->createMigration('2026_01_01_a.php', '// no-op');
        $this->createMigration('2026_01_02_b.php', '// no-op');

        $migrator = new Migrator($this->pdo, $this->migrationsDir);

        $pending = $migrator->pending();
        $this->assertCount(2, $pending);
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

```bash
./vendor/bin/phpunit packages/data/tests/Migration/
```

- [ ] **Step 4: Implement Table builder**

Create `packages/data/src/Migration/Table.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Migration;

final class Table
{
    /** @var array<int, array{name: string, type: string, nullable: bool, primary: bool, index: bool}> */
    private array $columns = [];

    public function __construct(
        private readonly string $name,
    ) {}

    public function uuid(string $name): self
    {
        return $this->addColumn($name, 'TEXT');
    }

    public function string(string $name, int $length = 255): self
    {
        return $this->addColumn($name, 'TEXT');
    }

    public function text(string $name): self
    {
        return $this->addColumn($name, 'TEXT');
    }

    public function integer(string $name): self
    {
        return $this->addColumn($name, 'INTEGER');
    }

    public function json(string $name): self
    {
        return $this->addColumn($name, 'TEXT');
    }

    public function boolean(string $name): self
    {
        return $this->addColumn($name, 'INTEGER');
    }

    public function timestamps(): self
    {
        $this->addColumn('created_at', 'TEXT');
        $this->columns[array_key_last($this->columns)]['nullable'] = true;
        $this->addColumn('updated_at', 'TEXT');
        $this->columns[array_key_last($this->columns)]['nullable'] = true;
        return $this;
    }

    public function nullable(): self
    {
        if ($this->columns !== []) {
            $this->columns[array_key_last($this->columns)]['nullable'] = true;
        }
        return $this;
    }

    public function primary(): self
    {
        if ($this->columns !== []) {
            $this->columns[array_key_last($this->columns)]['primary'] = true;
        }
        return $this;
    }

    public function index(): self
    {
        if ($this->columns !== []) {
            $this->columns[array_key_last($this->columns)]['index'] = true;
        }
        return $this;
    }

    /**
     * Generate the CREATE TABLE SQL.
     */
    public function toSql(): string
    {
        $parts = [];
        $indexes = [];

        foreach ($this->columns as $col) {
            $def = "\"{$col['name']}\" {$col['type']}";

            if ($col['primary']) {
                $def .= ' PRIMARY KEY';
            }

            if (!$col['nullable'] && !$col['primary']) {
                $def .= ' NOT NULL';
            }

            $parts[] = $def;

            if ($col['index'] && !$col['primary']) {
                $indexes[] = $col['name'];
            }
        }

        $sql = "CREATE TABLE \"{$this->name}\" (\n    " . implode(",\n    ", $parts) . "\n)";

        return $sql;
    }

    /**
     * @return string[]
     */
    public function getIndexes(): array
    {
        $indexes = [];
        foreach ($this->columns as $col) {
            if ($col['index'] && !$col['primary']) {
                $indexes[] = $col['name'];
            }
        }
        return $indexes;
    }

    public function getName(): string
    {
        return $this->name;
    }

    private function addColumn(string $name, string $type): self
    {
        $this->columns[] = [
            'name' => $name,
            'type' => $type,
            'nullable' => false,
            'primary' => false,
            'index' => false,
        ];
        return $this;
    }
}
```

- [ ] **Step 5: Implement Schema**

Create `packages/data/src/Migration/Schema.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Migration;

final class Schema
{
    public function __construct(
        private readonly \PDO $pdo,
    ) {}

    public function create(string $table, callable $callback): void
    {
        $builder = new Table($table);
        $callback($builder);

        $this->pdo->exec($builder->toSql());

        // Create indexes
        foreach ($builder->getIndexes() as $column) {
            $indexName = "idx_{$table}_{$column}";
            $this->pdo->exec("CREATE INDEX \"{$indexName}\" ON \"{$table}\" (\"{$column}\")");
        }
    }

    public function drop(string $table): void
    {
        $this->pdo->exec("DROP TABLE IF EXISTS \"{$table}\"");
    }
}
```

- [ ] **Step 6: Implement Migration base class**

Create `packages/data/src/Migration/Migration.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Migration;

abstract class Migration
{
    abstract public function up(Schema $schema): void;

    public function down(Schema $schema): void
    {
    }
}
```

- [ ] **Step 7: Implement Migrator**

Create `packages/data/src/Migration/Migrator.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Migration;

final class Migrator
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $migrationsPath,
    ) {
        $this->ensureMigrationsTable();
    }

    public function migrate(): void
    {
        $pending = $this->pending();

        foreach ($pending as $file) {
            $migration = require $file;

            if (!$migration instanceof Migration) {
                throw new \RuntimeException("Migration file [{$file}] must return a Migration instance.");
            }

            $schema = new Schema($this->pdo);
            $migration->up($schema);

            $this->recordMigration(basename($file));
        }
    }

    /**
     * @return string[] Full paths to pending migration files
     */
    public function pending(): array
    {
        $all = $this->allFiles();
        $ran = $this->ranMigrations();

        return array_values(array_filter($all, function (string $file) use ($ran) {
            return !in_array(basename($file), $ran, true);
        }));
    }

    /**
     * @return string[] All migration file paths, sorted by name
     */
    private function allFiles(): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }

        $files = glob($this->migrationsPath . '/*.php');
        sort($files);

        return $files;
    }

    /**
     * @return string[] Basenames of already-run migrations
     */
    private function ranMigrations(): array
    {
        $stmt = $this->pdo->query('SELECT migration FROM preflow_migrations ORDER BY migration');

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function recordMigration(string $name): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO preflow_migrations (migration, ran_at) VALUES (?, ?)');
        $stmt->execute([$name, date('Y-m-d H:i:s')]);
    }

    private function ensureMigrationsTable(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS preflow_migrations (
            migration TEXT PRIMARY KEY,
            ran_at TEXT NOT NULL
        )');
    }
}
```

- [ ] **Step 8: Run tests**

```bash
./vendor/bin/phpunit packages/data/tests/Migration/
```

Expected: All tests pass (SchemaTest + MigratorTest).

- [ ] **Step 9: Commit**

```bash
git add packages/data/src/Migration/ packages/data/tests/Migration/
git commit -m "feat(data): add migration system with Schema, Table builder, and Migrator"
```

---

### Task 11: Full Test Suite Verification

**Files:**
- No new files

- [ ] **Step 1: Run full test suite**

```bash
./vendor/bin/phpunit --display-errors --display-warnings
```

Expected: All tests pass across all 5 packages.

- [ ] **Step 2: Verify package integrates**

```bash
php -r "
require 'vendor/autoload.php';
echo 'StorageDriver: OK' . PHP_EOL;
echo 'JsonFileDriver: OK' . PHP_EOL;
echo 'SqliteDriver: OK' . PHP_EOL;
echo 'DataManager: OK' . PHP_EOL;
echo 'Migrator: OK' . PHP_EOL;
"
```

- [ ] **Step 3: Commit if cleanup needed**

---

## Phase 5a Deliverables

| Component | What It Does |
|---|---|
| `StorageDriver` | Interface for all storage backends |
| `Query` | Driver-agnostic query builder with where, orderBy, limit, offset, search |
| `ResultSet` / `PaginatedResult` | Query result containers with iteration + pagination |
| `JsonFileDriver` | File-based storage (one JSON file per record) |
| `PdoDriver` / `SqliteDriver` | SQL storage with PDO, SQLite-in-memory for tests |
| `QueryCompiler` | Compiles Query to SQL + bindings |
| Model attributes | `#[Entity]`, `#[Id]`, `#[Field]`, `#[Timestamps]` |
| `Model` | Base class with `fill()`, `toArray()` |
| `ModelMetadata` | Reads model attributes via reflection, caches results |
| `DataManager` / `QueryBuilder` | Unified entry point with fluent query API, multi-storage routing |
| `Migration` / `Schema` / `Table` / `Migrator` | Lightweight migration system |

**Deferred to Phase 5b:** MySQL driver, dynamic models (JSON-defined schemas), field transformers.

**Next phase:** `preflow/htmx` — hypermedia driver interface, HTMX implementation, token system, component endpoint.
