# Preflow Framework — Design Specification

**Date:** 2026-04-10
**Status:** Approved
**Repository:** github.com/getpreflow
**PHP Version:** 8.5+ (no backwards compatibility)

---

## 1. Vision & Philosophy

Preflow is a modern, modular PHP 8.5+ framework built around a core opinion: **browsers handle HTML best**. Instead of shipping JSON to the client and hydrating with JavaScript frameworks, Preflow renders HTML on the server and uses hypermedia (HTML-over-the-wire) for interactivity.

### Core Opinions

- **HTML over the wire** — server renders HTML fragments, browser swaps them in. No JSON APIs required for UI. No client-side hydration.
- **Components are the primitive** — co-located PHP logic + Twig template + inline CSS/JS. Self-contained, testable, composable.
- **Zero external asset requests** — all CSS/JS is inline in the HTML document, deduplicated by hash, minified in production. The HTML document IS the bundle.
- **Security first** — HMAC-signed component tokens, CSP nonces, component-level auth guards, security headers by default.
- **Embrace PHP 8.5** — readonly classes, property hooks, enums, fibers, asymmetric visibility, attributes for DI/routing/models. No legacy patterns.
- **Opinionated but open** — ships with strong defaults (Twig, HTMX, SQLite+MySQL) but every layer has an interface for alternatives.

### What Makes Preflow Unique

No existing PHP framework combines:
1. Component-based architecture with co-located assets and zero external requests
2. Hypermedia-driven interactivity abstracted behind a driver interface
3. Storage-agnostic data layer (JSON files, SQLite, MySQL in one app)
4. Component-level error boundaries (React-style, server-side)
5. First-class i18n from day one, including translatable model fields

---

## 2. Architecture — Dual-Mode Kernel

All requests flow through a single kernel that dispatches to one of two modes:

```
Request → Kernel::handle()
            ├─ Is component/page request? → ComponentMode
            │   ├─ Router → Page file or component endpoint
            │   ├─ Component tree render with error boundaries
            │   ├─ Asset collection + hash deduplication + inline
            │   └─ HTML response
            │
            └─ Is action request? → ActionMode
                ├─ Router → Controller method
                ├─ PSR-15 middleware stack
                └─ JSON/HTML/any response
```

Both modes share: DI container, data layer, middleware pipeline, error handling, config, i18n, testing utilities.

### Trade-offs

- Two code paths through the kernel adds slight complexity.
- The separation is clean — each mode is simpler than a combined approach would be.
- API endpoints stay clean without component overhead.
- Pages get the full component lifecycle without API compromises.

---

## 3. Package Structure

Preflow follows a core + first-party plugins architecture. The core is genuinely lean; users install what they need.

```
preflow/core          — Kernel, DI container, config, error handling, PSR-7/15
preflow/routing       — Hybrid router (file-based + attribute-based)
preflow/components    — Component base class, lifecycle, error boundaries, asset pipeline
preflow/data          — Storage interface, drivers (SQLite, MySQL, JSON), dynamic models
preflow/view          — Template engine interface + Twig adapter with asset extensions
preflow/htmx          — HTMX driver (token system, component endpoint, event helpers)
preflow/i18n          — Translation engine, locale detection, pluralization, model translations
preflow/testing       — Test utilities for components, routes, data layer
preflow/devtools      — Dev error pages, component inspector, request profiler
preflow/skeleton      — Project starter template (composer create-project)
```

Each package declares its dependencies via Composer. `preflow/core` is the only mandatory package. A user wanting just an API framework installs `core` + `routing`. Full component experience installs all packages.

---

## 4. Core — DI Container & Service Providers

### PSR-11 Container with Autowiring

The container auto-resolves dependencies from type hints. PHP 8.5 attributes provide contextual injection:

```php
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Config {
    public function __construct(public string $key) {}
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class Env {
    public function __construct(public string $name, public ?string $default = null) {}
}
```

Usage in any class:

