# Phase 5b: Data Layer — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend preflow/data with a MySQL driver (via a Dialect abstraction), a field transformer infrastructure (with JsonTransformer and DateTimeTransformer), and a dynamic model system (JSON schema definitions queried without PHP classes).

**Architecture:** Three features build on the existing StorageDriver/PdoDriver/QueryBuilder/DataManager foundation. The Dialect interface extracts SQL-dialect differences (quoting, upsert syntax) from QueryCompiler and PdoDriver. FieldTransformer provides a toStorage/fromStorage contract integrated into Model::fill()/toArray(). TypeRegistry loads JSON schema files into TypeDefinition objects; DynamicRecord is the generic data container; DataManager gains queryType/findType/saveType/deleteType.

**Tech Stack:** PHP 8.5, PDO (SQLite + MySQL), PHPUnit

---

## Phase 1: Dialect System

### Task 1: Dialect interface and SqliteDialect

**Files:**
- Create: `packages/data/src/Driver/Dialect.php`
- Create: `packages/data/src/Driver/SqliteDialect.php`
- Test: `packages/data/tests/Driver/SqliteDialectTest.php`

- [ ] **Step 1: Write tests for SqliteDialect**

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\SqliteDialect;

final class SqliteDialectTest extends TestCase
{
    private SqliteDialect $dialect;

    protected function setUp(): void
    {
        $this->dialect = new SqliteDialect();
    }

    public function test_quotes_identifier_with_double_quotes(): void
    {
        $this->assertSame('"users"', $this->dialect->quoteIdentifier('users'));
    }

