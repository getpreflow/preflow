# preflow/components

Core component system for Preflow. Provides an abstract base class, a renderer with lifecycle management, an error boundary, and a Twig integration.

## Installation

```bash
composer require preflow/components
```

Requires PHP 8.4+.

## What it does

Each component is a PHP class co-located with a `.twig` template. The renderer runs the component's hooks (`resolveState()`, or `mount()` and `prepare()` for a component with state: see "Components that keep their own state" below), then renders the template with the component's public properties as variables. The output is wrapped in a tagged element keyed by a stable component ID.

Errors are caught by `ErrorBoundary`: in dev mode it renders an inline panel with the exception class, message, component class, props, lifecycle phase, and stack trace; in prod mode it calls the component's `fallback()` or renders a hidden `<div>`. An `HttpException` (a 403, a 404…) is not caught: the page, or the component request, answers with its status (since 0.63.2).

A component's `{% apply css %}` and `{% apply js('head') %}` are printed by the layout's `{{ head() }}`, its body and inline JS by `{{ assets() }}`; what is collected after those calls ran (a component rendered further down) goes in before `</head>` and `</body>`.

A component with `$scopeCss = true` gets its CSS nested under its own class; a child component without it is not scoped by its parent. `@media` and `@supports` stay inside the scope; `@keyframes` and `@font-face` (and other at-rules that can't be nested) are kept outside it, so their names are global: give them names of the component's own (`card-spin`). `@import`, `@namespace` and the statement form `@layer a, b;` are left inside the scope, where browsers ignore them: put those in a stylesheet.

A page where a boundary stood in for a component is never stored by the page cache (`refused:component-error`). To show what a boundary renders without failing (an error-boundary demo, a style guide), implement `Preflow\Components\PreviewsFailure`: the renderer prints `ErrorBoundary::preview()` of `previewFailure()` in place of the component, the same output a real failure in `resolveState` gets, and reports no error, so the page is cached. The starter's error demo does this.

```php
final class ErrorDemo extends Component implements PreviewsFailure
{
    public function previewFailure(): \Throwable { return new \RuntimeException('Crashed on purpose'); }
    public function fallback(\Throwable $e): ?string { return '<div class="error-demo-fallback">…</div>'; }
}
```

## Components that keep their own state (0.86)

**State is the visitor's view, not a record.** A component's state is what one visitor sees right
now: the search words, the page of results, the rows of a form they are filling in. The page
carries it, signed, so nobody can change it. But an older valid copy can come back: the back
button, a second tab, a request sent twice. So anything that must never go backwards (an order is
paid, an invitation is used, a counter) lives in storage. State holds ids and the form's progress.

### The problem it solves

Before 0.86 a component forgot everything between its actions. Every action call had to send the
component's whole view by hand: a filter call sent all the *other* filters too, so they survived
the action. And the endpoint ran `resolveState()` before every action, so an action that loaded
its own data loaded it twice.

### The idea: mark what carries over

Mark a property with `#[State]`. Its value survives the component's own actions: the page carries
it, each action sends it back, the action runs on it, and the answer brings the new value back to
the page. You pass nothing by hand. The starter's job search keeps its filters this way:

```php
use Preflow\Components\State;

#[State]
public JobFilters $filters;
```

Only marked properties carry over, not every public property. Posted fields never fill state by
name: the action decides what changes. So a reader of the class sees what carries over and where
input comes in.

### When which method runs

| Moment | Component with `#[State]` | Component without `#[State]` (as before) |
|---|---|---|
| First render (a page, a `component()` call) | `mount()`, then `prepare()` | `resolveState()` |
| One of its actions | state restored, the action, `prepare()` | `resolveState()`, the action |
| Event refresh (`hd.on`) | state restored, `prepare()` | `resolveState()` |

- `mount()` runs on the first render only. It sets the state from the props.
- The action runs on the restored state. Nothing loads before it.
- `prepare()` runs before **every** render. It loads what derives from the state (search results,
  a list of places) and does not change the state.
- An event refresh restores the state and does not `mount()` again: a cart that refreshes on
  "item added" does not empty itself.
- `resolveState()` keeps its meaning for components **without** state; nothing changes for them. A
  component with `#[State]` that defines `resolveState()` fails in debug ("use mount() and
  prepare()").

The starter's `JobSearch` (`packages/starter/app/Components/JobSearch/JobSearch.php`), shortened:

```php
public function mount(): void
{
    $this->filters = JobFilters::fromArray($this->props);
}

public function prepare(): void
{
    $result = $this->search->search($this->filters);
    // … $this->jobs, $this->total, $this->lastPage from $result
}

public function actionSearch(array $params): void
{
    $this->filters = JobFilters::fromArray($params)->with(['page' => 1]);
    $this->hd->pushUrl($this->filters->url());
}
```

**Nested components start from their props.** When a parent renders again after its action, the
components its template renders are made fresh: they `mount()` from the props the parent passes.
A nested component keeps its state only across its *own* actions. So a multistep form keeps its
rows in the parent's state, not in one component per row.

**Props and state.** Props stay what they were: the signed identity a parent passes in (`key`,
`job`, …). For a component with state, the action token carries only `key`. The other props were
used by `mount()` and live on as state, so read them in `mount()`, not in an action.

### `$params` is input; state is trusted

The values a call sends (`hd.post('page', {page: 2})`) travel **unsigned**, together with the
fields of the form the element sits in (a form field wins over a value of the same name). All of
it reaches the action as `$params`. Check it like any posted form: a row id is checked against the
state or the storage, a page number is a whole number from 1. State is the only part a visitor
cannot change.

An action reads its input explicitly, so a call that sends only `{page: 2}` to a `search` action
would clear the filters. Give each intent its own action. The starter has `search` (the form
changed) and `page` (a pager link):

```php
public function actionPage(array $params): void
{
    $page = filter_var($params['page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($page === false) {
        throw new HttpException(400, 'Not a page number.');
    }
    // A page past the end shows no jobs, as the address bar does for it.
    $this->filters = $this->filters->with(['page' => $page]);
    $this->hd->pushUrl($this->filters->url());
}
```

How templates call actions, with the regions an action renders alone, is in preflow/htmx's README
("Calling actions from a template").

### What may be state

| Type | Example |
|---|---|
| Scalars and null | `#[State] public string $q = '';` |
| Arrays of scalars and null, any depth | `#[State] public array $rows = [];` |
| Backed enums | `#[State] public Sort $sort = Sort::Newest;` |
| Objects implementing `StateValue` | `#[State] public JobFilters $filters;` |
| A nullable class type of the two above | `#[State] public ?JobFilters $filters = null;` |

Not allowed: other objects, closures, and a union of a class with another type
(`JobFilters|array`), which can't be rebuilt from plain values. Arrays hold plain values only:
an enum or a `StateValue` inside an array is refused (store the enum's value, or the object's
`toState()`). A wrong type fails on the component's first render; in debug the message names the
class and the property.

A `StateValue` turns itself into plain values and is rebuilt from them, so the conversion stays
visible in the value class:

```php
final readonly class JobFilters implements StateValue
{
    public function toState(): array
    {
        return ['q' => $this->q, 'place' => $this->place, /* … */ 'page' => $this->page];
    }

    public static function fromState(array $state): static
    {
        // Read like any input, so a state of an older shape (a filter more or less) still works.
        return self::fromArray($state);
    }
}
```

What not to put in state:

- **Secrets.** State is signed, not encrypted: a visitor can read it.
- **Records and uploads.** State travels both ways with every action. A few KB is nothing; keep
  records in storage and only their ids in state.
- **Facts that must not go backwards** (see the first paragraph).

After a deploy, a state property you **added** keeps its default on pages that are already open. A
state property you **renamed or removed** makes their state unusable: the action answers 403 and
the visitor reloads the page.

### How long state lives

Signed state expires after 24 hours, like action tokens. The page cache keeps a page at most 20
hours, so the state of a cached page always has at least 4 hours left. An expired state is refused
like an expired token (403): a form left open for over a day starts again after a reload.

A tampered state, or the state of another component, is refused the same way.

### Large state behind a login: `#[StateIn(SessionStateStore::class)]`

By default the page carries the state itself (`SignedStateStore`). For large state behind a
login, keep it in the session instead; the page then carries a short random reference:

```php
use Preflow\Components\{SessionStateStore, StateIn};

#[StateIn(SessionStateStore::class)]
final class OrderForm extends Component { … }
```

Its limits:

- It needs a session, so it does **not** work on a page the page cache stores for every visitor:
  they would all carry one visitor's reference. In debug that render fails with a message; outside
  debug the page is not stored (`pages:why` says `component-session-state`). Use it behind a login,
  or on a page that is not cached.
- The session keeps the newest 8 states of each component. A ninth open tab of the same component
  makes the oldest tab's next action ask for a reload.
- It writes the session on every render of the component, and lives as long as the session.

Another store (a database, a cache) implements `StateStore` (`put()` returns the string the page
carries, `get()` turns it back or throws `StateRefused`), is built by the container, and is
checked with `Preflow\Testing\Contract\StateStoreContract` (preflow/testing). Name it with
`#[StateIn(YourStore::class)]`.

### Testing an action the way the page does it

`WebApplication::componentAction()` (preflow/testing) runs an action with the state the page
carries, so a test never reads driver attributes from HTML:

```php
$page = (string) $app->handle($request)->getBody();
$answer = WebApplication::componentAction($app, $page, JobSearch::class, 'search', ['remote' => 'full']);
$state = WebApplication::stateFrom((string) $answer->getBody(), $componentId); // the new state, for the next call
```

See preflow/testing's README for the parameters.

## API

### `Component` (abstract base class)

| Method | Description |
|---|---|
| `resolveState(): void` | Without `#[State]`: override to load data before every render and every action. |
| `mount(): void` | With `#[State]` (0.86): the first render only; set the state from the props. |
| `prepare(): void` | With `#[State]` (0.86): before every render; load what derives from the state. |
| `hasState(): bool` | Whether the component has a `#[State]` property (0.86). |
| `actions(): string[]` | Return allowed action names for this component. |
| `action{Name}(array $params): void` | Implement one method per declared action. |
| `fallback(\Throwable $e): ?string` | Return fallback HTML on error (prod only). Return `null` to hide. |
| `setProps(array $props): void` | Set props from outside (called by the renderer/extension). |
| `getProps(): array` | Read current props. |
| `getComponentId(): string` | `ShortClassName-{classHash}` (8 hex characters of the class name's hash), plus `-{key}` when the props have a `key`: the same for every instance of a class, so give a `key` to components that appear more than once on a page. |
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

Since 0.86, for components with state and regions (core wires all of this; you call it only when
you build a renderer yourself):

```php
$renderer->enableState(fn (string $storeClass): StateStore => …, debug: true); // the store of each class
$renderer->carryStateWith(fn (string $componentId, string $carried): string => …); // the driver's stateCarrier()
$renderer->stateFor(Component $c): ?string        // the string the page carries after its last render
$renderer->restore(Component $c, string $carried) // writes the carried state back (StateRefused: 403)
$renderer->renderAfterAction(Component $c, ?string $region = null): string // prepare(), then the component or one region
$renderer->regionsOf(Component $c): ?array        // its template's regions; null: the engine renders none
$renderer->current(): ?Component                  // the component whose template renders now
$renderer->parentOf(Component $c): ?Component     // the component whose template rendered $c
```

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

#[Freshness(Dial::Timed, seconds: 43200)]  // writes ignored; expires after 12 h (or max_age, if shorter)
final class Ticker extends Component { ... }
```

A parent's attribute counts for its subclasses; a scope inside (a template's `{% freshness %}`)
wins for its own reads; on a `#[Live]` component it does nothing. `Timed` without seconds is
an error (the component's error boundary shows it). A timed page never outlives the page
cache's `max_age` (4 h by default): raise it for longer times (up to 20 h).

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
