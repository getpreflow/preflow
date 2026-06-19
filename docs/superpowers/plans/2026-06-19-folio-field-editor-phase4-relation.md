# Folio Field/Editor System — Phase 4 (Relation Field) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a minimal `relation` field type — a server-rendered picker that lists records of a target content type and stores the selected target ID(s) — with no new data-layer relation model.

**Architecture:** `RelationFieldType` (a `FieldType`) reads `config.relation` (`to`, `multiple`, optional `labelField`), queries the target type via `DataManager` to populate a `<select>` (single) / multi-`<select>` (multiple), stores the chosen ID(s) (string or JSON array), and resolves IDs → records on the frontend to render their labels. Works with no JS; search-as-you-type is a later concern.

**Tech Stack:** PHP 8.5, `preflow/folio`, `preflow/data` (`DataManager`, `TypeRegistry`, `DynamicRecord`), Twig, PHPUnit 11. No build step; no new dependency; no admin JS.

## Global Constraints

- **PHP 8.5+**, `declare(strict_types=1)` in every PHP file.
- **No build step; no new Composer dependency; no admin JS** (the picker is a plain server-rendered `<select>`).
- **No emojis** in code or UI copy.
- **No data-layer relation model** — this is the minimal ID-list approach (per the spec). Stores target IDs only; resolves on demand. (`N+1` resolution is accepted at this scale and noted for the future relations roadmap item.)
- **Security:** all option values/labels and resolved labels are HTML-escaped; frontend render output contains only field-type-produced escaped markup.
- `config.relation` shape: `{ "to": "<typeKey>", "multiple": bool, "labelField"?: "<field>" }`. Option label = `labelField` if set, else the target type's first `string` field, else the record id.
- Storage: single → ID string; multiple → JSON array of IDs. `toStorage`/`fromStorage` mirror the asset/richtext convention (JSON for arrays, string otherwise; a leading-`[` string decodes to an array).
- Templates overridable via `@folio`; admin POSTs keep `_csrf_token`.
- **Test command:** `vendor/bin/phpunit <path>` from repo root `/Users/smyr/Sites/gbits/flopp`. Integration tests run under strict Twig (`APP_DEBUG=1`).

## File Structure

- `packages/folio/src/Field/Types/RelationFieldType.php` — **new**.
- `packages/folio/src/FolioServiceProvider.php` — **modify**: register `RelationFieldType` (needs `DataManager` + `TypeRegistry` from the container).
- Tests: `packages/folio/tests/Field/RelationFieldTypeTest.php` (new), `packages/folio/tests/Integration/FolioAppTest.php` (modify, incl. scaffold gains an `author` type + a `page.author` relation).
- `examples/folio-demo/config/models/author.json` — **new** (demo target type).
- `examples/folio-demo/config/models/page.json` — **modify** (add a `page.author` relation field).

---

### Task 1: `RelationFieldType`

**Files:**
- Create: `packages/folio/src/Field/Types/RelationFieldType.php`
- Test: `packages/folio/tests/Field/RelationFieldTypeTest.php` (new)

**Interfaces:**
- Consumes: `FieldType`/`FieldContext`; `Preflow\Data\DataManager` (`queryType($type)->get()->items()`, `findType($type,$id): ?DynamicRecord`), `Preflow\Data\TypeRegistry` (`has($type): bool`, `get($type): TypeDefinition` whose `->fields` is a `name => TypeFieldDefinition` map with `->type`), `Preflow\Data\DynamicRecord` (`getId(): ?string`, `get($field): mixed`).
- Produces: `RelationFieldType implements FieldType`, constructed `__construct(DataManager $dm, TypeRegistry $registry)`. Key `relation`. `renderEditor` emits a `<select>` (single, with a `— none —` option) / `<select multiple>` (multiple) populated from the target type, options selected against the stored value. `normalizeInput` → ID string (single) / ID list (multiple). `toStorage`/`fromStorage` JSON-for-array / string. `renderFrontend` resolves IDs → records and renders escaped labels. `assets()`/`rules()` return `[]`.

- [ ] **Step 1: Write the failing test**