```php
final class GameRepository
{
    public function __construct(
        private DatabaseConnection $db,
        #[Config('app.default_page_size')] private int $pageSize,
        #[Env('CDN_URL', 'https://cdn.example.com')] private string $cdnUrl,
    ) {}
}
```

### Service Providers

Interface bindings and singletons are configured in service providers:

```php
final class AppServiceProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $container->bind(StorageDriver::class, MySqlDriver::class);
        $container->bind(TemplateEngine::class, TwigEngine::class);
        $container->singleton(GameRepository::class);
    }
}
```

### Kernel

```php
final class Kernel
{
    public function __construct(
        private Container $container,
        private Router $router,
        private MiddlewarePipeline $pipeline,
        private ErrorHandler $errorHandler,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->pipeline->process($request, function ($request) {
            $route = $this->router->match($request);

            return match ($route->mode) {
                RouteMode::Component => $this->container
                    ->get(ComponentRenderer::class)
                    ->render($route, $request),
                RouteMode::Action => $this->container
                    ->get(ActionDispatcher::class)
                    ->dispatch($route, $request),
            };
        });
    }
}
```

---

## 5. Component System

### Component Lifecycle

```
Full page:  construct → resolveState → render
Actions:    construct → resolveState → handleAction → render
```

### Base Class

```php
abstract readonly class Component
{
    public string $componentId;
    public AssetCollector $assets;
    public array $props = [];

    protected function resolveState(): void {}
    protected function bindModel(): ?Model { return null; }
    protected function actions(): array { return []; }
    protected function fallback(\Throwable $e): ?string { return null; }
}
```

Components support constructor injection — dependencies are auto-resolved by the DI container:

```php
final class DiscoveryHero extends Component
{
    public ?Boardgame $game = null;
    public array $categories = [];

    public function __construct(
        private GameRepository $games,
    ) {}

    protected function resolveState(): void
    {
        $this->game = $this->props['game']
            ?? $this->games->findRandom();
        $this->categories = array_slice($this->game->categories, 0, 3);
    }

    protected function actions(): array
    {
        return ['refresh'];
    }

    public function actionRefresh(): void
    {
        $this->game = null;
        $this->resolveState();
    }

    protected function fallback(\Throwable $e): string
    {
        return '<div class="hero-error">Game unavailable</div>';
    }
}
```

### Co-located Templates

Convention: component class and template share a directory:

```
App/Components/DiscoveryHero/
├── DiscoveryHero.php
└── DiscoveryHero.twig
```

Template variables are directly available (no prefix needed):

```twig
<div class="discovery-hero">
    <h2>{{ game.title }}</h2>
    <button {{ hd.post('refresh') | raw }}>Show me another</button>
</div>

{% apply css %}
.discovery-hero { display: grid; gap: 1rem; }
{% endapply %}

{% apply js %}
console.log('DiscoveryHero loaded');
{% endapply %}
```

### Asset Blocks — Position Control

JS blocks accept a position argument for placement control:

```twig
{% apply js %}           {# Default: end of <body> #}
{% apply js('head') %}   {# In <head>: critical scripts, config #}
{% apply js('inline') %} {# Renders in-place: immediate execution, HTMX fragments #}
```

CSS is always collected into a single `<style>` block.

### Error Boundaries

Each component render is wrapped in a try/catch. On failure:

- **Dev mode:** Rich inline error panel showing component class, props, lifecycle phase, full stack trace, parent component, editor links.
- **Prod mode:** Component's `fallback()` return value, or a configurable generic fallback.

Parent and sibling components continue rendering normally.

```php
final class ComponentRenderer
{
    public function renderComponent(Component $component, Request $request): string
    {
        try {
            $component->resolveState();
            $html = $this->templateEngine->render($component);
            $this->assetCollector->collect($component);
            return $this->wrapHtml($component, $html);
        } catch (\Throwable $e) {
            $this->logger->error('Component render failed', [
                'component' => $component::class,
                'props' => $component->props,
                'phase' => $this->currentPhase,
                'exception' => $e,
            ]);
            return $this->errorBoundary->render($e, $component);
        }
    }
}
```

