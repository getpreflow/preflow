# preflow/htmx

Hypermedia abstraction and component endpoint for Preflow. Ships an HTMX driver out of the box; the `HypermediaDriver` interface allows swapping in alternatives (e.g. Datastar).

## Installation

```bash
composer require preflow/htmx
```

Requires PHP 8.4+, `ext-sodium`. Twig integration requires `twig/twig ^3.0`.

## What it does

`ComponentToken` issues HMAC-SHA256-signed tokens that encode a component class, props, and action name. `ComponentEndpoint` handles incoming hypermedia requests through five security layers: token verification → class validation → action whitelist → `Guarded` interface → dispatch. `HtmxDriver` generates `hx-*` attributes and sets response headers. The `hd` Twig global surfaces all of this as template helpers.

## API

### `HtmxDriver`

Generates HTML attributes and sets response headers.

```php
// Attributes
$driver->actionAttrs(method: 'post', url: $url, targetId: $id, swap: SwapStrategy::OuterHTML): HtmlAttributes
$driver->listenAttrs(event: 'itemSaved', url: $url, targetId: $id): HtmlAttributes

// Response headers (call from within an action)
$driver->triggerEvent('itemSaved')    // HX-Trigger
$driver->redirect('/dashboard')      // HX-Redirect
$driver->pushUrl('/posts/1')         // HX-Push-Url
```

### `ComponentToken`

```php
$token->encode(componentClass: Post::class, props: ['id' => 1], action: 'save'): string
$token->decode(tokenString: $str, maxAge: 86400): TokenPayload
```

Tokens are URL-safe base64 strings. `maxAge` (seconds) enforces expiry.

### `ComponentEndpoint`

Universal PSR-7 handler. Mount it at e.g. `/--component/action` and `/--component/render`.

```php
$endpoint->handle(ServerRequestInterface $request): ResponseInterface
```

Security layers applied on every request:
1. Token present and base64-decodable
2. HMAC-SHA256 signature valid, optional max-age enforced
3. `componentClass` is a real subclass of `Component`
4. `action` is in `$component->actions()` (or `'render'`)
5. If component implements `Guarded`, `authorize(action, request)` is called

### `Guarded` interface

Add component-level authorization without middleware.

```php
interface Guarded
{
    public function authorize(string $action, ServerRequestInterface $request): void;
}
```

Throw `ForbiddenHttpException` to deny access.

### `SwapStrategy` enum

`OuterHTML`, `InnerHTML`, `BeforeBegin`, `AfterBegin`, `BeforeEnd`, `AfterEnd`, `Delete`, `None`

### Twig `hd` global (`HdExtension`)

Available as `hd` in all templates once the extension is registered.

```twig
{# POST action — renders hx-post, hx-target, hx-swap attributes #}
<button {{ hd.post('increment', 'App\\Counter', componentId, props) }}>+1</button>

{# GET action #}
<div {{ hd.get('load', 'App\\Feed', componentId, {page: 2}) }}></div>

{# Listen for a server-sent event and re-render #}
<div {{ hd.on('itemSaved', 'App\\List', componentId, props) }}></div>

{# HTMX script tag #}
{{ hd.assetTag() }}
```

## Usage

**Component with an action:**

```php
use Preflow\Components\Component;

final class Counter extends Component
{
    public int $count = 0;

    public function resolveState(): void
    {
        $this->count = (int) ($_SESSION['count'] ?? 0);
    }

    public function actions(): array
    {
        return ['increment'];
    }

    public function actionIncrement(array $params): void
    {
        $this->count++;
        $_SESSION['count'] = $this->count;
    }
}
```

**Template** (`Counter.twig`):

```twig
<p>Count: {{ count }}</p>
<button {{ hd.post('increment', 'App\\Counter', componentId, props) }}>+1</button>
```

**Guarded component:**

```php
use Preflow\Htmx\Guarded;
use Preflow\Core\Exceptions\ForbiddenHttpException;
use Psr\Http\Message\ServerRequestInterface;

final class AdminPanel extends Component implements Guarded
{
    public function actions(): array
    {
        return ['deleteItem'];
    }

    public function authorize(string $action, ServerRequestInterface $request): void
    {
        $user = $request->getAttribute('user');
        if (!$user?->isAdmin()) {
            throw new ForbiddenHttpException('Admins only.');
        }
    }

    public function actionDeleteItem(array $params): void
    {
        // ...
    }
}
```

**Wire up the endpoint:**

```php
use Preflow\Htmx\{ComponentEndpoint, ComponentToken, HtmxDriver, ResponseHeaders};

$token    = new ComponentToken(secretKey: $_ENV['APP_KEY']);
$headers  = new ResponseHeaders();
$driver   = new HtmxDriver($headers);
$endpoint = new ComponentEndpoint(
    token: $token,
    renderer: $renderer,
    driver: $driver,
    componentFactory: fn (string $class, array $props) => new $class(),
);

// Route POST /--component/action and GET /--component/render to:
$response = $endpoint->handle($request);
```

**Add asset tag in your base layout:**

```twig
{{ hd.assetTag() }}
```

### Asset injection in fragment responses

`ComponentEndpoint` automatically appends any CSS and JS collected during component rendering to HTMX fragment responses. Styles and scripts defined inside a component with `{% apply css %}` / `{% apply js %}` are included even when the response is a partial swap — no separate asset pipeline step needed.

### Fragment rendering

When the `HX-Target` header does not match the component's own ID, `ComponentEndpoint` returns a fragment response via `renderFragment()` / `renderResolvedFragment()` rather than the full wrapped component. This lets a single component handle both full-page renders and targeted partial swaps.

`ComponentRenderer::renderResolvedFragment()` skips `resolveState()` and renders inner HTML only — used after an action has already mutated state.
