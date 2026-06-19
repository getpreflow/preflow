# Folio Matrix Core (Phase 5a) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `matrix` field type — an ordered, polymorphic list of references to standalone records of *allowed* content types, edited via add(pick-existing)/remove/reorder JS and rendered on the frontend through per-type templates.

**Architecture:** `MatrixFieldType` stores `[{_type,id},…]`, computes effective-allowed types (per-type `matrixable` opt-in ∩ per-field `allowed` list), renders an editor (rows + an embedded options blob + an add picker) driven by a new `admin.js`, and renders the frontend by resolving each reference to a record and rendering it via `@folio/frontend/types/{type}.twig` (userland-authored; package `_default.twig` fallback). A small `RecordRenderer` builds the per-field "rendered" map + resolves the per-type template, shared by the matrix and the existing page frontend.

**Tech Stack:** PHP 8.5, `preflow/folio`, `preflow/data`, Twig, vanilla JS (`admin.js`), PHPUnit 11. No build step; no new Composer dependency.

## Global Constraints

- **PHP 8.5+**, `declare(strict_types=1)` in every PHP file.
- **No build step; no new Composer dependency.** `admin.js` is hand-written vanilla JS, served via the existing asset route and declared by `MatrixFieldType::assets()` (so it loads only on pages with a matrix field).
- **No emojis** in code or UI copy (use "Up"/"Down"/"Remove" text, not arrow glyphs).
- **Matrix stores references**, not inline blocks: `[{"_type":"news","id":"abc"}, …]`. `toStorage`/`fromStorage` = JSON ↔ array (same convention as asset/relation).
- **Two-layer allow:** a content type opts in with `"matrixable": true` (type-level model key; the only `preflow/data` change); a matrix field config `{"matrix":{"allowed":[...]}}`. Effective allowed = `allowed ∩ matrixable`; if `allowed` absent/empty → all matrixable types.
- **Per-type frontend templates** authored in **userland** at `resources/folio/frontend/types/{type}.twig` (resolved via the `@folio` userland-first namespace); package ships `@folio/frontend/types/_default.twig` as the overridable fallback. Each receives `record` (raw) + `rendered` (registry `renderFrontend` map) + `type`.
- **Security:** all editor labels/types/ids and default-template output HTML-escaped; the options JSON blob uses `JSON_HEX_TAG` (cannot break out of `<script>`); matrix `renderFrontend` guards `registry->has($_type)` before `findType` (which throws on unknown types) and skips unresolved refs.
- Templates overridable via `@folio`; admin POSTs keep `_csrf_token`.
- **Test command:** `vendor/bin/phpunit <path>` from repo root `/Users/smyr/Sites/gbits/flopp`. Integration tests run under strict Twig (`APP_DEBUG=1`).

## File Structure

- `packages/data/src/TypeDefinition.php` + `TypeRegistry.php` — add `matrixable`.
- `packages/folio/src/Content/RecordRenderer.php` — **new**: rendered-map + per-type-template render.
- `packages/folio/templates/frontend/types/_default.twig` — **new** fallback.
- `packages/folio/src/Http/FrontendController.php` — **modify**: use `RecordRenderer` for the rendered map.
- `packages/folio/src/Field/Types/MatrixFieldType.php` — **new**.
- `packages/folio/assets/admin.js` — **new** (matrix add/remove/reorder).
- `packages/folio/src/FolioServiceProvider.php` — **modify**: register `MatrixFieldType` (with a locally-built `RecordRenderer`); add `admin.js` to the asset map; pass `RecordRenderer` to `FrontendController`.
- `packages/folio/templates/frontend/page.twig` — **modify**: render all fields generically (auto-includes matrix).
- Tests: `packages/folio/tests/Content/RecordRendererTest.php` (new), `packages/folio/tests/Field/MatrixFieldTypeTest.php` (new), `packages/folio/tests/Integration/FolioAppTest.php` (modify), `packages/folio/tests/Http/FrontendControllerTest.php` (modify).
- `examples/folio-demo/config/models/note.json` (new, matrixable) + `page.json` (add matrix field) + `examples/folio-demo` userland per-type template.

---

### Task 1: `matrixable` on the type definition

**Files:**
- Modify: `packages/data/src/TypeDefinition.php`, `packages/data/src/TypeRegistry.php`
- Test: `packages/data/tests/TypeMatrixableTest.php` (new)

**Interfaces:**
- Produces: `TypeDefinition` gains `public bool $matrixable = false` (constructor-promoted, appended last). `TypeRegistry::load()` populates it from the model JSON top-level key `matrixable` (default false).

- [ ] **Step 1: Write the failing test**

Create `packages/data/tests/TypeMatrixableTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\TypeRegistry;

final class TypeMatrixableTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/typematrix_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        file_put_contents($this->dir . '/note.json', json_encode([
            'key' => 'note', 'table' => 'note', 'storage' => 'json', 'id_field' => 'uuid', 'matrixable' => true,
            'fields' => ['name' => ['type' => 'string']],
        ]));
        file_put_contents($this->dir . '/page.json', json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => ['title' => ['type' => 'string']],
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/note.json');
        @unlink($this->dir . '/page.json');
        @rmdir($this->dir);
    }

    public function test_matrixable_defaults_false_and_reads_true(): void
    {
        $reg = new TypeRegistry($this->dir);
        $this->assertTrue($reg->get('note')->matrixable);
        $this->assertFalse($reg->get('page')->matrixable);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit packages/data/tests/TypeMatrixableTest.php`
