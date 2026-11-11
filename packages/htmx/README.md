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

// Requests and responses (0.65; the component endpoint and core call these)
$driver->fragmentTarget($request)    // the HX-Target id, or null
$driver->applyEffects($response)     // the recorded HX-* headers, plus HX-Nonce
$driver->isFragmentRequest($request) // HX-Request: true
$driver->isHistoryRestore($request)  // HX-History-Restore-Request: true
$driver->varyHeaders()               // HX-Request, HX-Target, HX-History-Restore-Request
$driver->navigate($response, $url)   // HX-Redirect: $url, or HX-Refresh: true for null
$driver->announceNonce($response, $n)// HX-Nonce: $n (nonceHeaders(): HX-Nonce)
$driver->pageNonce($request)         // null: htmx re-stamps scripts with the page nonce itself
```

### Another client library: `app.hypermedia` (0.65)

`HtmxDriver` implements `HypermediaDriver`, and an app has one driver: `config/app.php`
`'hypermedia'` names the class of another `HypermediaDriver` (default `HtmxDriver`), and the
container builds it (constructor parameters autowired, e.g. the `AssetCollector`). It is bound as
`HypermediaDriver`, and everything uses that binding: the `hd.*` helpers, `ComponentEndpoint`,
components that type-hint `HypermediaDriver` or the class, and the form helpers when the driver
also implements `Preflow\View\FormHypermediaDriver` (otherwise forms stay plain). Core also adds
it to `HypermediaProtocols` (preflow/core): auth, the expired-session answer, the security headers,
the page cache and GEO ask it about requests (see core's README, "Hypermedia clients").

The driver is built on its first use: `HypermediaDriver` is a `LazyHypermediaDriver`
(`LazyFormHypermediaDriver` for a form driver) standing in until then, and the driver itself is the
container's shared `$class` — after the service providers ran, so its constructor may take services
a provider binds, and a provider may bind `$class` itself.
With another driver htmx isn't inlined (`config/htmx.php` then does nothing); the driver's
`assetTag()` or your layout loads its client.

```php
// config/app.php
'hypermedia' => App\Hypermedia\DatastarDriver::class,
```

Besides the attribute methods and `triggerEvent()` / `redirect()` / `pushUrl()`, a driver
implements `fragmentTarget(ServerRequestInterface): ?string` (the id of the element the client
swaps the response into; the endpoint renders the component without its wrapper when that is
another element), `applyEffects(ResponseInterface): ResponseInterface` (the recorded effects as
the client reads them: headers, or events in the body; called on every endpoint response) and
core's `HypermediaProtocol`: `isFragmentRequest()`, `isHistoryRestore()`, `varyHeaders()`,
`navigate()`, `announceNonce()` / `nonceHeaders()` and `pageNonce()` (a client that sends its
page's CSP nonce back with a fragment request is answered with it, so its effect scripts pass the
page's policy). `pageNonce()` returns untrusted input: core adopts it for fragment requests only
(not plain requests or history restores), for that response only, never stores the response,
and the driver must not print the nonce into the page — its client reads `script.nonce` at
request time. An unknown class throws at boot. Check a driver with
`Preflow\Testing\Contract\HypermediaDriverContract` (preflow/testing).

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

#### Locale prefix

Since 0.46, `HtmxExtensionProvider` takes an optional `pathPrefix` closure (`\Closure(): string`) that is put before every URL it builds (`actionUrl`, `post`, `get`, `on`). Preflow's core passes the locale prefix of the current request (`LocaleMiddleware::urlPrefix()`), so on a `/de/…` page component URLs are `/de/--component/…` and the action runs in German; the locale middleware strips `/de` again before the endpoint. Pages without a prefix, and apps without the i18n URL prefix strategy, keep `/--component/…`. A hand-written `hx-post="/--component/…"` loses the locale, so use `hd.actionUrl()`. Only `HtmxExtensionProvider` (the `hd.*` helpers in core apps) applies the prefix; the legacy `HdExtension` does not. Folio's admin preview under `/folio` carries no locale prefix, so it renders components without it. Error pages are rendered after the pipeline (the kernel catches the exception once the locale middleware has returned), so a component on a 404 or 500 page under `/de/…` gets an unprefixed `/--component/…` URL and its action runs in the default locale.

```php
new HtmxExtensionProvider($driver, $token, pathPrefix: fn (): string => $localeMiddleware->urlPrefix());
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

When the driver's `fragmentTarget()` (htmx: the `HX-Target` header) does not match the component's own ID, `ComponentEndpoint` returns a fragment response via `renderFragment()` / `renderResolvedFragment()` rather than the full wrapped component. This lets a single component handle both full-page renders and targeted partial swaps.

`ComponentRenderer::renderResolvedFragment()` skips `resolveState()` and renders inner HTML only — used after an action has already mutated state.
