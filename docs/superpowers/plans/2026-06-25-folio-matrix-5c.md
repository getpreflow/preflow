# Folio Matrix Per-placement View Override (Phase 5c) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let each matrix reference carry an optional `view` so a placement renders through a per-type-per-view template `@folio/frontend/types/{type}_{view}.twig` (falling back to `{type}.twig` then `_default.twig`), chosen per row via a selector populated from the type's model-declared `views`.

**Architecture:** A reference grows from `{_type,id}` to `{_type,id,view}` (view omitted when empty). `TypeDefinition` gains a model-declared `views` list (the only `preflow/data` change). `RecordRenderer::renderTypeTemplate` takes an optional `$view` and resolves the variant first. `MatrixFieldType` carries the view through storage, whitelists it against the type's declared views on input (the security boundary), renders a per-row `<select>` (only for types that declare views), and passes the view to the renderer; `admin.js addRow` builds the matching select.

**Tech Stack:** PHP 8.5, `preflow/folio`, `preflow/data`, Twig, vanilla JS (`admin.js`), PHPUnit 11. No build step; no new Composer dependency.

## Global Constraints

- **PHP 8.5+**, `declare(strict_types=1)` in every PHP file.
- **No build step; no new Composer dependency.** `admin.js` stays hand-written vanilla JS.
- **No emojis** in code or UI copy (use "Default" text for the empty view option).
- **Additive on top of 5a/5b** — no change to the pick-existing flow, the create-in-drawer flow, or per-type frontend rendering for refs without a view. The 5a `toStorage`/`fromStorage` round-trip `[{"_type":"note","id":"n1"}]` must stay byte-identical (view omitted when empty).
- **Template naming:** flat `@folio/frontend/types/{type}_{view}.twig`. Resolution for a ref with a view: `{type}_{view}.twig` → `{type}.twig` → `_default.twig`. Empty view: `{type}.twig` → `_default.twig` (today's behavior).
- **View discovery:** declared per type in model JSON top-level `views` (array of strings; default `[]`). The editor selector offers `Default` plus the declared list.
- **Security:** `normalizeInput` whitelists the submitted `view` against the referenced type's declared `views` (drops unknowns) — the primary boundary, parallel to how `_type` is filtered against `allowed`. `RecordRenderer` additionally guards `view` with `^[a-z0-9_-]+$` before interpolating it into a template name (defense-in-depth); a non-matching view falls through to the default chain.
- **Markup parity:** the per-row view `<select>` produced by `MatrixFieldType::rowHtml` and by `admin.js addRow` must be DOM-equivalent (same `name="{field}[{i}][view]"`, same `data-matrix-view`, same option set), preserving the 5b client/server invariant.
- **Test command:** `vendor/bin/phpunit <path>` from repo root `/Users/smyr/Sites/gbits/flopp`. Integration tests run under strict Twig (`APP_DEBUG=1`).

## File Structure

- `packages/data/src/TypeDefinition.php` + `TypeRegistry.php` — add `views`.
- `packages/folio/src/Content/RecordRenderer.php` — `renderTypeTemplate($record, $view='')`: candidate chain + view-char guard + `view` in context.
- `packages/folio/src/Field/Types/MatrixFieldType.php` — `toRefs`/`normalizeInput` carry+whitelist `view`; `viewsFor` helper; `rowHtml` + `renderEditor` emit the per-row select + `views` in the options blob; `renderFrontend` passes the view.
- `packages/folio/assets/admin.js` — `addRow` builds the view select.
- `packages/folio/assets/admin.css` — minor style for the per-row view select.
- Tests: `packages/data/tests/TypeViewsTest.php` (new), `packages/folio/tests/Content/RecordRendererTest.php` (modify), `packages/folio/tests/Field/MatrixFieldTypeTest.php` (modify), `packages/folio/tests/Assets/AdminJsTest.php` (modify), `packages/folio/tests/Integration/FolioAppTest.php` (modify).
- Demo: `examples/folio-demo/config/models/note.json` (add `views`), `examples/folio-demo/resources/folio/frontend/types/note_card.twig` (new).

---

### Task 1: `views` on the type definition

**Files:**
- Modify: `packages/data/src/TypeDefinition.php`, `packages/data/src/TypeRegistry.php`
- Test: `packages/data/tests/TypeViewsTest.php` (new)

**Interfaces:**
- Produces: `TypeDefinition` gains `public array $views = []` (constructor-promoted, appended after `matrixable`). `TypeRegistry::load()` populates it from the model JSON top-level key `views`, filtered to strings (default `[]`).

- [ ] **Step 1: Write the failing test**

Create `packages/data/tests/TypeViewsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\TypeRegistry;

final class TypeViewsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/typeviews_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        file_put_contents($this->dir . '/note.json', json_encode([
            'key' => 'note', 'table' => 'note', 'storage' => 'json', 'id_field' => 'uuid',
            'views' => ['card', 'inline', 42], // non-strings filtered out
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

    public function test_views_default_empty_and_read_filtered_to_strings(): void
    {
        $reg = new TypeRegistry($this->dir);
        $this->assertSame(['card', 'inline'], $reg->get('note')->views);
        $this->assertSame([], $reg->get('page')->views);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit packages/data/tests/TypeViewsTest.php`
Expected: FAIL — `TypeDefinition` has no `views`.

- [ ] **Step 3: Add the property**

In `packages/data/src/TypeDefinition.php`, append a constructor-promoted property after `matrixable` (so the constructor tail becomes):

```php
        public bool $matrixable = false,
        public array $views = [],
    ) {}
```

- [ ] **Step 4: Populate it in `TypeRegistry::load()`**

In `packages/data/src/TypeRegistry.php`, in the `return new TypeDefinition(...)` call, add the argument after `matrixable:`:

```php
            matrixable: (bool) ($schema['matrixable'] ?? false),
            views: array_values(array_filter(
                (array) ($schema['views'] ?? []),
                static fn ($v) => is_string($v),
            )),
        );
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit packages/data/tests/TypeViewsTest.php`
Expected: PASS (1 test).

- [ ] **Step 6: Run the Data suite**

Run: `vendor/bin/phpunit packages/data/tests`
Expected: PASS — no regression (the new param is optional).

- [ ] **Step 7: Commit**

```bash
git add packages/data/src/TypeDefinition.php packages/data/src/TypeRegistry.php packages/data/tests/TypeViewsTest.php
git commit -m "feat(data): add views list to TypeDefinition"
```

---

### Task 2: `RecordRenderer` per-view resolution

**Files:**
- Modify: `packages/folio/src/Content/RecordRenderer.php`
- Test: `packages/folio/tests/Content/RecordRendererTest.php`

**Interfaces:**
- Produces: `RecordRenderer::renderTypeTemplate(DynamicRecord $record, string $view = ''): string`. With a non-empty `view` matching `^[a-z0-9_-]+$`, tries `@folio/frontend/types/{type}_{view}.twig` first, then `{type}.twig`, then `_default.twig`; with empty or invalid view, tries `{type}.twig` then `_default.twig`. The render context gains `'view' => $view`.

- [ ] **Step 1: Write the failing tests**

In `packages/folio/tests/Content/RecordRendererTest.php`, replace the `engine()` helper so it can report multiple existing templates, and add view tests. First change `engine()`:

```php
    private function engine(): TemplateEngineInterface
    {
        // Minimal fake: note.twig and note_card.twig exist; everything else falls back to _default.
        return new class implements TemplateEngineInterface {
            public function render(string $template, array $context = []): string
            {
                return $template . '|title=' . ($context['record']['title'] ?? '')
                    . '|body=' . ($context['rendered']['body'] ?? '')
                    . '|view=' . ($context['view'] ?? '');
            }
            public function exists(string $template): bool
            {
                return in_array($template, [
                    '@folio/frontend/types/note.twig',
                    '@folio/frontend/types/note_card.twig',
                ], true);
            }
            public function addFunction(TemplateFunctionDefinition $f): void {}
            public function addGlobal(string $n, mixed $v): void {}
            public function getTemplateExtension(): string { return 'twig'; }
            public function addNamespace(string $n, string $p): void {}
        };
    }
```

Then add these test methods (keep the existing two):

```php
    public function test_render_type_template_uses_view_variant_when_present(): void
    {
        $rr = new RecordRenderer($this->fieldRegistry(), $this->engine());
        $out = $rr->renderTypeTemplate($this->record(), 'card');
        $this->assertStringContainsString('@folio/frontend/types/note_card.twig', $out);
        $this->assertStringContainsString('view=card', $out);
    }

    public function test_render_type_template_view_falls_back_to_type_then_default(): void
    {
        $rr = new RecordRenderer($this->fieldRegistry(), $this->engine());
        // 'inline' variant does not exist -> falls back to note.twig (which does)
        $out = $rr->renderTypeTemplate($this->record(), 'inline');
        $this->assertStringContainsString('@folio/frontend/types/note.twig', $out);
    }

    public function test_render_type_template_rejects_traversal_view(): void
    {
        $rr = new RecordRenderer($this->fieldRegistry(), $this->engine());
        // a view with illegal chars is ignored -> normal chain (note.twig exists)
        $out = $rr->renderTypeTemplate($this->record(), '../secret');
        $this->assertStringContainsString('@folio/frontend/types/note.twig', $out);
        $this->assertStringNotContainsString('secret', $out);
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit packages/folio/tests/Content/RecordRendererTest.php`
Expected: FAIL — `renderTypeTemplate` takes no `$view` argument; the view variant is never chosen.

- [ ] **Step 3: Implement the view resolution**

In `packages/folio/src/Content/RecordRenderer.php`, replace `renderTypeTemplate` with:

```php
    /**
     * Resolve and render the record's per-type frontend template. With a view,
     * a per-view variant (@folio/frontend/types/{type}_{view}.twig) is tried
     * first, then the per-type template, then the package default. Userland may
     * override any of these via the @folio namespace.
     */
    public function renderTypeTemplate(DynamicRecord $record, string $view = ''): string
    {
        $type = $record->getType()->key;

        $candidates = [];
        if ($view !== '' && preg_match('/^[a-z0-9_-]+$/', $view) === 1) {
            $candidates[] = '@folio/frontend/types/' . $type . '_' . $view . '.twig';
        }
        $candidates[] = '@folio/frontend/types/' . $type . '.twig';

        $template = '@folio/frontend/types/_default.twig';
        foreach ($candidates as $candidate) {
            if ($this->engine->exists($candidate)) {
                $template = $candidate;
                break;
            }
        }

        return $this->engine->render($template, [
            'record' => $record->toArray(),
            'rendered' => $this->renderedMap($record),
            'type' => $type,
            'view' => $view,
        ]);
    }
```

- [ ] **Step 4: Run to verify they pass**

Run: `vendor/bin/phpunit packages/folio/tests/Content/RecordRendererTest.php`
Expected: PASS (5 tests) — including the pre-existing two (note.twig chosen when it exists; `_default` otherwise).

- [ ] **Step 5: Commit**

```bash
git add packages/folio/src/Content/RecordRenderer.php packages/folio/tests/Content/RecordRendererTest.php
git commit -m "feat(folio): RecordRenderer resolves per-view template variant"
```

---

### Task 3: `MatrixFieldType` carries, whitelists, and renders the view

**Files:**
- Modify: `packages/folio/src/Field/Types/MatrixFieldType.php`
- Test: `packages/folio/tests/Field/MatrixFieldTypeTest.php`

**Interfaces:**
- Consumes: `RecordRenderer::renderTypeTemplate($record, $view)` (Task 2); `TypeRegistry::get($type)->views` (Task 1).
- Produces: stored ref shape `{_type,id}` or `{_type,id,view}` (view present only when non-empty). `normalizeInput` keeps a submitted `view` only if it is in the referenced type's declared `views`. `renderEditor` emits, per row, a `<select name="{field}[{i}][view]" data-matrix-view>` with a `Default` (empty) option plus the type's declared views — **only when the type declares ≥1 view** — and adds `views: {type: [...]}` to the options blob. New private helper `viewsFor(string $type): string[]`.

- [ ] **Step 1: Extend the test setup + add the failing tests**

In `packages/folio/tests/Field/MatrixFieldTypeTest.php`:

(a) In `setUp()`, give `note` declared views and add a second matrixable type with **no** views. Replace the `note.json` write and add a `memo.json` write:

```php
        // matrixable type WITH declared views
        file_put_contents($this->base . '/m/note.json', json_encode([
            'key' => 'note', 'table' => 'note', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Notes', 'matrixable' => true,
            'views' => ['card', 'inline'],
            'fields' => ['name' => ['type' => 'string']],
        ]));
        // matrixable type with NO declared views
        file_put_contents($this->base . '/m/memo.json', json_encode([
            'key' => 'memo', 'table' => 'memo', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Memos', 'matrixable' => true,
            'fields' => ['name' => ['type' => 'string']],
        ]));
```

(b) Add these test methods:

```php
    public function test_render_editor_emits_view_select_for_type_with_views(): void
    {
        $html = $this->type()->renderEditor(new FieldContext(
            name: 'blocks', value: [['_type' => 'note', 'id' => 'n1', 'view' => 'card']], config: $this->config(['note']),
        ));
        $this->assertStringContainsString('name="blocks[0][view]"', $html);
        $this->assertStringContainsString('data-matrix-view', $html);
        $this->assertStringContainsString('<option value="">Default</option>', $html);
        $this->assertStringContainsString('<option value="card" selected>card</option>', $html);
        $this->assertStringContainsString('<option value="inline">inline</option>', $html);
    }

    public function test_render_editor_no_view_select_for_type_without_views(): void
    {
        $this->dm->saveType(DynamicRecord::fromArray($this->registry->get('memo'), ['uuid' => 'm1', 'name' => 'Memo one']));
        $html = $this->type()->renderEditor(new FieldContext(
            name: 'blocks', value: [['_type' => 'memo', 'id' => 'm1']], config: $this->config(['memo']),
        ));
        $this->assertStringNotContainsString('name="blocks[0][view]"', $html);
    }

    public function test_options_blob_includes_declared_views(): void
    {
        $html = $this->type()->renderEditor(new FieldContext(name: 'blocks', config: $this->config(['note'])));
        $this->assertStringContainsString('"views":{"note":["card","inline"]}', $html);
    }

    public function test_normalize_keeps_declared_view(): void
    {
        $raw = [['_type' => 'note', 'id' => 'n1', 'view' => 'card']];
        $out = $this->type()->normalizeInput($raw, $this->config(['note']));
        $this->assertSame([['_type' => 'note', 'id' => 'n1', 'view' => 'card']], $out);
    }

    public function test_normalize_drops_undeclared_view(): void
    {
        $raw = [['_type' => 'note', 'id' => 'n1', 'view' => 'bogus']];
        $out = $this->type()->normalizeInput($raw, $this->config(['note']));
        $this->assertSame([['_type' => 'note', 'id' => 'n1']], $out); // bogus view stripped
    }

    public function test_view_storage_roundtrip(): void
    {
        $t = $this->type();
        $json = $t->toStorage([['_type' => 'note', 'id' => 'n1', 'view' => 'card']]);
        $this->assertSame([['_type' => 'note', 'id' => 'n1', 'view' => 'card']], $t->fromStorage($json));
    }

    public function test_render_frontend_passes_view_to_renderer(): void
    {
        // the fake RecordRenderer engine in recordRenderer() echoes only type+name,
        // so assert the ref with a view still renders (view threads through without error)
        $out = $this->type()->renderFrontend([['_type' => 'note', 'id' => 'n1', 'view' => 'card']], $this->config(['note']));
        $this->assertStringContainsString('[note:First note]', $out);
    }
```

Note: the existing `test_normalize_filters_disallowed_and_reindexes`, `test_storage_roundtrip`, and `test_render_frontend_*` still pass unchanged — their inputs carry no `view`, so the stored shape stays `{_type,id}`.

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit packages/folio/tests/Field/MatrixFieldTypeTest.php`
Expected: FAIL — no view select, no `views` in the blob, `normalizeInput`/`toRefs` drop the `view`.

- [ ] **Step 3: Carry the view through `toRefs`**

In `packages/folio/src/Field/Types/MatrixFieldType.php`, replace `toRefs` (and its docblock) with:

```php
    /**
     * Normalize a stored/raw value to a list of {_type,id} refs, preserving a
     * non-empty view per entry.
     *
     * @return list<array{_type:string,id:string,view?:string}>
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
            if ($type === '' || $id === '') {
                continue;
            }
            $ref = ['_type' => $type, 'id' => $id];
            $view = (string) ($entry['view'] ?? '');
            if ($view !== '') {
                $ref['view'] = $view;
            }
            $out[] = $ref;
        }
        return $out;
    }
```

- [ ] **Step 4: Add `viewsFor` and whitelist the view in `normalizeInput`**

Add the helper (e.g. directly after `allowedTypes`):

```php
    /**
     * Declared views for a type (model JSON `views`), filtered to non-empty strings.
     *
     * @return string[]
     */
    private function viewsFor(string $type): array
    {
        if (!$this->registry->has($type)) {
            return [];
        }
        return array_values(array_filter(
            $this->registry->get($type)->views,
            static fn ($v) => is_string($v) && $v !== '',
        ));
    }
```

Replace `normalizeInput` with:

```php
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
            $ref = ['_type' => $type, 'id' => $id];
            $view = (string) ($entry['view'] ?? '');
            if ($view !== '' && in_array($view, $this->viewsFor($type), true)) {
                $ref['view'] = $view;
            }
            $out[] = $ref;
        }
        return $out;
    }