### Asset Pipeline — Zero External Requests

```php
final class AssetCollector
{
    private array $cssRegistry = [];  // hash => css
    private array $jsHead = [];       // hash => js
    private array $jsBody = [];       // hash => js (default)
    private array $jsInline = [];     // hash => js

    public function addCss(string $css, ?string $key = null): void
    {
        $key ??= hash('xxh3', $css);
        $this->cssRegistry[$key] ??= $css;
    }

    public function addJs(
        string $js,
        JsPosition $position = JsPosition::Body,
        ?string $key = null,
    ): void {
        $key ??= hash('xxh3', $js);
        match ($position) {
            JsPosition::Head   => $this->jsHead[$key] ??= $js,
            JsPosition::Body   => $this->jsBody[$key] ??= $js,
            JsPosition::Inline => $this->jsInline[$key] ??= $js,
        };
    }

    public function renderInline(): string
    {
        // In prod: minified + cached by content hash
        // CSP nonce automatically applied to all tags
        // Same component rendered N times = CSS/JS appears once
    }
}

enum JsPosition: string
{
    case Head = 'head';
    case Body = 'body';
    case Inline = 'inline';
}
```

---

## 6. Hypermedia Abstraction Layer

### Driver Interface

The component system never speaks HTMX directly — it speaks through a driver:

```php
interface HypermediaDriver
{
    public function actionAttrs(
        string $method, string $url, string $targetId,
        SwapStrategy $swap, array $extra = [],
    ): HtmlAttributes;

    public function listenAttrs(string $event, string $url, string $targetId): HtmlAttributes;

    public function triggerEvent(string $event): void;
    public function redirect(string $url): void;
    public function pushUrl(string $url): void;
    public function isFragmentRequest(Request $request): bool;
    public function assetTag(): string;
}
```

### HTMX Driver (Default Implementation)

```php
final class HtmxDriver implements HypermediaDriver
{
    public function actionAttrs(
        string $method, string $url, string $targetId,
        SwapStrategy $swap, array $extra = [],
    ): HtmlAttributes {
        return new HtmlAttributes([
            "hx-{$method}" => $url,
            'hx-target' => "#{$targetId}",
            'hx-swap' => $swap->value,
            ...$extra,
        ]);
    }

    public function isFragmentRequest(Request $request): bool
    {
        return $request->hasHeader('HX-Request');
    }

    public function triggerEvent(string $event): void
    {
        $this->responseHeaders->set('HX-Trigger', $event);
    }
}
```

Alternative drivers (Datastar, Turbo, etc.) implement the same interface. Templates use the `hd` helper and remain driver-agnostic:

```twig
<button {{ hd.post('refresh') | raw }}>Refresh</button>
<div {{ hd.on('cartUpdated') | raw }}>...</div>
```

### Component Token Security

Every component action URL is cryptographically signed:

```php
final readonly class ComponentToken
{
    public function encode(string $componentClass, array $props = [], string $action = 'render'): string
    {
        $payload = json_encode([
            'c' => $componentClass,
            'p' => $props,
            'a' => $action,
            't' => time(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $signature = hash_hmac('sha256', $payload, $this->secretKey);

        return sodium_bin2base64(
            $payload . '.' . $signature,
            SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING,
        );
    }

    public function decode(string $token, ?int $maxAge = null): TokenPayload
    {
        // Verify HMAC signature (constant-time comparison)
        // Check optional expiry
        // Return validated payload
    }
}
```

### Component Endpoint — Multi-Layer Security

```
POST /--component/action
GET  /--component/render

Security layers:
1. Token HMAC signature verification
2. Class validation (must extend Component)
3. Action whitelist check (must be in actions() array)
4. Component-level guards (Guarded interface — user-defined auth)
5. PSR-15 middleware (already executed before endpoint)
```