    public function test_upsert_generates_insert_or_replace(): void
    {
        $sql = $this->dialect->upsertSql('posts', ['uuid', 'title', 'body'], 'uuid');

        $this->assertStringContainsString('INSERT OR REPLACE INTO', $sql);
        $this->assertStringContainsString('"posts"', $sql);
        $this->assertStringContainsString('"uuid"', $sql);
        $this->assertStringContainsString('"title"', $sql);
        $this->assertSame(3, substr_count($sql, '?'));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit packages/data/tests/Driver/SqliteDialectTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Create Dialect interface**

Create `packages/data/src/Driver/Dialect.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

interface Dialect
{
    /**
     * Quote a table or column identifier for this database.
     */
    public function quoteIdentifier(string $name): string;

    /**
     * Generate an upsert statement (insert or update on conflict).
     *
     * @param string[] $columns All column names in the row
     */
    public function upsertSql(string $table, array $columns, string $idField): string;
}
```

- [ ] **Step 4: Create SqliteDialect**

Create `packages/data/src/Driver/SqliteDialect.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

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

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit packages/data/tests/Driver/SqliteDialectTest.php`
Expected: 2 tests, all PASS

- [ ] **Step 6: Commit**

```bash
git add packages/data/src/Driver/Dialect.php packages/data/src/Driver/SqliteDialect.php packages/data/tests/Driver/SqliteDialectTest.php
git commit -m "feat(data): add Dialect interface and SqliteDialect"
```

---

### Task 2: MysqlDialect

**Files:**
- Create: `packages/data/src/Driver/MysqlDialect.php`
- Test: `packages/data/tests/Driver/MysqlDialectTest.php`

- [ ] **Step 1: Write tests for MysqlDialect**

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\MysqlDialect;

final class MysqlDialectTest extends TestCase
{
    private MysqlDialect $dialect;

    protected function setUp(): void
    {
        $this->dialect = new MysqlDialect();
    }

    public function test_quotes_identifier_with_backticks(): void
    {
        $this->assertSame('`users`', $this->dialect->quoteIdentifier('users'));
    }

    public function test_upsert_generates_on_duplicate_key_update(): void
    {
        $sql = $this->dialect->upsertSql('posts', ['uuid', 'title', 'body'], 'uuid');

        $this->assertStringContainsString('INSERT INTO', $sql);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
        $this->assertStringContainsString('`posts`', $sql);
        $this->assertStringContainsString('`title` = VALUES(`title`)', $sql);
        $this->assertStringContainsString('`body` = VALUES(`body`)', $sql);
        // uuid should NOT appear in the UPDATE clause
        $this->assertStringNotContainsString('`uuid` = VALUES(`uuid`)', $sql);
        $this->assertSame(3, substr_count($sql, '?'));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit packages/data/tests/Driver/MysqlDialectTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Create MysqlDialect**

Create `packages/data/src/Driver/MysqlDialect.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

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

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit packages/data/tests/Driver/MysqlDialectTest.php`
Expected: 2 tests, all PASS

- [ ] **Step 5: Commit**

```bash
git add packages/data/src/Driver/MysqlDialect.php packages/data/tests/Driver/MysqlDialectTest.php
git commit -m "feat(data): add MysqlDialect with backtick quoting and ON DUPLICATE KEY UPDATE"
```

---

### Task 3: Refactor QueryCompiler to use Dialect

**Files:**
- Modify: `packages/data/src/Driver/QueryCompiler.php`
- Modify: `packages/data/tests/Driver/QueryCompilerTest.php`

- [ ] **Step 1: Update QueryCompiler constructor to accept Dialect**

In `packages/data/src/Driver/QueryCompiler.php`, add a constructor and replace all hardcoded `"` quoting with `$this->dialect->quoteIdentifier()`.

Current QueryCompiler (line 9) has no constructor. Change the class to:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

use Preflow\Data\Query;

class QueryCompiler
{
    public function __construct(
        private readonly Dialect $dialect = new SqliteDialect(),
    ) {}

    /**
     * @return array{string, array<int, mixed>}
     */
    public function compile(string $table, Query $query): array
    {
        $bindings = [];
        $sql = 'SELECT * FROM ' . $this->dialect->quoteIdentifier($table);

        $where = $this->compileWheres($query, $bindings);
        $search = $this->compileSearch($query, $bindings);

        if ($where !== '' || $search !== '') {
            $parts = array_filter([$where, $search]);
            $sql .= ' WHERE ' . implode(' AND ', $parts);
        }

        $sql .= $this->compileOrderBy($query);

        if ($query->getLimit() !== null) {
            $sql .= ' LIMIT ' . $query->getLimit();
        }

        if ($query->getOffset() !== null) {
            $sql .= ' OFFSET ' . $query->getOffset();
        }

        return [$sql, $bindings];
    }

    /**
     * @return array{string, array<int, mixed>}
     */
    public function compileCount(string $table, Query $query): array
    {
        $bindings = [];
        $sql = 'SELECT COUNT(*) as total FROM ' . $this->dialect->quoteIdentifier($table);

        $where = $this->compileWheres($query, $bindings);
        $search = $this->compileSearch($query, $bindings);

        if ($where !== '' || $search !== '') {
            $parts = array_filter([$where, $search]);
            $sql .= ' WHERE ' . implode(' AND ', $parts);
        }

        return [$sql, $bindings];
    }

    private function compileWheres(Query $query, array &$bindings): string
    {
        $wheres = $query->getWheres();
        if (empty($wheres)) {
            return '';
        }

        $parts = [];
        foreach ($wheres as $i => $where) {
            $clause = $this->dialect->quoteIdentifier($where['field']) . ' ' . $where['operator'] . ' ?';
            $bindings[] = $where['value'];

            if ($i === 0) {
                $parts[] = $clause;
            } else {
                $parts[] = strtoupper($where['boolean']) . ' ' . $clause;
            }
        }

        return implode(' ', $parts);
    }

    private function compileSearch(Query $query, array &$bindings): string
    {
        $term = $query->getSearchTerm();
        $fields = $query->getSearchFields();

        if ($term === null || empty($fields)) {
            return '';
        }

        $conditions = [];
        foreach ($fields as $field) {
            $conditions[] = $this->dialect->quoteIdentifier($field) . ' LIKE ?';
            $bindings[] = '%' . $term . '%';
        }

        return '(' . implode(' OR ', $conditions) . ')';
    }

    private function compileOrderBy(Query $query): string
    {
        $orderBy = $query->getOrderBy();
        if (empty($orderBy)) {
            return '';
        }

        $parts = [];
        foreach ($orderBy as $order) {
            $parts[] = $this->dialect->quoteIdentifier($order['field']) . ' ' . $order['direction'];
        }

        return ' ORDER BY ' . implode(', ', $parts);
    }
}
```

- [ ] **Step 2: Update QueryCompiler tests**

The existing tests in `packages/data/tests/Driver/QueryCompilerTest.php` create `new QueryCompiler()` which now defaults to `SqliteDialect` — all existing assertions about `"` quoting should still pass. Add one new test to verify dialect injection works:

```php
    public function test_uses_dialect_for_quoting(): void
    {
        $compiler = new QueryCompiler(new \Preflow\Data\Driver\MysqlDialect());
        $query = new Query();

        [$sql] = $compiler->compile('users', $query);

        $this->assertStringContainsString('`users`', $sql);
    }
```

- [ ] **Step 3: Run all QueryCompiler tests**

Run: `vendor/bin/phpunit packages/data/tests/Driver/QueryCompilerTest.php`
Expected: 11 tests (10 existing + 1 new), all PASS

- [ ] **Step 4: Run full test suite**

Run: `vendor/bin/phpunit`
Expected: All pass — SqliteDriver still works because QueryCompiler defaults to SqliteDialect

- [ ] **Step 5: Commit**

```bash
git add packages/data/src/Driver/QueryCompiler.php packages/data/tests/Driver/QueryCompilerTest.php
git commit -m "refactor(data): QueryCompiler uses Dialect for identifier quoting"
```

---

### Task 4: Refactor PdoDriver to use Dialect, update SqliteDriver

**Files:**
- Modify: `packages/data/src/Driver/PdoDriver.php`
- Modify: `packages/data/src/Driver/SqliteDriver.php`

- [ ] **Step 1: Update PdoDriver constructor and methods**

In `packages/data/src/Driver/PdoDriver.php`, change the constructor (line 13-16) to accept a `Dialect`:

```php
    public function __construct(
        protected readonly \PDO $pdo,
        protected readonly Dialect $dialect,
        protected readonly QueryCompiler $compiler,
    ) {}
```

Update `findOne()` (line 18) to use dialect:

```php
    public function findOne(string $type, string $id): ?array
    {
        $sql = sprintf(
            'SELECT * FROM %s WHERE %s = ?',
            $this->dialect->quoteIdentifier($type),
            $this->dialect->quoteIdentifier('uuid'),
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }
```

Update `save()` (line 44) to use dialect upsert:

```php
    public function save(string $type, string $id, array $data): void
    {
        if (!isset($data['uuid'])) {
            $data['uuid'] = $id;
        }

        $columns = array_keys($data);
        $sql = $this->dialect->upsertSql($type, $columns, 'uuid');
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($data));
    }
```

Update `delete()` (line 58) to use dialect:

```php
    public function delete(string $type, string $id): void
    {
        $sql = sprintf(
            'DELETE FROM %s WHERE %s = ?',
            $this->dialect->quoteIdentifier($type),
            $this->dialect->quoteIdentifier('uuid'),
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
    }
```

Update `exists()` (line 64) to use dialect:

```php
    public function exists(string $type, string $id): bool
    {
        $sql = sprintf(
            'SELECT 1 FROM %s WHERE %s = ? LIMIT 1',
            $this->dialect->quoteIdentifier($type),
            $this->dialect->quoteIdentifier('uuid'),
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch() !== false;
    }
```

Remove the old `insert()` and `update()` private methods — they are replaced by `save()` using the dialect's upsert.

- [ ] **Step 2: Update SqliteDriver**

In `packages/data/src/Driver/SqliteDriver.php`, update to pass SqliteDialect:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

final class SqliteDriver extends PdoDriver
{
    public function __construct(\PDO $pdo)
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $dialect = new SqliteDialect();
        parent::__construct($pdo, $dialect, new QueryCompiler($dialect));
    }
}
```

- [ ] **Step 3: Run existing SQLite tests**

Run: `vendor/bin/phpunit packages/data/tests/Driver/SqliteDriverTest.php`
Expected: 9 tests, all PASS — same behavior, different internals

- [ ] **Step 4: Run full test suite**

Run: `vendor/bin/phpunit`
Expected: All pass

- [ ] **Step 5: Commit**

```bash
git add packages/data/src/Driver/PdoDriver.php packages/data/src/Driver/SqliteDriver.php
git commit -m "refactor(data): PdoDriver uses Dialect for quoting and upsert"
```

---

### Task 5: MysqlDriver

**Files:**
- Create: `packages/data/src/Driver/MysqlDriver.php`
- Test: `packages/data/tests/Driver/MysqlDriverTest.php`

- [ ] **Step 1: Write tests for MysqlDriver**

Since we can't require a running MySQL server in CI, test against SQLite with the MysqlDialect to verify the driver plumbing. Then add a separate integration test that's skipped without MySQL.

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\MysqlDriver;
use Preflow\Data\Driver\MysqlDialect;
use Preflow\Data\Query;
use Preflow\Data\SortDirection;

final class MysqlDriverTest extends TestCase
{
    public function test_mysql_driver_uses_mysql_dialect(): void
    {
        $ref = new \ReflectionClass(MysqlDriver::class);
        $constructor = $ref->getConstructor();
        $params = $constructor->getParameters();

        // Constructor accepts PDO only
        $this->assertCount(1, $params);
        $this->assertSame('pdo', $params[0]->getName());
    }

    public function test_mysql_driver_extends_pdo_driver(): void
    {
        $this->assertTrue(is_subclass_of(MysqlDriver::class, \Preflow\Data\Driver\PdoDriver::class));
    }

    public function test_mysql_integration(): void
    {
        $host = getenv('MYSQL_HOST') ?: false;
        $db = getenv('MYSQL_DATABASE') ?: false;
        if (!$host || !$db) {
            $this->markTestSkipped('MySQL not configured (set MYSQL_HOST, MYSQL_DATABASE, MYSQL_USER, MYSQL_PASS)');
        }

        $pdo = new \PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $db),
            getenv('MYSQL_USER') ?: 'root',
            getenv('MYSQL_PASS') ?: '',
        );

        $pdo->exec('CREATE TABLE IF NOT EXISTS test_mysql_driver (
            uuid VARCHAR(36) PRIMARY KEY,
            title VARCHAR(255),
            body TEXT
        )');

        $driver = new MysqlDriver($pdo);

        // Save
        $driver->save('test_mysql_driver', 'test-1', [
            'uuid' => 'test-1',
            'title' => 'Hello',
            'body' => 'World',
        ]);

        // Find
        $row = $driver->findOne('test_mysql_driver', 'test-1');
        $this->assertSame('Hello', $row['title']);

        // Exists
        $this->assertTrue($driver->exists('test_mysql_driver', 'test-1'));

        // Delete
        $driver->delete('test_mysql_driver', 'test-1');
        $this->assertNull($driver->findOne('test_mysql_driver', 'test-1'));

        $pdo->exec('DROP TABLE test_mysql_driver');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit packages/data/tests/Driver/MysqlDriverTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Create MysqlDriver**

Create `packages/data/src/Driver/MysqlDriver.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

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

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit packages/data/tests/Driver/MysqlDriverTest.php`
Expected: 2 pass, 1 skipped (MySQL integration)

- [ ] **Step 5: Run full test suite**

Run: `vendor/bin/phpunit`
Expected: All pass

- [ ] **Step 6: Commit**

```bash
git add packages/data/src/Driver/MysqlDriver.php packages/data/tests/Driver/MysqlDriverTest.php
git commit -m "feat(data): add MysqlDriver extending PdoDriver with MysqlDialect"
```

---

## Phase 2: Field Transformers

### Task 6: FieldTransformer interface + JsonTransformer + DateTimeTransformer

**Files:**
- Create: `packages/data/src/FieldTransformer.php`
- Create: `packages/data/src/Transform/JsonTransformer.php`
- Create: `packages/data/src/Transform/DateTimeTransformer.php`
- Test: `packages/data/tests/Transform/JsonTransformerTest.php`
- Test: `packages/data/tests/Transform/DateTimeTransformerTest.php`

- [ ] **Step 1: Write tests for JsonTransformer**

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Transform;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Transform\JsonTransformer;

final class JsonTransformerTest extends TestCase
{
    private JsonTransformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new JsonTransformer();
    }

    public function test_to_storage_encodes_array(): void
    {
        $result = $this->transformer->toStorage(['key' => 'value']);
        $this->assertSame('{"key":"value"}', $result);
    }

    public function test_from_storage_decodes_json_string(): void
    {
        $result = $this->transformer->fromStorage('{"key":"value"}');
        $this->assertSame(['key' => 'value'], $result);
    }

    public function test_to_storage_null_returns_null(): void
    {
        $this->assertNull($this->transformer->toStorage(null));
    }

    public function test_from_storage_null_returns_null(): void
    {
        $this->assertNull($this->transformer->fromStorage(null));
    }

    public function test_from_storage_empty_string_returns_null(): void
    {
        $this->assertNull($this->transformer->fromStorage(''));
    }

    public function test_roundtrip(): void
    {
        $data = ['nested' => ['a' => 1, 'b' => [2, 3]]];
        $stored = $this->transformer->toStorage($data);
        $restored = $this->transformer->fromStorage($stored);
        $this->assertSame($data, $restored);
    }
}
```

- [ ] **Step 2: Write tests for DateTimeTransformer**

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Transform;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Transform\DateTimeTransformer;

final class DateTimeTransformerTest extends TestCase
{
    private DateTimeTransformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new DateTimeTransformer();
    }

    public function test_to_storage_formats_datetime(): void
    {
        $dt = new \DateTimeImmutable('2026-04-11 14:30:00');
        $result = $this->transformer->toStorage($dt);
        $this->assertSame('2026-04-11 14:30:00', $result);
    }

    public function test_from_storage_parses_datetime_string(): void
    {
        $result = $this->transformer->fromStorage('2026-04-11 14:30:00');
        $this->assertInstanceOf(\DateTimeImmutable::class, $result);
        $this->assertSame('2026-04-11 14:30:00', $result->format('Y-m-d H:i:s'));
    }

    public function test_from_storage_parses_iso8601(): void
    {
        $result = $this->transformer->fromStorage('2026-04-11T14:30:00+00:00');
        $this->assertInstanceOf(\DateTimeImmutable::class, $result);
        $this->assertSame('2026-04-11', $result->format('Y-m-d'));
    }

    public function test_to_storage_null_returns_null(): void
    {
        $this->assertNull($this->transformer->toStorage(null));
    }

    public function test_from_storage_null_returns_null(): void
    {
        $this->assertNull($this->transformer->fromStorage(null));
    }

    public function test_from_storage_empty_string_returns_null(): void
    {
        $this->assertNull($this->transformer->fromStorage(''));
    }

    public function test_to_storage_passes_through_non_datetime(): void
    {
        $this->assertSame('2026-04-11', $this->transformer->toStorage('2026-04-11'));
    }

    public function test_roundtrip(): void
    {
        $dt = new \DateTimeImmutable('2026-12-25 08:00:00');
        $stored = $this->transformer->toStorage($dt);
        $restored = $this->transformer->fromStorage($stored);
        $this->assertSame($dt->format('Y-m-d H:i:s'), $restored->format('Y-m-d H:i:s'));
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `vendor/bin/phpunit packages/data/tests/Transform/`
Expected: FAIL — classes not found

- [ ] **Step 4: Create FieldTransformer interface**

Create `packages/data/src/FieldTransformer.php`:

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

- [ ] **Step 5: Create JsonTransformer**

Create `packages/data/src/Transform/JsonTransformer.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Transform;

use Preflow\Data\FieldTransformer;

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

- [ ] **Step 6: Create DateTimeTransformer**

Create `packages/data/src/Transform/DateTimeTransformer.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Transform;

use Preflow\Data\FieldTransformer;

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
            $dt = new \DateTimeImmutable($value);
        }
        return $dt;
    }
}
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `vendor/bin/phpunit packages/data/tests/Transform/`
Expected: 14 tests, all PASS

- [ ] **Step 8: Commit**

```bash
git add packages/data/src/FieldTransformer.php packages/data/src/Transform/ packages/data/tests/Transform/
git commit -m "feat(data): add FieldTransformer interface, JsonTransformer, DateTimeTransformer"
```

---

### Task 7: Integrate transforms into Model and ModelMetadata

**Files:**
- Modify: `packages/data/src/ModelMetadata.php`
- Modify: `packages/data/src/Model.php`
- Test: `packages/data/tests/ModelTransformTest.php`

- [ ] **Step 1: Write tests for transformer integration**

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Model;
use Preflow\Data\ModelMetadata;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Transform\JsonTransformer;
use Preflow\Data\Transform\DateTimeTransformer;

#[Entity(table: 'articles')]
final class ArticleWithTransforms extends Model
{
    #[Id]
    public string $uuid = '';