```

- [ ] **Step 5: Render the per-row view select**

Replace `rowHtml` with the new signature (adds `$views` + `$view`) that emits the select only when `$views` is non-empty:

```php
    /**
     * @param string[] $views declared views for $type ([] => no selector)
     */
    private function rowHtml(string $field, int $i, string $type, string $id, string $label, array $views, string $view): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $viewSelect = '';
        if ($views !== []) {
            $viewSelect = '<select name="' . $e($field) . '[' . $i . '][view]" data-matrix-view>'
                . '<option value="">Default</option>';
            foreach ($views as $v) {
                $viewSelect .= '<option value="' . $e($v) . '"' . ($v === $view ? ' selected' : '') . '>' . $e($v) . '</option>';
            }
            $viewSelect .= '</select>';
        }

        return '      <div class="folio-matrix-row" data-matrix-row>'
            . '<input type="hidden" name="' . $e($field) . '[' . $i . '][_type]" value="' . $e($type) . '">'
            . '<input type="hidden" name="' . $e($field) . '[' . $i . '][id]" value="' . $e($id) . '">'
            . '<span class="folio-matrix-label">' . $e($label) . ' <em>(' . $e($type) . ')</em></span>'
            . $viewSelect
            . '<span class="folio-matrix-controls">'
            . '<button type="button" data-matrix-up>Up</button>'
            . '<button type="button" data-matrix-down>Down</button>'
            . '<button type="button" data-matrix-remove>Remove</button>'
            . '</span></div>' . "\n";
    }
