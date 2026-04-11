# Navigation Component

**Date:** 2026-04-11
**Scope:** Add `RequestContext` service to core, create a `Navigation` skeleton component with active route highlighting and co-located CSS.

## Problem

The skeleton's layout has hardcoded `<nav>` markup with no active state highlighting. There's no framework mechanism for components to access the current request path. This is an opportunity to demonstrate co-located CSS and component logic working together, while solving a real navigation need.

## RequestContext Service

**Location:** `packages/core/src/Http/RequestContext.php`

```php
final readonly class RequestContext
{
    public function __construct(
        public string $path,
        public string $method,
    ) {}
}
```

A deliberately narrow view of the current request. Components should not have access to the full `ServerRequestInterface` (headers, cookies, auth tokens, body). `RequestContext` exposes only what's safe and commonly needed.

**Registration:** `Application::handle()` creates and registers `RequestContext` in the container before dispatching to the kernel:

```php
$context = new RequestContext(
    path: $request->getUri()->getPath(),
    method: $request->getMethod(),
);
$this->container->instance(RequestContext::class, $context);
```

Components inject it via constructor DI, the same pattern as `DataManager` in `BlogGrid`.

## Navigation Component

**Location:**
- `packages/skeleton/app/Components/Navigation/Navigation.php`
- `packages/skeleton/app/Components/Navigation/Navigation.twig`

### PHP Class

- Extends `Component`
- `protected string $tag = 'nav'` for semantic wrapper element
- Constructor-injects `RequestContext`
- Props:
  - `items` — array of `{path: string, label: string}` objects (required)
  - `brand` — string for the logo/brand link text (optional, defaults to empty string)
- Public properties (set in `resolveState()`):
  - `$items` — enriched with `active: bool` per item
  - `$brand` — the brand string

### Active State Logic

In `resolveState()`, each item is checked against the current path:

- **`/` (home):** exact match only — avoids being active on every page
- **All other paths:** prefix match via `str_starts_with()` — `/blog` is active on `/blog`, `/blog/my-post`, etc.

```php
public function resolveState(): void
{
    $currentPath = $this->requestContext->path;
    $this->brand = $this->props['brand'] ?? '';

    foreach ($this->props['items'] ?? [] as $item) {
        $path = $item['path'];
        if ($path === '/') {
            $active = $currentPath === '/';
        } else {
            $active = str_starts_with($currentPath, $path);
        }
        $this->items[] = [...$item, 'active' => $active];
    }
}
```

No configuration flags for match behavior. The `/` special case is automatic.

### Twig Template

Uses `{% apply css %}` for co-located styles — the primary demo purpose of this component:

- Flex layout with brand on the left, links on the right
- Active state highlighted via CSS class `.nav-link--active`
- Labels rendered with `{{ t(item.label) }}` for i18n support (falls through to raw string if no translation key matches)
- Clean, readable HTML structure

### Usage in _layout.twig

Replaces the current hardcoded `<nav>` block:

```twig
{{ component('Navigation', {
    brand: 'Preflow',
    items: [
        { path: '/', label: 'app.nav.home' },
        { path: '/blog', label: 'app.nav.blog' },
        { path: '/about', label: 'app.nav.about' },
    ]
}) }}
```

The layout stays in control of which links appear. The component handles rendering, active state, and styling.

## Tests

### RequestContext (core)

- Constructor sets `path` and `method` as readonly properties
- Properties are accessible

### Navigation active state logic

- Item with matching exact path `/` → `active: true`
- Item with `/` when current path is `/blog` → `active: false`
- Item with `/blog` when current path is `/blog/my-post` → `active: true` (prefix match)
- Item with `/about` when current path is `/` → `active: false`
- Multiple items: only matching ones get `active: true`
- Empty items array → no error, empty `$items`

### Integration: RequestContext in container

- After `Application::handle()`, `RequestContext` is resolvable from the container
- Path and method match the request that was handled

## Out of Scope

- Nested/dropdown items — flat links only for now
- Responsive hamburger menu (JS toggle) — CSS-only for this iteration
- Auto-discovery of routes — items are explicitly declared
- `exact` match flag per item — the `/` special case covers the only realistic scenario
- Accessibility (aria-current) — could be added later but not blocking