    #[Field]
    public string $title = '';

    #[Field(transform: JsonTransformer::class)]
    public ?array $metadata = null;

    #[Field(transform: DateTimeTransformer::class)]
    public ?\DateTimeImmutable $publishedAt = null;
}

final class ModelTransformTest extends TestCase
{
    protected function setUp(): void
    {
        ModelMetadata::clearCache();
    }

    public function test_metadata_resolves_transformers(): void
    {
        $meta = ModelMetadata::for(ArticleWithTransforms::class);

        $this->assertArrayHasKey('metadata', $meta->transformers);
        $this->assertArrayHasKey('publishedAt', $meta->transformers);
        $this->assertInstanceOf(JsonTransformer::class, $meta->transformers['metadata']);
        $this->assertInstanceOf(DateTimeTransformer::class, $meta->transformers['publishedAt']);
        $this->assertArrayNotHasKey('title', $meta->transformers);
    }

    public function test_fill_applies_from_storage_transforms(): void
    {
        $article = new ArticleWithTransforms();
        $article->fill([
            'uuid' => 'test-1',
            'title' => 'Hello',
            'metadata' => '{"tags":["php","preflow"]}',
            'publishedAt' => '2026-04-11 14:30:00',
        ]);

        $this->assertSame(['tags' => ['php', 'preflow']], $article->metadata);
        $this->assertInstanceOf(\DateTimeImmutable::class, $article->publishedAt);
        $this->assertSame('2026-04-11 14:30:00', $article->publishedAt->format('Y-m-d H:i:s'));
        $this->assertSame('Hello', $article->title); // untransformed field
    }

