# Folio Live Preview 3a Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a draft-aware live preview: a Preview button on the page edit/create form opens a full-screen split that renders the in-progress, unsaved form values through the real frontend template into a resizable, style-isolated iframe.

**Architecture:** A side-effect-free `PreviewController` builds an in-memory (unsaved) `DynamicRecord` from the POSTed form values and renders the same `@folio/frontend/page.twig` the public site uses (reusing `RecordRenderer::renderedMap`) — no save, no validation gate, no published-status gate. New admin POST routes `{type}/preview` and `{type}/{id}/preview` (ordered before `store`/`update`). `admin.js` toggles a full-screen overlay, reparents the real `<form>` into it, debounces changes, `fetch`-POSTs to the preview route, and sets `iframe.srcdoc` (style isolation) with viewport-width presets (responsive simulation).

**Tech Stack:** PHP 8.5, `preflow/folio`, `preflow/data`, Twig, vanilla JS (`admin.js`), PHPUnit 11. No build step; no new Composer dependency.

## Global Constraints

- **PHP 8.5+**, `declare(strict_types=1)` in every PHP file.
- **No build step; no new Composer dependency.** `admin.js` is hand-written vanilla JS.
- **No emojis** in code or UI copy ("Preview", "Close", "Desktop"/"Tablet"/"Mobile").
- **Side-effect-free preview:** the preview path MUST NOT write storage, MUST NOT persist uploads, MUST NOT call `saveType` (so no validation), and MUST NOT apply the `status==='published'` gate. It renders whatever draft values arrive.
- **Reuse the real render path:** preview renders `@folio/frontend/page.twig` via `RecordRenderer::renderedMap` — identical sanitization/escaping as the live site (no new XSS surface).
- **Frontend type only:** preview is enabled solely for the frontend type (`page` — the literal `FrontendResolver` is constructed with). 404 for any other type.
- **Route ordering:** `POST {prefix}/{type}/preview` MUST be registered before `POST {prefix}/{type}/{id}` (update), or the update route (`id="preview"`) shadows it.
- **CSRF:** preview POSTs carry the form's existing session `_csrf_token` (FormData includes the hidden input); admin routes keep `middleware: []` exactly as today.
- **Test command:** `vendor/bin/phpunit <path>` from `/Users/smyr/Sites/gbits/flopp`. Integration/demo run under strict Twig (`APP_DEBUG=1` scoped to the path); the full suite runs with NO `APP_DEBUG` exported (the core `ApplicationEnvTest` asserts `.env` precedence and fails if `APP_DEBUG` is set in the shell).

## File Structure

- `packages/folio/src/Http/PreviewController.php` — **new**: builds the draft record + renders the frontend template.
- `packages/folio/src/Routing/FolioRoutes.php` — **modify**: two preview routes (different controller), ordered before store/update.
- `packages/folio/src/Http/AdminController.php` — **modify**: inject the frontend type; pass `previewable`/`preview_url` into the form context.
- `packages/folio/src/FolioServiceProvider.php` — **modify**: a single `$frontendType` value feeding `FrontendResolver`, `AdminController`, and a new `PreviewController` bind.
- `packages/folio/templates/admin/form.twig` — **modify**: Preview button in the topbar `actions` block when previewable.
- `packages/folio/assets/admin.js` — **modify**: preview overlay (reparent form, viewport presets, debounced fetch→srcdoc, close).
- `packages/folio/assets/admin.css` — **modify**: overlay/stage/frame styles.
- Tests: `packages/folio/tests/Http/PreviewControllerTest.php` (new), `packages/folio/tests/Integration/FolioAppTest.php` (modify), `packages/folio/tests/Assets/AdminJsTest.php` (modify).

---

### Task 1: `PreviewController` (draft build + frontend render)

**Files:**
- Create: `packages/folio/src/Http/PreviewController.php`
- Test: `packages/folio/tests/Http/PreviewControllerTest.php` (new)

