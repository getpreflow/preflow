# Folio Matrix Create-in-Drawer (Phase 5b) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** From the matrix editor, let an author create a brand-new record of an allowed type in a drawer (iframe) and, on save, append a reference row — the new id is `postMessage`d back and the label resolved via a small JSON API.

**Architecture:** Extract the matrix's record-label logic into a shared `RecordLabeler`. Add a read-only label API (`GET {prefix}/{type}/{id}/label` → `{id,label}`). Give `createForm`/`store` a `?_drawer=1` mode that renders a bare layout and, on success, returns a tiny page that `postMessage`s `{source:'folio-drawer',type,id}` to the opener instead of redirecting. `admin.js` gains a "New" button that opens the drawer, a single origin-checked `message` listener, and a label `fetch` that calls the existing `addRow`.

**Tech Stack:** PHP 8.5, `preflow/folio`, `preflow/data`, Twig, vanilla JS (`admin.js`), PHPUnit 11. No build step; no new Composer dependency.

## Global Constraints

- **PHP 8.5+**, `declare(strict_types=1)` in every PHP file.
- **No build step; no new Composer dependency.** `admin.js` stays hand-written vanilla JS served via the existing asset route; declared by `MatrixFieldType::assets()`.
- **No emojis** in code or UI copy (use plain text: "New", "Close").
- **Additive on top of 5a** — no change to the stored `[{_type,id}]` shape, the pick-existing flow, or per-type frontend rendering.
- **postMessage is origin-pinned:** the saved page posts to `window.location.origin`; the parent listener accepts only `event.origin === window.location.origin` AND `data.source === 'folio-drawer'`. No wildcard target origin.
- **Label API is read-only GET** behind the same admin-auth surface as every other folio route; guards unknown type/record with `404`; exposes only id+label (already present in the editor picker blob).
- **Drawer-mode flag is read from the URI query directly** (`parse_str($request->getUri()->getQuery(), …)`), not `getQueryParams()`, so it works regardless of query-param middleware.
- **Escaping:** label inserted client-side via the existing `esc()` in `addRow`; `drawer_saved.twig` injects `type`/`id` with Twig `json_encode` inside `<script>`; the matrix options blob keeps `JSON_HEX_TAG | JSON_UNESCAPED_SLASHES`.
- **Test command:** `vendor/bin/phpunit <path>` from repo root `/Users/smyr/Sites/gbits/flopp`. Integration tests run under strict Twig (`APP_DEBUG=1`).

## File Structure

- `packages/folio/src/Content/RecordLabeler.php` — **new**: `label(DynamicRecord): string`.
- `packages/folio/src/Field/Types/MatrixFieldType.php` — **modify**: delegate labels to `RecordLabeler`; inject `prefix`; embed `prefix` in the options blob; add the "New" button.
- `packages/folio/src/Http/AdminController.php` — **modify**: `recordLabel()`; `?_drawer=1` in `createForm`/`store`; `layout` param on `form()`; inject `RecordLabeler`.
- `packages/folio/src/Routing/FolioRoutes.php` — **modify**: label route.
- `packages/folio/src/FolioServiceProvider.php` — **modify**: pass `RecordLabeler` + `prefix` to `MatrixFieldType`; pass `RecordLabeler` to `AdminController`.
- `packages/folio/templates/admin/_drawer_layout.twig` — **new**: bare layout (no shell).
- `packages/folio/templates/admin/drawer_saved.twig` — **new**: postMessage page.
- `packages/folio/templates/admin/form.twig` — **modify**: dynamic `{% extends layout %}`.
- `packages/folio/assets/admin.js` — **modify**: drawer + create + message listener + label fetch.
- `packages/folio/assets/admin.css` — **modify**: drawer + create-button styles.
- Tests: `tests/Content/RecordLabelerTest.php` (new), `tests/Http/AdminControllerTest.php` (modify), `tests/Field/MatrixFieldTypeTest.php` (modify), `tests/Integration/FolioAppTest.php` (modify), `tests/Assets/AdminJsTest.php` (new), `tests/TemplatesExistTest.php` (modify).

---

### Task 1: `RecordLabeler` (extract shared label logic)

Behavior-preserving extraction of the matrix's private "first string field, else id" logic into a standalone, dependency-free service.

**Files:**
- Create: `packages/folio/src/Content/RecordLabeler.php`
- Test: `packages/folio/tests/Content/RecordLabelerTest.php`

**Interfaces:**
- Consumes: `Preflow\Data\DynamicRecord` (`getType(): TypeDefinition`, `get(string): mixed`, `getId(): ?string`); `TypeDefinition->fields` (map of name → field def with `->type`).
- Produces: `RecordLabeler` with `label(DynamicRecord $record): string` — value of the first `string`-typed field if non-empty, else the id. Only the **first** string field is consulted (matches 5a `MatrixFieldType::recordLabel`).

- [ ] **Step 1: Write the failing test**