    public function test_to_array_applies_to_storage_transforms(): void
    {
        $article = new ArticleWithTransforms();
        $article->uuid = 'test-1';
        $article->title = 'Hello';
        $article->metadata = ['tags' => ['php']];
        $article->publishedAt = new \DateTimeImmutable('2026-04-11 14:30:00');

        $data = $article->toArray();

        $this->assertSame('{"tags":["php"]}', $data['metadata']);
        $this->assertSame('2026-04-11 14:30:00', $data['publishedAt']);
        $this->assertSame('Hello', $data['title']);
    }

    public function test_fill_handles_null_transformed_fields(): void
    {
        $article = new ArticleWithTransforms();
        $article->fill([
            'uuid' => 'test-1',
            'title' => 'Hello',
            'metadata' => null,
            'publishedAt' => null,
        ]);

        $this->assertNull($article->metadata);
        $this->assertNull($article->publishedAt);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit packages/data/tests/ModelTransformTest.php`
Expected: FAIL — `transformers` property doesn't exist on ModelMetadata

- [ ] **Step 3: Update ModelMetadata to resolve transformers**

In `packages/data/src/ModelMetadata.php`, add a `transformers` property to the constructor (line 21-29). Add it after `hasTimestamps`:

```php
    private function __construct(
        public readonly string $modelClass,
        public readonly string $table,
        public readonly string $storage,
        public readonly string $idField,
        public readonly array $fields,
        public readonly array $searchableFields,
        public readonly bool $hasTimestamps,
        /** @var array<string, \Preflow\Data\FieldTransformer> */
        public readonly array $transformers = [],
    ) {}
```

In the `for()` method (line 34), after the field loop that builds `$fields` and `$searchableFields`, add transformer resolution:

```php
        $transformers = [];
        foreach ($fields as $name => $fieldAttr) {
            if ($fieldAttr->transform !== null) {
                $transformerClass = $fieldAttr->transform;
                if (!class_exists($transformerClass)) {
                    throw new \RuntimeException("Transformer class not found: {$transformerClass}");
                }
                $transformers[$name] = new $transformerClass();
            }
        }
```

Pass `$transformers` to the constructor call.

- [ ] **Step 4: Update Model::fill() to apply fromStorage transforms**

In `packages/data/src/Model.php`, replace `fill()` (line 14):

```php
    public function fill(array $data): void
    {
        $meta = ModelMetadata::for(static::class);

        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                if (isset($meta->transformers[$key])) {
                    $value = $meta->transformers[$key]->fromStorage($value);
                }
                $this->{$key} = $value;
            }
        }
    }
```

- [ ] **Step 5: Update Model::toArray() to apply toStorage transforms**

In `packages/data/src/Model.php`, replace `toArray()` (line 28):

```php
    public function toArray(): array
    {
        $meta = ModelMetadata::for(static::class);
        $ref = new \ReflectionClass($this);
        $data = [];

        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $name = $prop->getName();
            $value = $this->{$name};

            if (isset($meta->transformers[$name])) {
                $value = $meta->transformers[$name]->toStorage($value);
            }

            $data[$name] = $value;
        }