```php
interface Guarded
{
    public function authorize(string $action, Request $request): void;
}
```

### Built-In Security Headers

Ships enabled by default:

```php
Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{auto}'; style-src 'self' 'nonce-{auto}'; ...
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: camera=(), microphone=()
```

CSP nonces flow automatically into the AssetCollector — every inline `<style>` and `<script>` tag gets the nonce attribute.

---

## 7. Data Layer

### Storage Driver Interface

```php
interface StorageDriver
{
    public function findOne(string $type, string $id): ?array;
    public function findMany(string $type, Query $query): ResultSet;
    public function save(string $type, string $id, array $data): void;
    public function delete(string $type, string $id): void;
    public function exists(string $type, string $id): bool;
}
```

### Shipped Drivers

| Driver | Use Case |
|---|---|
| `SqliteDriver` | Zero-config local development, small-to-medium apps |
| `MysqlDriver` | Production databases |
| `JsonFileDriver` | CMS content, config, file-based storage |

SQL drivers share a `PdoDriver` base with driver-specific `QueryCompiler` implementations. Adding PostgreSQL or other databases means implementing `StorageDriver` or extending `PdoDriver` with a new compiler.

### Query Object

```php
final class Query
{
    public function where(string $field, mixed $operator, mixed $value = null): self;
    public function orWhere(string $field, mixed $operator, mixed $value = null): self;
    public function orderBy(string $field, SortDirection $direction = SortDirection::Asc): self;
    public function limit(int $limit): self;
    public function offset(int $offset): self;
    public function search(string $term, array $fields = []): self;
    public function with(string ...$relations): self;
}
```

### Typed Models

For known schemas — define your structure in code with PHP 8.5 attributes:

```php
#[Entity(table: 'posts', storage: 'mysql')]
final class Post extends Model
{
    #[Id]
    public string $uuid;

    #[Field(searchable: true, translatable: true)]
    public string $title;

    #[Field(translatable: true)]
    public string $body;

    #[Field(transform: Transform::Json)]
    public array $metadata = [];

    #[Relation(type: RelationType::BelongsToMany, model: Category::class)]
    public array $categories = [];

    #[Timestamps]
    public \DateTimeImmutable $createdAt;
    public \DateTimeImmutable $updatedAt;
}
```

### Dynamic Models

For runtime-defined schemas (CMS content types). Schema defined in JSON, no PHP class needed:

```json
// workspace/elements/article.json
{
    "storage": "json",
    "fields": [
        {"key": "title", "type": "text", "rules": ["required"], "searchable": true},
        {"key": "body", "type": "richtext", "translatable": true},
        {"key": "category", "type": "relation", "target": "category"}
    ]
}
```

```php
$articles = $data->queryType('article')
    ->where('state', 'published')
    ->search('strategy games')
    ->orderBy('created', SortDirection::Desc)
    ->paginate(perPage: 12, page: $page);
```

### Multi-Storage Per Application

Different models can use different storage backends in the same application:

```php
#[Entity(storage: 'mysql')]   final class User extends Model { ... }
#[Entity(storage: 'json')]    final class SiteSettings extends Model { ... }
#[Entity(storage: 'sqlite')]  final class PageView extends Model { ... }
```

### DataManager — Unified Entry Point

```php
final class DataManager
{
    public function query(string $modelClass): QueryBuilder;      // typed models
    public function queryType(string $type): QueryBuilder;        // dynamic models
    public function save(Model $model): void;
}
```

### Field Transformers

Declared via attributes on model fields:

```php
#[Field(transform: Transform::Json)]      public array $metadata = [];
#[Field(transform: Transform::DateTime)]  public \DateTimeImmutable $publishedAt;
#[Field(transform: Transform::Hash)]      public string $password;
```

Custom transformers:

```php
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Slug implements FieldTransformer
{
    public function __construct(public string $sourceField = 'title') {}

    public function beforeSave(string $field, mixed $value, array $data): mixed
    {
        return $value ?: Str::slug($data[$this->sourceField]);
    }

    public function afterFind(string $field, mixed $value, array $data): mixed
    {
        return $value;
    }
}
```