Create `packages/folio/tests/Field/RelationFieldTypeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\Types\RelationFieldType;

final class RelationFieldTypeTest extends TestCase
{
    private string $base;
    private DataManager $dm;
    private TypeRegistry $registry;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/folio_rel_' . bin2hex(random_bytes(4));
        mkdir($this->base . '/m', 0777, true);
        mkdir($this->base . '/s', 0777, true);
        file_put_contents($this->base . '/m/author.json', json_encode([
            'key' => 'author', 'table' => 'author', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Authors',
            'fields' => ['name' => ['type' => 'string', 'validate' => ['required']]],
        ]));
        $this->registry = new TypeRegistry($this->base . '/m');
        $this->dm = new DataManager(['json' => new JsonFileDriver($this->base . '/s')], 'json', $this->registry);

        foreach (['a1' => 'Ann', 'a2' => 'Bob'] as $id => $name) {
            $this->dm->saveType(DynamicRecord::fromArray($this->registry->get('author'), ['uuid' => $id, 'name' => $name]));
        }
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

    private function type(): RelationFieldType
    {
        return new RelationFieldType($this->dm, $this->registry);
    }

    private function singleConfig(): array
    {
        return ['relation' => ['to' => 'author', 'multiple' => false]];
    }

    public function test_key(): void
    {
        $this->assertSame('relation', $this->type()->key());
    }

    public function test_render_editor_single_select_with_options(): void
    {
        $html = $this->type()->renderEditor(new FieldContext(
            name: 'writer', label: 'Writer', value: 'a1', config: $this->singleConfig(),
        ));
        $this->assertStringContainsString('<select name="writer"', $html);
        $this->assertStringContainsString('— none —', $html);
        $this->assertStringContainsString('<option value="a1" selected>Ann</option>', $html);
        $this->assertStringContainsString('<option value="a2">Bob</option>', $html);
    }

    public function test_render_editor_multiple_uses_array_name_and_marks_selected(): void
    {
        $html = $this->type()->renderEditor(new FieldContext(
            name: 'writers', value: ['a2'], config: ['relation' => ['to' => 'author', 'multiple' => true]],
        ));
        $this->assertStringContainsString('<select name="writers[]"', $html);
        $this->assertStringContainsString('multiple', $html);
        $this->assertStringContainsString('<option value="a2" selected>Bob</option>', $html);
    }

    public function test_normalize_single_and_multiple(): void
    {
        $t = $this->type();
        $this->assertSame('a1', $t->normalizeInput('a1', $this->singleConfig()));
        $this->assertSame(['a1', 'a2'], $t->normalizeInput(['a1', 'a2'], ['relation' => ['to' => 'author', 'multiple' => true]]));
        $this->assertSame([], $t->normalizeInput('x', ['relation' => ['to' => 'author', 'multiple' => true]]));
    }

    public function test_storage_roundtrip(): void
    {
        $t = $this->type();
        $this->assertSame('a1', $t->toStorage('a1'));
        $this->assertSame('a1', $t->fromStorage('a1'));
        $json = $t->toStorage(['a1', 'a2']);
        $this->assertSame(['a1', 'a2'], $t->fromStorage($json));
    }

    public function test_render_frontend_resolves_labels(): void
    {
        $out = $this->type()->renderFrontend('a1', $this->singleConfig());
        $this->assertStringContainsString('Ann', $out);
        $this->assertStringNotContainsString('a1', $out); // shows the label, not the raw id
    }

    public function test_unknown_target_renders_empty_select(): void
    {
        $html = $this->type()->renderEditor(new FieldContext(
            name: 'x', config: ['relation' => ['to' => 'ghost', 'multiple' => false]],
        ));
        $this->assertStringContainsString('<select name="x"', $html);
        $this->assertStringNotContainsString('Ann', $html);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit packages/folio/tests/Field/RelationFieldTypeTest.php`
Expected: FAIL — `Preflow\Folio\Field\Types\RelationFieldType` does not exist.

- [ ] **Step 3: Create the field type**