        return $data;
    }
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit packages/data/tests/ModelTransformTest.php`
Expected: 4 tests, all PASS

- [ ] **Step 7: Run full test suite**

Run: `vendor/bin/phpunit`
Expected: All pass — existing models without transforms are unaffected

- [ ] **Step 8: Commit**

```bash
git add packages/data/src/Model.php packages/data/src/ModelMetadata.php packages/data/tests/ModelTransformTest.php
git commit -m "feat(data): integrate field transformers into Model fill/toArray via ModelMetadata"
```

---

## Phase 3: Dynamic Models

### Task 8: TypeFieldDefinition and TypeDefinition

**Files:**
- Create: `packages/data/src/TypeFieldDefinition.php`
- Create: `packages/data/src/TypeDefinition.php`
- Test: `packages/data/tests/TypeDefinitionTest.php`

- [ ] **Step 1: Write tests**

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Transform\JsonTransformer;

final class TypeDefinitionTest extends TestCase
{
    public function test_stores_key_table_storage(): void
    {
        $type = new TypeDefinition(
            key: 'tournament',
            table: 'tournaments',
            storage: 'mysql',
            fields: [],
        );

        $this->assertSame('tournament', $type->key);
        $this->assertSame('tournaments', $type->table);
        $this->assertSame('mysql', $type->storage);
    }

    public function test_id_field_defaults_to_uuid(): void
    {
        $type = new TypeDefinition(key: 'x', table: 'x', storage: 'default', fields: []);
        $this->assertSame('uuid', $type->idField);
    }

    public function test_stores_fields(): void
    {
        $field = new TypeFieldDefinition(name: 'title', type: 'string', searchable: true);
        $type = new TypeDefinition(
            key: 'post',
            table: 'posts',
            storage: 'default',
            fields: ['title' => $field],
        );

        $this->assertArrayHasKey('title', $type->fields);
        $this->assertSame('string', $type->fields['title']->type);
        $this->assertTrue($type->fields['title']->searchable);
    }

    public function test_searchable_fields_populated(): void
    {
        $type = new TypeDefinition(
            key: 'post',
            table: 'posts',
            storage: 'default',
            fields: [
                'title' => new TypeFieldDefinition(name: 'title', searchable: true),
                'status' => new TypeFieldDefinition(name: 'status'),
            ],
            searchableFields: ['title'],
        );

        $this->assertSame(['title'], $type->searchableFields);
    }

    public function test_transformers_populated(): void
    {
        $transformer = new JsonTransformer();
        $type = new TypeDefinition(
            key: 'post',
            table: 'posts',
            storage: 'default',
            fields: [],
            transformers: ['metadata' => $transformer],
        );

        $this->assertArrayHasKey('metadata', $type->transformers);
        $this->assertInstanceOf(JsonTransformer::class, $type->transformers['metadata']);
    }

    public function test_field_definition_defaults(): void
    {
        $field = new TypeFieldDefinition(name: 'title');

        $this->assertSame('title', $field->name);
        $this->assertSame('string', $field->type);
        $this->assertFalse($field->searchable);
        $this->assertNull($field->transform);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit packages/data/tests/TypeDefinitionTest.php`
Expected: FAIL — classes not found

- [ ] **Step 3: Create TypeFieldDefinition**

Create `packages/data/src/TypeFieldDefinition.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data;

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

- [ ] **Step 4: Create TypeDefinition**

Create `packages/data/src/TypeDefinition.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data;