Create `packages/folio/tests/Content/RecordLabelerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\RecordLabeler;

final class RecordLabelerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rl_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    /** @param array<string,array<string,mixed>> $fields */
    private function reg(array $fields): TypeRegistry
    {
        file_put_contents($this->dir . '/thing.json', json_encode([
            'key' => 'thing', 'table' => 'thing', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => $fields,
        ]));
        return new TypeRegistry($this->dir);
    }

    public function test_uses_first_string_field_value(): void
    {
        $reg = $this->reg(['name' => ['type' => 'string'], 'note' => ['type' => 'string']]);
        $rec = DynamicRecord::fromArray($reg->get('thing'), ['uuid' => 't1', 'name' => 'Hello', 'note' => 'X']);
        $this->assertSame('Hello', (new RecordLabeler())->label($rec));
    }

    public function test_falls_back_to_id_when_first_string_empty(): void
    {
        $reg = $this->reg(['name' => ['type' => 'string']]);
        $rec = DynamicRecord::fromArray($reg->get('thing'), ['uuid' => 't2', 'name' => '']);
        $this->assertSame('t2', (new RecordLabeler())->label($rec));
    }

    public function test_only_first_string_field_considered(): void
    {
        // First string field empty -> id (the later non-empty string is NOT used).
        $reg = $this->reg(['name' => ['type' => 'string'], 'alt' => ['type' => 'string']]);
        $rec = DynamicRecord::fromArray($reg->get('thing'), ['uuid' => 't4', 'name' => '', 'alt' => 'Second']);
        $this->assertSame('t4', (new RecordLabeler())->label($rec));
    }

    public function test_falls_back_to_id_when_no_string_field(): void
    {
        $reg = $this->reg(['count' => ['type' => 'number']]);
        $rec = DynamicRecord::fromArray($reg->get('thing'), ['uuid' => 't3', 'count' => 5]);
        $this->assertSame('t3', (new RecordLabeler())->label($rec));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit packages/folio/tests/Content/RecordLabelerTest.php`
Expected: FAIL — `Preflow\Folio\Content\RecordLabeler` does not exist.

- [ ] **Step 3: Create `RecordLabeler`**

Create `packages/folio/src/Content/RecordLabeler.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\DynamicRecord;

/**
 * Resolves a human label for a record: the value of its first string field,
 * falling back to the record id. Single source of truth shared by the matrix
 * editor and the record-label API.
 */
final class RecordLabeler
{
    public function label(DynamicRecord $record): string
    {
        foreach ($record->getType()->fields as $name => $def) {
            if ($def->type === 'string') {
                $value = $record->get($name);
                if (is_string($value) && $value !== '') {
                    return $value;
                }
                break;
            }
        }

        return (string) $record->getId();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit packages/folio/tests/Content/RecordLabelerTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add packages/folio/src/Content/RecordLabeler.php packages/folio/tests/Content/RecordLabelerTest.php
git commit -m "feat(folio): RecordLabeler — shared first-string-field label resolution"
```

---

### Task 2: `MatrixFieldType` delegates to `RecordLabeler` + embeds `prefix`

Refactor the field type to use `RecordLabeler` (removing its private `recordLabel`) and inject the admin `prefix` so the options blob can carry it for `admin.js`.

**Files:**
- Modify: `packages/folio/src/Field/Types/MatrixFieldType.php`
- Modify: `packages/folio/src/FolioServiceProvider.php`
- Test: `packages/folio/tests/Field/MatrixFieldTypeTest.php`

**Interfaces:**
- Consumes: `Preflow\Folio\Content\RecordLabeler` (Task 1).
- Produces: `MatrixFieldType::__construct(TypeCatalog $catalog, TypeRegistry $registry, DataManager $dm, RecordRenderer $records, RecordLabeler $labeler, string $prefix)`. Options blob gains a top-level `"prefix"` string (e.g. `"/folio"`). Label rendering (editor rows + picker blob) routes through `RecordLabeler::label`.

- [ ] **Step 1: Update the test for the new constructor + add the prefix assertion**

In `packages/folio/tests/Field/MatrixFieldTypeTest.php`:

(a) Add the import near the other `use` statements:

```php
use Preflow\Folio\Content\RecordLabeler;
```

(b) Change the `type()` helper to pass the new args (currently `new MatrixFieldType($this->catalog, $this->registry, $this->dm, $this->recordRenderer())`):

```php
    private function type(): MatrixFieldType
    {
        return new MatrixFieldType(
            $this->catalog,
            $this->registry,
            $this->dm,
            $this->recordRenderer(),
            new RecordLabeler(),
            '/folio',
        );
    }
```

(c) Add a new test method asserting the prefix is embedded in the options blob:

```php
    public function test_render_editor_embeds_prefix_for_admin_js(): void
    {
        $html = $this->type()->renderEditor(new FieldContext(name: 'blocks', config: $this->config()));
        $this->assertStringContainsString('"prefix":"/folio"', $html);
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit packages/folio/tests/Field/MatrixFieldTypeTest.php`
Expected: FAIL — constructor arity mismatch (too few/many args) and/or missing `"prefix"` in the blob.

- [ ] **Step 3: Update the `MatrixFieldType` constructor + imports**

In `packages/folio/src/Field/Types/MatrixFieldType.php`, add the import after `use Preflow\Folio\Content\RecordRenderer;`:

```php
use Preflow\Folio\Content\RecordLabeler;
```

Replace the constructor:

```php
    public function __construct(
        private readonly TypeCatalog $catalog,
        private readonly TypeRegistry $registry,
        private readonly DataManager $dm,
        private readonly RecordRenderer $records,
        private readonly RecordLabeler $labeler,
        private readonly string $prefix,
    ) {}
```

- [ ] **Step 4: Embed `prefix` in the options blob and delegate labels**

In `renderEditor`, change the options initializer (currently `$options = ['types' => [], 'records' => []];`) to include the prefix:

```php
        $options = ['prefix' => $this->prefix, 'types' => [], 'records' => []];
```

In the same method, change the picker-record label call (currently `$this->recordLabel($record, $key)`) to:

```php
                $recs[] = ['id' => (string) $id, 'label' => $this->labeler->label($record)];
```

- [ ] **Step 5: Route `refLabel` through `RecordLabeler` and remove the private `recordLabel`**

Replace the `refLabel` method body so it delegates:

```php
    /** @param array{_type:string,id:string} $ref */
    private function refLabel(array $ref): string
    {
        if (!$this->registry->has($ref['_type'])) {
            return $ref['id'];
        }
        $record = $this->dm->findType($ref['_type'], $ref['id']);
        return $record === null ? $ref['id'] : $this->labeler->label($record);
    }
```