**Interfaces:**
- Consumes: `TypeCatalog::has`; `TypeRegistry::get`; `DataManager::findType`/`queryType`; `FieldTypeRegistry::get`; `FieldType` (`normalizeInput`/`toStorage`/`fromStorage`) + `HandlesUpload` (`storeUploads`); `RecordRenderer::renderedMap`; `TemplateEngineInterface::render`; `DynamicRecord::fromArray`/`toArray`.
- Produces: `PreviewController::__construct(TypeCatalog $catalog, TypeRegistry $registry, DataManager $dm, FieldTypeRegistry $fieldTypes, RecordRenderer $records, TemplateEngineInterface $engine, string $frontendType)` and `preview(ServerRequestInterface $request): ResponseInterface` — returns `200 text/html` (rendered `@folio/frontend/page.twig`) for the frontend type, `404` otherwise; reads `type`/`id` request attributes and the parsed body; never persists.

- [ ] **Step 1: Write the failing test**

Create `packages/folio/tests/Http/PreviewControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\RecordRenderer;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\Folio\Field\Types\TextFieldType;
use Preflow\Folio\Http\PreviewController;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

final class PreviewControllerTest extends TestCase
{
    private string $base;
    private TypeRegistry $registry;
    private DataManager $dm;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/folio_prev_' . bin2hex(random_bytes(4));
        mkdir($this->base . '/m', 0777, true);
        mkdir($this->base . '/s', 0777, true);
        file_put_contents($this->base . '/m/page.json', json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Pages',
            'fields' => [
                'title' => ['type' => 'string', 'validate' => ['required']],
                'body' => ['type' => 'text'],
                'status' => ['type' => 'string'],
            ],
        ]));
        // a second, non-frontend type so the 404 path is exercised
        file_put_contents($this->base . '/m/note.json', json_encode([
            'key' => 'note', 'table' => 'note', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Notes',
            'fields' => ['name' => ['type' => 'string']],
        ]));
        $this->registry = new TypeRegistry($this->base . '/m');
        $this->dm = new DataManager(['json' => new JsonFileDriver($this->base . '/s')], 'json', $this->registry);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->base);
    }

    private function fieldTypes(): FieldTypeRegistry
    {
        $r = new FieldTypeRegistry();
        $r->register(new StringFieldType());
        $r->register(new TextFieldType());
        return $r;
    }

    private function engine(): TemplateEngineInterface
    {
        // Echoes the frontend render inputs so the test can see draft values.
        return new class implements TemplateEngineInterface {
            public function render(string $template, array $context = []): string
            {
                return $template . '|record=' . json_encode($context['record'] ?? [])
                    . '|rendered=' . json_encode($context['rendered'] ?? []);
            }
            public function exists(string $template): bool { return false; }
            public function addFunction(TemplateFunctionDefinition $f): void {}
            public function addGlobal(string $n, mixed $v): void {}
            public function getTemplateExtension(): string { return 'twig'; }
            public function addNamespace(string $n, string $p): void {}
        };
    }

    private function controller(): PreviewController
    {
        $fieldTypes = $this->fieldTypes();
        $engine = $this->engine();
        return new PreviewController(
            new TypeCatalog($this->base . '/m'),
            $this->registry,
            $this->dm,
            $fieldTypes,
            new RecordRenderer($fieldTypes, $engine),
            $engine,
            'page',
        );
    }

    public function test_renders_draft_values_without_saving(): void
    {
        $req = (new Psr17Factory())->createServerRequest('POST', '/folio/page/preview')
            ->withAttribute('type', 'page')
            ->withParsedBody(['title' => 'Draft Title', 'body' => 'Draft body', 'status' => 'draft']);

        $res = $this->controller()->preview($req);

        $this->assertSame(200, $res->getStatusCode());
        $body = (string) $res->getBody();
        $this->assertStringContainsString('@folio/frontend/page.twig', $body);
        $this->assertStringContainsString('Draft Title', $body);  // in record
        $this->assertStringContainsString('Draft body', $body);   // in rendered map
        // Nothing was persisted.
        $this->assertCount(0, $this->dm->queryType('page')->get()->items());
    }

    public function test_existing_record_preview_does_not_mutate_storage(): void
    {
        $this->dm->saveType(DynamicRecord::fromArray($this->registry->get('page'),
            ['uuid' => 'p1', 'title' => 'Original', 'body' => 'b', 'status' => 'published']));

        $req = (new Psr17Factory())->createServerRequest('POST', '/folio/page/p1/preview')
            ->withAttribute('type', 'page')->withAttribute('id', 'p1')
            ->withParsedBody(['title' => 'Edited', 'body' => 'b', 'status' => 'published']);

        $res = $this->controller()->preview($req);

        $this->assertStringContainsString('Edited', (string) $res->getBody());      // draft shown
        $this->assertSame('Original', $this->dm->findType('page', 'p1')->get('title')); // storage unchanged
    }

    public function test_unknown_type_404(): void
    {
        $req = (new Psr17Factory())->createServerRequest('POST', '/folio/ghost/preview')
            ->withAttribute('type', 'ghost')->withParsedBody(['title' => 'x']);
        $this->assertSame(404, $this->controller()->preview($req)->getStatusCode());
    }

    public function test_non_frontend_type_404(): void
    {
        $req = (new Psr17Factory())->createServerRequest('POST', '/folio/note/preview')
            ->withAttribute('type', 'note')->withParsedBody(['name' => 'x']);
        $this->assertSame(404, $this->controller()->preview($req)->getStatusCode());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit packages/folio/tests/Http/PreviewControllerTest.php`