Expected: FAIL — `TypeDefinition` has no `matrixable`.

- [ ] **Step 3: Add the property**

In `packages/data/src/TypeDefinition.php`, append a constructor-promoted property after `transformers`:

```php
        public array $transformers = [],
        public bool $matrixable = false,
    ) {}
```

- [ ] **Step 4: Populate it in `TypeRegistry::load()`**

In `packages/data/src/TypeRegistry.php`, in the `return new TypeDefinition(...)` call, add the argument (after `transformers`):

```php
            transformers: $transformers,
            matrixable: (bool) ($schema['matrixable'] ?? false),
        );
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit packages/data/tests/TypeMatrixableTest.php`
Expected: PASS (1 test).

- [ ] **Step 6: Run the Data suite**

Run: `vendor/bin/phpunit packages/data/tests`
Expected: PASS (no regression — the new param is optional).

- [ ] **Step 7: Commit**

```bash
git add packages/data/src/TypeDefinition.php packages/data/src/TypeRegistry.php packages/data/tests/TypeMatrixableTest.php
git commit -m "feat(data): add matrixable flag to TypeDefinition"
```

---

### Task 2: `RecordRenderer` + per-type template fallback + FrontendController refactor

**Files:**
- Create: `packages/folio/src/Content/RecordRenderer.php`, `packages/folio/templates/frontend/types/_default.twig`
- Modify: `packages/folio/src/Http/FrontendController.php`, `packages/folio/src/FolioServiceProvider.php`
- Test: `packages/folio/tests/Content/RecordRendererTest.php` (new), `packages/folio/tests/Http/FrontendControllerTest.php` (modify)

**Interfaces:**
- Consumes: `FieldTypeRegistry` (`get`), `TemplateEngineInterface` (`render`, `exists`), `Preflow\Data\DynamicRecord` (`getType()`, `get()`, `toArray()`), `TypeDefinition->key`/`->fields`.
- Produces: `RecordRenderer::__construct(FieldTypeRegistry $fieldTypes, TemplateEngineInterface $engine)` with `renderedMap(DynamicRecord $record): array` (field name → `renderFrontend` HTML) and `renderTypeTemplate(DynamicRecord $record): string` (resolves `@folio/frontend/types/{type}.twig`, falling back to `_default.twig`, rendering with `record`/`rendered`/`type`). `FrontendController` constructor changes from `(FrontendResolver, TemplateEngineInterface, FieldTypeRegistry)` to `(FrontendResolver, TemplateEngineInterface, RecordRenderer)`.

- [ ] **Step 1: Write the failing test**

Create `packages/folio/tests/Content/RecordRendererTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\RecordRenderer;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\Folio\Field\Types\TextFieldType;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

final class RecordRendererTest extends TestCase
{
    private function fieldRegistry(): FieldTypeRegistry
    {
        $r = new FieldTypeRegistry();
        $r->register(new StringFieldType());
        $r->register(new TextFieldType());
        return $r;
    }

    private function engine(): TemplateEngineInterface
    {
        // Minimal fake: renders a known per-type template, falls back to _default.
        return new class implements TemplateEngineInterface {
            public function render(string $template, array $context = []): string
            {
                return $template . '|title=' . ($context['record']['title'] ?? '') . '|body=' . ($context['rendered']['body'] ?? '');
            }
            public function exists(string $template): bool
            {
                return $template === '@folio/frontend/types/note.twig';
            }
            public function addFunction(TemplateFunctionDefinition $f): void {}
            public function addGlobal(string $n, mixed $v): void {}
            public function getTemplateExtension(): string { return 'twig'; }
            public function addNamespace(string $n, string $p): void {}
        };
    }

    private function record(): DynamicRecord
    {
        $dir = sys_get_temp_dir() . '/rr_' . bin2hex(random_bytes(4));
        mkdir($dir . '/m', 0777, true);
        mkdir($dir . '/s', 0777, true);
        file_put_contents($dir . '/m/note.json', json_encode([
            'key' => 'note', 'table' => 'note', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => ['title' => ['type' => 'string'], 'body' => ['type' => 'text']],
        ]));
        $reg = new TypeRegistry($dir . '/m');
        return DynamicRecord::fromArray($reg->get('note'), ['uuid' => 'n1', 'title' => 'Hi', 'body' => 'B']);
    }

    public function test_rendered_map_escapes_scalar_fields(): void
    {
        $rr = new RecordRenderer($this->fieldRegistry(), $this->engine());
        $map = $rr->renderedMap($this->record());
        $this->assertSame('Hi', $map['title']);
        $this->assertSame('B', $map['body']);
    }

    public function test_render_type_template_uses_specific_then_default(): void
    {
        $rr = new RecordRenderer($this->fieldRegistry(), $this->engine());
        $out = $rr->renderTypeTemplate($this->record());
        // engine fake "exists" only for note.twig, so it should be chosen
        $this->assertStringContainsString('@folio/frontend/types/note.twig', $out);
        $this->assertStringContainsString('title=Hi', $out);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit packages/folio/tests/Content/RecordRendererTest.php`