```

- [ ] **Step 6: Feed views into `renderEditor` (rows + options blob)**

In `renderEditor`, change the options blob initializer to include `views` and fill it per allowed type. Replace the options-building block (from `$options = [...]` through the `$optionsJson = ...` line) with:

```php
        // Options blob for the JS picker: types + their records (id,label) + declared views.
        $options = ['prefix' => $this->prefix, 'types' => [], 'records' => [], 'views' => []];
        foreach ($allowed as $key) {
            $options['types'][] = ['key' => $key, 'label' => $this->typeLabel($key)];
            $recs = [];
            foreach ($this->dm->queryType($key)->get()->items() as $record) {
                $id = $record->getId();
                if ($id === null) {
                    continue;
                }
                $recs[] = ['id' => (string) $id, 'label' => $this->labeler->label($record)];
            }
            $options['records'][$key] = $recs;
            $options['views'][$key] = $this->viewsFor($key);
        }
        $optionsJson = json_encode($options, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES);
```

In the same method, change the row-rendering loop to pass the views + current view:

```php
        foreach (array_values($refs) as $i => $ref) {
            $html .= $this->rowHtml(
                $name,
                $i,
                $ref['_type'],
                $ref['id'],
                $this->refLabel($ref),
                $this->viewsFor($ref['_type']),
                $ref['view'] ?? '',
            );
        }