final readonly class TypeDefinition
{
    /**
     * @param array<string, TypeFieldDefinition> $fields
     * @param string[] $searchableFields
     * @param array<string, FieldTransformer> $transformers
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

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit packages/data/tests/TypeDefinitionTest.php`
Expected: 6 tests, all PASS

- [ ] **Step 6: Commit**

```bash
git add packages/data/src/TypeFieldDefinition.php packages/data/src/TypeDefinition.php packages/data/tests/TypeDefinitionTest.php
git commit -m "feat(data): add TypeDefinition and TypeFieldDefinition value objects"
```

---

### Task 9: TypeRegistry

**Files:**
- Create: `packages/data/src/TypeRegistry.php`
- Test: `packages/data/tests/TypeRegistryTest.php`

- [ ] **Step 1: Write tests**

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\TypeRegistry;
use Preflow\Data\TypeDefinition;
use Preflow\Data\Transform\JsonTransformer;
use Preflow\Data\Transform\DateTimeTransformer;

final class TypeRegistryTest extends TestCase
{
    private string $tmpDir;
    private TypeRegistry $registry;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/preflow_type_registry_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->registry = new TypeRegistry($this->tmpDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir . '/*.json'));
        rmdir($this->tmpDir);
    }

    private function writeSchema(string $name, array $schema): void
    {
        file_put_contents(
            $this->tmpDir . '/' . $name . '.json',
            json_encode($schema, JSON_PRETTY_PRINT),
        );
    }

    public function test_loads_type_from_json_file(): void
    {
        $this->writeSchema('tournament', [
            'key' => 'tournament',
            'table' => 'tournaments',
            'storage' => 'mysql',
            'fields' => [
                'title' => ['type' => 'string', 'searchable' => true],
                'max_players' => ['type' => 'integer'],
            ],
        ]);

        $type = $this->registry->get('tournament');

        $this->assertInstanceOf(TypeDefinition::class, $type);
        $this->assertSame('tournament', $type->key);
        $this->assertSame('tournaments', $type->table);
        $this->assertSame('mysql', $type->storage);
        $this->assertCount(2, $type->fields);
        $this->assertSame(['title'], $type->searchableFields);
    }

    public function test_resolves_transformers(): void
    {
        $this->writeSchema('article', [
            'key' => 'article',
            'table' => 'articles',
            'fields' => [
                'metadata' => [
                    'type' => 'json',
                    'transform' => 'Preflow\\Data\\Transform\\JsonTransformer',
                ],
                'published_at' => [
                    'type' => 'datetime',
                    'transform' => 'Preflow\\Data\\Transform\\DateTimeTransformer',
                ],
            ],
        ]);

        $type = $this->registry->get('article');

        $this->assertCount(2, $type->transformers);
        $this->assertInstanceOf(JsonTransformer::class, $type->transformers['metadata']);
        $this->assertInstanceOf(DateTimeTransformer::class, $type->transformers['published_at']);
    }

    public function test_storage_defaults_to_default(): void
    {
        $this->writeSchema('simple', [
            'key' => 'simple',
            'table' => 'simples',
            'fields' => ['name' => ['type' => 'string']],
        ]);

        $type = $this->registry->get('simple');
        $this->assertSame('default', $type->storage);
    }

    public function test_has_returns_true_for_existing_type(): void
    {
        $this->writeSchema('exists', [
            'key' => 'exists',
            'table' => 'exists',
            'fields' => [],
        ]);

        $this->assertTrue($this->registry->has('exists'));
    }

    public function test_has_returns_false_for_missing_type(): void
    {
        $this->assertFalse($this->registry->has('nonexistent'));
    }

    public function test_throws_for_unknown_type(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown type: missing');

        $this->registry->get('missing');
    }

    public function test_caches_loaded_types(): void
    {
        $this->writeSchema('cached', [
            'key' => 'cached',
            'table' => 'cached',
            'fields' => ['name' => ['type' => 'string']],
        ]);

        $first = $this->registry->get('cached');
        $second = $this->registry->get('cached');

        $this->assertSame($first, $second);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit packages/data/tests/TypeRegistryTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Create TypeRegistry**

Create `packages/data/src/TypeRegistry.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data;

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

        $fields = [];
        $searchableFields = [];
        $transformers = [];

        foreach ($schema['fields'] ?? [] as $name => $fieldDef) {
            $fieldType = $fieldDef['type'] ?? 'string';
            $searchable = $fieldDef['searchable'] ?? false;
            $transform = $fieldDef['transform'] ?? null;

            $fields[$name] = new TypeFieldDefinition(
                name: $name,
                type: $fieldType,
                searchable: $searchable,
                transform: $transform,
            );

            if ($searchable) {
                $searchableFields[] = $name;
            }

            if ($transform !== null) {
                if (!class_exists($transform)) {
                    throw new \RuntimeException("Transformer class not found: {$transform}");
                }
                $transformers[$name] = new $transform();
            }
        }

        return new TypeDefinition(
            key: $schema['key'],
            table: $schema['table'],
            storage: $schema['storage'] ?? 'default',
            fields: $fields,
            idField: $schema['id_field'] ?? 'uuid',
            searchableFields: $searchableFields,
            transformers: $transformers,
        );
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit packages/data/tests/TypeRegistryTest.php`
Expected: 7 tests, all PASS

- [ ] **Step 5: Commit**

```bash
git add packages/data/src/TypeRegistry.php packages/data/tests/TypeRegistryTest.php
git commit -m "feat(data): add TypeRegistry — loads and caches JSON schema definitions"
```

---

### Task 10: DynamicRecord

**Files:**
- Create: `packages/data/src/DynamicRecord.php`
- Test: `packages/data/tests/DynamicRecordTest.php`

- [ ] **Step 1: Write tests**

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Transform\JsonTransformer;
use Preflow\Data\Transform\DateTimeTransformer;

final class DynamicRecordTest extends TestCase
{
    private TypeDefinition $type;

    protected function setUp(): void
    {
        $this->type = new TypeDefinition(
            key: 'article',
            table: 'articles',
            storage: 'default',
            fields: [
                'title' => new TypeFieldDefinition(name: 'title'),
                'metadata' => new TypeFieldDefinition(name: 'metadata', type: 'json', transform: JsonTransformer::class),
                'published_at' => new TypeFieldDefinition(name: 'published_at', type: 'datetime', transform: DateTimeTransformer::class),
            ],
            transformers: [
                'metadata' => new JsonTransformer(),
                'published_at' => new DateTimeTransformer(),
            ],
        );
    }

    public function test_get_and_set(): void
    {
        $record = new DynamicRecord($this->type);
        $record->set('title', 'Hello');

        $this->assertSame('Hello', $record->get('title'));
    }

    public function test_get_returns_null_for_missing_field(): void
    {
        $record = new DynamicRecord($this->type);
        $this->assertNull($record->get('nonexistent'));
    }

    public function test_get_id(): void
    {
        $record = new DynamicRecord($this->type, ['uuid' => 'abc-123']);
        $this->assertSame('abc-123', $record->getId());
    }

    public function test_get_type(): void
    {
        $record = new DynamicRecord($this->type);
        $this->assertSame($this->type, $record->getType());
    }

    public function test_to_array_applies_to_storage_transforms(): void
    {
        $record = new DynamicRecord($this->type, [
            'uuid' => 'test-1',
            'title' => 'Hello',
            'metadata' => ['tags' => ['php']],
            'published_at' => new \DateTimeImmutable('2026-04-11 14:30:00'),
        ]);

        $data = $record->toArray();

        $this->assertSame('{"tags":["php"]}', $data['metadata']);
        $this->assertSame('2026-04-11 14:30:00', $data['published_at']);
        $this->assertSame('Hello', $data['title']);
    }

    public function test_from_array_applies_from_storage_transforms(): void
    {
        $record = DynamicRecord::fromArray($this->type, [
            'uuid' => 'test-1',
            'title' => 'Hello',
            'metadata' => '{"tags":["php"]}',
            'published_at' => '2026-04-11 14:30:00',
        ]);

        $this->assertSame(['tags' => ['php']], $record->get('metadata'));
        $this->assertInstanceOf(\DateTimeImmutable::class, $record->get('published_at'));
        $this->assertSame('Hello', $record->get('title'));
    }

    public function test_from_array_handles_null_transforms(): void
    {
        $record = DynamicRecord::fromArray($this->type, [
            'uuid' => 'test-1',
            'metadata' => null,
            'published_at' => null,
        ]);

        $this->assertNull($record->get('metadata'));
        $this->assertNull($record->get('published_at'));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit packages/data/tests/DynamicRecordTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Create DynamicRecord**

Create `packages/data/src/DynamicRecord.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data;

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

    public function getId(): ?string
    {
        return $this->data[$this->type->idField] ?? null;
    }

    public function getType(): TypeDefinition
    {
        return $this->type;
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

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit packages/data/tests/DynamicRecordTest.php`
Expected: 7 tests, all PASS

- [ ] **Step 5: Commit**

```bash
git add packages/data/src/DynamicRecord.php packages/data/tests/DynamicRecordTest.php
git commit -m "feat(data): add DynamicRecord — generic data container with transform support"
```

---

### Task 11: DataManager dynamic model methods

**Files:**
- Modify: `packages/data/src/DataManager.php`
- Modify: `packages/data/src/QueryBuilder.php`
- Test: `packages/data/tests/DataManagerDynamicTest.php`

- [ ] **Step 1: Write tests**

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Data\Driver\SqliteDriver;

final class DataManagerDynamicTest extends TestCase
{
    private DataManager $dm;
    private string $tmpDir;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec('CREATE TABLE events (
            uuid TEXT PRIMARY KEY,
            title TEXT,
            capacity INTEGER
        )');

        $driver = new SqliteDriver($this->pdo);

        $this->tmpDir = sys_get_temp_dir() . '/preflow_dm_dynamic_' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        file_put_contents($this->tmpDir . '/event.json', json_encode([
            'key' => 'event',
            'table' => 'events',
            'storage' => 'sqlite',
            'fields' => [
                'title' => ['type' => 'string', 'searchable' => true],
                'capacity' => ['type' => 'integer'],
            ],
        ]));

        $registry = new TypeRegistry($this->tmpDir);

        $this->dm = new DataManager(
            drivers: ['sqlite' => $driver, 'default' => $driver],
            typeRegistry: $registry,
        );
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir . '/*.json'));
        rmdir($this->tmpDir);
    }

    public function test_save_type_and_find_type(): void
    {
        $type = $this->dm->getTypeRegistry()->get('event');
        $record = new DynamicRecord($type, [
            'uuid' => 'evt-1',
            'title' => 'PHP Meetup',
            'capacity' => 50,
        ]);

        $this->dm->saveType($record);

        $found = $this->dm->findType('event', 'evt-1');
        $this->assertInstanceOf(DynamicRecord::class, $found);
        $this->assertSame('PHP Meetup', $found->get('title'));
        $this->assertSame('50', $found->get('capacity')); // SQLite returns strings
    }

    public function test_query_type(): void
    {
        $type = $this->dm->getTypeRegistry()->get('event');

        $this->dm->saveType(new DynamicRecord($type, ['uuid' => 'e1', 'title' => 'A', 'capacity' => 10]));
        $this->dm->saveType(new DynamicRecord($type, ['uuid' => 'e2', 'title' => 'B', 'capacity' => 20]));
        $this->dm->saveType(new DynamicRecord($type, ['uuid' => 'e3', 'title' => 'C', 'capacity' => 30]));

        $results = $this->dm->queryType('event')
            ->where('capacity', '>', 15)
            ->orderBy('title')
            ->get();

        $this->assertCount(2, $results);
        $items = $results->items();
        $this->assertInstanceOf(DynamicRecord::class, $items[0]);
        $this->assertSame('B', $items[0]->get('title'));
    }

    public function test_query_type_search(): void
    {
        $type = $this->dm->getTypeRegistry()->get('event');

        $this->dm->saveType(new DynamicRecord($type, ['uuid' => 'e1', 'title' => 'PHP Meetup', 'capacity' => 10]));
        $this->dm->saveType(new DynamicRecord($type, ['uuid' => 'e2', 'title' => 'JS Conference', 'capacity' => 20]));

        $results = $this->dm->queryType('event')->search('PHP')->get();

        $this->assertCount(1, $results);
        $this->assertSame('PHP Meetup', $results->first()->get('title'));
    }

    public function test_delete_type(): void
    {
        $type = $this->dm->getTypeRegistry()->get('event');
        $this->dm->saveType(new DynamicRecord($type, ['uuid' => 'del-1', 'title' => 'Gone', 'capacity' => 0]));

        $this->dm->deleteType('event', 'del-1');

        $this->assertNull($this->dm->findType('event', 'del-1'));
    }

    public function test_find_type_returns_null_for_missing(): void
    {
        $this->assertNull($this->dm->findType('event', 'nonexistent'));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit packages/data/tests/DataManagerDynamicTest.php`
Expected: FAIL — DataManager doesn't accept TypeRegistry

- [ ] **Step 3: Update DataManager constructor and add dynamic methods**

In `packages/data/src/DataManager.php`, update the constructor (line 12-15) to accept an optional TypeRegistry:

```php
    public function __construct(
        private readonly array $drivers,
        private readonly string $defaultDriver = 'default',
        private readonly ?TypeRegistry $typeRegistry = null,
    ) {}
```

Add a getter:

```php
    public function getTypeRegistry(): ?TypeRegistry
    {
        return $this->typeRegistry;
    }
```

Add the four dynamic model methods:

```php
    public function findType(string $type, string $id): ?DynamicRecord
    {
        $typeDef = $this->requireTypeRegistry()->get($type);
        $driver = $this->resolveDriver($typeDef->storage);
        $data = $driver->findOne($typeDef->table, $id);

        if ($data === null) {
            return null;
        }

        return DynamicRecord::fromArray($typeDef, $data);
    }

    public function queryType(string $type): QueryBuilder
    {
        $typeDef = $this->requireTypeRegistry()->get($type);
        $driver = $this->resolveDriver($typeDef->storage);

        return QueryBuilder::forType($driver, $typeDef);
    }

    public function saveType(DynamicRecord $record): void
    {
        $typeDef = $record->getType();
        $driver = $this->resolveDriver($typeDef->storage);
        $id = $record->getId();

        if ($id === null) {
            throw new \RuntimeException("DynamicRecord must have an ID ({$typeDef->idField}) before saving.");
        }

        $driver->save($typeDef->table, $id, $record->toArray());
    }

    public function deleteType(string $type, string $id): void
    {
        $typeDef = $this->requireTypeRegistry()->get($type);
        $driver = $this->resolveDriver($typeDef->storage);
        $driver->delete($typeDef->table, $id);
    }

    private function requireTypeRegistry(): TypeRegistry
    {
        if ($this->typeRegistry === null) {
            throw new \RuntimeException('TypeRegistry is not configured. Set data.models_path in config.');
        }
        return $this->typeRegistry;
    }
```

- [ ] **Step 4: Add QueryBuilder::forType() factory**

In `packages/data/src/QueryBuilder.php`, add a static factory method that creates a QueryBuilder for dynamic types. The key difference is that `get()` and `first()` return `DynamicRecord` instead of `Model`.

Add a nullable `TypeDefinition` property and alternate constructor:

```php
    private ?TypeDefinition $typeDef = null;

    public static function forType(StorageDriver $driver, TypeDefinition $typeDef): self
    {
        $builder = new self($driver, null, null);
        $builder->typeDef = $typeDef;
        return $builder;
    }
```

Update the constructor to allow nullable `$meta` and `$modelClass`:

```php
    public function __construct(
        private readonly StorageDriver $driver,
        private readonly ?ModelMetadata $meta,
        private readonly ?string $modelClass,
    ) {}
```

Update `search()` to use either `$meta->searchableFields` or `$typeDef->searchableFields`:

```php
    public function search(string $term, ?array $fields = null): self
    {
        $searchFields = $fields;
        if ($searchFields === null) {
            $searchFields = $this->meta?->searchableFields ?? $this->typeDef?->searchableFields ?? [];
        }
        $this->query->search($term, $searchFields);
        return $this;
    }
```

Update `get()` to return `DynamicRecord` results when `$typeDef` is set:

```php
    public function get(): ResultSet
    {
        $table = $this->meta?->table ?? $this->typeDef?->table;
        $result = $this->driver->findMany($table, $this->query);

        if ($this->typeDef !== null) {
            $items = array_map(
                fn (array $row) => DynamicRecord::fromArray($this->typeDef, $row),
                $result->items(),
            );
            return new ResultSet($items, $result->total());
        }

        $items = array_map(function (array $row) {
            $model = new ($this->modelClass)();
            $model->fill($row);
            return $model;
        }, $result->items());

        return new ResultSet($items, $result->total());
    }
```

Update `first()` similarly:

```php
    public function first(): mixed
    {
        $this->query->limit(1);
        $result = $this->get();
        return $result->first();
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit packages/data/tests/DataManagerDynamicTest.php`
Expected: 5 tests, all PASS

- [ ] **Step 6: Run full test suite**

Run: `vendor/bin/phpunit`
Expected: All pass — existing DataManager usage is unaffected (TypeRegistry defaults to null)

- [ ] **Step 7: Commit**

```bash
git add packages/data/src/DataManager.php packages/data/src/QueryBuilder.php packages/data/tests/DataManagerDynamicTest.php
git commit -m "feat(data): DataManager gains queryType/findType/saveType/deleteType for dynamic models"
```

---

## Phase 4: Application Integration

### Task 12: Update Application.php and skeleton config

**Files:**
- Modify: `packages/core/src/Application.php` (bootDataLayer method, lines 198-237)
- Modify: `packages/skeleton/config/data.php`
- Modify: `packages/skeleton/.env.example`

- [ ] **Step 1: Update bootDataLayer() to support MySQL and TypeRegistry**

In `packages/core/src/Application.php`, replace `bootDataLayer()` (lines 198-237):

```php
    private function bootDataLayer(): void
    {
        if (!class_exists(\Preflow\Data\DataManager::class)) {
            return;
        }

        $dataConfigPath = $this->basePath('config/data.php');
        if (!file_exists($dataConfigPath)) {
            return;
        }

        $dataConfig = require $dataConfigPath;
        $drivers = [];

        foreach ($dataConfig['drivers'] ?? [] as $name => $driverConfig) {
            $drivers[$name] = match ($name) {
                'sqlite' => $this->createSqliteDriver($driverConfig),
                'mysql' => $this->createMysqlDriver($driverConfig),
                'json' => new \Preflow\Data\Driver\JsonFileDriver(
                    $driverConfig['path'] ?? $this->basePath('storage/data'),
                ),
                default => null,
            };
        }

        $drivers = array_filter($drivers);

        $default = $dataConfig['default'] ?? 'sqlite';
        if (isset($drivers[$default])) {
            $drivers['default'] = $drivers[$default];
        }

        // TypeRegistry for dynamic models
        $typeRegistry = null;
        $modelsPath = $dataConfig['models_path'] ?? $this->basePath('config/models');
        if (is_dir($modelsPath)) {
            $typeRegistry = new \Preflow\Data\TypeRegistry($modelsPath);
            $this->container->instance(\Preflow\Data\TypeRegistry::class, $typeRegistry);
        }

        $dataManager = new \Preflow\Data\DataManager($drivers, 'default', $typeRegistry);
        $this->container->instance(\Preflow\Data\DataManager::class, $dataManager);
    }

    private function createSqliteDriver(array $config): \Preflow\Data\Driver\SqliteDriver
    {
        $path = $config['path'] ?? $this->basePath('storage/data/app.sqlite');
        $dbDir = dirname($path);
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0755, true);
        }
        $dsn = str_starts_with($path, 'sqlite:') ? $path : 'sqlite:' . $path;
        $pdo = new \PDO($dsn);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->container->instance(\PDO::class, $pdo);
        return new \Preflow\Data\Driver\SqliteDriver($pdo);
    }

    private function createMysqlDriver(array $config): \Preflow\Data\Driver\MysqlDriver
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['host'] ?? getenv('DB_HOST') ?: '127.0.0.1',
            $config['port'] ?? getenv('DB_PORT') ?: '3306',
            $config['database'] ?? getenv('DB_NAME') ?: '',
        );
        $pdo = new \PDO(
            $dsn,
            $config['username'] ?? getenv('DB_USER') ?: 'root',
            $config['password'] ?? getenv('DB_PASS') ?: '',
        );
        $this->container->instance(\PDO::class, $pdo);
        return new \Preflow\Data\Driver\MysqlDriver($pdo);
    }
```

- [ ] **Step 2: Update skeleton config/data.php**

Replace `packages/skeleton/config/data.php`:

```php
<?php

return [
    'drivers' => [
        'sqlite' => [
            'driver' => \Preflow\Data\Driver\SqliteDriver::class,
            'path' => __DIR__ . '/../storage/data/app.sqlite',
        ],
        'json' => [
            'driver' => \Preflow\Data\Driver\JsonFileDriver::class,
            'path' => __DIR__ . '/../storage/data',
        ],
        // 'mysql' => [
        //     'driver' => \Preflow\Data\Driver\MysqlDriver::class,
        //     'host' => getenv('DB_HOST') ?: '127.0.0.1',
        //     'port' => getenv('DB_PORT') ?: '3306',
        //     'database' => getenv('DB_NAME') ?: '',
        //     'username' => getenv('DB_USER') ?: 'root',
        //     'password' => getenv('DB_PASS') ?: '',
        // ],
    ],
    'default' => getenv('DB_DRIVER') ?: 'sqlite',
    'models_path' => __DIR__ . '/models',
];
```

- [ ] **Step 3: Update skeleton .env.example**

Add database connection variables:

```
DB_DRIVER=sqlite
DB_PATH=storage/data/app.sqlite
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_NAME=
# DB_USER=root
# DB_PASS=
```

- [ ] **Step 4: Create skeleton config/models/ directory**

```bash
mkdir -p packages/skeleton/config/models
```

Add a `.gitkeep` file so the empty directory is tracked:

```bash
touch packages/skeleton/config/models/.gitkeep
```

- [ ] **Step 5: Run full test suite**

Run: `vendor/bin/phpunit`
Expected: All pass

- [ ] **Step 6: Commit**

```bash
git add packages/core/src/Application.php packages/skeleton/config/data.php packages/skeleton/.env.example packages/skeleton/config/models/.gitkeep
git commit -m "feat: Application supports MySQL driver and TypeRegistry, skeleton config updated"
```

---

### Task 13: Update plans README

**Files:**
- Modify: `docs/superpowers/plans/README.md`

- [ ] **Step 1: Update Phase 5b status**

In `docs/superpowers/plans/README.md`, change line 12 from:

```markdown
| 5b | `preflow/data` (mysql, dynamic models, transformers) | TBD | Blocked on Phase 5a |
```

To:

```markdown
| 5b | `preflow/data` (mysql, dynamic models, transformers) | [2026-04-11-phase-5b-data-layer.md](2026-04-11-phase-5b-data-layer.md) | Ready |
```

- [ ] **Step 2: Commit**

```bash
git add docs/superpowers/plans/README.md
git commit -m "docs: update plans README with Phase 5b status"
```