### Migrations

Lightweight, for SQL drivers:

```php
return new class extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('posts', function (Table $table) {
            $table->uuid('uuid')->primary();
            $table->string('title')->index();
            $table->text('body');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('posts');
    }
};
```

```bash
php preflow migrate
php preflow migrate:fresh    # dev only
php preflow migrate:status
```

---

## 8. Routing

### File-Based Routes (Component Mode)

Directory structure maps directly to URLs:

```
app/pages/
├── index.twig                    → GET /
├── about.twig                    → GET /about
├── blog/
│   ├── index.twig                → GET /blog
│   ├── [slug].twig               → GET /blog/{slug}
│   └── [slug]/comments.twig      → GET /blog/{slug}/comments
├── games/
│   ├── [category]/
│   │   └── index.twig            → GET /games/{category}
│   └── [...path].twig            → GET /games/* (catch-all)
├── _layout.twig                  → Layout wrapper (not a route)
└── _error.twig                   → Error page (not a route)
```

Conventions:
- `[param]` — dynamic segment
- `[...param]` — catch-all segment
- `_layout.twig` — layout file (inherited by children, not routed)
- `_error.twig` — error page for that route segment
- `index.twig` — directory index

### Pages With Logic

Co-located PHP file provides data and guards:

```php
// app/pages/blog/[slug]/index.php
#[Middleware(AuthMiddleware::class)]
return new class extends Page
{
    public function __construct(
        private PostRepository $posts,
    ) {}

    public function data(RouteParams $route): array
    {
        $post = $this->posts->findBySlug($route->slug)
            ?? throw new NotFoundException();

        return ['post' => $post];
    }
};
```

### Attribute-Based Routes (Action Mode)

For APIs, webhooks, custom endpoints:

```php
#[Route('/api/v1/posts')]
#[Middleware(ApiAuthMiddleware::class)]
final class PostController
{
    #[Get('/')]
    public function index(Request $request): JsonResponse { ... }

    #[Get('/{uuid}')]
    public function show(string $uuid): JsonResponse { ... }

    #[Post('/')]
    #[Middleware(AdminMiddleware::class)]
    public function store(Request $request): JsonResponse { ... }

    #[Delete('/{uuid}')]
    #[Middleware(AdminMiddleware::class)]
    public function destroy(string $uuid): JsonResponse { ... }
}
```

### Component Endpoint (Automatic)

Registered automatically by `preflow/htmx`:

```
POST /--component/action    → Component action dispatch
GET  /--component/render    → Component re-render (event refresh)
```

Prefix is configurable. Token-based routing — no user configuration needed.

### Middleware Assignment Levels

```
Global       → config/middleware.php
Route group  → #[Route('/api', middleware: [...])]
Single route → #[Get('/admin', middleware: [...])]
Page         → #[Middleware(...)] in co-located PHP
Layout       → _layout.php (applies to all children)
Component    → Guarded interface
```

### Route Caching

- **Dev:** Routes discovered on every request (fast with opcache)
- **Prod:** Compiled to cached PHP array via `php preflow routes:cache`

---

## 9. Middleware — PSR-15 + Component Hooks

### HTTP Layer

Standard PSR-15 middleware for cross-cutting concerns:

```php
// config/middleware.php
return [
    SecurityHeadersMiddleware::class,  // CSP, X-Frame-Options, etc.
    CorsMiddleware::class,
    SessionMiddleware::class,
    LocaleMiddleware::class,
    CsrfMiddleware::class,
];
```

### Component Layer

Components get their own lifecycle hooks that run inside the component endpoint:

- `resolveState()` — load data
- `bindModel()` — bind POST data
- `actions()` — whitelist callable actions
- `authorize()` — component-level auth (via `Guarded` interface)
- `fallback()` — error boundary handler

### CSRF