Create `packages/folio/src/Field/Types/RelationFieldType.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;

/**
 * Minimal relation field: a server-rendered picker over a target content type,
 * storing the selected target id(s). No data-layer relation model — ids are
 * resolved to records on demand for display.
 */
final class RelationFieldType implements FieldType
{
    public function __construct(
        private readonly DataManager $dm,
        private readonly TypeRegistry $registry,
    ) {}

    public function key(): string
    {
        return 'relation';
    }

    public function renderEditor(FieldContext $ctx): string
    {
        $cfg = $this->relationConfig($ctx->config);
        $name = $ctx->name;
        $selected = $this->toList($ctx->value);
        $label = $ctx->label ?? ucfirst(str_replace('_', ' ', $name));
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $hasError = $ctx->errors !== [];

        $html = '<div class="form-group folio-relation' . ($hasError ? ' has-error' : '') . '">' . "\n";
        $html .= '  <label for="' . $e($name) . '">' . $e($label);
        if ($ctx->required) {
            $html .= ' <span class="form-required">*</span>';
        }
        $html .= '</label>' . "\n";

        $selectName = $cfg['multiple'] ? $name . '[]' : $name;
        $multipleAttr = $cfg['multiple'] ? ' multiple' : '';
        $html .= '  <select name="' . $e($selectName) . '" id="' . $e($name) . '"' . $multipleAttr . '>' . "\n";
        if (!$cfg['multiple']) {
            $html .= '    <option value="">— none —</option>' . "\n";
        }
        foreach ($this->options($cfg) as $id => $optLabel) {
            $sel = in_array((string) $id, $selected, true) ? ' selected' : '';
            $html .= '    <option value="' . $e((string) $id) . '"' . $sel . '>' . $e($optLabel) . '</option>' . "\n";
        }
        $html .= '  </select>' . "\n";

        if ($ctx->help !== null && $ctx->help !== '') {
            $html .= '  <small class="form-help">' . $e($ctx->help) . '</small>' . "\n";
        }
        if ($hasError) {
            $html .= '  <div class="form-error">' . $e((string) ($ctx->errors[0] ?? '')) . '</div>' . "\n";
        }
        $html .= '</div>';

        return $html;
    }

    public function normalizeInput(mixed $raw, array $config): mixed
    {
        if ($this->relationConfig($config)['multiple']) {
            return is_array($raw)
                ? array_values(array_filter($raw, static fn ($v) => is_string($v) && $v !== ''))
                : [];
        }
        return is_string($raw) ? $raw : '';
    }

    public function toStorage(mixed $value): mixed
    {
        if (is_array($value)) {
            return json_encode(array_values($value), JSON_UNESCAPED_SLASHES);
        }
        return (string) ($value ?? '');
    }

    public function fromStorage(mixed $value): mixed
    {
        if (is_string($value) && str_starts_with($value, '[')) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $value;
    }

    public function renderFrontend(mixed $value, array $config): string
    {
        $cfg = $this->relationConfig($config);
        $ids = $this->toList($value);
        if ($ids === [] || $cfg['to'] === '') {
            return '';
        }
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $parts = [];
        foreach ($ids as $id) {
            $record = $this->dm->findType($cfg['to'], $id);
            if ($record === null) {
                continue;
            }
            $parts[] = '<span class="folio-relation-item">' . $e($this->labelFor($record, $cfg)) . '</span>';
        }
        return implode(', ', $parts);
    }

    public function rules(array $config): array
    {
        return [];
    }

    public function assets(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{to: string, multiple: bool, labelField: ?string}
     */
    private function relationConfig(array $config): array
    {
        $r = is_array($config['relation'] ?? null) ? $config['relation'] : [];
        return [
            'to' => (string) ($r['to'] ?? ''),
            'multiple' => (bool) ($r['multiple'] ?? false),
            'labelField' => isset($r['labelField']) ? (string) $r['labelField'] : null,
        ];
    }

    /**
     * @param array{to: string, multiple: bool, labelField: ?string} $cfg
     * @return array<string, string> id => label
     */
    private function options(array $cfg): array
    {
        if ($cfg['to'] === '' || !$this->registry->has($cfg['to'])) {
            return [];
        }
        $out = [];
        foreach ($this->dm->queryType($cfg['to'])->get()->items() as $record) {
            $id = $record->getId();
            if ($id === null) {
                continue;
            }
            $out[(string) $id] = $this->labelFor($record, $cfg);
        }
        return $out;
    }

    /** @param array{to: string, multiple: bool, labelField: ?string} $cfg */
    private function labelFor(DynamicRecord $record, array $cfg): string
    {
        if ($cfg['labelField'] !== null) {
            $v = $record->get($cfg['labelField']);
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }
        $field = $this->firstStringField($cfg['to']);
        if ($field !== null) {
            $v = $record->get($field);
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }
        return (string) $record->getId();
    }

    private function firstStringField(string $type): ?string
    {
        if (!$this->registry->has($type)) {
            return null;
        }
        foreach ($this->registry->get($type)->fields as $name => $def) {
            if ($def->type === 'string') {
                return $name;
            }
        }
        return null;
    }

    /** @return string[] */
    private function toList(mixed $value): array
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

Run: `vendor/bin/phpunit packages/folio/tests/Field/RelationFieldTypeTest.php`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add packages/folio/src/Field/Types/RelationFieldType.php packages/folio/tests/Field/RelationFieldTypeTest.php
git commit -m "feat(folio): minimal relation field type (id picker + resolver)"
```