Expected: FAIL — `Preflow\Folio\Content\RecordRenderer` does not exist.

- [ ] **Step 3: Create `RecordRenderer`**

Create `packages/folio/src/Content/RecordRenderer.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\DynamicRecord;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\View\TemplateEngineInterface;

/**
 * Renders a record's fields to a safe per-field HTML map and renders a record
 * through its per-type frontend template (with a default fallback). Shared by
 * the page frontend and the matrix field.
 */
final class RecordRenderer
{
    public function __construct(
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly TemplateEngineInterface $engine,
    ) {}

    /**
     * @return array<string, string> field name => safe frontend HTML
     */
    public function renderedMap(DynamicRecord $record): array
    {
        $typeDef = $record->getType();
        $map = [];
        foreach ($typeDef->fields as $name => $fieldDef) {
            $fieldType = $this->fieldTypes->get($fieldDef->type);
            $map[$name] = $fieldType->renderFrontend(
                $fieldType->fromStorage($record->get($name)),
                $fieldDef->config,
            );
        }
        return $map;
    }

    /**
     * Resolve and render the record's per-type frontend template. Userland may
     * provide @folio/frontend/types/{type}.twig; otherwise the package default
     * is used.
     */
    public function renderTypeTemplate(DynamicRecord $record): string
    {
        $type = $record->getType()->key;
        $template = '@folio/frontend/types/' . $type . '.twig';
        if (!$this->engine->exists($template)) {
            $template = '@folio/frontend/types/_default.twig';
        }

        return $this->engine->render($template, [
            'record' => $record->toArray(),
            'rendered' => $this->renderedMap($record),
            'type' => $type,
        ]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit packages/folio/tests/Content/RecordRendererTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Create the default per-type template**

Create `packages/folio/templates/frontend/types/_default.twig`:

```twig
<article class="folio-type folio-type-{{ type }}">
    {% for name, html in rendered %}
        <div class="folio-field folio-field-{{ name }}">{{ html|raw }}</div>
    {% endfor %}
</article>
```

- [ ] **Step 6: Refactor `FrontendController` to use `RecordRenderer`**

Replace the contents of `packages/folio/src/Http/FrontendController.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Content\RecordRenderer;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class FrontendController
{
    public function __construct(
        private readonly FrontendResolver $resolver,
        private readonly TemplateEngineInterface $engine,
        private readonly RecordRenderer $records,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $path = (string) $request->getAttribute('path', '');
        $record = $this->resolver->resolve($path);

        if ($record === null) {
            throw new NotFoundHttpException();
        }

        $html = $this->engine->render('@folio/frontend/page.twig', [
            'record' => $record->toArray(),
            'rendered' => $this->records->renderedMap($record),
        ]);

        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $html);
    }
}
```

- [ ] **Step 7: Update the `FrontendController` binding**

In `packages/folio/src/FolioServiceProvider.php`, add the import:

```php
use Preflow\Folio\Content\RecordRenderer;
```

Replace the `FrontendController` binding so it constructs a `RecordRenderer`:

```php
        $container->bind(FrontendController::class, fn (Container $c) => new FrontendController(
            $c->get(FrontendResolver::class),
            $c->get(TemplateEngineInterface::class),
            new RecordRenderer($c->get(FieldTypeRegistry::class), $c->get(TemplateEngineInterface::class)),
        ));
```

- [ ] **Step 8: Update `FrontendControllerTest` for the new constructor**

In `packages/folio/tests/Http/FrontendControllerTest.php`, the controller is constructed with a field registry as the 3rd arg; change it to pass a `RecordRenderer`. Replace the `registry()` helper usage / 3rd constructor argument: wherever `new FrontendController(...)` is called, pass `new \Preflow\Folio\Content\RecordRenderer($registry, $engine)` as the 3rd arg (where `$registry` is the existing field registry and `$engine` the existing fake engine the test already builds). Keep the existing test assertions.

- [ ] **Step 9: Run the affected suites**

Run: `vendor/bin/phpunit packages/folio/tests/Content/RecordRendererTest.php packages/folio/tests/Http/FrontendControllerTest.php packages/folio/tests/Integration/FolioAppTest.php`
Expected: PASS — the frontend integration tests still render (now via `RecordRenderer::renderedMap`, identical output).

- [ ] **Step 10: Commit**

```bash
git add packages/folio/src/Content/RecordRenderer.php packages/folio/templates/frontend/types/_default.twig packages/folio/src/Http/FrontendController.php packages/folio/src/FolioServiceProvider.php packages/folio/tests/Content/RecordRendererTest.php packages/folio/tests/Http/FrontendControllerTest.php
git commit -m "feat(folio): RecordRenderer + per-type template fallback; FrontendController uses it"
```

---

### Task 3: `MatrixFieldType`

**Files:**
- Create: `packages/folio/src/Field/Types/MatrixFieldType.php`
- Test: `packages/folio/tests/Field/MatrixFieldTypeTest.php` (new)

**Interfaces:**
- Consumes: `FieldType`/`FieldContext`; `Preflow\Folio\Content\TypeCatalog` (`all(): TypeListing[]` with `->key`/`->label`); `Preflow\Data\TypeRegistry` (`has`, `get` → `->matrixable`, `->fields`); `Preflow\Data\DataManager` (`queryType`, `findType`); `Preflow\Folio\Content\RecordRenderer` (`renderTypeTemplate`); `Preflow\Data\DynamicRecord`.
- Produces: `MatrixFieldType implements FieldType`, `__construct(TypeCatalog $catalog, TypeRegistry $registry, DataManager $dm, RecordRenderer $records)`. Key `matrix`. `assets()` returns `['admin.js']`. Stores `[{_type,id}]`; `normalizeInput` reconstructs + filters to effective-allowed; `renderEditor` emits `data-folio-matrix` container + rows + options blob + add picker; `renderFrontend` resolves each ref and renders via the per-type template (guards unknown types). `rules()` returns `[]`.

- [ ] **Step 1: Write the failing test**

Create `packages/folio/tests/Field/MatrixFieldTypeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\RecordRenderer;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\MatrixFieldType;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