Delete the now-unused private `recordLabel(DynamicRecord $record, string $type): string` method entirely (it was the only other caller). Leave `typeLabel`, `rowHtml`, `allowedTypes`, and `toRefs` unchanged.

- [ ] **Step 6: Wire the new args in the service provider**

In `packages/folio/src/FolioServiceProvider.php`:

(a) Add the import after `use Preflow\Folio\Content\RecordRenderer;`:

```php
use Preflow\Folio\Content\RecordLabeler;
```

(b) The `FieldTypeRegistry` bind closure currently captures `use ($uploadsDir, $uploadUrlPrefix)`. Add `$prefix` (already defined at the top of `register()`):

```php
        $container->bind(FieldTypeRegistry::class, function (Container $c) use ($uploadsDir, $uploadUrlPrefix, $prefix): FieldTypeRegistry {
```

(c) Replace the `MatrixFieldType` registration with the new args:

```php
            $registry->register(new MatrixFieldType(
                $c->get(TypeCatalog::class),
                $c->get(TypeRegistry::class),
                $c->get(\Preflow\Data\DataManager::class),
                new RecordRenderer($registry, $c->get(TemplateEngineInterface::class)),
                new RecordLabeler(),
                $prefix,
            ));
```

- [ ] **Step 7: Run the field + provider suites**

Run: `vendor/bin/phpunit packages/folio/tests/Field/MatrixFieldTypeTest.php packages/folio/tests/FolioServiceProviderTest.php`
Expected: PASS — the new prefix test passes; existing label assertions (`First note`) still pass (delegation is behavior-preserving).

- [ ] **Step 8: Commit**

```bash
git add packages/folio/src/Field/Types/MatrixFieldType.php packages/folio/src/FolioServiceProvider.php packages/folio/tests/Field/MatrixFieldTypeTest.php
git commit -m "feat(folio): matrix delegates labels to RecordLabeler + embeds admin prefix"
```

---

### Task 3: Record-label API endpoint + route

`GET {prefix}/{type}/{id}/label` → JSON `{id,label}`, backed by `RecordLabeler`.

**Files:**
- Modify: `packages/folio/src/Http/AdminController.php`
- Modify: `packages/folio/src/Routing/FolioRoutes.php`
- Modify: `packages/folio/src/FolioServiceProvider.php`
- Test: `packages/folio/tests/Http/AdminControllerTest.php`

**Interfaces:**
- Consumes: `RecordLabeler` (Task 1); `DataManager::findType`; `TypeCatalog::has`.
- Produces: `AdminController::__construct(TypeCatalog, TypeRegistry, DataManager, TemplateEngineInterface, ActionResolver, FieldTypeRegistry, string $prefix, RecordLabeler $labeler)` (RecordLabeler appended last). `AdminController::recordLabel(ServerRequestInterface): ResponseInterface` returns `200 application/json` `{"id":…,"label":…}`, or `404` for unknown type / missing record. Route `GET {prefix}/{type}/{id}/label` → `recordLabel`.

- [ ] **Step 1: Write the failing tests**

In `packages/folio/tests/Http/AdminControllerTest.php`:

(a) Add the import after `use Preflow\Folio\Http\AdminController;`:

```php
use Preflow\Folio\Content\RecordLabeler;
```

(b) Update the `controller()` helper to pass the labeler as the 8th arg (after `'/folio'`):

```php
    private function controller(): AdminController
    {
        return new AdminController(
            new TypeCatalog($this->base . '/m'),
            $this->registry,
            $this->dm,
            $this->engine(),
            new ActionResolver(new Container(), 'Preflow\\Folio\\Tests\\Overrides\\'),
            $this->fieldTypeRegistry(),
            '/folio',
            new RecordLabeler(),
        );
    }
```

(c) Add the test methods:

```php
    public function test_record_label_returns_json(): void
    {
        $this->controller()->store((new Psr17Factory())->createServerRequest('POST', '/folio/page')
            ->withAttribute('type', 'page')
            ->withParsedBody(['title' => 'Hello', 'slug' => 'hello', 'body' => 'B', 'status' => 'published']));
        $id = $this->dm->queryType('page')->where('slug', 'hello')->first()->getId();

        $req = (new Psr17Factory())->createServerRequest('GET', '/folio/page/' . $id . '/label')
            ->withAttribute('type', 'page')->withAttribute('id', $id);
        $res = $this->controller()->recordLabel($req);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('application/json; charset=UTF-8', $res->getHeaderLine('Content-Type'));
        $data = json_decode((string) $res->getBody(), true);
        $this->assertSame($id, $data['id']);
        $this->assertSame('Hello', $data['label']);
    }

    public function test_record_label_unknown_type_404(): void
    {
        $req = (new Psr17Factory())->createServerRequest('GET', '/folio/ghost/x/label')
            ->withAttribute('type', 'ghost')->withAttribute('id', 'x');
        $this->assertSame(404, $this->controller()->recordLabel($req)->getStatusCode());
    }

    public function test_record_label_missing_record_404(): void
    {
        $req = (new Psr17Factory())->createServerRequest('GET', '/folio/page/nope/label')
            ->withAttribute('type', 'page')->withAttribute('id', 'nope');
        $this->assertSame(404, $this->controller()->recordLabel($req)->getStatusCode());
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit packages/folio/tests/Http/AdminControllerTest.php`
Expected: FAIL — constructor arity mismatch and `recordLabel` undefined.

- [ ] **Step 3: Inject `RecordLabeler` into `AdminController`**

In `packages/folio/src/Http/AdminController.php`, add the import after `use Preflow\Folio\Content\TypeCatalog;`:

```php
use Preflow\Folio\Content\RecordLabeler;
```

Append the dependency to the constructor (after `private readonly string $prefix,`):