Traditional forms use CSRF tokens. Component actions skip CSRF — the HMAC token is the security layer.

```twig
<form method="post">
    {{ csrf() }}
    ...
</form>
```

---

## 10. Internationalization (i18n)

### Translation Files

```
app/lang/
├── en/
│   ├── app.php
│   ├── auth.php
│   └── blog.php
└── de/
    ├── app.php
    ├── auth.php
    └── blog.php
```

```php
// app/lang/en/blog.php
return [
    'title' => 'Blog',
    'post_count' => '{0} No posts|{1} One post|[2,*] :count posts',
    'published_at' => 'Published :date',
    'filter.category' => 'Filter by category',
];
```

### Template Usage

```twig
{{ t('blog.title') }}
{{ t('blog.published_at', { date: post.createdAt | date('d.m.Y') }) }}
{{ t('blog.post_count', { count: total }, count: total) }}

{# Component-scoped: auto-prefixes with component name #}
{{ tc('read_more') }}
```

### Locale Detection

Priority: URL prefix → cookie → Accept-Language header → default.

```php
// config/i18n.php
return [
    'default' => 'en',
    'available' => ['en', 'de', 'fr'],
    'fallback' => 'en',
    'url_strategy' => 'prefix',  // prefix | subdomain | none
];
```

URL examples: `/en/blog/my-post`, `/de/blog/mein-beitrag`.
The router strips the locale prefix before matching — page files don't need per-locale duplicates.

### Translatable Model Fields

Ties directly into the data layer:

```php
#[Field(translatable: true)]
public string $title;

// Querying automatically uses current locale
$post = $posts->findBySlug('my-post');
echo $post->title; // Returns title in current locale
```

### Translation Storage Strategy

**SQL drivers:** Translatable fields are stored in a companion `_translations` table:

```
posts                          post_translations
┌──────────┬───────────┐      ┌──────────┬────────┬───────┬──────────┐
│ uuid     │ slug      │      │ post_uuid│ locale │ title │ body     │
├──────────┼───────────┤      ├──────────┼────────┼───────┼──────────┤
│ abc-123  │ my-post   │      │ abc-123  │ en     │ Hi    │ Hello... │
│          │           │      │ abc-123  │ de     │ Hallo │ Hallo... │
└──────────┴───────────┘      └──────────┴────────┴───────┴──────────┘
```

Non-translatable fields stay on the main table. The query layer auto-joins the translation table for the current locale with fallback to the default locale.

**JSON driver:** Each locale gets its own file or a `_translations` key within the record:

```json
{
    "uuid": "abc-123",
    "slug": "my-post",
    "_translations": {
        "en": { "title": "Hi", "body": "Hello..." },
        "de": { "title": "Hallo", "body": "Hallo..." }
    }
}
```

Migration helper auto-generates the companion table:

```php
$schema->create('posts', function (Table $table) {
    $table->uuid('uuid')->primary();
    $table->string('slug');
    $table->timestamps();
    $table->translations('title', 'body'); // creates post_translations table
});
```

---

## 11. Testing — preflow/testing

Ships from day one with helpers for every framework layer.

### Component Testing

```php
final class BlogFilterTest extends ComponentTestCase
{
    public function test_emits_filter_event(): void
    {
        $category = Category::factory()->create(['slug' => 'strategy']);

        $result = $this->action(
            BlogFilter::class,
            action: 'filter',
            props: [],
            body: ['category' => 'strategy'],
        );

        $result->assertOk();
        $result->assertEventEmitted('postsFiltered');
    }

    public function test_error_boundary_renders_fallback(): void
    {
        $result = $this->render(ErrorExample::class, props: ['break' => true]);

        $result->assertSee('The component crashed, but the page survived.');
        $result->assertNoException();
    }
}
```

### Asset Testing

```php
public function test_css_deduplicated(): void
{
    $result = $this->renderPage('pages/index');

    $result->assertCssCount('.blog-post', times: 1);
    $result->assertNoAssetRequests();
}
```