final class MatrixFieldTypeTest extends TestCase
{
    private string $base;
    private TypeRegistry $registry;
    private DataManager $dm;
    private TypeCatalog $catalog;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/folio_mx_' . bin2hex(random_bytes(4));
        mkdir($this->base . '/m', 0777, true);
        mkdir($this->base . '/s', 0777, true);
        // matrixable type
        file_put_contents($this->base . '/m/note.json', json_encode([
            'key' => 'note', 'table' => 'note', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Notes', 'matrixable' => true,
            'fields' => ['name' => ['type' => 'string']],
        ]));
        // non-matrixable type
        file_put_contents($this->base . '/m/secret.json', json_encode([
            'key' => 'secret', 'table' => 'secret', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Secrets',
            'fields' => ['name' => ['type' => 'string']],
        ]));
        $this->registry = new TypeRegistry($this->base . '/m');
        $this->dm = new DataManager(['json' => new JsonFileDriver($this->base . '/s')], 'json', $this->registry);
        $this->catalog = new TypeCatalog($this->base . '/m');
        $this->dm->saveType(DynamicRecord::fromArray($this->registry->get('note'), ['uuid' => 'n1', 'name' => 'First note']));
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

    private function recordRenderer(): RecordRenderer
    {
        $fr = new FieldTypeRegistry();
        $fr->register(new StringFieldType());
        $engine = new class implements TemplateEngineInterface {
            public function render(string $template, array $context = []): string
            {
                return '[' . ($context['type'] ?? '') . ':' . ($context['rendered']['name'] ?? '') . ']';
            }
            public function exists(string $template): bool { return false; }
            public function addFunction(TemplateFunctionDefinition $f): void {}
            public function addGlobal(string $n, mixed $v): void {}
            public function getTemplateExtension(): string { return 'twig'; }
            public function addNamespace(string $n, string $p): void {}
        };
        return new RecordRenderer($fr, $engine);
    }

    private function type(): MatrixFieldType
    {
        return new MatrixFieldType($this->catalog, $this->registry, $this->dm, $this->recordRenderer());
    }

    private function config(array $allowed = []): array
    {
        return ['matrix' => ['allowed' => $allowed]];
    }

    public function test_key_and_assets(): void
    {
        $t = $this->type();
        $this->assertSame('matrix', $t->key());
        $this->assertSame(['admin.js'], $t->assets());
    }

    public function test_render_editor_has_hook_options_and_allowed_types(): void
    {
        $html = $this->type()->renderEditor(new FieldContext(name: 'blocks', config: $this->config()));
        $this->assertStringContainsString('data-folio-matrix', $html);
        $this->assertStringContainsString('data-field="blocks"', $html);
        $this->assertStringContainsString('data-matrix-options', $html);
        $this->assertStringContainsString('First note', $html); // note record in the options blob
        $this->assertStringContainsString('value="note"', $html); // note type addable
        $this->assertStringNotContainsString('value="secret"', $html); // not matrixable -> excluded
    }

    public function test_render_editor_existing_rows(): void
    {
        $html = $this->type()->renderEditor(new FieldContext(
            name: 'blocks', value: [['_type' => 'note', 'id' => 'n1']], config: $this->config(),
        ));
        $this->assertStringContainsString('name="blocks[0][_type]" value="note"', $html);
        $this->assertStringContainsString('name="blocks[0][id]" value="n1"', $html);
        $this->assertStringContainsString('First note', $html); // resolved label
    }

    public function test_normalize_filters_disallowed_and_reindexes(): void
    {
        $raw = [
            5 => ['_type' => 'note', 'id' => 'n1'],
            2 => ['_type' => 'secret', 'id' => 's1'], // not matrixable -> dropped
            7 => ['_type' => 'note', 'id' => ''],      // empty id -> dropped
        ];
        $out = $this->type()->normalizeInput($raw, $this->config());
        $this->assertSame([['_type' => 'note', 'id' => 'n1']], $out);
    }