---

### Task 2: Register the relation type + admin round-trip

**Files:**
- Modify: `packages/folio/src/FolioServiceProvider.php`
- Test: `packages/folio/tests/Integration/FolioAppTest.php` (modify, incl. scaffold)

**Interfaces:**
- Consumes: `RelationFieldType` (Task 1); the `FieldTypeRegistry` bind closure; `DataManager` + `TypeRegistry` from the container.
- Produces: `RelationFieldType` registered in the registry (so model fields of `type: relation` resolve to it). Scaffold gains an `author` type and a `page.author` relation field; the admin form renders the populated picker and stores/round-trips the selected id.

- [ ] **Step 1: Update the scaffold + add the failing tests**

In `packages/folio/tests/Integration/FolioAppTest.php`:

(a) In `scaffold()`, write a second model file alongside `config/models/page.json` (use the same `$w` helper):

```php
        $w('config/models/author.json', json_encode([
            'key' => 'author', 'table' => 'author', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Authors',
            'fields' => [
                'name' => ['type' => 'string', 'validate' => ['required']],
            ],
        ], JSON_PRETTY_PRINT));
```

(b) In the same `scaffold()`'s `config/models/page.json` `fields`, add an `author` relation field (keep the others):

```php
                'author' => ['type' => 'relation', 'relation' => ['to' => 'author', 'multiple' => false]],
```

(c) Add this test method:

```php
    public function test_relation_picker_lists_targets_and_round_trips(): void
    {
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();

        // create a target author
        $app->handle($f->createServerRequest('POST', '/folio/author')
            ->withParsedBody(['name' => 'Ada Lovelace']));
        $dm = $app->container()->get(\Preflow\Data\DataManager::class);
        $authorId = $dm->queryType('author')->first()->getId();

        // the new-page form lists the author as an option
        $newForm = (string) $app->handle($f->createServerRequest('GET', '/folio/page/new'))->getBody();
        $this->assertStringContainsString('<select name="author"', $newForm);
        $this->assertStringContainsString('Ada Lovelace', $newForm);

        // create a page selecting that author, then the edit form shows it selected
        $app->handle($f->createServerRequest('POST', '/folio/page')
            ->withParsedBody(['title' => 'Rel', 'slug' => 'rel', 'body' => 'b', 'status' => 'published', 'author' => $authorId]));
        $pageId = $dm->queryType('page')->where('slug', 'rel')->first()->getId();

        $editForm = (string) $app->handle($f->createServerRequest('GET', '/folio/page/' . $pageId . '/edit')
            ->withAttribute('type', 'page')->withAttribute('id', $pageId))->getBody();
        $this->assertStringContainsString('value="' . $authorId . '" selected', $editForm);
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit packages/folio/tests/Integration/FolioAppTest.php --filter test_relation_picker_lists_targets_and_round_trips`
Expected: FAIL — `relation` type isn't registered, so the field falls back to the `string` type (a text input, no `<select>`/options).

- [ ] **Step 3: Register the relation type in the provider**

In `packages/folio/src/FolioServiceProvider.php`, add the import:

```php
use Preflow\Folio\Field\Types\RelationFieldType;
```

The `FieldTypeRegistry` bind closure must resolve `DataManager` + `TypeRegistry` from the container. Change the closure signature to accept the container and register the relation type. Replace the closure's opening + the registration block so it reads:

```php
        $container->bind(FieldTypeRegistry::class, function (Container $c) use ($uploadsDir, $uploadUrlPrefix): FieldTypeRegistry {
            $registry = new FieldTypeRegistry();
            $registry->register(new StringFieldType());
            $registry->register(new TextFieldType());
            $registry->register(new NumberFieldType());
            $registry->register(new RichTextFieldType(new HtmlSanitizer(
                (new HtmlSanitizerConfig())
                    ->allowSafeElements()
                    ->allowRelativeLinks()
                    ->allowRelativeMedias(),
            )));
            $registry->register(new AssetFieldType($uploadsDir, $uploadUrlPrefix));
            $registry->register(new RelationFieldType(
                $c->get(\Preflow\Data\DataManager::class),
                $c->get(TypeRegistry::class),
            ));
            $registry->alias('int', 'number');
            $registry->alias('integer', 'number');
            $registry->alias('float', 'number');
            return $registry;
        });
```

(`TypeRegistry` is already imported in this file; `DataManager` is referenced by FQCN to avoid adding an import if not present — if a `use Preflow\Data\DataManager;` already exists, the short name may be used instead.)

