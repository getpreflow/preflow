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