Expected: FAIL — `Preflow\Folio\Http\PreviewController` does not exist.

- [ ] **Step 3: Create the controller**

Create `packages/folio/src/Http/PreviewController.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\RecordRenderer;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\HandlesUpload;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders a draft (in-progress, unsaved) record through the real frontend
 * template for live preview. Side-effect-free: never writes storage, never
 * persists uploads, never calls saveType (so no validation), and ignores the
 * published-status gate. Enabled only for the frontend type.
 */
final class PreviewController
{
    public function __construct(
        private readonly TypeCatalog $catalog,
        private readonly TypeRegistry $registry,
        private readonly DataManager $dm,
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly RecordRenderer $records,
        private readonly TemplateEngineInterface $engine,
        private readonly string $frontendType,
    ) {}

    public function preview(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        if (!$this->catalog->has($type) || $type !== $this->frontendType) {
            return new Response(404, [], 'Not found');
        }

        $typeDef = $this->registry->get($type);
        $id = (string) $request->getAttribute('id', '');
        $draft = $this->draftRecord($typeDef, $request, $id);

        $html = $this->engine->render('@folio/frontend/page.twig', [
            'record' => $draft->toArray(),
            'rendered' => $this->records->renderedMap($draft),
        ]);

        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $html);
    }

    /**
     * Build an in-memory record from the submitted form values WITHOUT saving.
     * Upload fields reuse storeUploads with an EMPTY uploaded list: that writes
     * no files (verified in AssetFieldType::storeUploads — the loop is skipped)
     * yet returns the field's correct domain shape for kept-minus-removed paths.
     */
    private function draftRecord(TypeDefinition $typeDef, ServerRequestInterface $request, string $id): DynamicRecord
    {
        $submitted = (array) $request->getParsedBody();
        $existing = $id !== '' ? ($this->dm->findType($typeDef->key, $id)?->toArray() ?? []) : [];
        $data = [];

        foreach ($typeDef->fields as $name => $fieldDef) {
            if ($name === $typeDef->idField) {
                continue;
            }
            $fieldType = $this->fieldTypes->get($fieldDef->type);

            if ($fieldType instanceof HandlesUpload) {
                $removed = array_values(array_filter(
                    (array) ($submitted[$name . '_remove'] ?? []),
                    static fn ($v) => is_string($v),
                ));
                $existingList = $this->pathList($fieldType->fromStorage($existing[$name] ?? null));
                $kept = array_values(array_diff($existingList, $removed));
                $data[$name] = $fieldType->toStorage($fieldType->storeUploads([], $kept, $fieldDef->config));
                continue;
            }

            $data[$name] = $fieldType->toStorage(
                $fieldType->normalizeInput($submitted[$name] ?? null, $fieldDef->config),
            );
        }

        $data[$typeDef->idField] = $id;

        return DynamicRecord::fromArray($typeDef, $data);
    }

    /** @return string[] */
    private function pathList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, static fn ($v) => is_string($v) && $v !== ''));
        }
        if (is_string($value) && $value !== '') {
            return [$value];
        }
        return [];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit packages/folio/tests/Http/PreviewControllerTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add packages/folio/src/Http/PreviewController.php packages/folio/tests/Http/PreviewControllerTest.php
git commit -m "feat(folio): PreviewController renders draft records without saving"
```