```php
        private readonly string $prefix,
        private readonly RecordLabeler $labeler,
    ) {}
```

- [ ] **Step 4: Add the `recordLabel` action**

In `packages/folio/src/Http/AdminController.php`, add this method (e.g. directly after `list()`):

```php
    public function recordLabel(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        $id = (string) $request->getAttribute('id', '');
        if (!$this->catalog->has($type)) {
            return new Response(404, [], 'Unknown type');
        }

        $record = $this->dm->findType($type, $id);
        if ($record === null) {
            return new Response(404, [], 'Not found');
        }

        $payload = json_encode(
            ['id' => $id, 'label' => $this->labeler->label($record)],
            JSON_UNESCAPED_SLASHES,
        );

        return new Response(200, ['Content-Type' => 'application/json; charset=UTF-8'], (string) $payload);
    }
```

- [ ] **Step 5: Register the route**

In `packages/folio/src/Routing/FolioRoutes.php`, add the label entry to `$defs` directly after the `editForm` line:

```php
            ['GET',  $prefix . '/{type}/{id}/edit',    'editForm'],
            ['GET',  $prefix . '/{type}/{id}/label',   'recordLabel'],
```

- [ ] **Step 6: Pass the labeler in the provider's `AdminController` binding**

In `packages/folio/src/FolioServiceProvider.php`, update the `AdminController` bind to append `new RecordLabeler()` (the import was added in Task 2):

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
        ));
```

- [ ] **Step 7: Run the affected suites**

Run: `vendor/bin/phpunit packages/folio/tests/Http/AdminControllerTest.php packages/folio/tests/Routing`
Expected: PASS — label endpoint returns JSON + 404s; existing admin/route tests still green.

- [ ] **Step 8: Commit**

```bash
git add packages/folio/src/Http/AdminController.php packages/folio/src/Routing/FolioRoutes.php packages/folio/src/FolioServiceProvider.php packages/folio/tests/Http/AdminControllerTest.php
git commit -m "feat(folio): record-label JSON API (GET {type}/{id}/label)"
```

---

### Task 4: Drawer-mode create form + saved page (server side)

`?_drawer=1` makes `createForm`/`store` render a bare layout and, on success, return a postMessage page instead of a redirect.

**Files:**
- Create: `packages/folio/templates/admin/_drawer_layout.twig`, `packages/folio/templates/admin/drawer_saved.twig`
- Modify: `packages/folio/templates/admin/form.twig`
- Modify: `packages/folio/src/Http/AdminController.php`
- Test: `packages/folio/tests/Http/AdminControllerTest.php`, `packages/folio/tests/TemplatesExistTest.php`, `packages/folio/tests/Integration/FolioAppTest.php`

**Interfaces:**
- Consumes: the `recordLabel`/labeler wiring (Task 3); the existing `form()` render path.
- Produces: `AdminController::form(... , string $layout = '@folio/admin/_layout.twig')` threading `layout` into the template context. In drawer mode (`?_drawer=1`): `createForm` renders with `@folio/admin/_drawer_layout.twig` and an action of `{prefix}/{type}?_drawer=1`; `store` success returns `200` rendering `@folio/admin/drawer_saved.twig` with `{type,id}`; `store` validation error re-renders the bare form at `422`. `form.twig` extends `layout|default('@folio/admin/_layout.twig')`.

- [ ] **Step 1: Write the failing tests**

In `packages/folio/tests/Http/AdminControllerTest.php`:

(a) Add a capturing engine + a controller factory that uses it (after the existing `engine()` helper). The capturing engine echoes the full context as JSON so layout/action values are observable:

```php
    private function capturingEngine(): TemplateEngineInterface
    {
        return new class implements TemplateEngineInterface {
            public function render(string $template, array $context = []): string
            {
                return $template . '|' . json_encode($context, JSON_UNESCAPED_SLASHES);
            }
            public function exists(string $template): bool { return true; }
            public function addFunction(\Preflow\View\TemplateFunctionDefinition $f): void {}
            public function addGlobal(string $n, mixed $v): void {}
            public function getTemplateExtension(): string { return 'twig'; }
            public function addNamespace(string $n, string $p): void {}
        };
    }

    private function controllerWith(TemplateEngineInterface $engine): AdminController
    {
        return new AdminController(
            new TypeCatalog($this->base . '/m'),
            $this->registry,
            $this->dm,
            $engine,
            new ActionResolver(new Container(), 'Preflow\\Folio\\Tests\\Overrides\\'),
            $this->fieldTypeRegistry(),
            '/folio',
            new RecordLabeler(),
        );
    }
