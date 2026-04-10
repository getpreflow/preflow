# Phase 6: preflow/htmx — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the hypermedia package (`preflow/htmx`) — the `HypermediaDriver` interface, HTMX default driver, HMAC-signed component tokens, the universal component endpoint, `Guarded` interface for component-level auth, and the `hd` Twig helper that makes templates driver-agnostic.

**Architecture:** The `HypermediaDriver` interface abstracts all hypermedia interactions (action attributes, event listening, response headers). The `HtmxDriver` implements it with HTMX-specific attributes and headers. `ComponentToken` signs and verifies payloads (class + props + action) with HMAC-SHA256. `ComponentEndpoint` handles all component HTTP requests through a multi-layer security pipeline. A Twig extension provides the `hd` template helper and auto-generates signed action URLs.

**Tech Stack:** PHP 8.5+, PHPUnit 11+, sodium (for base64), preflow/core, preflow/components

---

## File Structure

```
packages/htmx/
├── src/
│   ├── HypermediaDriver.php            — Interface for all hypermedia drivers
│   ├── SwapStrategy.php                — Enum: outerHTML, innerHTML, beforeend, etc.
│   ├── HtmlAttributes.php             — Value object for HTML attribute rendering
│   ├── ResponseHeaders.php            — Collects response headers for the current request
│   ├── HtmxDriver.php                 — HTMX implementation of HypermediaDriver
│   ├── ComponentToken.php             — HMAC-signed token encode/decode
│   ├── TokenPayload.php               — Decoded token value object
│   ├── ComponentEndpoint.php          — Universal HTTP handler for component actions
│   ├── Guarded.php                    — Interface for component-level authorization
│   ├── Twig/
│   │   └── HdExtension.php           — {{ hd.post('action') }} Twig helper
├── tests/
│   ├── HtmlAttributesTest.php
│   ├── HtmxDriverTest.php
│   ├── ComponentTokenTest.php
│   ├── ComponentEndpointTest.php
│   ├── Twig/
│   │   └── HdExtensionTest.php
└── composer.json
```

---

### Task 1: Package Scaffolding

**Files:**
- Create: `packages/htmx/composer.json`
- Modify: `composer.json` (root)
- Modify: `phpunit.xml`

- [ ] **Step 1: Create packages/htmx/composer.json**

```json
{
    "name": "preflow/htmx",
    "description": "Preflow HTMX — hypermedia driver, component tokens, component endpoint",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.4",
        "ext-sodium": "*",
        "preflow/core": "^0.1 || @dev",
        "preflow/components": "^0.1 || @dev"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0",
        "twig/twig": "^3.0"
    },
    "autoload": {
        "psr-4": {
            "Preflow\\Htmx\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Preflow\\Htmx\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 2: Update root composer.json**

Add to `repositories`:
```json
{ "type": "path", "url": "packages/htmx", "options": { "symlink": true } }
```
Add `"preflow/htmx": "@dev"` to `require-dev`.

- [ ] **Step 3: Update phpunit.xml**

Add testsuite `Htmx` pointing to `packages/htmx/tests`. Add `packages/htmx/src` to source include.

- [ ] **Step 4: Create directories and install**

```bash
mkdir -p packages/htmx/src/Twig packages/htmx/tests/Twig
composer update
```

- [ ] **Step 5: Commit**

```bash
git add packages/htmx/composer.json composer.json phpunit.xml
git commit -m "feat: scaffold preflow/htmx package"
```

---

### Task 2: SwapStrategy + HtmlAttributes + ResponseHeaders

**Files:**
- Create: `packages/htmx/src/SwapStrategy.php`
- Create: `packages/htmx/src/HtmlAttributes.php`
- Create: `packages/htmx/src/ResponseHeaders.php`
- Create: `packages/htmx/tests/HtmlAttributesTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/htmx/tests/HtmlAttributesTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Htmx\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Htmx\HtmlAttributes;

final class HtmlAttributesTest extends TestCase
{
    public function test_renders_single_attribute(): void
    {
        $attrs = new HtmlAttributes(['hx-get' => '/api/data']);

        $this->assertSame('hx-get="/api/data"', (string) $attrs);
    }

    public function test_renders_multiple_attributes(): void
    {
        $attrs = new HtmlAttributes([
            'hx-post' => '/action',
            'hx-target' => '#result',
            'hx-swap' => 'outerHTML',
        ]);

        $result = (string) $attrs;

        $this->assertStringContainsString('hx-post="/action"', $result);
        $this->assertStringContainsString('hx-target="#result"', $result);
        $this->assertStringContainsString('hx-swap="outerHTML"', $result);
    }

    public function test_escapes_values(): void
    {
        $attrs = new HtmlAttributes(['data-name' => 'O\'Brien & Co']);

        $result = (string) $attrs;

        $this->assertStringContainsString('data-name="O&#039;Brien &amp; Co"', $result);
    }