### Route Testing

```php
public function test_file_route_resolves(): void
{
    $this->get('/blog/my-post')
        ->assertOk()
        ->assertIsComponentMode();
}

public function test_api_returns_json(): void
{
    $this->get('/api/v1/posts')
        ->assertOk()
        ->assertJson();
}

public function test_auth_required(): void
{
    $this->get('/admin/dashboard')
        ->assertRedirect('/login');

    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/dashboard')
        ->assertOk();
}
```

### Data Layer Testing

```php
public function test_storage_drivers_behave_identically(): void
{
    $this->forEachDriver(['sqlite', 'json'], function () {
        $this->save('article', ['title' => 'Test', 'state' => 'published']);
        $result = $this->query('article')->where('state', 'published')->first();
        $this->assertEquals('Test', $result['title']);
    });
}
```

### Security Testing

```php
public function test_token_expiry(): void
{
    $token = $this->createComponentToken(DiscoveryHero::class);
    $this->travelTo(now()->addHours(25));

    $this->post('/--component/action', ['token' => $token])
        ->assertForbidden();
}

public function test_csp_headers(): void
{
    $this->get('/')
        ->assertHeaderContains('Content-Security-Policy', "script-src 'self' 'nonce-")
        ->assertHeader('X-Content-Type-Options', 'nosniff');
}
```

### Factories

```php
final class PostFactory extends Factory
{
    protected string $model = Post::class;

    public function definition(): array
    {
        return [
            'uuid' => $this->faker->uuid(),
            'title' => $this->faker->sentence(),
            'body' => $this->faker->paragraphs(3, asText: true),
            'state' => 'published',
        ];
    }

    public function draft(): self
    {
        return $this->state(['state' => 'draft']);
    }
}
```

---

## 12. Developer Tools — preflow/devtools

### Error Pages

Rich error page in dev mode:
- Full stack trace with source code context
- Request details (headers, params, session)
- Component context if error occurred during component render
- Clickable file paths (opens in editor)
- Query log showing all queries before the error

### Component Inspector

Dev-only toolbar injected at the bottom of every page:

```
┌─ Preflow Inspector ──────────────────────────────────────────┐
│ Components (7)  │  Queries (12)  │  Assets  │  Route        │
│                                                              │
│ ▸ Layout                    3.2ms                            │
│   ▸ NavBar                  1.1ms                            │
│   ▸ DiscoveryHero          45.3ms  ⚠ slow                   │
│   ▸ BlogGrid               12.4ms                           │
│     ▸ BlogPost ×6            0.8ms avg                       │
│   ▸ Footer                  0.4ms                            │
│                                                              │
│ CSS: 3 blocks (2.1KB) │ JS: 2 blocks (1.4KB) │ 0 external  │
│ Route: pages/index.twig │ Mode: Component │ 67ms total      │
└──────────────────────────────────────────────────────────────┘
```

Shows: component tree with render times, query log grouped by component, asset inventory, route info, N+1 query detection.

### CLI Tools

```bash
php preflow serve                # Development server with file watching
php preflow routes:list          # Show all registered routes
php preflow routes:cache         # Compile route cache for production
php preflow components:list      # List all discovered components
php preflow make:component Name  # Scaffold component (PHP + template)
php preflow make:model Name      # Scaffold typed model
php preflow make:controller Name # Scaffold controller
php preflow make:migration name  # Create migration file
php preflow migrate              # Run pending migrations
php preflow migrate:fresh        # Reset and re-run (dev only)
php preflow migrate:status       # Show migration status
php preflow db:seed              # Run database seeders
php preflow test                 # Run test suite
php preflow cache:clear          # Clear all framework caches
php preflow config:check         # Validate configuration
```

### Hot Reload

The dev server watches for changes:
- `.php` files — clears opcache
- `.twig` files — clears template cache
- `.json` definition files — reloads model definitions

No build tools. No webpack. No node_modules. Save and refresh.

---

## 13. Project Skeleton

