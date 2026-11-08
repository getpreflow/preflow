# preflow/components

Core component system for Preflow. Provides an abstract base class, a renderer with lifecycle management, an error boundary, and a Twig integration.

## Installation

```bash
composer require preflow/components
```

Requires PHP 8.4+.

## What it does

Each component is a PHP class co-located with a `.twig` template. The renderer calls `resolveState()` (load data from DB, session, etc.) then renders the template with the component's public properties as variables. The output is wrapped in a tagged element keyed by a stable component ID.

Errors are caught by `ErrorBoundary`: in dev mode it renders an inline panel with the exception class, message, component class, props, lifecycle phase, and stack trace; in prod mode it calls the component's `fallback()` or renders a hidden `<div>`.

## API

### `Component` (abstract base class)

| Method | Description |
|---|---|
| `resolveState(): void` | Override to load data before render. |
| `actions(): string[]` | Return allowed action names for this component. |
| `action{Name}(array $params): void` | Implement one method per declared action. |
| `fallback(\Throwable $e): ?string` | Return fallback HTML on error (prod only). Return `null` to hide. |
| `setProps(array $props): void` | Set props from outside (called by the renderer/extension). |
| `getProps(): array` | Read current props. |
| `getComponentId(): string` | `ShortClassName-{propsHash}` — stable across renders with same props. |
| `getTag(): string` | Wrapper HTML tag (default `div`). Override `$tag` to change. |

Public properties are automatically exposed as Twig template variables alongside `componentId`.

### `ComponentRenderer`

```php
$renderer->render(Component $component): string                  // full lifecycle + wrapper
$renderer->renderFragment(Component $component): string          // inner HTML only (for HTMX partials)
$renderer->renderResolved(Component $component): string          // skip resolveState (after action dispatch)
$renderer->renderResolvedFragment(Component $component): string  // skip resolveState, inner HTML only
```

`renderResolvedFragment()` combines the two partial-render flags: it skips `resolveState()` (state was already mutated by an action) and returns inner HTML without the wrapper element. Used by `ComponentEndpoint` when the HTMX target does not match the component's own ID.

### Live components: `#[Live]` and `PrefetchesLive`

A component class marked `#[Live]` is a live part of cached pages (preflow/core's page cache):
while a page renders for the cache, `ComponentRenderer::render()` returns a signed placeholder
of its class and props, and the component is made and rendered for each visitor when the page
is served (wrapper and error boundary as always). Props must be scalars and arrays and the
same for every visitor; the component reads what is personal (the visitor's cookie, from
`RequestContext::$request`) in `resolveState()`.

```php
#[Live]
final class AlbumButton extends Component implements PrefetchesLive
{
    public function __construct(private readonly Albums $albums) {}

    public function prefetchLive(array $propsList): void
    {
        $this->albums->prefetch(array_column($propsList, 'number')); // one call for the page's 24 buttons
    }
}
```

`prefetchLive()` runs once per class before a served page's live parts are rendered, on an
instance made like the others: keep what it loads in an injected service (or a static).

### Freshness: `#[Freshness]`

What a component reads, as a dependency of the cached page (preflow/core's page cache,
"Freshness, per dependency"), around its `resolveState()` and its render:

```php
use Preflow\Components\Freshness;
use Preflow\Core\PageCache\Freshness as Dial;

#[Freshness(Dial::NextVisit)]              // a write marks the page stale
final class OtherJobs extends Component { ... }

#[Freshness(Dial::Timed, seconds: 43200)]  // writes ignored; the page expires after 12 h
final class Ticker extends Component { ... }
```

A parent's attribute counts for its subclasses; a scope inside (a template's `{% freshness %}`)
wins for its own reads; on a `#[Live]` component it does nothing. `Timed` without seconds is
an error (the component's error boundary shows it).

### `ComponentExtension` (Twig)

Registers `{{ component('Name', {props}) }}`. Component names are resolved via a short-name → FQCN map or a fully-qualified class name.

## Usage

**Component class** (`src/Components/Counter/Counter.php`):

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

**Template** (`src/Components/Counter/Counter.twig`):

```twig
<p>Count: {{ count }}</p>
<button>+1</button>
```

**In a page template:**

```twig
{{ component('Counter', { initialCount: 0 }) }}

{# Or using a fully-qualified class name: #}
{{ component('App\\Components\\Counter\\Counter') }}
```

**Register the extension:**

```php
$componentMap = ['Counter' => App\Components\Counter\Counter::class];
$twig->addExtension(new ComponentExtension($renderer, $componentMap));
```