    public function test_storage_roundtrip(): void
    {
        $t = $this->type();
        $json = $t->toStorage([['_type' => 'note', 'id' => 'n1']]);
        $this->assertSame([['_type' => 'note', 'id' => 'n1']], $t->fromStorage($json));
    }

    public function test_render_frontend_resolves_via_type_template(): void
    {
        $out = $this->type()->renderFrontend([['_type' => 'note', 'id' => 'n1']], $this->config());
        $this->assertStringContainsString('[note:First note]', $out); // per-type template output
    }

    public function test_render_frontend_skips_unknown_type(): void
    {
        $out = $this->type()->renderFrontend([['_type' => 'ghost', 'id' => 'x']], $this->config());
        $this->assertSame('', $out);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit packages/folio/tests/Field/MatrixFieldTypeTest.php`
Expected: FAIL — `Preflow\Folio\Field\Types\MatrixFieldType` does not exist.

- [ ] **Step 3: Create the field type**

Create `packages/folio/src/Field/Types/MatrixFieldType.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\RecordRenderer;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;

/**
 * Ordered, polymorphic reference list. Stores [{_type,id},...] referencing
 * standalone records of allowed (matrixable) content types. Edited via admin.js
 * (add pick-existing / remove / reorder); rendered on the frontend through each
 * reference's per-type template.
 */
final class MatrixFieldType implements FieldType
{
    public function __construct(
        private readonly TypeCatalog $catalog,
        private readonly TypeRegistry $registry,
        private readonly DataManager $dm,
        private readonly RecordRenderer $records,
    ) {}

    public function key(): string
    {
        return 'matrix';
    }

    public function renderEditor(FieldContext $ctx): string
    {
        $name = $ctx->name;
        $allowed = $this->allowedTypes($ctx->config);
        $refs = $this->toRefs($ctx->value);
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $label = $ctx->label ?? ucfirst(str_replace('_', ' ', $name));

        // Options blob for the JS picker: types + their records (id,label).
        $options = ['types' => [], 'records' => []];
        foreach ($allowed as $key) {
            $options['types'][] = ['key' => $key, 'label' => $this->typeLabel($key)];
            $recs = [];
            foreach ($this->dm->queryType($key)->get()->items() as $record) {
                $id = $record->getId();
                if ($id === null) {
                    continue;
                }
                $recs[] = ['id' => (string) $id, 'label' => $this->recordLabel($record, $key)];
            }
            $options['records'][$key] = $recs;
        }
        $optionsJson = json_encode($options, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES);

        $html = '<div class="form-group folio-matrix-field">' . "\n";
        $html .= '  <label>' . $e($label) . '</label>' . "\n";
        $html .= '  <div class="folio-matrix" data-folio-matrix data-field="' . $e($name) . '" data-next-index="' . count($refs) . '">' . "\n";
        $html .= '    <script type="application/json" data-matrix-options>' . $optionsJson . '</script>' . "\n";
        $html .= '    <div class="folio-matrix-rows" data-matrix-rows>' . "\n";
        foreach (array_values($refs) as $i => $ref) {
            $html .= $this->rowHtml($name, $i, $ref['_type'], $ref['id'], $this->refLabel($ref));
        }
        $html .= '    </div>' . "\n";
        $html .= '    <div class="folio-matrix-add">' . "\n";
        $html .= '      <select data-matrix-type>';
        foreach ($allowed as $key) {
            $html .= '<option value="' . $e($key) . '">' . $e($this->typeLabel($key)) . '</option>';
        }
        $html .= '</select>' . "\n";
        $html .= '      <select data-matrix-record></select>' . "\n";
        $html .= '      <button type="button" class="btn btn-secondary" data-matrix-add>Add</button>' . "\n";
        $html .= '    </div>' . "\n";
        $html .= '  </div>' . "\n";
        $html .= '</div>';

        return $html;
    }

    public function normalizeInput(mixed $raw, array $config): mixed
    {
        if (!is_array($raw)) {
            return [];
        }
        $allowed = $this->allowedTypes($config);
        $out = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $type = (string) ($entry['_type'] ?? '');
            $id = (string) ($entry['id'] ?? '');
            if ($type === '' || $id === '' || !in_array($type, $allowed, true)) {
                continue;
            }
            $out[] = ['_type' => $type, 'id' => $id];
        }
        return $out;
    }

    public function toStorage(mixed $value): mixed
    {
        return json_encode($this->toRefs($value), JSON_UNESCAPED_SLASHES);
    }

    public function fromStorage(mixed $value): mixed
    {
        return $this->toRefs($value);
    }

    public function renderFrontend(mixed $value, array $config): string
    {
        $out = '';
        foreach ($this->toRefs($value) as $ref) {
            if (!$this->registry->has($ref['_type'])) {
                continue;
            }
            $record = $this->dm->findType($ref['_type'], $ref['id']);
            if ($record === null) {
                continue;
            }
            $out .= $this->records->renderTypeTemplate($record);
        }
        return $out;
    }

    public function rules(array $config): array
    {
        return [];
    }

    public function assets(): array
    {
        return ['admin.js'];
    }

    /**
     * Effective allowed types = (config.allowed or all type keys) ∩ matrixable.
     *
     * @param array<string, mixed> $config
     * @return string[]
     */
    private function allowedTypes(array $config): array
    {
        $matrix = is_array($config['matrix'] ?? null) ? $config['matrix'] : [];
        $allowed = array_values(array_filter((array) ($matrix['allowed'] ?? []), static fn ($v) => is_string($v)));

        $candidates = $allowed !== []
            ? $allowed
            : array_map(static fn ($listing) => $listing->key, $this->catalog->all());

        $out = [];
        foreach ($candidates as $key) {
            if ($this->registry->has($key) && $this->registry->get($key)->matrixable) {
                $out[] = $key;
            }
        }
        return $out;
    }

    private function typeLabel(string $key): string
    {
        foreach ($this->catalog->all() as $listing) {
            if ($listing->key === $key) {
                return $listing->label;
            }
        }
        return ucfirst($key);
    }

    private function recordLabel(DynamicRecord $record, string $type): string
    {
        if ($this->registry->has($type)) {
            foreach ($this->registry->get($type)->fields as $fname => $def) {
                if ($def->type === 'string') {
                    $v = $record->get($fname);
                    if (is_string($v) && $v !== '') {
                        return $v;
                    }
                    break;
                }
            }
        }
        return (string) $record->getId();
    }

    /** @param array{_type:string,id:string} $ref */
    private function refLabel(array $ref): string
    {
        if (!$this->registry->has($ref['_type'])) {
            return $ref['id'];
        }
        $record = $this->dm->findType($ref['_type'], $ref['id']);
        return $record === null ? $ref['id'] : $this->recordLabel($record, $ref['_type']);
    }

    private function rowHtml(string $field, int $i, string $type, string $id, string $label): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        return '      <div class="folio-matrix-row" data-matrix-row>'
            . '<input type="hidden" name="' . $e($field) . '[' . $i . '][_type]" value="' . $e($type) . '">'
            . '<input type="hidden" name="' . $e($field) . '[' . $i . '][id]" value="' . $e($id) . '">'
            . '<span class="folio-matrix-label">' . $e($label) . ' <em>(' . $e($type) . ')</em></span>'
            . '<span class="folio-matrix-controls">'
            . '<button type="button" data-matrix-up>Up</button>'
            . '<button type="button" data-matrix-down>Down</button>'
            . '<button type="button" data-matrix-remove>Remove</button>'
            . '</span></div>' . "\n";
    }