---

### Task 2: Wire preview — routes, provider, Preview button + integration

**Files:**
- Modify: `packages/folio/src/Routing/FolioRoutes.php`
- Modify: `packages/folio/src/FolioServiceProvider.php`
- Modify: `packages/folio/src/Http/AdminController.php`
- Modify: `packages/folio/templates/admin/form.twig`
- Test: `packages/folio/tests/Integration/FolioAppTest.php` (modify)

**Interfaces:**
- Consumes: `PreviewController::preview` (Task 1).
- Produces: routes `POST {prefix}/{type}/preview` and `POST {prefix}/{type}/{id}/preview` → `PreviewController@preview` (registered before `store`/`update`). `PreviewController` bound in the container. `AdminController::__construct` gains a trailing `string $frontendType`; `form()` adds `previewable` (`$type === $this->frontendType`) and `preview_url` (`$action . '/preview'`) to the template context. `form.twig` renders a `data-folio-preview` button with `data-preview-url` in the topbar `actions` block when `previewable`.

- [ ] **Step 1: Add the failing integration tests**

In `packages/folio/tests/Integration/FolioAppTest.php`, add:

```php
    public function test_preview_renders_draft_without_saving(): void
    {
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $res = $app->handle($f->createServerRequest('POST', '/folio/page/preview')->withParsedBody([
            'title' => 'Live Draft', 'slug' => 'live-draft', 'body' => 'b', 'status' => 'draft',
        ]));

        $this->assertSame(200, $res->getStatusCode());                 // not a 302 -> hit preview, not update/store
        $this->assertStringContainsString('Live Draft', (string) $res->getBody());
        $dm = $app->container()->get(\Preflow\Data\DataManager::class);
        $this->assertNull($dm->queryType('page')->where('slug', 'live-draft')->first()); // nothing saved
    }

    public function test_preview_existing_record_leaves_storage_unchanged(): void
    {
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $app->handle($f->createServerRequest('POST', '/folio/page')->withParsedBody([
            'title' => 'Orig', 'slug' => 'orig', 'body' => 'b', 'status' => 'published',
        ]));
        $dm = $app->container()->get(\Preflow\Data\DataManager::class);
        $id = $dm->queryType('page')->where('slug', 'orig')->first()->getId();

        $res = $app->handle($f->createServerRequest('POST', '/folio/page/' . $id . '/preview')
            ->withParsedBody(['title' => 'Edited', 'slug' => 'orig', 'body' => 'b', 'status' => 'published']));

        $this->assertStringContainsString('Edited', (string) $res->getBody());          // draft shown
        $this->assertSame('Orig', $dm->findType('page', $id)->get('title'));             // storage unchanged
    }

    public function test_edit_form_has_preview_button_for_page(): void
    {
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $body = (string) $app->handle($f->createServerRequest('GET', '/folio/page/new'))->getBody();
        $this->assertStringContainsString('data-folio-preview', $body);
        $this->assertStringContainsString('data-preview-url="/folio/page/preview"', $body);
    }

    public function test_edit_form_no_preview_button_for_non_frontend_type(): void
    {
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $body = (string) $app->handle($f->createServerRequest('GET', '/folio/note/new'))->getBody();
        $this->assertStringNotContainsString('data-folio-preview', $body);
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `APP_DEBUG=1 vendor/bin/phpunit packages/folio/tests/Integration/FolioAppTest.php --filter preview`
Expected: FAIL — no preview route (the `/page/preview` POST falls through to `update` → 302/404), no `data-folio-preview` button.

- [ ] **Step 3: Add the preview routes (correct ordering, separate controller)**

In `packages/folio/src/Routing/FolioRoutes.php`:

(a) Add a class constant beside `ADMIN`:

```php
    private const ADMIN = 'Preflow\\Folio\\Http\\AdminController';
    private const PREVIEW = 'Preflow\\Folio\\Http\\PreviewController';