What `composer create-project preflow/skeleton myapp` generates:

```
myapp/
├── app/
│   ├── Components/
│   │   ├── BlogPost/
│   │   │   ├── BlogPost.php
│   │   │   └── BlogPost.twig
│   │   ├── BlogFilter/
│   │   │   ├── BlogFilter.php
│   │   │   └── BlogFilter.twig
│   │   ├── BlogGrid/
│   │   │   ├── BlogGrid.php
│   │   │   └── BlogGrid.twig
│   │   ├── LocaleSwitcher/
│   │   │   ├── LocaleSwitcher.php
│   │   │   └── LocaleSwitcher.twig
│   │   └── ErrorExample/
│   │       ├── ErrorExample.php
│   │       └── ErrorExample.twig
│   ├── Controllers/Api/
│   │   └── PostController.php
│   ├── Models/
│   │   ├── Post.php
│   │   ├── Category.php
│   │   └── User.php
│   ├── Middleware/
│   │   └── AuthMiddleware.php
│   ├── Providers/
│   │   └── AppServiceProvider.php
│   └── pages/
│       ├── _layout.twig
│       ├── _error.twig
│       ├── index.twig
│       ├── blog/[slug].twig
│       ├── auth/login.twig
│       ├── auth/register.twig
│       └── admin/index.twig
├── config/
│   ├── app.php
│   ├── auth.php
│   ├── data.php
│   ├── i18n.php
│   ├── middleware.php
│   ├── providers.php
│   └── view.php
├── lang/
│   ├── en/ (app.php, auth.php, blog.php)
│   └── de/ (app.php, auth.php, blog.php)
├── migrations/
├── public/ (index.php, favicon.ico, assets/)
├── storage/ (cache/, logs/, data/)
├── tests/
│   ├── Components/
│   ├── Api/
│   ├── Auth/
│   ├── I18n/
│   └── TestCase.php
├── workspace/elements/
├── .env, .env.example
├── composer.json, phpunit.xml
└── preflow
```

### What the Skeleton Demonstrates

| Component/Feature | Demonstrates |
|---|---|
| **BlogFilter** | Component events via `hd`, emitting `postsFiltered` |
| **BlogGrid** | Listening to events, re-rendering on filter change, pagination |
| **BlogPost** | Basic component with co-located CSS/JS |
| **LocaleSwitcher** | i18n integration, locale switching |
| **ErrorExample** | Deliberate error boundary — crashes gracefully |
| **PostController** | REST API (Action Mode) alongside component pages |
| **Auth pages** | Session auth, middleware protection, CSRF |
| **Bilingual content** | Translatable model fields, `t()` in templates |

### First Run

```bash
composer create-project preflow/skeleton myapp
cd myapp
cp .env.example .env
php preflow migrate
php preflow db:seed        # 2 categories, 6 posts (en+de), 1 admin user
php preflow serve

# → http://localhost:8080
# → admin@preflow.dev / preflow
```

Working bilingual blog with filterable posts, REST API, auth, and visible error boundary — every USP demonstrated in under 60 seconds.

---

## 14. Summary of Differentiators

| Feature | Preflow | Laravel | Symfony | Slim |
|---|---|---|---|---|
| Component-first architecture | Yes | No (Blade is templates, not components) | No (Twig is templates) | No |
| Co-located CSS/JS in components | Yes | No | No | No |
| Zero external asset requests | Yes | No (Vite) | No (Webpack Encore) | No |
| Component error boundaries | Yes | No | No | No |
| Hypermedia driver abstraction | Yes | No (Livewire is Livewire) | No | No |
| Multi-storage per app (DB + JSON) | Yes | No | No | No |
| Dynamic models from JSON definitions | Yes | No | No | No |
| File-based + attribute routing hybrid | Yes | No | No | No |
| i18n with translatable model fields | Yes | Partial | Partial | No |
| PHP 8.5+ only | Yes | No (BC) | No (BC) | No (BC) |