```

(b) Add the test methods:

```php
    public function test_create_form_drawer_uses_bare_layout_and_keeps_flag(): void
    {
        $req = (new Psr17Factory())->createServerRequest('GET', '/folio/page/new?_drawer=1')
            ->withAttribute('type', 'page');
        $res = $this->controllerWith($this->capturingEngine())->createForm($req);
        $body = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('@folio/admin/form.twig', $body);
        $this->assertStringContainsString('"layout":"@folio/admin/_drawer_layout.twig"', $body);
        $this->assertStringContainsString('"action":"/folio/page?_drawer=1"', $body);
    }

    public function test_create_form_normal_uses_full_layout(): void
    {
        $req = (new Psr17Factory())->createServerRequest('GET', '/folio/page/new')
            ->withAttribute('type', 'page');
        $body = (string) $this->controllerWith($this->capturingEngine())->createForm($req)->getBody();

        $this->assertStringContainsString('"layout":"@folio/admin/_layout.twig"', $body);
        $this->assertStringContainsString('"action":"/folio/page"', $body);
    }

    public function test_store_drawer_returns_postmessage_page_not_redirect(): void
    {
        $req = (new Psr17Factory())->createServerRequest('POST', '/folio/page?_drawer=1')
            ->withAttribute('type', 'page')
            ->withParsedBody(['title' => 'Hi', 'slug' => 'hi', 'body' => 'B', 'status' => 'published']);
        $res = $this->controllerWith($this->capturingEngine())->store($req);
        $body = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('@folio/admin/drawer_saved.twig', $body);
        $this->assertStringContainsString('"type":"page"', $body);
        $id = $this->dm->queryType('page')->where('slug', 'hi')->first()->getId();
        $this->assertStringContainsString('"id":"' . $id . '"', $body);
    }

    public function test_store_drawer_validation_error_stays_bare_422(): void
    {
        $req = (new Psr17Factory())->createServerRequest('POST', '/folio/page?_drawer=1')
            ->withAttribute('type', 'page')
            ->withParsedBody(['title' => '', 'slug' => 'x', 'body' => 'b', 'status' => 'draft']);
        $res = $this->controllerWith($this->capturingEngine())->store($req);
        $body = (string) $res->getBody();

        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('"layout":"@folio/admin/_drawer_layout.twig"', $body);
        $this->assertStringContainsString('"action":"/folio/page?_drawer=1"', $body);
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit packages/folio/tests/Http/AdminControllerTest.php --filter drawer`
Expected: FAIL — no drawer branch yet (createForm/store ignore `?_drawer=1`; `form()` has no `layout` param).

- [ ] **Step 3: Add the `isDrawer` helper**

In `packages/folio/src/Http/AdminController.php`, add a private helper (e.g. above `labelFor`):

```php
    private function isDrawer(ServerRequestInterface $request): bool
    {
        parse_str($request->getUri()->getQuery(), $q);

        return ($q['_drawer'] ?? null) === '1';
    }
```

- [ ] **Step 4: Branch `createForm` on drawer mode**

Replace `createForm`:

```php
    public function createForm(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        if (!$this->catalog->has($type)) {
            return new Response(404, [], 'Unknown type');
        }

        $csrf = $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '';
        $drawer = $this->isDrawer($request);
        $action = $this->prefix . '/' . $type . ($drawer ? '?_drawer=1' : '');
        $layout = $drawer ? '@folio/admin/_drawer_layout.twig' : '@folio/admin/_layout.twig';

        return $this->form($type, [], $action, 'New ' . $this->labelFor($type), [], $csrf, 200, $layout);
    }
```

- [ ] **Step 5: Branch `store` on drawer mode**

Replace `store`:

```php
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        if (!$this->catalog->has($type)) {
            return new Response(404, [], 'Unknown type');
        }

        $typeDef = $this->registry->get($type);
        $csrf = $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '';
        $drawer = $this->isDrawer($request);

        $data = $this->collectFieldData($typeDef, $request, []);
        $data[$typeDef->idField] = bin2hex(random_bytes(16));

        try {
            $this->dm->saveType(DynamicRecord::fromArray($typeDef, $data));
        } catch (ValidationException $e) {
            $action = $this->prefix . '/' . $type . ($drawer ? '?_drawer=1' : '');
            $layout = $drawer ? '@folio/admin/_drawer_layout.twig' : '@folio/admin/_layout.twig';

            return $this->form(
                $type,
                (array) $request->getParsedBody(),
                $action,
                'New ' . $this->labelFor($type),
                $e->errors(),
                $csrf,
                422,
                $layout,
            );
        }

        if ($drawer) {
            return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $this->engine->render('@folio/admin/drawer_saved.twig', [
                'type' => $type,
                'id' => $data[$typeDef->idField],
            ]));
        }

        return new Response(302, ['Location' => $this->prefix . '/' . $type]);
    }
```

- [ ] **Step 6: Add the `layout` parameter to `form()`**

In `form()`, change the signature (currently ends `string $csrf = '', int $status = 200`) to add the layout, and pass it into the render context:

```php
    private function form(string $type, array $values, string $action, string $heading, array $errors, string $csrf = '', int $status = 200, string $layout = '@folio/admin/_layout.twig'): ResponseInterface
```

Then add to the `$this->engine->render('@folio/admin/form.twig', [...])` context array (alongside the existing keys):

```php
            'layout' => $layout,
```

- [ ] **Step 7: Create the bare drawer layout**

Create `packages/folio/templates/admin/_drawer_layout.twig`:

```twig
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{% block title %}Folio{% endblock %}</title>
    <link rel="stylesheet" href="{{ folio_asset('admin.css') }}">
    {% for a in editor_assets|default([]) %}
        {%- if a ends with '.css' %}<link rel="stylesheet" href="{{ folio_asset(a) }}">
        {%- elseif a ends with '.js' %}<script src="{{ folio_asset(a) }}" defer></script>
        {%- endif %}
    {% endfor %}
</head>
<body class="folio-drawer-body">
    <main class="folio-drawer-content">
        <h1 class="folio-drawer-heading">{% block page_title %}{% endblock %}</h1>
        {% block content %}{% endblock %}
    </main>
</body>
</html>
```

- [ ] **Step 8: Create the saved (postMessage) page**

Create `packages/folio/templates/admin/drawer_saved.twig`:

```twig
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Saved</title></head>
<body>
<script>
(function () {
    var msg = { source: 'folio-drawer', type: {{ type|json_encode|raw }}, id: {{ id|json_encode|raw }} };
    if (window.parent && window.parent !== window) {
        window.parent.postMessage(msg, window.location.origin);
    }
})();
</script>
</body>
</html>
```

- [ ] **Step 9: Make `form.twig` honor the `layout` variable**

In `packages/folio/templates/admin/form.twig`, change line 1 from `{% extends "@folio/admin/_layout.twig" %}` to:

```twig
{% extends layout|default('@folio/admin/_layout.twig') %}
```

(Leave the rest of `form.twig` unchanged.)

- [ ] **Step 10: Register the new templates in `TemplatesExistTest`**

In `packages/folio/tests/TemplatesExistTest.php`, add the two entries to the list (after `'/admin/form.twig',`):

```php
            '/admin/_drawer_layout.twig',
            '/admin/drawer_saved.twig',