```

- [ ] **Step 7: Pass the view in `renderFrontend`**

In `renderFrontend`, change the render call (currently `$out .= $this->records->renderTypeTemplate($record);`) to:

```php
            $out .= $this->records->renderTypeTemplate($record, $ref['view'] ?? '');
```

- [ ] **Step 8: Run the field suite**

Run: `vendor/bin/phpunit packages/folio/tests/Field/MatrixFieldTypeTest.php`
Expected: PASS — new view tests plus all pre-existing ones (viewless refs unchanged).

- [ ] **Step 9: Commit**

```bash
git add packages/folio/src/Field/Types/MatrixFieldType.php packages/folio/tests/Field/MatrixFieldTypeTest.php
git commit -m "feat(folio): matrix carries + whitelists per-ref view, renders view selector"
```

---

### Task 4: `admin.js` view select (markup parity) + CSS

**Files:**
- Modify: `packages/folio/assets/admin.js`, `packages/folio/assets/admin.css`
- Test: `packages/folio/tests/Assets/AdminJsTest.php`

**Interfaces:**
- Consumes: `opts.views[type]` from the options blob (Task 3); the per-row select markup must match `MatrixFieldType::rowHtml` (Task 3): `<select name="{field}[{i}][view]" data-matrix-view><option value="">Default</option>{<option value="{v}"[ selected]>{v}</option>}…</select>`, emitted only when the type declares ≥1 view.
- Produces: `addRow(type, id, label, view)` — `view` optional, defaults `''`. Existing call sites that pass three args are unaffected (new rows get `Default`).

- [ ] **Step 1: Add the failing static assertion**

In `packages/folio/tests/Assets/AdminJsTest.php`, add a method:

```php
    public function test_addrow_builds_view_select_from_options(): void
    {
        $js = $this->js();
        $this->assertStringContainsString('opts.views', $js);                 // reads declared views
        $this->assertStringContainsString('[view]', $js);                     // emits the [view] input name
        $this->assertStringContainsString('data-matrix-view', $js);           // matches server markup
        $this->assertStringContainsString('<option value="">Default</option>', $js);
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit packages/folio/tests/Assets/AdminJsTest.php`
Expected: FAIL — `admin.js addRow` does not build a view select yet.

- [ ] **Step 3: Build the view select in `addRow`**

In `packages/folio/assets/admin.js`, replace the `addRow` function with the version below (adds the optional `view` param and the per-type select, inserted between the label span and the controls span — DOM-equivalent to the server `rowHtml`):

```js
        function addRow(type, id, label, view) {
            view = view || '';
            var i = next++;
            var row = document.createElement('div');
            row.className = 'folio-matrix-row';
            row.setAttribute('data-matrix-row', '');

            var views = (opts.views && opts.views[type]) || [];
            var viewSelect = '';
            if (views.length) {
                viewSelect = '<select name="' + esc(field) + '[' + i + '][view]" data-matrix-view>' +
                    '<option value="">Default</option>';
                views.forEach(function (v) {
                    viewSelect += '<option value="' + esc(v) + '"' + (v === view ? ' selected' : '') + '>' + esc(v) + '</option>';
                });
                viewSelect += '</select>';
            }

            row.innerHTML =
                '<input type="hidden" name="' + esc(field) + '[' + i + '][_type]" value="' + esc(type) + '">' +
                '<input type="hidden" name="' + esc(field) + '[' + i + '][id]" value="' + esc(id) + '">' +
                '<span class="folio-matrix-label">' + esc(label) + ' <em>(' + esc(type) + ')</em></span>' +
                viewSelect +
                '<span class="folio-matrix-controls">' +
                '<button type="button" data-matrix-up>Up</button>' +
                '<button type="button" data-matrix-down>Down</button>' +
                '<button type="button" data-matrix-remove>Remove</button>' +
                '</span>';
            rowsEl.appendChild(row);
        }
```

(The two existing `addRow(...)` call sites — the "Add" pick-existing button and the drawer `message` handler — pass three arguments; `view` defaults to `''`, so newly added rows render with `Default`. Leave those call sites unchanged.)

- [ ] **Step 4: Add a minor style for the view select**

Append to `packages/folio/assets/admin.css`:

```css
/* Matrix per-placement view selector (Phase 5c) */
[data-matrix-view] {
    margin: 0 0.5rem;
}
```

- [ ] **Step 5: Run the static + field suites**

Run: `vendor/bin/phpunit packages/folio/tests/Assets/AdminJsTest.php packages/folio/tests/Field/MatrixFieldTypeTest.php`
Expected: PASS — admin.js now defines the view select; field markup unchanged.

- [ ] **Step 6: Commit**

```bash
git add packages/folio/assets/admin.js packages/folio/assets/admin.css packages/folio/tests/Assets/AdminJsTest.php
git commit -m "feat(folio): admin.js builds per-row view select (markup parity with server)"
```

---

### Task 5: Demo + integration tests + full-suite verification

**Files:**
- Modify: `examples/folio-demo/config/models/note.json`
- Create: `examples/folio-demo/resources/folio/frontend/types/note_card.twig`
- Test: `packages/folio/tests/Integration/FolioAppTest.php` (modify, incl. scaffold)

**Interfaces:**
- Consumes: the full 5c chain (Tasks 1-4). Renders, under real Twig, a page block via its per-view variant.

- [ ] **Step 1: Extend the integration scaffold**

In `packages/folio/tests/Integration/FolioAppTest.php` `scaffold()`:

(a) Give the `note` model declared views — replace the `config/models/note.json` write with:

```php
        $w('config/models/note.json', json_encode([
            'key' => 'note', 'table' => 'note', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Notes', 'matrixable' => true,
            'views' => ['card'],
            'fields' => [
                'name' => ['type' => 'string', 'validate' => ['required']],
            ],
        ], JSON_PRETTY_PRINT));
```

(b) Add userland per-type templates so the default and the card view are distinguishable — add these two `$w(...)` writes (e.g. just after the `app/pages/index.twig` write):

```php
        $w('resources/folio/frontend/types/note.twig', '<section class="note-default"><h3>{{ record.name }}</h3></section>');
        $w('resources/folio/frontend/types/note_card.twig', '<aside class="note-card">{{ record.name }}</aside>');
```

- [ ] **Step 2: Add the failing integration tests**

Add these methods to `FolioAppTest`:

```php
    public function test_matrix_view_override_selects_per_view_template(): void
    {
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $app->handle($f->createServerRequest('POST', '/folio/note')->withParsedBody(['name' => 'Viewed Note']));
        $dm = $app->container()->get(\Preflow\Data\DataManager::class);
        $noteId = $dm->queryType('note')->where('name', 'Viewed Note')->first()->getId();

        $app->handle($f->createServerRequest('POST', '/folio/page')->withParsedBody([
            'title' => 'Views', 'slug' => 'views', 'body' => 'b', 'status' => 'published',
            'blocks' => [
                ['_type' => 'note', 'id' => $noteId],                    // default view
                ['_type' => 'note', 'id' => $noteId, 'view' => 'card'],  // card view
            ],
        ]));

        $front = (string) $app->handle($f->createServerRequest('GET', '/views'))->getBody();
        $this->assertStringContainsString('note-default', $front); // viewless -> note.twig
        $this->assertStringContainsString('note-card', $front);    // view=card -> note_card.twig
    }

    public function test_matrix_view_round_trips_in_editor_and_drops_undeclared(): void
    {
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $app->handle($f->createServerRequest('POST', '/folio/note')->withParsedBody(['name' => 'RT Note']));
        $dm = $app->container()->get(\Preflow\Data\DataManager::class);
        $noteId = $dm->queryType('note')->where('name', 'RT Note')->first()->getId();

        $app->handle($f->createServerRequest('POST', '/folio/page')->withParsedBody([
            'title' => 'RT', 'slug' => 'rt', 'body' => 'b', 'status' => 'published',
            'blocks' => [
                ['_type' => 'note', 'id' => $noteId, 'view' => 'card'],  // declared -> kept
                ['_type' => 'note', 'id' => $noteId, 'view' => 'bogus'], // undeclared -> stripped
            ],
        ]));
        $pageId = $dm->queryType('page')->where('slug', 'rt')->first()->getId();

        $edit = (string) $app->handle($f->createServerRequest('GET', '/folio/page/' . $pageId . '/edit')
            ->withAttribute('type', 'page')->withAttribute('id', $pageId))->getBody();
        $this->assertStringContainsString('<option value="card" selected>card</option>', $edit); // kept view shows selected
        $this->assertStringNotContainsString('bogus', $edit);                                     // undeclared dropped
    }
```

- [ ] **Step 3: Run to verify they fail, then (after Tasks 1-4 are in) pass**

Run: `APP_DEBUG=1 vendor/bin/phpunit packages/folio/tests/Integration/FolioAppTest.php --filter test_matrix_view`
Expected: PASS (Tasks 1-4 implement the behavior; this task only adds the demo assets + tests). If these are written before Tasks 1-4 land they FAIL — but in the task order they run last, so expect PASS. Verify the two new tests pass and the existing matrix integration tests still pass.

- [ ] **Step 4: Add the demo assets**

Add `"views": ["card"]` to `examples/folio-demo/config/models/note.json` (top-level, alongside `"matrixable": true`). The file becomes:

```json
{
    "key": "note",
    "table": "note",
    "storage": "json",
    "id_field": "uuid",
    "label": "Notes",
    "matrixable": true,
    "views": ["card"],
    "fields": {
        "name": { "type": "string", "validate": ["required"] },
        "body": { "type": "richtext", "label": "Body" }
    }
}
```

Create `examples/folio-demo/resources/folio/frontend/types/note_card.twig`:

```twig
<aside class="note-card-compact">
    <strong>{{ record.name }}</strong>
</aside>
```

- [ ] **Step 5: Verify the demo smoke test still passes**

Run: `APP_DEBUG=1 vendor/bin/phpunit examples/folio-demo/tests`
Expected: PASS — the demo app still boots and serves `/folio`.

- [ ] **Step 6: Run the full repo suite**

Run: `vendor/bin/phpunit`
Expected: PASS — all suites green; only the pre-existing PHPUnit deprecations + 1 skip. No test failures. (Note: do NOT export `APP_DEBUG` globally — the core `ApplicationEnvTest` asserts `.env` precedence and fails if `APP_DEBUG` is set in the shell. Run folio integration with `APP_DEBUG=1` scoped to the path, as above.)

- [ ] **Step 7: Commit**

```bash
git add examples/folio-demo/config/models/note.json examples/folio-demo/resources/folio/frontend/types/note_card.twig packages/folio/tests/Integration/FolioAppTest.php
git commit -m "docs(folio): demo a note 'card' view + integration tests for per-placement view"
```

---

## Self-Review

**Spec coverage (5c):**
- Optional per-ref `view`, omitted when empty → Task 3 (`toRefs`/`normalizeInput`); round-trip + backward-compat → Task 3 tests + Task 5 (viewless default render).
- `TypeDefinition.views` from model JSON (only data-layer change) → Task 1.
- Resolution `{type}_{view}` → `{type}` → `_default`, view-char guard, `view` in context → Task 2.
- Editor per-row `<select>` (Default + declared views; only when ≥1 view), `views` in options blob, whitelist on input → Task 3; `admin.js` parity → Task 4.
- `renderFrontend` passes the view → Task 3; end-to-end variant render → Task 5.
- Demo (declared view + variant template) → Task 5.
- Out of scope held: per-field view narrowing, filesystem auto-discovery.

**Placeholder scan:** No `TBD`/`TODO`. Every code step shows full code; every modify step quotes the exact anchor to change.

**Type consistency:**
- `TypeDefinition.views` (Task 1) read by `RecordRenderer`? no — read by `MatrixFieldType::viewsFor` (Task 3) and `TypeViewsTest` (Task 1).
- `renderTypeTemplate(DynamicRecord, string $view = '')` (Task 2) called by `MatrixFieldType::renderFrontend` with `$ref['view'] ?? ''` (Task 3 Step 7) and tested in Task 2.
- Ref shape `{_type,id,view?}` consistent across `toRefs`, `normalizeInput` (Task 3), the stored/edited round-trip (Task 3 + Task 5), and `renderFrontend`.
- View `<select>` markup `name="{field}[{i}][view]" data-matrix-view` + `<option value="">Default</option>` identical between `rowHtml` (Task 3 Step 5) and `admin.js addRow` (Task 4 Step 3), asserted in Task 3 (`test_render_editor_emits_view_select_for_type_with_views`), Task 4 (`AdminJsTest`), and Task 5 (`<option value="card" selected>`).
- Options blob key `"views"` (Task 3 Step 6) read as `opts.views` in `admin.js` (Task 4 Step 3); asserted `"views":{"note":["card","inline"]}` in Task 3.
- `viewsFor` (Task 3 Step 4) is the single source for both the editor options/rows and the `normalizeInput` whitelist.
```