    public function test_merge(): void
    {
        $a = new HtmlAttributes(['hx-get' => '/a', 'hx-target' => '#x']);
        $b = new HtmlAttributes(['hx-swap' => 'innerHTML']);

        $merged = $a->merge($b);

        $result = (string) $merged;
        $this->assertStringContainsString('hx-get="/a"', $result);
        $this->assertStringContainsString('hx-swap="innerHTML"', $result);
    }

    public function test_empty_renders_empty_string(): void
    {
        $attrs = new HtmlAttributes([]);

        $this->assertSame('', (string) $attrs);
    }

    public function test_to_array(): void
    {
        $attrs = new HtmlAttributes(['hx-get' => '/test']);

        $this->assertSame(['hx-get' => '/test'], $attrs->toArray());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit packages/htmx/tests/HtmlAttributesTest.php
```

- [ ] **Step 3: Create SwapStrategy enum**

Create `packages/htmx/src/SwapStrategy.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Htmx;

enum SwapStrategy: string
{
    case OuterHTML = 'outerHTML';
    case InnerHTML = 'innerHTML';
    case BeforeBegin = 'beforebegin';
    case AfterBegin = 'afterbegin';
    case BeforeEnd = 'beforeend';
    case AfterEnd = 'afterend';
    case Delete = 'delete';
    case None = 'none';
}
```

- [ ] **Step 4: Implement HtmlAttributes**

Create `packages/htmx/src/HtmlAttributes.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Htmx;

final readonly class HtmlAttributes implements \Stringable
{
    /**
     * @param array<string, string> $attributes
     */
    public function __construct(
        private array $attributes = [],
    ) {}

    public function merge(self $other): self
    {
        return new self(array_merge($this->attributes, $other->attributes));
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    public function __toString(): string
    {
        if ($this->attributes === []) {
            return '';
        }

        $parts = [];
        foreach ($this->attributes as $name => $value) {
            $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $parts[] = "{$name}=\"{$escaped}\"";
        }

        return implode(' ', $parts);
    }
}
```

- [ ] **Step 5: Create ResponseHeaders**

Create `packages/htmx/src/ResponseHeaders.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Htmx;

final class ResponseHeaders
{
    /** @var array<string, string> */
    private array $headers = [];

    public function set(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }

    public function get(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->headers;
    }

    public function clear(): void
    {
        $this->headers = [];
    }
}
```

- [ ] **Step 6: Run tests**

```bash
./vendor/bin/phpunit packages/htmx/tests/HtmlAttributesTest.php
```

Expected: All 6 tests pass.

- [ ] **Step 7: Commit**

```bash
git add packages/htmx/src/SwapStrategy.php packages/htmx/src/HtmlAttributes.php packages/htmx/src/ResponseHeaders.php packages/htmx/tests/HtmlAttributesTest.php
git commit -m "feat(htmx): add SwapStrategy, HtmlAttributes, ResponseHeaders"
```

---

### Task 3: HypermediaDriver Interface + HtmxDriver

**Files:**
- Create: `packages/htmx/src/HypermediaDriver.php`
- Create: `packages/htmx/src/HtmxDriver.php`
- Create: `packages/htmx/tests/HtmxDriverTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/htmx/tests/HtmxDriverTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Htmx\Tests;

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Preflow\Htmx\HtmxDriver;
use Preflow\Htmx\HtmlAttributes;
use Preflow\Htmx\ResponseHeaders;
use Preflow\Htmx\SwapStrategy;

final class HtmxDriverTest extends TestCase
{
    private ResponseHeaders $headers;
    private HtmxDriver $driver;

    protected function setUp(): void
    {
        $this->headers = new ResponseHeaders();
        $this->driver = new HtmxDriver($this->headers);
    }

    private function createRequest(array $headers = []): ServerRequestInterface
    {
        $request = (new Psr17Factory())->createServerRequest('GET', '/');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        return $request;
    }

    public function test_action_attrs_generates_hx_post(): void
    {
        $attrs = $this->driver->actionAttrs('post', '/action', 'comp-123', SwapStrategy::OuterHTML);

        $result = (string) $attrs;
        $this->assertStringContainsString('hx-post="/action"', $result);
        $this->assertStringContainsString('hx-target="#comp-123"', $result);
        $this->assertStringContainsString('hx-swap="outerHTML"', $result);
    }

    public function test_action_attrs_generates_hx_get(): void
    {
        $attrs = $this->driver->actionAttrs('get', '/data', 'target-1', SwapStrategy::InnerHTML);

        $result = (string) $attrs;
        $this->assertStringContainsString('hx-get="/data"', $result);
        $this->assertStringContainsString('hx-swap="innerHTML"', $result);
    }

    public function test_action_attrs_with_extra(): void
    {
        $attrs = $this->driver->actionAttrs('post', '/act', 'c-1', SwapStrategy::OuterHTML, [
            'hx-confirm' => 'Are you sure?',
        ]);

        $result = (string) $attrs;
        $this->assertStringContainsString('hx-confirm="Are you sure?"', $result);
    }

    public function test_listen_attrs_generates_trigger(): void
    {
        $attrs = $this->driver->listenAttrs('cartUpdated', '/refresh', 'cart-1');

        $result = (string) $attrs;
        $this->assertStringContainsString('hx-trigger="cartUpdated from:body"', $result);
        $this->assertStringContainsString('hx-get="/refresh"', $result);
        $this->assertStringContainsString('hx-target="#cart-1"', $result);
    }

    public function test_trigger_event_sets_header(): void
    {
        $this->driver->triggerEvent('itemAdded');

        $this->assertSame('itemAdded', $this->headers->get('HX-Trigger'));
    }

    public function test_redirect_sets_header(): void
    {
        $this->driver->redirect('/dashboard');

        $this->assertSame('/dashboard', $this->headers->get('HX-Redirect'));
    }

    public function test_push_url_sets_header(): void
    {
        $this->driver->pushUrl('/new-page');

        $this->assertSame('/new-page', $this->headers->get('HX-Push-Url'));
    }

    public function test_is_fragment_request_true(): void
    {
        $request = $this->createRequest(['HX-Request' => 'true']);

        $this->assertTrue($this->driver->isFragmentRequest($request));
    }

    public function test_is_fragment_request_false(): void
    {
        $request = $this->createRequest();

        $this->assertFalse($this->driver->isFragmentRequest($request));
    }

    public function test_asset_tag_returns_script(): void
    {
        $tag = $this->driver->assetTag();

        $this->assertStringContainsString('<script', $tag);
        $this->assertStringContainsString('htmx.org', $tag);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit packages/htmx/tests/HtmxDriverTest.php
```

- [ ] **Step 3: Create HypermediaDriver interface**

Create `packages/htmx/src/HypermediaDriver.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Htmx;

use Psr\Http\Message\ServerRequestInterface;

interface HypermediaDriver
{
    public function actionAttrs(
        string $method,
        string $url,
        string $targetId,
        SwapStrategy $swap,
        array $extra = [],
    ): HtmlAttributes;

    public function listenAttrs(
        string $event,
        string $url,
        string $targetId,
    ): HtmlAttributes;

    public function triggerEvent(string $event): void;

    public function redirect(string $url): void;

    public function pushUrl(string $url): void;

    public function isFragmentRequest(ServerRequestInterface $request): bool;

    public function assetTag(): string;
}
```

- [ ] **Step 4: Implement HtmxDriver**

Create `packages/htmx/src/HtmxDriver.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Htmx;

use Psr\Http\Message\ServerRequestInterface;

final class HtmxDriver implements HypermediaDriver
{
    public function __construct(
        private readonly ResponseHeaders $responseHeaders,
    ) {}

    public function actionAttrs(
        string $method,
        string $url,
        string $targetId,
        SwapStrategy $swap,
        array $extra = [],
    ): HtmlAttributes {
        return new HtmlAttributes(array_merge([
            "hx-{$method}" => $url,
            'hx-target' => "#{$targetId}",
            'hx-swap' => $swap->value,
        ], $extra));
    }

    public function listenAttrs(
        string $event,
        string $url,
        string $targetId,
    ): HtmlAttributes {
        return new HtmlAttributes([
            'hx-trigger' => "{$event} from:body",
            'hx-get' => $url,
            'hx-target' => "#{$targetId}",
            'hx-swap' => SwapStrategy::OuterHTML->value,
        ]);
    }

    public function triggerEvent(string $event): void
    {
        $this->responseHeaders->set('HX-Trigger', $event);
    }

    public function redirect(string $url): void
    {
        $this->responseHeaders->set('HX-Redirect', $url);
    }

    public function pushUrl(string $url): void
    {
        $this->responseHeaders->set('HX-Push-Url', $url);
    }

    public function isFragmentRequest(ServerRequestInterface $request): bool
    {
        return $request->hasHeader('HX-Request');
    }

    public function assetTag(): string
    {
        return '<script src="https://unpkg.com/htmx.org@2" defer></script>';
    }
}
```

- [ ] **Step 5: Run tests**

```bash
./vendor/bin/phpunit packages/htmx/tests/HtmxDriverTest.php
```

Expected: All 10 tests pass.

- [ ] **Step 6: Commit**

```bash
git add packages/htmx/src/HypermediaDriver.php packages/htmx/src/HtmxDriver.php packages/htmx/tests/HtmxDriverTest.php
git commit -m "feat(htmx): add HypermediaDriver interface and HtmxDriver implementation"
```

---

### Task 4: ComponentToken + TokenPayload

**Files:**
- Create: `packages/htmx/src/TokenPayload.php`
- Create: `packages/htmx/src/ComponentToken.php`
- Create: `packages/htmx/tests/ComponentTokenTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/htmx/tests/ComponentTokenTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Htmx\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Htmx\ComponentToken;
use Preflow\Htmx\TokenPayload;
use Preflow\Core\Exceptions\SecurityException;

final class ComponentTokenTest extends TestCase
{
    private ComponentToken $token;

    protected function setUp(): void
    {
        $this->token = new ComponentToken('test-secret-key-32-chars-long!!');
    }

    public function test_encode_returns_string(): void
    {
        $encoded = $this->token->encode('App\\Components\\Hero', ['id' => '1']);

        $this->assertIsString($encoded);
        $this->assertNotEmpty($encoded);
    }

    public function test_decode_returns_payload(): void
    {
        $encoded = $this->token->encode('App\\Components\\Hero', ['id' => '1'], 'refresh');

        $payload = $this->token->decode($encoded);

        $this->assertInstanceOf(TokenPayload::class, $payload);
        $this->assertSame('App\\Components\\Hero', $payload->componentClass);
        $this->assertSame(['id' => '1'], $payload->props);
        $this->assertSame('refresh', $payload->action);
    }

    public function test_round_trip_preserves_data(): void
    {
        $class = 'App\\Widgets\\GameCard';
        $props = ['game_id' => '42', 'category' => 'strategy'];
        $action = 'toggle';

        $encoded = $this->token->encode($class, $props, $action);
        $decoded = $this->token->decode($encoded);

        $this->assertSame($class, $decoded->componentClass);
        $this->assertSame($props, $decoded->props);
        $this->assertSame($action, $decoded->action);
    }

    public function test_default_action_is_render(): void
    {
        $encoded = $this->token->encode('App\\X');

        $decoded = $this->token->decode($encoded);

        $this->assertSame('render', $decoded->action);
    }

    public function test_tampered_token_throws(): void
    {
        $encoded = $this->token->encode('App\\X');

        // Tamper with the token
        $tampered = $encoded . 'x';

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('Invalid component token');
        $this->token->decode($tampered);
    }

    public function test_wrong_key_throws(): void
    {
        $encoded = $this->token->encode('App\\X');

        $otherToken = new ComponentToken('different-secret-key-32-chars!!');

        $this->expectException(SecurityException::class);
        $this->token = $otherToken;
        $otherToken->decode($encoded);
    }

    public function test_expired_token_throws(): void
    {
        // Create a token encoder that produces expired timestamps
        $token = new class('test-secret-key-32-chars-long!!') extends ComponentToken {
            protected function currentTime(): int
            {
                return time() - 100000; // 100k seconds ago
            }
        };

        $encoded = $token->encode('App\\X');

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('expired');
        $this->token->decode($encoded, maxAge: 3600);
    }

    public function test_non_expired_token_passes(): void
    {
        $encoded = $this->token->encode('App\\X');

        $decoded = $this->token->decode($encoded, maxAge: 86400);

        $this->assertSame('App\\X', $decoded->componentClass);
    }

    public function test_timestamp_included(): void
    {
        $before = time();
        $encoded = $this->token->encode('App\\X');
        $after = time();

        $decoded = $this->token->decode($encoded);

        $this->assertGreaterThanOrEqual($before, $decoded->timestamp);
        $this->assertLessThanOrEqual($after, $decoded->timestamp);
    }

    public function test_empty_props_encoded(): void
    {
        $encoded = $this->token->encode('App\\X', []);
        $decoded = $this->token->decode($encoded);

        $this->assertSame([], $decoded->props);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit packages/htmx/tests/ComponentTokenTest.php
```

- [ ] **Step 3: Create TokenPayload**

Create `packages/htmx/src/TokenPayload.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Htmx;

final readonly class TokenPayload
{
    /**
     * @param array<string, mixed> $props
     */
    public function __construct(
        public string $componentClass,
        public array $props,
        public string $action,
        public int $timestamp,
    ) {}
}
```

- [ ] **Step 4: Implement ComponentToken**

Create `packages/htmx/src/ComponentToken.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Htmx;

use Preflow\Core\Exceptions\SecurityException;

class ComponentToken
{
    public function __construct(
        private readonly string $secretKey,
        private readonly string $algorithm = 'sha256',
    ) {}

    /**
     * @param array<string, mixed> $props
     */
    public function encode(
        string $componentClass,
        array $props = [],
        string $action = 'render',
    ): string {
        $payload = json_encode([
            'c' => $componentClass,
            'p' => $props,
            'a' => $action,
            't' => $this->currentTime(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $signature = hash_hmac($this->algorithm, $payload, $this->secretKey);

        return sodium_bin2base64(
            $payload . '.' . $signature,
            SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING,
        );
    }

    public function decode(string $token, ?int $maxAge = null): TokenPayload
    {
        try {
            $decoded = sodium_base642bin($token, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (\SodiumException) {
            throw new SecurityException('Invalid component token format.');
        }

        $lastDot = strrpos($decoded, '.');
        if ($lastDot === false) {
            throw new SecurityException('Invalid component token structure.');
        }

        $payload = substr($decoded, 0, $lastDot);
        $signature = substr($decoded, $lastDot + 1);

        $expected = hash_hmac($this->algorithm, $payload, $this->secretKey);

        if (!hash_equals($expected, $signature)) {
            throw new SecurityException('Invalid component token signature.');
        }

        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        if ($maxAge !== null && (time() - ($data['t'] ?? 0)) > $maxAge) {
            throw new SecurityException('Component token expired.');
        }

        return new TokenPayload(
            componentClass: $data['c'],
            props: $data['p'] ?? [],
            action: $data['a'] ?? 'render',
            timestamp: $data['t'] ?? 0,
        );
    }

    protected function currentTime(): int
    {
        return time();
    }
}
```

- [ ] **Step 5: Run tests**

```bash
./vendor/bin/phpunit packages/htmx/tests/ComponentTokenTest.php
```

Expected: All 10 tests pass.

- [ ] **Step 6: Commit**

```bash
git add packages/htmx/src/TokenPayload.php packages/htmx/src/ComponentToken.php packages/htmx/tests/ComponentTokenTest.php
git commit -m "feat(htmx): add ComponentToken with HMAC-SHA256 signing and verification"
```

---

### Task 5: Guarded Interface + ComponentEndpoint

**Files:**
- Create: `packages/htmx/src/Guarded.php`
- Create: `packages/htmx/src/ComponentEndpoint.php`
- Create: `packages/htmx/tests/ComponentEndpointTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/htmx/tests/ComponentEndpointTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Htmx\Tests;

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ServerRequestInterface;
use Preflow\Components\Component;
use Preflow\Components\ComponentRenderer;
use Preflow\Components\ErrorBoundary;
use Preflow\Core\Exceptions\ForbiddenHttpException;
use Preflow\Core\Exceptions\SecurityException;
use Preflow\Htmx\ComponentEndpoint;
use Preflow\Htmx\ComponentToken;
use Preflow\Htmx\Guarded;
use Preflow\Htmx\HtmxDriver;
use Preflow\Htmx\ResponseHeaders;
use Preflow\View\TemplateEngineInterface;

class EndpointTestComponent extends Component
{
    public string $title = 'Test';

    public function resolveState(): void
    {
        $this->title = $this->props['title'] ?? 'Default';
    }

    public function actions(): array
    {
        return ['refresh'];
    }

    public function actionRefresh(array $params = []): void
    {
        $this->title = 'Refreshed';
    }
}

class GuardedComponent extends Component implements Guarded
{
    public function actions(): array
    {
        return ['admin'];
    }

    public function actionAdmin(array $params = []): void {}

    public function authorize(string $action, ServerRequestInterface $request): void
    {
        if (!$request->hasHeader('X-Admin')) {
            throw new ForbiddenHttpException('Admin required');
        }
    }
}

class EndpointFakeEngine implements TemplateEngineInterface
{
    public function render(string $template, array $context = []): string
    {
        return '<p>' . ($context['title'] ?? 'no title') . '</p>';
    }

    public function exists(string $template): bool
    {
        return true;
    }
}

final class ComponentEndpointTest extends TestCase
{
    private ComponentToken $tokenService;
    private ComponentEndpoint $endpoint;
    private ResponseHeaders $responseHeaders;

    protected function setUp(): void
    {
        $this->tokenService = new ComponentToken('test-secret-key-32-chars-long!!');
        $this->responseHeaders = new ResponseHeaders();

        $engine = new EndpointFakeEngine();
        $renderer = new ComponentRenderer($engine, new ErrorBoundary(debug: true));

        $this->endpoint = new ComponentEndpoint(
            token: $this->tokenService,
            renderer: $renderer,
            driver: new HtmxDriver($this->responseHeaders),
            componentFactory: fn (string $class, array $props) => $this->makeComponent($class, $props),
        );
    }

    private function makeComponent(string $class, array $props): Component
    {
        $component = new $class();
        $component->setProps($props);
        return $component;
    }

    private function createRequest(
        string $method,
        string $uri,
        array $query = [],
        array $body = [],
        array $headers = [],
    ): ServerRequestInterface {
        $factory = new Psr17Factory();
        $uriObj = $factory->createUri($uri);
        if ($query) {
            $uriObj = $uriObj->withQuery(http_build_query($query));
        }
        $request = $factory->createServerRequest($method, $uriObj);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body) {
            $request = $request->withParsedBody($body);
        }
        return $request;
    }

    public function test_render_action_returns_html(): void
    {
        $token = $this->tokenService->encode(EndpointTestComponent::class, ['title' => 'Hello']);

        $request = $this->createRequest('GET', '/--component/render', ['token' => $token]);
        $response = $this->endpoint->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Hello', $body);
    }

    public function test_action_dispatch_and_rerender(): void
    {
        $token = $this->tokenService->encode(
            EndpointTestComponent::class,
            ['title' => 'Before'],
            'refresh',
        );

        $request = $this->createRequest('POST', '/--component/action', ['token' => $token]);
        $response = $this->endpoint->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Refreshed', $body);
    }

    public function test_invalid_token_returns_403(): void
    {
        $request = $this->createRequest('POST', '/--component/action', ['token' => 'tampered-garbage']);

        $this->expectException(SecurityException::class);
        $this->endpoint->handle($request);
    }

    public function test_non_component_class_throws(): void
    {
        $token = $this->tokenService->encode(\stdClass::class);

        $request = $this->createRequest('GET', '/--component/render', ['token' => $token]);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('Invalid component class');
        $this->endpoint->handle($request);
    }

    public function test_unlisted_action_throws(): void
    {
        $token = $this->tokenService->encode(EndpointTestComponent::class, [], 'delete');

        $request = $this->createRequest('POST', '/--component/action', ['token' => $token]);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('not allowed');
        $this->endpoint->handle($request);
    }

    public function test_guarded_component_authorized(): void
    {
        $token = $this->tokenService->encode(GuardedComponent::class, [], 'admin');

        $request = $this->createRequest('POST', '/--component/action', ['token' => $token], headers: [
            'X-Admin' => 'true',
        ]);

        $response = $this->endpoint->handle($request);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_guarded_component_unauthorized(): void
    {
        $token = $this->tokenService->encode(GuardedComponent::class, [], 'admin');

        $request = $this->createRequest('POST', '/--component/action', ['token' => $token]);

        $this->expectException(ForbiddenHttpException::class);
        $this->endpoint->handle($request);
    }

    public function test_response_includes_driver_headers(): void
    {
        $token = $this->tokenService->encode(EndpointTestComponent::class, [], 'refresh');

        $request = $this->createRequest('POST', '/--component/action', ['token' => $token]);
        $response = $this->endpoint->handle($request);

        // Response headers from the driver should be in the response
        $this->assertSame(200, $response->getStatusCode());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit packages/htmx/tests/ComponentEndpointTest.php
```

- [ ] **Step 3: Create Guarded interface**

Create `packages/htmx/src/Guarded.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Htmx;

use Psr\Http\Message\ServerRequestInterface;

interface Guarded
{
    /**
     * Authorize the given action. Throw an exception if unauthorized.
     *
     * @throws \Preflow\Core\Exceptions\ForbiddenHttpException
     */
    public function authorize(string $action, ServerRequestInterface $request): void;
}
```

- [ ] **Step 4: Implement ComponentEndpoint**

Create `packages/htmx/src/ComponentEndpoint.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Htmx;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Preflow\Components\Component;
use Preflow\Components\ComponentRenderer;
use Preflow\Core\Exceptions\SecurityException;

final class ComponentEndpoint
{
    /** @var callable(string, array): Component */
    private $componentFactory;

    /**
     * @param callable(string $class, array $props): Component $componentFactory
     */
    public function __construct(
        private readonly ComponentToken $token,
        private readonly ComponentRenderer $renderer,
        private readonly HypermediaDriver $driver,
        callable $componentFactory,
    ) {
        $this->componentFactory = $componentFactory;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Extract token from query or body
        $queryParams = $request->getQueryParams();
        $bodyParams = $request->getParsedBody() ?? [];
        $tokenString = $queryParams['token'] ?? $bodyParams['token'] ?? null;

        if ($tokenString === null) {
            throw new SecurityException('Missing component token.');
        }

        // Layer 1: Token signature verification
        $payload = $this->token->decode($tokenString, maxAge: 86400);

        // Layer 2: Class validation
        if (!class_exists($payload->componentClass)
            || !is_subclass_of($payload->componentClass, Component::class)) {
            throw new SecurityException(
                "Invalid component class [{$payload->componentClass}]."
            );
        }

        // Create component via factory (supports DI)
        $component = ($this->componentFactory)($payload->componentClass, $payload->props);

        // Layer 3: Action whitelist
        if ($payload->action !== 'render'
            && !in_array($payload->action, $component->actions(), true)) {
            throw new SecurityException(
                "Action [{$payload->action}] is not allowed on [{$payload->componentClass}]."
            );
        }

        // Layer 4: Component-level guards
        if ($component instanceof Guarded && $payload->action !== 'render') {
            $component->authorize($payload->action, $request);
        }

        // Dispatch action
        if ($payload->action !== 'render') {
            $params = is_array($bodyParams) ? $bodyParams : [];
            $component->handleAction($payload->action, $params);
        }

        // Render fragment (no wrapper — HTMX replaces the outer element)
        $html = $this->renderer->render($component);

        // Build response with driver headers
        $response = new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $html);

        if ($this->driver instanceof HtmxDriver) {
            $headers = $this->getDriverHeaders();
            foreach ($headers as $name => $value) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function getDriverHeaders(): array
    {
        // Access response headers via reflection or direct access
        // The ResponseHeaders object is shared with the driver
        $ref = new \ReflectionClass($this->driver);
        $prop = $ref->getProperty('responseHeaders');
        $headers = $prop->getValue($this->driver);

        return $headers instanceof ResponseHeaders ? $headers->all() : [];
    }
}
```

- [ ] **Step 5: Run tests**

```bash
./vendor/bin/phpunit packages/htmx/tests/ComponentEndpointTest.php
```

Expected: All 8 tests pass.

- [ ] **Step 6: Commit**

```bash
git add packages/htmx/src/Guarded.php packages/htmx/src/ComponentEndpoint.php packages/htmx/tests/ComponentEndpointTest.php
git commit -m "feat(htmx): add ComponentEndpoint with multi-layer security and Guarded interface"
```

---

### Task 6: HdExtension — Twig `hd` Helper

**Files:**
- Create: `packages/htmx/src/Twig/HdExtension.php`
- Create: `packages/htmx/tests/Twig/HdExtensionTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/htmx/tests/Twig/HdExtensionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Htmx\Tests\Twig;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Preflow\Htmx\ComponentToken;
use Preflow\Htmx\HtmxDriver;
use Preflow\Htmx\ResponseHeaders;
use Preflow\Htmx\SwapStrategy;
use Preflow\Htmx\Twig\HdExtension;

final class HdExtensionTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $headers = new ResponseHeaders();
        $driver = new HtmxDriver($headers);
        $token = new ComponentToken('test-key-for-hd-extension-32ch!');

        $extension = new HdExtension($driver, $token, '/--component');

        $this->twig = new Environment(new ArrayLoader([]), ['autoescape' => false]);
        $this->twig->addExtension($extension);
    }

    private function render(string $template, array $context = []): string
    {
        return $this->twig->createTemplate($template)->render($context);
    }

    public function test_hd_post_generates_attributes(): void
    {
        $result = $this->render(
            "{{ hd.post('refresh', componentClass, componentId, props) }}",
            [
                'componentClass' => 'App\\Hero',
                'componentId' => 'hero-abc',
                'props' => [],
            ]
        );

        $this->assertStringContainsString('hx-post=', $result);
        $this->assertStringContainsString('hx-target="#hero-abc"', $result);
        $this->assertStringContainsString('hx-swap="outerHTML"', $result);
        $this->assertStringContainsString('token=', $result);
    }

    public function test_hd_get_generates_attributes(): void
    {
        $result = $this->render(
            "{{ hd.get('render', componentClass, componentId, props) }}",
            [
                'componentClass' => 'App\\Hero',
                'componentId' => 'hero-abc',
                'props' => [],
            ]
        );

        $this->assertStringContainsString('hx-get=', $result);
    }

    public function test_hd_on_generates_listen_attributes(): void
    {
        $result = $this->render(
            "{{ hd.on('cartUpdated', componentClass, componentId, props) }}",
            [
                'componentClass' => 'App\\Cart',
                'componentId' => 'cart-1',
                'props' => [],
            ]
        );

        $this->assertStringContainsString('hx-trigger="cartUpdated from:body"', $result);
        $this->assertStringContainsString('hx-get=', $result);
        $this->assertStringContainsString('hx-target="#cart-1"', $result);
    }

    public function test_hd_asset_tag(): void
    {
        $result = $this->render("{{ hd.assetTag() }}");

        $this->assertStringContainsString('htmx.org', $result);
    }

    public function test_token_is_signed_in_url(): void
    {
        $result = $this->render(
            "{{ hd.post('refresh', componentClass, componentId, props) }}",
            [
                'componentClass' => 'App\\Hero',
                'componentId' => 'hero-1',
                'props' => ['id' => '42'],
            ]
        );

        // URL should contain a token parameter
        $this->assertStringContainsString('/--component', $result);
        $this->assertStringContainsString('token=', $result);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit packages/htmx/tests/Twig/HdExtensionTest.php
```

- [ ] **Step 3: Implement HdExtension**

Create `packages/htmx/src/Twig/HdExtension.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Htmx\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Preflow\Htmx\ComponentToken;
use Preflow\Htmx\HtmlAttributes;
use Preflow\Htmx\HypermediaDriver;
use Preflow\Htmx\SwapStrategy;

final class HdExtension extends AbstractExtension
{
    public function __construct(
        private readonly HypermediaDriver $driver,
        private readonly ComponentToken $token,
        private readonly string $endpointPrefix = '/--component',
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('hd', fn () => $this, ['is_safe' => ['html']]),
        ];
    }

    /**
     * Generate action attributes for POST (most common).
     *
     * @param array<string, mixed> $props
     * @param array<string, string> $extra
     */
    public function post(
        string $action,
        string $componentClass,
        string $componentId,
        array $props = [],
        SwapStrategy $swap = SwapStrategy::OuterHTML,
        array $extra = [],
    ): string {
        return $this->actionAttrs('post', $action, $componentClass, $componentId, $props, $swap, $extra);
    }

    /**
     * Generate action attributes for GET.
     *
     * @param array<string, mixed> $props
     * @param array<string, string> $extra
     */
    public function get(
        string $action,
        string $componentClass,
        string $componentId,
        array $props = [],
        SwapStrategy $swap = SwapStrategy::OuterHTML,
        array $extra = [],
    ): string {
        return $this->actionAttrs('get', $action, $componentClass, $componentId, $props, $swap, $extra);
    }

    /**
     * Generate event listening attributes.
     *
     * @param array<string, mixed> $props
     */
    public function on(
        string $event,
        string $componentClass,
        string $componentId,
        array $props = [],
    ): string {
        $tokenStr = $this->token->encode($componentClass, $props, 'render');
        $url = $this->endpointPrefix . '/render?token=' . urlencode($tokenStr);

        $attrs = $this->driver->listenAttrs($event, $url, $componentId);

        return (string) $attrs;
    }

    /**
     * Get the hypermedia library asset tag.
     */
    public function assetTag(): string
    {
        return $this->driver->assetTag();
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, string> $extra
     */
    private function actionAttrs(
        string $method,
        string $action,
        string $componentClass,
        string $componentId,
        array $props,
        SwapStrategy $swap,
        array $extra,
    ): string {
        $tokenStr = $this->token->encode($componentClass, $props, $action);
        $url = $this->endpointPrefix . '/action?token=' . urlencode($tokenStr);

        $attrs = $this->driver->actionAttrs($method, $url, $componentId, $swap, $extra);

        return (string) $attrs;
    }
}
```

- [ ] **Step 4: Run tests**

```bash
./vendor/bin/phpunit packages/htmx/tests/Twig/HdExtensionTest.php
```

Expected: All 5 tests pass.

- [ ] **Step 5: Commit**

```bash
git add packages/htmx/src/Twig/HdExtension.php packages/htmx/tests/Twig/HdExtensionTest.php
git commit -m "feat(htmx): add HdExtension Twig helper with signed component URLs"
```

---

### Task 7: Full Test Suite Verification

**Files:**
- No new files

- [ ] **Step 1: Run full test suite**

```bash
./vendor/bin/phpunit --display-errors --display-warnings
```

Expected: All tests pass across all 6 packages.

- [ ] **Step 2: Verify package loads**

```bash
php -r "
require 'vendor/autoload.php';
echo 'HypermediaDriver: OK' . PHP_EOL;
echo 'HtmxDriver: OK' . PHP_EOL;
echo 'ComponentToken: OK' . PHP_EOL;
echo 'ComponentEndpoint: OK' . PHP_EOL;
echo 'HdExtension: OK' . PHP_EOL;
"
```

- [ ] **Step 3: Commit if cleanup needed**

---

## Phase 6 Deliverables

| Component | What It Does |
|---|---|
| `HypermediaDriver` | Interface for hypermedia abstraction (swappable: HTMX, Datastar, etc.) |
| `SwapStrategy` | Enum: outerHTML, innerHTML, beforebegin, afterend, delete, none |
| `HtmlAttributes` | Renders HTML attributes with escaping, supports merge |
| `ResponseHeaders` | Collects response headers for driver events/redirects |
| `HtmxDriver` | HTMX implementation: hx-post, hx-get, hx-trigger, HX-Trigger header, etc. |
| `ComponentToken` | HMAC-SHA256 signed tokens: encode(class, props, action) / decode with expiry |
| `TokenPayload` | Decoded token value object |
| `Guarded` | Interface for component-level authorization |
| `ComponentEndpoint` | Universal HTTP handler: token verify → class validate → action whitelist → guard → dispatch → render |
| `HdExtension` | Twig: `{{ hd.post('action', ...) }}`, `{{ hd.on('event', ...) }}`, `{{ hd.assetTag() }}` |

**Next phase:** `preflow/i18n` — translations, locale detection, translatable model fields.