```

- [ ] **Step 11: Add the integration tests (real Twig)**

In `packages/folio/tests/Integration/FolioAppTest.php`, add (near the existing `test_matrix_*` methods):

```php
    public function test_drawer_create_returns_postmessage_with_id(): void
    {
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $res = $app->handle($f->createServerRequest('POST', '/folio/note?_drawer=1')
            ->withParsedBody(['name' => 'Drawer Note']));

        $this->assertSame(200, $res->getStatusCode());
        $body = (string) $res->getBody();
        $this->assertStringContainsString("'folio-drawer'", $body);          // postMessage source literal
        $id = $app->container()->get(\Preflow\Data\DataManager::class)
            ->queryType('note')->where('name', 'Drawer Note')->first()->getId();
        $this->assertStringContainsString($id, $body);                        // new id in the payload
    }

    public function test_label_endpoint_returns_record_label(): void
    {
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $app->handle($f->createServerRequest('POST', '/folio/note')->withParsedBody(['name' => 'Labeled Note']));
        $id = $app->container()->get(\Preflow\Data\DataManager::class)
            ->queryType('note')->where('name', 'Labeled Note')->first()->getId();

        $res = $app->handle($f->createServerRequest('GET', '/folio/note/' . $id . '/label'));
        $this->assertSame(200, $res->getStatusCode());
        $data = json_decode((string) $res->getBody(), true);
        $this->assertSame('Labeled Note', $data['label']);
        $this->assertSame($id, $data['id']);
    }
```

- [ ] **Step 12: Run the affected suites**

Run: `vendor/bin/phpunit packages/folio/tests/Http/AdminControllerTest.php packages/folio/tests/TemplatesExistTest.php packages/folio/tests/Integration/FolioAppTest.php`
Expected: PASS — drawer create returns the postMessage page with the id; the label endpoint resolves the name; bare-layout + flag-preservation assertions hold; existing tests green.

- [ ] **Step 13: Commit**

```bash
git add packages/folio/src/Http/AdminController.php packages/folio/templates/admin/_drawer_layout.twig packages/folio/templates/admin/drawer_saved.twig packages/folio/templates/admin/form.twig packages/folio/tests/Http/AdminControllerTest.php packages/folio/tests/TemplatesExistTest.php packages/folio/tests/Integration/FolioAppTest.php
git commit -m "feat(folio): drawer-mode create form + postMessage saved page"
```

---

### Task 5: Drawer UI — "New" button, `admin.js` drawer/message/fetch, CSS

Wire the client: a "New" button opens the drawer iframe; an origin-checked `message` listener resolves the label and appends a row.

**Files:**
- Modify: `packages/folio/src/Field/Types/MatrixFieldType.php` (add the "New" button to `renderEditor`)
- Modify: `packages/folio/assets/admin.js`
- Modify: `packages/folio/assets/admin.css`
- Create: `packages/folio/tests/Assets/AdminJsTest.php`
- Test: `packages/folio/tests/Integration/FolioAppTest.php` (assert the button renders)

**Interfaces:**
- Consumes: the `prefix` in the options blob (Task 2); the drawer route + label API (Tasks 3-4).
- Produces: a `[data-matrix-create]` button in the matrix editor; `admin.js` `openDrawer(url)`/`closeDrawer()`, a per-root `addRow` exposed via `root._folioMatrix`, and a single `window` `message` listener that fetches `{prefix}/{type}/{id}/label` then calls `addRow(type, id, label)`.

- [ ] **Step 1: Write the failing static test**

Create `packages/folio/tests/Assets/AdminJsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Assets;

use PHPUnit\Framework\TestCase;

final class AdminJsTest extends TestCase
{
    private function js(): string
    {
        $path = dirname(__DIR__, 2) . '/assets/admin.js';
        $this->assertFileExists($path);
        return (string) file_get_contents($path);
    }

    public function test_defines_matrix_add_remove_reorder(): void
    {
        $js = $this->js();
        foreach (['data-folio-matrix', 'data-matrix-add', 'data-matrix-remove', 'data-matrix-up', 'data-matrix-down'] as $hook) {
            $this->assertStringContainsString($hook, $js);
        }
    }