```

(b) Insert the two preview entries into `$defs` at the right positions (4th tuple element = handler class):

```php
        $defs = [
            ['GET',  $prefix,                          'index'],
            ['GET',  $prefix . '/{type}',              'list'],
            ['GET',  $prefix . '/{type}/new',          'createForm'],
            ['POST', $prefix . '/{type}/preview',      'preview', self::PREVIEW],
            ['POST', $prefix . '/{type}',              'store'],
            ['GET',  $prefix . '/{type}/{id}/edit',    'editForm'],
            ['GET',  $prefix . '/{type}/{id}/label',   'recordLabel'],
            ['POST', $prefix . '/{type}/{id}/preview', 'preview', self::PREVIEW],
            ['POST', $prefix . '/{type}/{id}',         'update'],
            ['POST', $prefix . '/{type}/{id}/delete',  'destroy'],
        ];
```

(c) Update the build loop to honor the optional handler class (replace the `foreach ($defs as [$method, $pattern, $action])` block):

```php
        $entries = [];
        foreach ($defs as $def) {
            [$method, $pattern, $action] = $def;
            $handlerClass = $def[3] ?? self::ADMIN;
            $c = PatternCompiler::compile($pattern);
            $entries[] = new RouteEntry(
                pattern: $pattern,
                handler: $handlerClass . '@' . $action,
                method: $method,
                mode: RouteMode::Action,
                middleware: [],
                paramNames: $c['paramNames'],
                regex: $c['regex'],
                isCatchAll: $c['isCatchAll'],
            );
        }
```

- [ ] **Step 4: Bind `PreviewController` + thread the frontend type**

In `packages/folio/src/FolioServiceProvider.php`:

(a) Add the import (after `use Preflow\Folio\Http\FrontendController;`):

```php
use Preflow\Folio\Http\PreviewController;
```

(b) Define a single frontend-type value near the top of `register()` (right after `$prefix = $this->prefix($app);`):

```php
        $frontendType = 'page';
```

(c) Use it in the `FrontendResolver` bind (replace the hard-coded `'page'`):

```php
        $container->bind(FrontendResolver::class, fn (Container $c) => new FrontendResolver($c->get(DataManager::class), $frontendType));
```

(d) Append `$frontendType` to the `AdminController` bind args (after `new RecordLabeler()`):

```php
        $container->bind(AdminController::class, fn (Container $c) => new AdminController(
            $c->get(TypeCatalog::class),
            $c->get(TypeRegistry::class),
            $c->get(DataManager::class),
            $c->get(TemplateEngineInterface::class),
            $c->get(ActionResolver::class),
            $c->get(FieldTypeRegistry::class),
            $prefix,
            new RecordLabeler(),
            $frontendType,
        ));