    /**
     * Normalize a stored/raw value to a list of {_type,id} arrays.
     *
     * @return list<array{_type:string,id:string}>
     */
    private function toRefs(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $type = (string) ($entry['_type'] ?? '');
            $id = (string) ($entry['id'] ?? '');
            if ($type !== '' && $id !== '') {
                $out[] = ['_type' => $type, 'id' => $id];
            }
        }
        return $out;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit packages/folio/tests/Field/MatrixFieldTypeTest.php`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add packages/folio/src/Field/Types/MatrixFieldType.php packages/folio/tests/Field/MatrixFieldTypeTest.php
git commit -m "feat(folio): matrix field type (ordered polymorphic references)"
```

---

### Task 4: `admin.js` + register matrix + generic frontend page + integration

**Files:**
- Create: `packages/folio/assets/admin.js`
- Modify: `packages/folio/src/FolioServiceProvider.php`, `packages/folio/templates/frontend/page.twig`
- Test: `packages/folio/tests/Integration/FolioAppTest.php` (modify, incl. scaffold)

**Interfaces:**
- Consumes: `MatrixFieldType` (Task 3); `RecordRenderer` (Task 2); the asset route + `assetMap()` + the editor-assets union (Phases 2/3).
- Produces: `admin.js` served at `{prefix}/_assets/admin.js`; `MatrixFieldType` registered in the field registry (built with `TypeCatalog`, `TypeRegistry`, `DataManager`, and a locally-constructed `RecordRenderer`); `page.twig` renders every field generically (so a matrix field appears on the frontend).

- [ ] **Step 1: Update the scaffold + add the failing tests**

In `packages/folio/tests/Integration/FolioAppTest.php`:

(a) In `scaffold()`, add a matrixable `note` model (same `$w` helper):

```php
        $w('config/models/note.json', json_encode([
            'key' => 'note', 'table' => 'note', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Notes', 'matrixable' => true,
            'fields' => [
                'name' => ['type' => 'string', 'validate' => ['required']],
            ],
        ], JSON_PRETTY_PRINT));
```

(b) In the same scaffold's `page.json` `fields`, add a matrix field (keep the others):

```php
                'blocks' => ['type' => 'matrix', 'matrix' => ['allowed' => ['note']]],
```

(c) Add these test methods:

```php
    public function test_matrix_editor_renders_picker_and_loads_admin_js(): void
    {
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $app->handle($f->createServerRequest('POST', '/folio/note')->withParsedBody(['name' => 'Note A']));

        $body = (string) $app->handle($f->createServerRequest('GET', '/folio/page/new'))->getBody();
        $this->assertStringContainsString('data-folio-matrix', $body);
        $this->assertStringContainsString('Note A', $body);               // record in options blob
        $this->assertStringContainsString('/folio/_assets/admin.js?v=', $body); // matrix declares admin.js
    }

    public function test_matrix_reference_round_trips_and_renders_on_frontend(): void
    {
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $app->handle($f->createServerRequest('POST', '/folio/note')->withParsedBody(['name' => 'Block Note']));
        $dm = $app->container()->get(\Preflow\Data\DataManager::class);
        $noteId = $dm->queryType('note')->first()->getId();

        $app->handle($f->createServerRequest('POST', '/folio/page')->withParsedBody([
            'title' => 'Composed', 'slug' => 'composed', 'body' => 'b', 'status' => 'published',
            'blocks' => [['_type' => 'note', 'id' => $noteId]],
        ]));
        $pageId = $dm->queryType('page')->where('slug', 'composed')->first()->getId();

        $edit = (string) $app->handle($f->createServerRequest('GET', '/folio/page/' . $pageId . '/edit')
            ->withAttribute('type', 'page')->withAttribute('id', $pageId))->getBody();
        $this->assertStringContainsString('value="' . $noteId . '"', $edit); // row present in editor

        $front = (string) $app->handle($f->createServerRequest('GET', '/composed'))->getBody();
        $this->assertStringContainsString('Block Note', $front); // referenced note rendered via _default type template
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit packages/folio/tests/Integration/FolioAppTest.php --filter test_matrix`
Expected: FAIL — `matrix` type isn't registered (falls back to string), no `admin.js`, and `page.twig` doesn't render the matrix field.

- [ ] **Step 3: Create `admin.js`**

Create `packages/folio/assets/admin.js`:

```js
/* Folio admin behaviors. Vanilla, no build step. */
(function () {
    'use strict';

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function initMatrix(root) {
        var field = root.getAttribute('data-field') || '';
        var rowsEl = root.querySelector('[data-matrix-rows]');
        var optsEl = root.querySelector('[data-matrix-options]');
        var typeSel = root.querySelector('[data-matrix-type]');
        var recSel = root.querySelector('[data-matrix-record]');
        var addBtn = root.querySelector('[data-matrix-add]');
        if (!rowsEl || !optsEl) { return; }

        var opts;
        try { opts = JSON.parse(optsEl.textContent || '{}'); } catch (e) { return; }
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

    function boot() {
        document.querySelectorAll('[data-folio-matrix]').forEach(initMatrix);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
```

- [ ] **Step 4: Add `admin.js` to the asset map + register `MatrixFieldType`**

In `packages/folio/src/FolioServiceProvider.php`:

(a) Add `admin.js` to `assetMap()`:

```php
    private function assetMap(): array
    {
        return [
            'admin.css' => 'admin.css',
            'admin.js' => 'admin.js',
            'trix.css' => 'vendor/trix.css',
            'trix.js' => 'vendor/trix.umd.min.js',
        ];
    }
```

(b) Add imports:

```php
use Preflow\Folio\Field\Types\MatrixFieldType;
```

(c) In the `FieldTypeRegistry` bind closure, after the `RelationFieldType` registration and before the aliases, register the matrix type. It needs a `RecordRenderer` built from the registry being assembled (constructed directly here — NOT via `$c->get(RecordRenderer)` which does not exist and would risk recursion):

```php
            $registry->register(new MatrixFieldType(
                $c->get(TypeCatalog::class),
                $c->get(TypeRegistry::class),
                $c->get(\Preflow\Data\DataManager::class),
                new RecordRenderer($registry, $c->get(TemplateEngineInterface::class)),
            ));
```

(`TypeCatalog`, `TypeRegistry`, `TemplateEngineInterface`, `RecordRenderer` are already imported in this file from earlier tasks/phases.)

- [ ] **Step 5: Render fields generically on the frontend page**

Replace `packages/folio/templates/frontend/page.twig`:

```twig
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>{{ record.title }}</title></head>
<body>
    <article>
        <h1>{{ record.title }}</h1>
        {% for name, html in rendered %}
            {% if name != 'title' %}<div class="folio-field folio-field-{{ name }}">{{ html|raw }}</div>{% endif %}
        {% endfor %}
    </article>
</body>
</html>
```

- [ ] **Step 6: Run the integration suite**

Run: `vendor/bin/phpunit packages/folio/tests/Integration/FolioAppTest.php`
Expected: PASS — including the two new matrix tests and all existing tests. (The generic page render still surfaces the title h1, the sanitized rich-text body, and the uploaded image; the matrix field now renders its referenced records via the `_default` per-type template.)

- [ ] **Step 7: Commit**

```bash
git add packages/folio/assets/admin.js packages/folio/src/FolioServiceProvider.php packages/folio/templates/frontend/page.twig packages/folio/tests/Integration/FolioAppTest.php
git commit -m "feat(folio): register matrix field, ship admin.js, generic frontend render"
```

---

### Task 5: Demo showcase + full-suite verification

**Files:**
- Create: `examples/folio-demo/config/models/note.json`, `examples/folio-demo/resources/folio/frontend/types/note.twig`
- Modify: `examples/folio-demo/config/models/page.json`
- Test: whole repo suite

**Interfaces:**
- Consumes: the matrix field type + per-type template resolution (Tasks 1-4).

- [ ] **Step 1: Add a matrixable demo type**

Create `examples/folio-demo/config/models/note.json`:

```json
{
    "key": "note",
    "table": "note",
    "storage": "json",
    "id_field": "uuid",
    "label": "Notes",
    "matrixable": true,
    "fields": {
        "name": { "type": "string", "validate": ["required"] },
        "body": { "type": "richtext", "label": "Body" }
    }
}
```

- [ ] **Step 2: Add a matrix field to the demo page**

In `examples/folio-demo/config/models/page.json`, add a `blocks` matrix field (keep the others). The `fields` object becomes:

```json
    "fields": {
        "title": { "type": "string", "validate": ["required"] },
        "slug": { "type": "string", "validate": ["required"] },
        "cover": { "type": "asset", "label": "Cover image", "help": "Upload an image; stored under the Folio uploads dir.", "asset": { "multiple": false, "accept": "image/*" } },
        "author": { "type": "relation", "label": "Author", "help": "Pick an author record.", "relation": { "to": "author", "multiple": false } },
        "blocks": { "type": "matrix", "label": "Content blocks", "help": "Compose this page from Note records, in order.", "matrix": { "allowed": ["note"] } },
        "body": { "type": "richtext", "label": "Body content", "help": "Rich text — formatting is preserved and sanitized on save." },
        "status": { "type": "string" }
    }
```

- [ ] **Step 3: Add a userland per-type template for `note`**

Create `examples/folio-demo/resources/folio/frontend/types/note.twig` (shows that userland authors the per-type rendering):

```twig
<section class="note-card">
    <h3>{{ record.name }}</h3>
    {{ rendered.body|default('')|raw }}
</section>
```

- [ ] **Step 4: Verify the demo smoke test still passes**

Run: `vendor/bin/phpunit examples/folio-demo/tests`
Expected: PASS (2 tests) — the demo app boots and serves `/folio` (now with Pages + Articles + Authors + Notes).

- [ ] **Step 5: Run the full repo suite**

Run: `vendor/bin/phpunit`
Expected: PASS — all suites green. Only expected non-failures are the pre-existing PHPUnit deprecations + 1 skip. No test failures. If anything fails, investigate before committing.

- [ ] **Step 6: Commit**

```bash
git add examples/folio-demo/config/models/note.json examples/folio-demo/config/models/page.json examples/folio-demo/resources/folio/frontend/types/note.twig
git commit -m "docs(folio): demo a matrixable Note type + page matrix field + userland type template"
```

---

## Self-Review

**Spec coverage (5a):**
- Ordered polymorphic references stored as `[{_type,id}]` → Task 3 (`toStorage`/`fromStorage`/`normalizeInput`).
- Per-type `matrixable` opt-in → Task 1; per-matrix `allowed` list + effective = `allowed ∩ matrixable` (all matrixable when absent) → Task 3 (`allowedTypes`).
- Editor: rows + reorder/remove + add(pick existing) + embedded options blob + `data-folio-matrix` → Task 3 (`renderEditor`) + Task 4 (`admin.js`).
- Per-type frontend templates (userland `@folio/frontend/types/{type}.twig`, `_default` fallback) → Task 2 (`RecordRenderer` + `_default.twig`) used by Task 3 (`renderFrontend`).
- `admin.js` served only on matrix pages (via `assets()` + the editor-assets union) → Tasks 3, 4.
- Security: escaping, `JSON_HEX_TAG` options blob, `registry->has()` guard before `findType` → Task 3.
- Demo + full suite → Task 5.
- Out of scope (per spec): create-in-drawer (5b), per-placement view override (5c), inline edit, search/paged picker endpoint.

**Placeholder scan:** No `TBD`/`TODO`. Task 2 Step 8 describes the `FrontendControllerTest` change in prose because that test file's exact current shape isn't reproduced here — the change is mechanical (swap the 3rd constructor arg to a `RecordRenderer` wrapping the existing field registry + fake engine); the implementer adapts the existing helper.

**Type consistency:** `MatrixFieldType.__construct(TypeCatalog, TypeRegistry, DataManager, RecordRenderer)` consistent in Task 3 (test) and Task 4 (provider registration). `RecordRenderer.__construct(FieldTypeRegistry, TemplateEngineInterface)` + `renderedMap`/`renderTypeTemplate` consistent across Tasks 2, 3, 4. Stored shape `{_type,id}` and the `{field}[{i}][_type]`/`[id]` input names identical between `MatrixFieldType::renderEditor`/`rowHtml` (Task 3) and `admin.js` (Task 4). `matrixable` (Task 1) read by `allowedTypes` (Task 3). `assets()` returns `['admin.js']` (Task 3) and `admin.js` is in `assetMap()` (Task 4) and surfaced by the existing editor-assets union (Phase 2). The `note` type key + `page.blocks` matrix `allowed:['note']` consistent between Task 4 scaffold and Task 5 demo.
