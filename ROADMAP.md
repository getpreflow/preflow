# Preflow Roadmap

> Last updated: 2026-04-14 (v0.11.0)

## Current State

**v0.11.0** — 565 tests, 13 packages, production-validated via a full application migration (interactive board game teaching tool with 6 step types, admin CRUD, flow editor, image management, auth).

All critical framework issues have been resolved. The HTML-over-the-wire architecture is proven with HTMX component lifecycle, auto-increment IDs, catch-all routes, fragment rendering, JSON middleware, and CSRF token handling.

---

## Next Up

### CSS Scoping for Components

Prevent class name collisions between components using data-attribute scoping with a hash derived from the component class path. Currently, two components using the same CSS class name (e.g., `.title`) will collide. Scoping would automatically namespace component styles.

### Form & Validation Package

Declarative validation for models and form handling:

```php
#[Entity(table: 'posts')]
class Post extends Model {
    #[Id]
    public int $id = 0;

    #[Field, Validate('required', 'min:3', 'max:200')]
    public string $title = '';

    #[Field, Validate('required', 'email')]
    public string $author_email = '';
}
```

With error collection, field-level messages, and HTMX-friendly inline validation.

### Guide-Level Tutorial

A step-by-step guide building a complete small application covering file-based + attribute routing, components with HTMX actions, data models, authentication, and deployment.

---

## Medium Term

### Database Relations

Simple eager loading for related models:

```php
$posts = $manager->query(Post::class)->with('author')->get();
```

Or relation attributes on models. The `raw()` method covers complex JOINs for now.

### Route Caching

Compile the route collection to a PHP file for production. The `RouteCompiler` exists but isn't integrated into the Application boot lifecycle with automatic invalidation.

### Middleware for File-Based Routes

File-based routes can't carry middleware annotations. Options under consideration:
- Convention-based `_middleware.php` files in page directories
- Frontmatter in template files
- Keep global path-based middleware as the primary pattern

### Component Asset Optimization

Track which CSS/JS blocks have already been sent to the client to avoid re-sending on every HTMX swap. Options: client-side cookie tracking, CSS deduplication by component name, or preloading all component CSS on initial render.

---

## Lower Priority

- **Error page customization** — app-level 404/500 templates
- **Remember me** — persistent login via rotated token cookies
- **Dynamic model validation** — validation rules in JSON schemas
- **Blade adapter testing** — stress test the Blade adapter like Twig was tested
- **Testing utilities** — component render assertions, HTMX action dispatch helpers, snapshot testing

---

## Completed (v0.11.0)

- HTMX-aware CSS/JS delivery in fragment responses
- Auto-increment ID support
- Catch-all attribute route params (`{param...}`)
- Request context in page templates
- `csrf_token()` / `csrf_field()` split
- JSON body parsing middleware
- Explicit `insert()` / `update()` on DataManager
- `delete()` accepts model instances
- Raw SQL queries
- Fragment rendering for targeted HTMX swaps
- `AssetCollector::fork()` for isolated sub-renders
- FlashType enum and typed flash helpers
- `asset_url()` template function
- FileRouteScanner proper prefix tracking
- Recursive component discovery
- DI in component endpoint factory
- `config/middleware.php` auto-loading
- Configurable ID fields in StorageDriver