```

(e) Add the `PreviewController` bind (after the `FrontendController` bind):

```php
        $container->bind(PreviewController::class, fn (Container $c) => new PreviewController(
            $c->get(TypeCatalog::class),
            $c->get(TypeRegistry::class),
            $c->get(DataManager::class),
            $c->get(FieldTypeRegistry::class),
            new RecordRenderer($c->get(FieldTypeRegistry::class), $c->get(TemplateEngineInterface::class)),
            $c->get(TemplateEngineInterface::class),
            $frontendType,
        ));
```

(`FrontendResolver`'s closure now reads `$frontendType` — ensure it is captured; the closures use `fn` arrow functions which auto-capture by value, so `$frontendType` is in scope.)

- [ ] **Step 5: Inject the frontend type into `AdminController` + expose preview in `form()`**

In `packages/folio/src/Http/AdminController.php`:

(a) Append the constructor property (after `private readonly RecordLabeler $labeler,`):

```php
        private readonly RecordLabeler $labeler,
        private readonly string $frontendType,
    ) {}
```

(b) In `form()`, add `previewable`/`preview_url` to the render context (alongside the existing keys, after `'layout' => $layout,`):

```php
            'layout' => $layout,
            'previewable' => $type === $this->frontendType,
            'preview_url' => $action . '/preview',
        ]);