- [ ] **Step 4: Run the integration suite**

Run: `vendor/bin/phpunit packages/folio/tests/Integration/FolioAppTest.php`
Expected: PASS — including the new relation round-trip test and the existing tests. (The new `author` type adds an Authors entry to the dashboard/nav; existing assertions for `Pages` and counts remain true.)

- [ ] **Step 5: Commit**

```bash
git add packages/folio/src/FolioServiceProvider.php packages/folio/tests/Integration/FolioAppTest.php
git commit -m "feat(folio): register relation field type; admin picker round-trip"
```

---

### Task 3: Demo showcase + full-suite verification

**Files:**
- Create: `examples/folio-demo/config/models/author.json`
- Modify: `examples/folio-demo/config/models/page.json`
- Test: whole repo suite

**Interfaces:**
- Consumes: the relation field type (Tasks 1-2).

- [ ] **Step 1: Add a demo target type**

Create `examples/folio-demo/config/models/author.json`:

```json
{
    "key": "author",
    "table": "author",
    "storage": "json",
    "id_field": "uuid",
    "label": "Authors",
    "fields": {
        "name": { "type": "string", "validate": ["required"] }
    }
}
```

- [ ] **Step 2: Add a relation field to the demo page model**

In `examples/folio-demo/config/models/page.json`, add an `author` relation field (keep the others). The `fields` object becomes:

```json
    "fields": {
        "title": { "type": "string", "validate": ["required"] },
        "slug": { "type": "string", "validate": ["required"] },
        "cover": { "type": "asset", "label": "Cover image", "help": "Upload an image; stored under the Folio uploads dir.", "asset": { "multiple": false, "accept": "image/*" } },
        "author": { "type": "relation", "label": "Author", "help": "Pick an author record.", "relation": { "to": "author", "multiple": false } },
        "body": { "type": "richtext", "label": "Body content", "help": "Rich text — formatting is preserved and sanitized on save." },
        "status": { "type": "string" }
    }
```

- [ ] **Step 3: Verify the demo smoke test still passes**

Run: `vendor/bin/phpunit examples/folio-demo/tests`
Expected: PASS (2 tests) — the demo app still boots and serves `/folio` (now with Pages + Articles + Authors).

- [ ] **Step 4: Run the full repo suite**

Run: `vendor/bin/phpunit`
Expected: PASS — all suites green. Only expected non-failures are the pre-existing PHPUnit deprecations + 1 skip. No test failures. If anything fails, investigate before committing.

- [ ] **Step 5: Commit**

```bash
git add examples/folio-demo/config/models/author.json examples/folio-demo/config/models/page.json
git commit -m "docs(folio): demo an author type + page-author relation field"
```

---

## Self-Review

**Spec coverage (Phase 4 portion of the field/editor spec):**
- Minimal relation field: config `{to, multiple, labelField?}`; server-rendered select/multi-select populated from the target type → Task 1 (`renderEditor`/`options`).
- Stores target ID(s) as JSON (no data-layer relation model) → Task 1 (`toStorage`/`fromStorage`).
- Resolver: IDs → records for frontend display → Task 1 (`renderFrontend`/`labelFor`).
- Label = `labelField` else first string field else id → Task 1 (`labelFor`).
- Registered + admin round-trip → Task 2.
- Demo showcase → Task 3.
- Out of scope (per spec): full relation model (belongsTo/hasMany, eager loading, integrity); matrix (Phase 5); search-as-you-type in the picker.

**Known limitation (acceptable for this phase):** the picker queries ALL target records to build options, and `renderFrontend` resolves each id with a separate `findType` (N+1). Fine at this scale; the future relations roadmap item / a search-driven picker will replace it. Noted, not hidden.

**Placeholder scan:** No `TBD`/`TODO`. The note in Task 2 Step 3 about `DataManager` import is a real conditional instruction (use the short name if already imported), not a deferral.

**Type consistency:** `RelationFieldType.__construct(DataManager, TypeRegistry)` consistent in Task 1 (test) and Task 2 (provider registration). `config.relation` shape (`to`/`multiple`/`labelField`) identical across `renderEditor`/`normalizeInput`/`renderFrontend`/`relationConfig`. `toStorage`/`fromStorage` JSON-array convention matches the asset field (Phase 3). The scaffold `author` model key + `page.author` `relation.to: author` are consistent between Task 2 (scaffold) and Task 3 (demo). The `<option value="..." selected>` format asserted in Task 1's test matches what `renderEditor` emits and what Task 2's edit-form assertion checks.