    public function test_defines_drawer_create_and_origin_checked_message_handler(): void
    {
        $js = $this->js();
        $this->assertStringContainsString('data-matrix-create', $js);
        $this->assertStringContainsString('openDrawer', $js);
        $this->assertStringContainsString('/new?_drawer=1', $js);                  // create url flag
        $this->assertStringContainsString("addEventListener('message'", $js);      // cross-frame listener
        $this->assertStringContainsString('e.origin !== window.location.origin', $js); // origin pin
        $this->assertStringContainsString("data.source !== 'folio-drawer'", $js);  // shape guard
        $this->assertStringContainsString("'/label'", $js);                        // label fetch path
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit packages/folio/tests/Assets/AdminJsTest.php`
Expected: FAIL — `admin.js` has no drawer/create/message code yet.

- [ ] **Step 3: Add the "New" button to the matrix editor**

In `packages/folio/src/Field/Types/MatrixFieldType.php` `renderEditor`, add the create button immediately after the existing Add button line (`...data-matrix-add>Add</button>' . "\n";`):

```php
        $html .= '      <button type="button" class="btn btn-secondary" data-matrix-create>New</button>' . "\n";
```

- [ ] **Step 4: Rewrite `admin.js` with the drawer + message handling**

Replace the entire contents of `packages/folio/assets/admin.js`:

```js
/* Folio admin behaviors. Vanilla, no build step. */
(function () {
    'use strict';

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    var activeMatrix = null;   // the matrix root that opened the current drawer
    var drawerEl = null;       // the current drawer overlay element

    function closeDrawer() {
        if (drawerEl && drawerEl.parentNode) {
            drawerEl.parentNode.removeChild(drawerEl);
        }
        drawerEl = null;
        activeMatrix = null;
    }

    function openDrawer(url) {
        closeDrawer();
        var overlay = document.createElement('div');
        overlay.className = 'folio-drawer';
        var panel = document.createElement('div');
        panel.className = 'folio-drawer-panel';
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'folio-drawer-close';
        close.textContent = 'Close';
        close.addEventListener('click', closeDrawer);
        var frame = document.createElement('iframe');
        frame.className = 'folio-drawer-frame';
        frame.src = url;
        panel.appendChild(close);
        panel.appendChild(frame);
        overlay.appendChild(panel);
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) { closeDrawer(); }
        });
        document.body.appendChild(overlay);
        drawerEl = overlay;
    }

    function initMatrix(root) {
        var field = root.getAttribute('data-field') || '';
        var rowsEl = root.querySelector('[data-matrix-rows]');
        var optsEl = root.querySelector('[data-matrix-options]');
        var typeSel = root.querySelector('[data-matrix-type]');
        var recSel = root.querySelector('[data-matrix-record]');
        var addBtn = root.querySelector('[data-matrix-add]');
        var createBtn = root.querySelector('[data-matrix-create]');
        if (!rowsEl || !optsEl) { return; }

        var opts;
        try { opts = JSON.parse(optsEl.textContent || '{}'); } catch (e) { return; }
        var prefix = opts.prefix || '';
        var next = parseInt(root.getAttribute('data-next-index') || '0', 10) || 0;

        function populateRecords() {
            if (!recSel || !typeSel) { return; }
            var recs = (opts.records && opts.records[typeSel.value]) || [];
            recSel.innerHTML = '';
            recs.forEach(function (r) {
                var o = document.createElement('option');
                o.value = r.id;
                o.textContent = r.label;
                recSel.appendChild(o);
            });
        }

        function addRow(type, id, label) {
            var i = next++;
            var row = document.createElement('div');
            row.className = 'folio-matrix-row';
            row.setAttribute('data-matrix-row', '');
            row.innerHTML =
                '<input type="hidden" name="' + esc(field) + '[' + i + '][_type]" value="' + esc(type) + '">' +
                '<input type="hidden" name="' + esc(field) + '[' + i + '][id]" value="' + esc(id) + '">' +
                '<span class="folio-matrix-label">' + esc(label) + ' <em>(' + esc(type) + ')</em></span>' +
                '<span class="folio-matrix-controls">' +
                '<button type="button" data-matrix-up>Up</button>' +
                '<button type="button" data-matrix-down>Down</button>' +
                '<button type="button" data-matrix-remove>Remove</button>' +
                '</span>';
            rowsEl.appendChild(row);
        }

        // Expose for the cross-frame message handler (keyed off activeMatrix).
        root._folioMatrix = { addRow: addRow, prefix: prefix };

        if (typeSel) {
            typeSel.addEventListener('change', populateRecords);
            populateRecords();
        }

        if (addBtn) {
            addBtn.addEventListener('click', function () {
                if (!typeSel || !recSel || !typeSel.value || !recSel.value) { return; }
                var opt = recSel.options[recSel.selectedIndex];
                addRow(typeSel.value, recSel.value, opt ? opt.textContent : recSel.value);
            });
        }

        if (createBtn) {
            createBtn.addEventListener('click', function () {
                if (!typeSel || !typeSel.value) { return; }
                activeMatrix = root;
                openDrawer(prefix + '/' + encodeURIComponent(typeSel.value) + '/new?_drawer=1');
            });
        }

        rowsEl.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('button') : null;
            if (!btn) { return; }
            var row = btn.closest('[data-matrix-row]');
            if (!row) { return; }
            if (btn.hasAttribute('data-matrix-remove')) {
                row.remove();
            } else if (btn.hasAttribute('data-matrix-up') && row.previousElementSibling) {
                rowsEl.insertBefore(row, row.previousElementSibling);
            } else if (btn.hasAttribute('data-matrix-down') && row.nextElementSibling) {
                rowsEl.insertBefore(row.nextElementSibling, row);
            }
        });
    }

    function onMessage(e) {
        if (e.origin !== window.location.origin) { return; }
        var data = e.data;
        if (!data || typeof data !== 'object' || data.source !== 'folio-drawer') { return; }

        var matrix = activeMatrix;
        closeDrawer();
        if (!matrix || !matrix._folioMatrix) { return; }

        var ctrl = matrix._folioMatrix;
        var type = String(data.type || '');
        var id = String(data.id || '');
        if (!type || !id) { return; }

        var url = ctrl.prefix + '/' + encodeURIComponent(type) + '/' + encodeURIComponent(id) + '/label';
        fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) { ctrl.addRow(type, id, (j && j.label) ? j.label : id); })
            .catch(function () { ctrl.addRow(type, id, id); });
    }

    function onKeydown(e) {
        if (e.key === 'Escape' && drawerEl) { closeDrawer(); }
    }

    function boot() {
        document.querySelectorAll('[data-folio-matrix]').forEach(initMatrix);
        window.addEventListener('message', onMessage);
        document.addEventListener('keydown', onKeydown);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
```

- [ ] **Step 5: Add drawer + create-button styles**

Append to `packages/folio/assets/admin.css`:

```css
/* Matrix create-in-drawer (Phase 5b) */
[data-matrix-create] {
    margin-left: 0.25rem;
}
.folio-drawer {
    position: fixed;
    inset: 0;
    z-index: 1000;
    display: flex;
    justify-content: flex-end;
    background: rgba(0, 0, 0, 0.5);
}
.folio-drawer-panel {
    display: flex;
    flex-direction: column;
    width: min(640px, 100%);
    height: 100%;
    background: var(--c-bg);
    box-shadow: -8px 0 24px rgba(0, 0, 0, 0.25);
}
.folio-drawer-close {
    align-self: flex-end;
    margin: 0.75rem;
}
.folio-drawer-frame {
    flex: 1 1 auto;
    width: 100%;
    border: 0;
}
.folio-drawer-content {
    padding: 1.5rem;
}
```

- [ ] **Step 6: Add the integration assertion for the "New" button**

In `packages/folio/tests/Integration/FolioAppTest.php`, add:

```php
    public function test_matrix_editor_renders_create_new_button(): void
    {
        $body = (string) $this->app()->handle(
            (new \Nyholm\Psr7\Factory\Psr17Factory())->createServerRequest('GET', '/folio/page/new')
        )->getBody();
        $this->assertStringContainsString('data-matrix-create', $body);
        $this->assertStringContainsString('"prefix":"/folio"', $body); // prefix embedded for admin.js
    }
```

- [ ] **Step 7: Run the affected suites**

Run: `vendor/bin/phpunit packages/folio/tests/Assets/AdminJsTest.php packages/folio/tests/Field/MatrixFieldTypeTest.php packages/folio/tests/Integration/FolioAppTest.php`
Expected: PASS — static admin.js checks; matrix editor still renders rows/picker plus the new button + prefix.

- [ ] **Step 8: Commit**

```bash
git add packages/folio/src/Field/Types/MatrixFieldType.php packages/folio/assets/admin.js packages/folio/assets/admin.css packages/folio/tests/Assets/AdminJsTest.php packages/folio/tests/Integration/FolioAppTest.php
git commit -m "feat(folio): matrix create-in-drawer UI (New button, drawer iframe, postMessage)"
```

---

### Task 6: Full-suite + demo verification

**Files:** none changed — verification only.

- [ ] **Step 1: Run the whole folio package suite**

Run: `vendor/bin/phpunit packages/folio/tests`
Expected: PASS — all folio tests green.

- [ ] **Step 2: Run the demo smoke test**

Run: `vendor/bin/phpunit examples/folio-demo/tests`
Expected: PASS — the demo app boots and serves `/folio` (the existing `note`/`page.blocks` matrix already exercises create-in-drawer end-to-end; no new demo files needed).

- [ ] **Step 3: Run the full repo suite**

Run: `vendor/bin/phpunit`
Expected: PASS — all suites green; only the pre-existing PHPUnit deprecations + 1 skip. No test failures. If anything fails, investigate before finishing.

---

## Self-Review

**Spec coverage (5b):**
- "New" flow opening the create view in a drawer/iframe → Task 5 (`data-matrix-create` button + `openDrawer`).
- Bare drawer layout (no shell) → Task 4 (`_drawer_layout.twig` + `form.twig` dynamic extends + `createForm`/`store` layout selection).
- Save passes the new id back via `postMessage` (return-to-opener mode) → Task 4 (`drawer_saved.twig`; `store` returns it instead of 302).
- Matrix resolves the label via an API (id-only postMessage) → Task 3 (`recordLabel` endpoint + route) consumed by Task 5 (`message` listener → `fetch` → `addRow`).
- Shared `RecordLabeler` (single source of truth) → Task 1; consumed by Task 2 (editor) + Task 3 (API).
- `prefix` available to `admin.js` → Task 2 (options blob) consumed by Task 5.
- Security: origin-pinned postMessage (Task 5 `onMessage` + Task 4 `drawer_saved.twig`); read-only label GET with type/record guards (Task 3); `json_encode`-injected ids in the saved page; client-side `esc()` on the appended row.
- Out of scope held: no per-placement view (5c), no inline edit of references, no search/paged picker (pick-existing stays embedded; label API resolves only new ids).

**Placeholder scan:** No `TBD`/`TODO`. Every code step shows full code; every modify step quotes the exact anchor text to change.

**Type consistency:**
- `RecordLabeler::label(DynamicRecord): string` — defined Task 1; called identically in Task 2 (`$this->labeler->label($record)`) and Task 3 (`$this->labeler->label($record)`).
- `MatrixFieldType::__construct(TypeCatalog, TypeRegistry, DataManager, RecordRenderer, RecordLabeler, string)` — Task 2; the provider registration (Task 2 Step 6) and the test `type()` helper (Task 2 Step 1) pass args in that order.
- `AdminController::__construct(…, string $prefix, RecordLabeler $labeler)` — Task 3; the provider bind (Task 3 Step 6) and both test factories (`controller()` Task 3, `controllerWith()` Task 4) append `new RecordLabeler()` last.
- `form(…, int $status = 200, string $layout = '@folio/admin/_layout.twig')` — Task 4 Step 6; `createForm`/`store` (Steps 4-5) pass the 8th arg; existing callers (`editForm`, `update`) rely on the default — unchanged.
- postMessage shape `{source:'folio-drawer', type, id}` — emitted in `drawer_saved.twig` (Task 4 Step 8), consumed in `onMessage` (Task 5 Step 4) with matching `data.source`/`data.type`/`data.id`.
- Options blob `"prefix"` (Task 2 Step 4) read as `opts.prefix` in `admin.js` (Task 5 Step 4) and asserted `"prefix":"/folio"` in Task 2 Step 1 + Task 5 Step 6.
- Label route `{prefix}/{type}/{id}/label` (Task 3 Step 5) ↔ URL built in `admin.js` as `ctrl.prefix + '/' + … + '/label'` (Task 5 Step 4).
```