```

- [ ] **Step 6: Render the Preview button in the form**

In `packages/folio/templates/admin/form.twig`, add an `actions` block (after the `page_title` block line):

```twig
{% block actions %}{% if previewable|default(false) %}<button type="button" class="btn btn-secondary" data-folio-preview data-preview-url="{{ preview_url }}">Preview</button>{% endif %}{% endblock %}
```

(The bare `@folio/admin/_drawer_layout.twig` has no `actions` block, so this override is simply ignored in drawer mode — where `previewable` is false anyway.)

- [ ] **Step 7: Run the integration suite**

Run: `APP_DEBUG=1 vendor/bin/phpunit packages/folio/tests/Integration/FolioAppTest.php`
Expected: PASS — the four new preview tests plus all existing tests (the new routes don't shadow store/update; the Preview button renders only for `page`).

- [ ] **Step 8: Commit**

```bash
git add packages/folio/src/Routing/FolioRoutes.php packages/folio/src/FolioServiceProvider.php packages/folio/src/Http/AdminController.php packages/folio/templates/admin/form.twig packages/folio/tests/Integration/FolioAppTest.php
git commit -m "feat(folio): wire preview routes + Preview button (frontend type only)"
```

---

### Task 3: Admin UI — preview overlay (`admin.js` + CSS)

**Files:**
- Modify: `packages/folio/assets/admin.js`
- Modify: `packages/folio/assets/admin.css`
- Test: `packages/folio/tests/Assets/AdminJsTest.php` (modify)

**Interfaces:**
- Consumes: the `data-folio-preview` button + `data-preview-url` (Task 2); the preview route returning a full HTML document.
- Produces: an `initPreview()` boot step that opens a full-screen `.folio-preview` overlay, reparents the page `<form>` into it, renders via debounced `fetch` POST (`new FormData(form)`) → `iframe.srcdoc`, offers viewport-width presets, and closes (button or Escape) restoring the form.

- [ ] **Step 1: Add the failing static assertions**

In `packages/folio/tests/Assets/AdminJsTest.php`, add:

```php
    public function test_defines_live_preview_overlay(): void
    {
        $js = $this->js();
        $this->assertStringContainsString('data-folio-preview', $js);   // button hook
        $this->assertStringContainsString('folio-preview', $js);        // overlay class
        $this->assertStringContainsString('new FormData(', $js);        // serializes the form
        $this->assertStringContainsString('srcdoc', $js);               // isolated render target
        $this->assertStringContainsString("'768px'", $js);              // tablet preset
        $this->assertStringContainsString("'375px'", $js);              // mobile preset
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit packages/folio/tests/Assets/AdminJsTest.php`
Expected: FAIL — `admin.js` has no preview code.

- [ ] **Step 3: Add the preview overlay to `admin.js`**

In `packages/folio/assets/admin.js`, add this function inside the IIFE (e.g. just before the `boot` function):

```js
    function initPreview() {
        var btn = document.querySelector('[data-folio-preview]');
        if (!btn) { return; }
        var form = document.querySelector('.folio-form form');
        if (!form) { return; }
        var url = btn.getAttribute('data-preview-url') || '';

        var overlay = null, frame = null, anchor = null, parent = null, reqSeq = 0, timer = null;

        function render() {
            if (!frame) { return; }
            var seq = ++reqSeq;
            fetch(url, { method: 'POST', body: new FormData(form), headers: { 'Accept': 'text/html' } })
                .then(function (r) { return r.ok ? r.text() : null; })
                .then(function (html) {
                    if (html != null && seq === reqSeq && frame) { frame.srcdoc = html; }
                })
                .catch(function () {});
        }

        function schedule() {
            if (timer) { clearTimeout(timer); }
            timer = setTimeout(render, 400);
        }

        function setWidth(w) {
            if (frame) { frame.style.width = w; }
        }

        function closePreview() {
            if (timer) { clearTimeout(timer); timer = null; }
            form.removeEventListener('input', schedule);
            form.removeEventListener('change', schedule);
            if (anchor && parent) { parent.insertBefore(form, anchor); parent.removeChild(anchor); }
            if (overlay && overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
            overlay = null; frame = null; anchor = null; parent = null;
        }

        function open() {
            overlay = document.createElement('div');
            overlay.className = 'folio-preview';
            var formPane = document.createElement('div');
            formPane.className = 'folio-preview-form';
            var stage = document.createElement('div');
            stage.className = 'folio-preview-stage';
            var bar = document.createElement('div');
            bar.className = 'folio-preview-bar';

            [['Desktop', '100%'], ['Tablet', '768px'], ['Mobile', '375px']].forEach(function (vp) {
                var b = document.createElement('button');
                b.type = 'button';
                b.textContent = vp[0];
                b.setAttribute('data-preview-viewport', vp[1]);
                b.addEventListener('click', function () { setWidth(vp[1]); });
                bar.appendChild(b);
            });
            var close = document.createElement('button');
            close.type = 'button';
            close.className = 'folio-preview-close';
            close.textContent = 'Close';
            close.addEventListener('click', closePreview);
            bar.appendChild(close);

            frame = document.createElement('iframe');
            frame.className = 'folio-preview-frame';

            stage.appendChild(bar);
            stage.appendChild(frame);

            // Remember the form's home, then move the real form into the overlay
            // (appendChild preserves the node + its listeners, so trix/matrix survive).
            parent = form.parentNode;
            anchor = document.createElement('span');
            anchor.style.display = 'none';
            parent.insertBefore(anchor, form);
            formPane.appendChild(form);

            overlay.appendChild(formPane);
            overlay.appendChild(stage);
            document.body.appendChild(overlay);

            form.addEventListener('input', schedule);
            form.addEventListener('change', schedule);
            render();
        }

        btn.addEventListener('click', open);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && overlay) { closePreview(); }
        });
    }
```

Then call it from `boot()` (add the line alongside the existing boot calls):

```js
        initPreview();
```

- [ ] **Step 4: Add the overlay styles**

Append to `packages/folio/assets/admin.css`:

```css
/* Live preview overlay (3a) */
.folio-preview {
    position: fixed;
    inset: 0;
    z-index: 1100;
    display: flex;
    background: var(--c-bg);
}
.folio-preview-form {
    width: 380px;
    max-width: 40%;
    overflow: auto;
    padding: 1.5rem;
    border-right: 1px solid var(--c-border);
}
.folio-preview-stage {
    flex: 1 1 auto;
    display: flex;
    flex-direction: column;
    background: var(--c-surface);
}
.folio-preview-bar {
    display: flex;
    gap: 0.5rem;
    align-items: center;
    padding: 0.5rem 0.75rem;
    border-bottom: 1px solid var(--c-border);
}
.folio-preview-close {
    margin-left: auto;
}
.folio-preview-frame {
    flex: 1 1 auto;
    width: 100%;
    height: 100%;
    margin: 0 auto;
    border: 0;
    background: #fff;
}
```

- [ ] **Step 5: Run the static + integration suites**

Run: `vendor/bin/phpunit packages/folio/tests/Assets/AdminJsTest.php` and
`APP_DEBUG=1 vendor/bin/phpunit packages/folio/tests/Integration/FolioAppTest.php`
Expected: PASS — admin.js defines the preview overlay; integration still green.

- [ ] **Step 6: Commit**

```bash
git add packages/folio/assets/admin.js packages/folio/assets/admin.css packages/folio/tests/Assets/AdminJsTest.php
git commit -m "feat(folio): live preview overlay (reparent form, viewport presets, debounced srcdoc)"
```

---

### Task 4: Full-suite verification

**Files:** none changed — verification only.

- [ ] **Step 1: Run the folio package suite under strict Twig**

Run: `APP_DEBUG=1 vendor/bin/phpunit packages/folio/tests`
Expected: PASS — all folio tests green.

- [ ] **Step 2: Run the demo smoke test**

Run: `APP_DEBUG=1 vendor/bin/phpunit examples/folio-demo/tests`
Expected: PASS — the demo app boots and serves `/folio`. (No new demo files: the existing demo `page` + `@folio/frontend/page.twig` are previewable as-is.)

- [ ] **Step 3: Run the full repo suite**

Run: `vendor/bin/phpunit` (NO `APP_DEBUG` exported)
Expected: PASS — all suites green; only the pre-existing PHPUnit deprecations + 1 skip. No failures.

---

## Self-Review

**Spec coverage (3a):**
- Preview endpoint building an unsaved record from form values, side-effect-free, reusing the frontend render path → Task 1 (`PreviewController` + `draftRecord`).
- No save / no validation / no published gate → Task 1 (renders without `saveType`; tests assert storage unchanged + draft/invalid renders).
- Asset side-effect-free (kept-minus-removed, no new uploads) → Task 1 (`storeUploads([], $kept, ...)`, verified no-write).
- Routes `{type}/preview` + `{type}/{id}/preview`, ordered before store/update, CSRF via form token → Task 2.
- Frontend-type-only (404 otherwise; button only for `page`) → Task 1 (404) + Task 2 (button gating) + integration tests.
- Craft-style toggle: reparent form, resizable iframe, viewport presets, debounced fetch→srcdoc, style isolation → Task 3.
- Out of scope held: surgical per-field swap (3b), unsaved-upload preview, non-frontend types.

**Placeholder scan:** No `TBD`/`TODO`. Every code step shows full code; every modify step quotes the exact anchor.

**Type consistency:**
- `PreviewController::__construct(TypeCatalog, TypeRegistry, DataManager, FieldTypeRegistry, RecordRenderer, TemplateEngineInterface, string $frontendType)` — identical in Task 1 (test + class) and Task 2 (provider bind).
- `preview(ServerRequestInterface): ResponseInterface` reads `type`/`id` attributes — matched by the route param names (`{type}`/`{id}`) in Task 2.
- `AdminController` constructor gains a trailing `string $frontendType` — Task 2 updates both the class and the provider bind; `form()` emits `previewable`/`preview_url` consumed by `form.twig` (Task 2 Step 6).
- `data-folio-preview` + `data-preview-url` produced in `form.twig` (Task 2) and consumed in `admin.js initPreview` (Task 3); asserted in Task 2 (integration) and Task 3 (static).
- `$frontendType = 'page'` is the single source feeding `FrontendResolver`, `AdminController`, and `PreviewController` (Task 2 Step 4).
- Route handler-class override (`$def[3] ?? self::ADMIN`) lets the preview routes target `PreviewController` while all others stay on `AdminController` (Task 2 Step 3).
```
