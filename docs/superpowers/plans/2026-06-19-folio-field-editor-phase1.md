# Folio Field/Editor System — Phase 1 (Framework + Foundation) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace Folio's hardcoded `FieldMapper` with an extensible field-type framework (interface + registry + built-in string/text/number types), pass full field definitions to the admin form, and fix the live validation-error bug (catch `ValidationException`, re-render the form at 422 with values + per-field errors).

**Architecture:** A `FieldType` interface (render editor / normalize input / (de)serialize storage / render frontend / rules / assets) with a `FieldTypeRegistry`. `string`/`text`/`number` become trivial field types. `AdminController` drives field rendering and the save path through the registry. `preflow/data`'s `TypeFieldDefinition` gains `label`/`help`/`config` so editors get everything they need from the model JSON.

**Tech Stack:** PHP 8.5, `preflow/folio`, `preflow/data`, `preflow/form` (`FieldRenderer`), `preflow/validation` (`ValidationException`), Twig, PHPUnit 11. No build step, no new dependencies in this phase.

## Global Constraints

- **PHP 8.5+**, `declare(strict_types=1)` in every PHP file.
- **No build step, no new dependencies** in Phase 1 (Trix + `symfony/html-sanitizer` arrive in Phase 2).
- **No emojis** in code or UI copy.
- The Folio admin renders in **action mode** (bypasses `AssetCollector`); Phase 1 introduces no admin JS/CSS — that wiring is deferred to Phase 2 where rich text needs it. `FieldType::assets()` exists in the interface but every Phase 1 type returns `[]`.
- Templates stay overridable via the `@folio` namespace.
- Field types live in `preflow/folio`; the **only** `preflow/data` change is adding `label`/`help`/`config` to `TypeFieldDefinition` (no relation model, no other data-layer change).
- **Test command:** `vendor/bin/phpunit <path>` from repo root `/Users/smyr/Sites/gbits/flopp`. Bare `vendor/bin/phpunit` runs all suites (Folio + Data included).
- Folio admin POSTs already carry `_csrf_token`; do not remove it.
- Integration tests run under strict Twig (`APP_DEBUG=1`) — guard undefined-variable access.

## File Structure

- `packages/data/src/TypeFieldDefinition.php` — **modify**: add `label`, `help`, `config`.
- `packages/data/src/TypeRegistry.php` — **modify**: parse `label`, `help`, and a `config` bag (all non-reserved field keys).
- `packages/folio/src/Field/FieldType.php` — **new**: the interface.
- `packages/folio/src/Field/FieldContext.php` — **new**: value object passed to `renderEditor`.
- `packages/folio/src/Field/FieldTypeRegistry.php` — **new**: registry with aliases + fallback.
- `packages/folio/src/Field/Types/AbstractScalarFieldType.php` — **new**: shared base.
- `packages/folio/src/Field/Types/StringFieldType.php` / `TextFieldType.php` / `NumberFieldType.php` — **new**.
- `packages/folio/src/Content/FieldMapper.php` — **delete** (replaced by the registry).
- `packages/folio/tests/Content/FieldMapperTest.php` — **delete**.
- `packages/folio/src/FolioServiceProvider.php` — **modify**: bind + populate `FieldTypeRegistry`; inject into `AdminController`.
- `packages/folio/src/Http/AdminController.php` — **modify**: registry-driven field rendering + registry save path + validation round-trip.
- `packages/folio/templates/admin/form.twig` — **modify**: output pre-rendered field HTML.
- Tests: `packages/data/tests/TypeFieldConfigTest.php` (new), `packages/folio/tests/Field/FieldTypeRegistryTest.php` (new), `packages/folio/tests/Field/ScalarFieldTypesTest.php` (new), `packages/folio/tests/Integration/FolioAppTest.php` (modify).

---

### Task 1: Extend `TypeFieldDefinition` with label / help / config

**Files:**
- Modify: `packages/data/src/TypeFieldDefinition.php`
- Modify: `packages/data/src/TypeRegistry.php`
- Test: `packages/data/tests/TypeFieldConfigTest.php` (new)

**Interfaces:**
- Produces: `TypeFieldDefinition` gains `public ?string $label = null`, `public ?string $help = null`, `public array $config = []`. `TypeRegistry::load()` populates them from the model JSON; `config` = all field keys except the reserved set (`type`, `searchable`, `transform`, `validate`, `label`, `help`).

- [ ] **Step 1: Write the failing test**

Create `packages/data/tests/TypeFieldConfigTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\TypeRegistry;

final class TypeFieldConfigTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/typecfg_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        file_put_contents($this->dir . '/post.json', json_encode([
            'key' => 'post', 'table' => 'post', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => [
                'title' => ['type' => 'string', 'validate' => ['required'], 'label' => 'Headline', 'help' => 'Shown in lists'],
                'author' => ['type' => 'relation', 'relation' => ['to' => 'user', 'multiple' => false]],
                'plain' => ['type' => 'string'],
            ],
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/post.json');
        @rmdir($this->dir);
    }

    public function test_parses_label_help_and_config_bag(): void
    {
        $def = (new TypeRegistry($this->dir))->get('post');

        $title = $def->fields['title'];
        $this->assertSame('Headline', $title->label);
        $this->assertSame('Shown in lists', $title->help);
        $this->assertSame([], $title->config); // no non-reserved keys

        $author = $def->fields['author'];
        $this->assertSame(['relation' => ['to' => 'user', 'multiple' => false]], $author->config);

        $plain = $def->fields['plain'];
        $this->assertNull($plain->label);
        $this->assertNull($plain->help);
        $this->assertSame([], $plain->config);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit packages/data/tests/TypeFieldConfigTest.php`
Expected: FAIL — `TypeFieldDefinition` has no `label`/`help`/`config` (unknown named arg / undefined property).

- [ ] **Step 3: Add the properties to `TypeFieldDefinition`**

Replace the constructor in `packages/data/src/TypeFieldDefinition.php`:

```php
    /**
     * @param list<string> $validate
     * @param array<string, mixed> $config
     */
    public function __construct(
        public string $name,
        public string $type = 'string',
        public bool $searchable = false,
        public ?string $transform = null,
        public array $validate = [],
        public ?string $label = null,
        public ?string $help = null,
        public array $config = [],
    ) {}
```

- [ ] **Step 4: Populate them in `TypeRegistry::load()`**

In `packages/data/src/TypeRegistry.php`, inside the `foreach ($schema['fields'] ...)` loop, replace the body that builds `$fields[$name]` with:

```php
            $fieldType = $fieldDef['type'] ?? 'string';
            $searchable = $fieldDef['searchable'] ?? false;
            $transform = $fieldDef['transform'] ?? null;
            $validate = $fieldDef['validate'] ?? [];
            $label = $fieldDef['label'] ?? null;
            $help = $fieldDef['help'] ?? null;
            $config = array_diff_key($fieldDef, array_flip(
                ['type', 'searchable', 'transform', 'validate', 'label', 'help'],
            ));

            $fields[$name] = new TypeFieldDefinition(
                name: $name,
                type: $fieldType,
                searchable: $searchable,
                transform: $transform,
                validate: $validate,
                label: $label,
                help: $help,
                config: $config,
            );
```

(Leave the `$searchable`/`$transform` handling below the assignment unchanged.)

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit packages/data/tests/TypeFieldConfigTest.php`
Expected: PASS (1 test).

- [ ] **Step 6: Run the Data suite to confirm no regression**

Run: `vendor/bin/phpunit packages/data/tests`
Expected: PASS (existing Data tests still green — the new params are optional).

- [ ] **Step 7: Commit**

```bash
git add packages/data/src/TypeFieldDefinition.php packages/data/src/TypeRegistry.php packages/data/tests/TypeFieldConfigTest.php
git commit -m "feat(data): add label/help/config to TypeFieldDefinition"
```

---

### Task 2: Field-type framework — interface, context, registry

**Files:**
- Create: `packages/folio/src/Field/FieldType.php`
- Create: `packages/folio/src/Field/FieldContext.php`
- Create: `packages/folio/src/Field/FieldTypeRegistry.php`
- Test: `packages/folio/tests/Field/FieldTypeRegistryTest.php` (new)

**Interfaces:**
- Produces:
  - `interface Preflow\Folio\Field\FieldType` with: `key(): string`, `renderEditor(FieldContext $ctx): string`, `normalizeInput(mixed $raw, array $config): mixed`, `toStorage(mixed $value): mixed`, `fromStorage(mixed $value): mixed`, `renderFrontend(mixed $value, array $config): string`, `rules(array $config): array`, `assets(): array`.
  - `final readonly class Preflow\Folio\Field\FieldContext` (constructor below).
  - `final class Preflow\Folio\Field\FieldTypeRegistry` with `register(FieldType $type): void`, `alias(string $from, string $to): void`, `has(string $key): bool`, `get(string $key): FieldType` (alias-resolved; falls back to the `string` type for unknown keys; throws if no `string` type registered).

- [ ] **Step 1: Write the failing test**

Create `packages/folio/tests/Field/FieldTypeRegistryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;
use Preflow\Folio\Field\FieldTypeRegistry;

final class FieldTypeRegistryTest extends TestCase
{
    private function fakeType(string $key): FieldType
    {
        return new class($key) implements FieldType {
            public function __construct(private string $k) {}
            public function key(): string { return $this->k; }
            public function renderEditor(FieldContext $ctx): string { return $this->k; }
            public function normalizeInput(mixed $raw, array $config): mixed { return $raw; }
            public function toStorage(mixed $value): mixed { return $value; }
            public function fromStorage(mixed $value): mixed { return $value; }
            public function renderFrontend(mixed $value, array $config): string { return (string) $value; }
            public function rules(array $config): array { return []; }
            public function assets(): array { return []; }
        };
    }

    public function test_register_get_has(): void
    {
        $r = new FieldTypeRegistry();
        $r->register($this->fakeType('string'));
        $r->register($this->fakeType('text'));

        $this->assertTrue($r->has('text'));
        $this->assertFalse($r->has('nope'));
        $this->assertSame('text', $r->get('text')->key());
    }

    public function test_unknown_falls_back_to_string(): void
    {
        $r = new FieldTypeRegistry();
        $r->register($this->fakeType('string'));
        $this->assertSame('string', $r->get('totally-unknown')->key());
    }

    public function test_alias_resolves(): void
    {
        $r = new FieldTypeRegistry();
        $r->register($this->fakeType('string'));
        $r->register($this->fakeType('number'));
        $r->alias('integer', 'number');

        $this->assertTrue($r->has('integer'));
        $this->assertSame('number', $r->get('integer')->key());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit packages/folio/tests/Field/FieldTypeRegistryTest.php`
Expected: FAIL — `Preflow\Folio\Field\*` classes do not exist.

- [ ] **Step 3: Create the interface**

Create `packages/folio/src/Field/FieldType.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Field;

/**
 * A pluggable content field type: it renders its own admin editor, normalizes
 * and (de)serializes its value, renders safe frontend output, and declares any
 * validation rules and admin assets it needs.
 */
interface FieldType
{
    /** Stable key used in model JSON ("type": "...") and the registry. */
    public function key(): string;

    /** Admin-form HTML for this field. */
    public function renderEditor(FieldContext $ctx): string;

    /**
     * Raw POST value -> domain value (sanitize/parse here).
     *
     * @param array<string, mixed> $config
     */
    public function normalizeInput(mixed $raw, array $config): mixed;

    /** Domain value -> storage-ready value (JSON string for structured types). */
    public function toStorage(mixed $value): mixed;

    /** Storage value -> domain value. */
    public function fromStorage(mixed $value): mixed;

    /**
     * Safe HTML for the public frontend.
     *
     * @param array<string, mixed> $config
     */
    public function renderFrontend(mixed $value, array $config): string;

    /**
     * Validation rules this field type contributes, given its config.
     *
     * @param array<string, mixed> $config
     * @return array<string, list<string>>
     */
    public function rules(array $config): array;

    /**
     * Admin asset handles (keys into the asset route) this editor needs.
     *
     * @return list<string>
     */
    public function assets(): array;
}
```

- [ ] **Step 4: Create the context value object**

Create `packages/folio/src/Field/FieldContext.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Field;

/**
 * Everything an editor needs to render one field instance.
 */
final readonly class FieldContext
{
    /**
     * @param list<string> $errors
     * @param array<string, mixed> $config
     */
    public function __construct(
        public string $name,
        public ?string $label = null,
        public ?string $help = null,
        public mixed $value = null,
        public array $errors = [],
        public array $config = [],
        public bool $required = false,
    ) {}
}
```

- [ ] **Step 5: Create the registry**

Create `packages/folio/src/Field/FieldTypeRegistry.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Field;

final class FieldTypeRegistry
{
    /** @var array<string, FieldType> */
    private array $types = [];

    /** @var array<string, string> */
    private array $aliases = [];

    public function register(FieldType $type): void
    {
        $this->types[$type->key()] = $type;
    }

    public function alias(string $from, string $to): void
    {
        $this->aliases[$from] = $to;
    }

    public function has(string $key): bool
    {
        $key = $this->aliases[$key] ?? $key;
        return isset($this->types[$key]);
    }

    /**
     * Resolve a field type by key. Unknown keys fall back to the `string` type.
     */
    public function get(string $key): FieldType
    {
        $key = $this->aliases[$key] ?? $key;

        return $this->types[$key]
            ?? $this->types['string']
            ?? throw new \RuntimeException('No field type registered for "' . $key . '" and no "string" fallback.');
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/phpunit packages/folio/tests/Field/FieldTypeRegistryTest.php`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
git add packages/folio/src/Field/FieldType.php packages/folio/src/Field/FieldContext.php packages/folio/src/Field/FieldTypeRegistry.php packages/folio/tests/Field/FieldTypeRegistryTest.php
git commit -m "feat(folio): field-type framework (interface, context, registry)"
```

---

### Task 3: Built-in scalar field types (string / text / number)

**Files:**
- Create: `packages/folio/src/Field/Types/AbstractScalarFieldType.php`
- Create: `packages/folio/src/Field/Types/StringFieldType.php`
- Create: `packages/folio/src/Field/Types/TextFieldType.php`
- Create: `packages/folio/src/Field/Types/NumberFieldType.php`
- Test: `packages/folio/tests/Field/ScalarFieldTypesTest.php` (new)

**Interfaces:**
- Consumes: `FieldType`, `FieldContext` (Task 2); `Preflow\Form\FieldRenderer` (`renderField(string $name, array $options): string`, where options accept `type`, `value`, `label`, `help`, `required`, `errors`).
- Produces: `StringFieldType` (key `string`, input `text`), `TextFieldType` (key `text`, input `textarea`), `NumberFieldType` (key `number`, input `number`). All scalar: `normalizeInput`/`toStorage`/`fromStorage` cast to string / passthrough; `renderFrontend` returns HTML-escaped text; `rules`/`assets` return `[]`.

- [ ] **Step 1: Write the failing test**

Create `packages/folio/tests/Field/ScalarFieldTypesTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\Types\NumberFieldType;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\Folio\Field\Types\TextFieldType;

final class ScalarFieldTypesTest extends TestCase
{
    public function test_keys(): void
    {
        $this->assertSame('string', (new StringFieldType())->key());
        $this->assertSame('text', (new TextFieldType())->key());
        $this->assertSame('number', (new NumberFieldType())->key());
    }

    public function test_string_renders_text_input_with_label_and_value(): void
    {
        $html = (new StringFieldType())->renderEditor(new FieldContext(
            name: 'title', label: 'Title', value: 'Hello', required: true,
        ));
        $this->assertStringContainsString('name="title"', $html);
        $this->assertStringContainsString('type="text"', $html);
        $this->assertStringContainsString('value="Hello"', $html);
        $this->assertStringContainsString('Title', $html);
        $this->assertStringContainsString('form-required', $html); // required marker
    }

    public function test_text_renders_textarea(): void
    {
        $html = (new TextFieldType())->renderEditor(new FieldContext(name: 'body'));
        $this->assertStringContainsString('<textarea', $html);
        $this->assertStringContainsString('name="body"', $html);
    }

    public function test_number_renders_number_input(): void
    {
        $html = (new NumberFieldType())->renderEditor(new FieldContext(name: 'qty'));
        $this->assertStringContainsString('type="number"', $html);
    }

    public function test_errors_render(): void
    {
        $html = (new StringFieldType())->renderEditor(new FieldContext(
            name: 'title', errors: ['Title is required'],
        ));
        $this->assertStringContainsString('has-error', $html);
        $this->assertStringContainsString('Title is required', $html);
    }

    public function test_storage_roundtrip_and_safe_frontend(): void
    {
        $t = new StringFieldType();
        $this->assertSame('x', $t->toStorage($t->normalizeInput('x', [])));
        $this->assertSame('x', $t->fromStorage('x'));
        $this->assertSame('&lt;b&gt;hi&lt;/b&gt;', $t->renderFrontend('<b>hi</b>', []));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit packages/folio/tests/Field/ScalarFieldTypesTest.php`
Expected: FAIL — `Preflow\Folio\Field\Types\*` do not exist.

- [ ] **Step 3: Create the abstract base**

Create `packages/folio/src/Field/Types/AbstractScalarFieldType.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;
use Preflow\Form\FieldRenderer;

/**
 * Shared behavior for plain scalar fields. Subclasses only declare their key
 * and the HTML input type they render.
 */
abstract class AbstractScalarFieldType implements FieldType
{
    public function __construct(
        private readonly FieldRenderer $renderer = new FieldRenderer(),
    ) {}

    abstract public function key(): string;

    /** HTML input type: 'text', 'textarea', 'number'. */
    abstract protected function inputType(): string;

    public function renderEditor(FieldContext $ctx): string
    {
        $options = [
            'type' => $this->inputType(),
            'value' => (string) ($ctx->value ?? ''),
            'help' => $ctx->help ?? '',
            'required' => $ctx->required,
            'errors' => $ctx->errors,
        ];
        // Only set label when provided; FieldRenderer humanizes the name otherwise.
        if ($ctx->label !== null) {
            $options['label'] = $ctx->label;
        }

        return $this->renderer->renderField($ctx->name, $options);
    }

    public function normalizeInput(mixed $raw, array $config): mixed
    {
        return is_string($raw) ? $raw : (string) ($raw ?? '');
    }

    public function toStorage(mixed $value): mixed
    {
        return $value;
    }

    public function fromStorage(mixed $value): mixed
    {
        return $value;
    }

    public function renderFrontend(mixed $value, array $config): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function rules(array $config): array
    {
        return [];
    }

    public function assets(): array
    {
        return [];
    }
}
```

- [ ] **Step 4: Create the three concrete types**

Create `packages/folio/src/Field/Types/StringFieldType.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

final class StringFieldType extends AbstractScalarFieldType
{
    public function key(): string
    {
        return 'string';
    }

    protected function inputType(): string
    {
        return 'text';
    }
}
```

Create `packages/folio/src/Field/Types/TextFieldType.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

final class TextFieldType extends AbstractScalarFieldType
{
    public function key(): string
    {
        return 'text';
    }

    protected function inputType(): string
    {
        return 'textarea';
    }
}
```

Create `packages/folio/src/Field/Types/NumberFieldType.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

final class NumberFieldType extends AbstractScalarFieldType
{
    public function key(): string
    {
        return 'number';
    }

    protected function inputType(): string
    {
        return 'number';
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit packages/folio/tests/Field/ScalarFieldTypesTest.php`
Expected: PASS (6 tests).

- [ ] **Step 6: Commit**

```bash
git add packages/folio/src/Field/Types/ packages/folio/tests/Field/ScalarFieldTypesTest.php
git commit -m "feat(folio): built-in string/text/number field types"
```

---

### Task 4: Wire the registry into the admin form (replace FieldMapper)

**Files:**
- Modify: `packages/folio/src/FolioServiceProvider.php`
- Modify: `packages/folio/src/Http/AdminController.php`
- Modify: `packages/folio/templates/admin/form.twig`
- Delete: `packages/folio/src/Content/FieldMapper.php`, `packages/folio/tests/Content/FieldMapperTest.php`
- Test: `packages/folio/tests/Integration/FolioAppTest.php` (modify)

**Interfaces:**
- Consumes: `FieldTypeRegistry`, `FieldContext`, `StringFieldType`/`TextFieldType`/`NumberFieldType` (Tasks 2-3); `TypeFieldDefinition->label/help/config` (Task 1).
- Produces: `AdminController` constructor gains a `FieldTypeRegistry $fieldTypes` parameter (appended after `ActionResolver`, before `string $prefix`). `form()` passes `fields` to the template as a list of `['name' => string, 'html' => string]` (pre-rendered editors); the `values`/`errors` template vars are removed (baked into the HTML). `form()` gains a trailing `int $status = 200` parameter.

- [ ] **Step 1: Write the failing test**

Add this method to `packages/folio/tests/Integration/FolioAppTest.php` (inside the class):

```php
    public function test_create_form_renders_fields_via_registry(): void
    {
        $body = (string) $this->get('/folio/page/new')->getBody();
        // string field -> text input; text field (body) -> textarea (registry mapping)
        $this->assertStringContainsString('name="title"', $body);
        $this->assertStringContainsString('type="text"', $body);
        $this->assertStringContainsString('<textarea name="body"', $body);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit packages/folio/tests/Integration/FolioAppTest.php --filter test_create_form_renders_fields_via_registry`
Expected: FAIL — `body` currently renders via `FieldMapper` as a textarea too, but `AdminController` does not yet receive a `FieldTypeRegistry`, so this test will pass only after wiring. (If it already passes because the existing FieldMapper happens to produce a textarea, proceed anyway — the wiring below is still required and the later steps' suite run is the real gate. The assertion `<textarea name="body"` matches both old and new output; the binding change is verified by the suite not erroring on the new constructor arg.)

- [ ] **Step 3: Bind and populate the registry in the provider**

In `packages/folio/src/FolioServiceProvider.php`, add imports near the other `use` lines:

```php
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\NumberFieldType;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\Folio\Field\Types\TextFieldType;
```

In `register()`, before the `AdminController` binding, add:

```php
        $container->bind(FieldTypeRegistry::class, function (): FieldTypeRegistry {
            $registry = new FieldTypeRegistry();
            $registry->register(new StringFieldType());
            $registry->register(new TextFieldType());
            $registry->register(new NumberFieldType());
            // Preserve the old FieldMapper numeric aliases.
            $registry->alias('int', 'number');
            $registry->alias('integer', 'number');
            $registry->alias('float', 'number');
            return $registry;
        });
```

Then update the `AdminController` binding to pass it (insert the registry arg after `ActionResolver`):

```php
        $container->bind(AdminController::class, fn (Container $c) => new AdminController(
            $c->get(TypeCatalog::class),
            $c->get(TypeRegistry::class),
            $c->get(DataManager::class),
            $c->get(TemplateEngineInterface::class),
            $c->get(ActionResolver::class),
            $c->get(FieldTypeRegistry::class),
            $prefix,
        ));
```

- [ ] **Step 4: Update `AdminController` — constructor + `form()`**

In `packages/folio/src/Http/AdminController.php`:

Replace the `use Preflow\Folio\Content\FieldMapper;` import with:

```php
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldTypeRegistry;
```

Add the constructor parameter (after `ActionResolver $overrides`, before `string $prefix`):

```php
        private readonly ActionResolver $overrides,
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly string $prefix,
```

Replace the entire `form()` method with:

```php
    /** @param array<string, mixed> $values @param array<string, list<string>> $errors */
    private function form(string $type, array $values, string $action, string $heading, array $errors, string $csrf = '', int $status = 200): ResponseInterface
    {
        $typeDef = $this->registry->get($type);
        $fields = [];
        foreach ($typeDef->fields as $name => $fieldDef) {
            if ($name === $typeDef->idField) {
                continue;
            }
            $fieldType = $this->fieldTypes->get($fieldDef->type);
            $ctx = new FieldContext(
                name: $name,
                label: $fieldDef->label,
                help: $fieldDef->help,
                value: $fieldType->fromStorage($values[$name] ?? null),
                errors: $errors[$name] ?? [],
                config: $fieldDef->config,
                required: in_array('required', $fieldDef->validate, true),
            );
            $fields[] = ['name' => $name, 'html' => $fieldType->renderEditor($ctx)];
        }

        $html = $this->engine->render('@folio/admin/form.twig', [
            'prefix' => $this->prefix,
            'type' => $type,
            'label' => $this->labelFor($type),
            'types' => $this->catalog->all(),
            'heading' => $heading,
            'action' => $action,
            'csrf' => $csrf,
            'fields' => $fields,
        ]);

        return new Response($status, ['Content-Type' => 'text/html; charset=UTF-8'], $html);
    }
```

- [ ] **Step 5: Update the form template**

Replace the field loop in `packages/folio/templates/admin/form.twig` (lines that iterate `fields` calling `form.field`) so the body reads:

```twig
{% extends "@folio/admin/_layout.twig" %}
{% block title %}Folio — {{ heading }}{% endblock %}
{% block page_title %}{{ heading }}{% endblock %}
{% block content %}
    <div class="folio-form">
        {% set form = form_begin({action: action, csrf_token: csrf}) %}
        {{ form.begin()|raw }}
        {% for field in fields %}
            {{ field.html|raw }}
        {% endfor %}
        <div class="folio-form-actions">
            {{ form.submit('Save', {attrs: {class: 'btn btn-primary'}})|raw }}
            <a class="btn btn-secondary" href="{{ prefix }}/{{ type }}">Cancel</a>
        </div>
        {{ form_end()|raw }}
    </div>
{% endblock %}
```

- [ ] **Step 6: Delete FieldMapper and its test**

```bash
git rm packages/folio/src/Content/FieldMapper.php packages/folio/tests/Content/FieldMapperTest.php
```

- [ ] **Step 7: Run the integration test + full Folio suite**

Run: `vendor/bin/phpunit packages/folio/tests`
Expected: PASS — including `test_create_form_renders_fields_via_registry` and the existing `test_create_form_renders_under_strict_twig`. No reference to `FieldMapper` remains (its test is gone).

- [ ] **Step 8: Commit**

```bash
git add packages/folio/src/FolioServiceProvider.php packages/folio/src/Http/AdminController.php packages/folio/templates/admin/form.twig packages/folio/tests/Integration/FolioAppTest.php
git commit -m "feat(folio): drive admin form via field-type registry; remove FieldMapper"
```

---

### Task 5: Validation round-trip + registry save path

**Files:**
- Modify: `packages/folio/src/Http/AdminController.php`
- Test: `packages/folio/tests/Integration/FolioAppTest.php` (modify)

**Interfaces:**
- Consumes: `FieldTypeRegistry` + `form()` with `int $status` (Task 4); `Preflow\Validation\ValidationException` (`errors(): array<string, list<string>>`).
- Produces: `store()`/`update()` route POST values through the registry (`normalizeInput` + `toStorage`) via a new private `collectFieldData(TypeDefinition $typeDef, array $submitted): array`, and catch `ValidationException` to re-render the form with the submitted values + per-field errors at HTTP 422.

- [ ] **Step 1: Write the failing test**

Add these methods to `packages/folio/tests/Integration/FolioAppTest.php`:

```php
    public function test_invalid_create_rerenders_form_with_errors_422(): void
    {
        $app = $this->app();
        // page model requires title, slug, status(in:draft,published). Omit title.
        $res = $app->handle((new \Nyholm\Psr7\Factory\Psr17Factory())
            ->createServerRequest('POST', '/folio/page')
            ->withParsedBody(['title' => '', 'slug' => 'x', 'body' => 'b', 'status' => 'draft']));

        $this->assertSame(422, $res->getStatusCode());
        $body = (string) $res->getBody();
        $this->assertStringContainsString('form-error', $body);   // error block shown
        $this->assertStringContainsString('name="title"', $body);  // form re-rendered
        $this->assertStringContainsString('value="x"', $body);     // slug input retained
    }

    public function test_valid_create_still_redirects(): void
    {
        $app = $this->app();
        $res = $app->handle((new \Nyholm\Psr7\Factory\Psr17Factory())
            ->createServerRequest('POST', '/folio/page')
            ->withParsedBody(['title' => 'Valid', 'slug' => 'valid', 'body' => 'b', 'status' => 'published']));
        $this->assertSame(302, $res->getStatusCode());
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit packages/folio/tests/Integration/FolioAppTest.php --filter "test_invalid_create_rerenders_form_with_errors_422|test_valid_create_still_redirects"`
Expected: FAIL — invalid POST currently throws `ValidationException` (uncaught → 500), not 422.

- [ ] **Step 3: Add imports and the `collectFieldData` helper**

In `packages/folio/src/Http/AdminController.php`, add imports:

```php
use Preflow\Data\TypeDefinition;
use Preflow\Validation\ValidationException;
```

Add this private method (next to `form()`):

```php
    /**
     * Build the storage payload by routing each submitted field through its
     * field type (normalize + serialize). idField is handled by the caller.
     *
     * @param array<string, mixed> $submitted
     * @return array<string, mixed>
     */
    private function collectFieldData(TypeDefinition $typeDef, array $submitted): array
    {
        $data = [];
        foreach ($typeDef->fields as $name => $fieldDef) {
            if ($name === $typeDef->idField) {
                continue;
            }
            $fieldType = $this->fieldTypes->get($fieldDef->type);
            $raw = $submitted[$name] ?? null;
            $data[$name] = $fieldType->toStorage($fieldType->normalizeInput($raw, $fieldDef->config));
        }
        return $data;
    }
```

- [ ] **Step 4: Rewrite `store()`**

Replace the `store()` method body with:

```php
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        if (!$this->catalog->has($type)) {
            return new Response(404, [], 'Unknown type');
        }

        $typeDef = $this->registry->get($type);
        $submitted = (array) $request->getParsedBody();
        $csrf = $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '';

        $data = $this->collectFieldData($typeDef, $submitted);
        $data[$typeDef->idField] = bin2hex(random_bytes(16));

        try {
            $this->dm->saveType(DynamicRecord::fromArray($typeDef, $data));
        } catch (ValidationException $e) {
            return $this->form(
                $type,
                $submitted,
                $this->prefix . '/' . $type,
                'New ' . $this->labelFor($type),
                $e->errors(),
                $csrf,
                422,
            );
        }

        return new Response(302, ['Location' => $this->prefix . '/' . $type]);
    }
```

- [ ] **Step 5: Rewrite `update()`**

Replace the `update()` method body with:

```php
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        $id = (string) $request->getAttribute('id', '');
        if (!$this->catalog->has($type)) {
            return new Response(404, [], 'Unknown type');
        }

        if ($this->dm->findType($type, $id) === null) {
            return new Response(404, [], 'Not found');
        }

        $typeDef = $this->registry->get($type);
        $submitted = (array) $request->getParsedBody();
        $csrf = $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '';

        $data = $this->collectFieldData($typeDef, $submitted);
        $data[$typeDef->idField] = $id;

        try {
            $this->dm->saveType(DynamicRecord::fromArray($typeDef, $data));
        } catch (ValidationException $e) {
            return $this->form(
                $type,
                $submitted,
                $this->prefix . '/' . $type . '/' . $id,
                'Edit ' . $this->labelFor($type),
                $e->errors(),
                $csrf,
                422,
            );
        }

        return new Response(302, ['Location' => $this->prefix . '/' . $type]);
    }
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit packages/folio/tests/Integration/FolioAppTest.php`
Expected: PASS — all methods, including the new 422 and redirect tests, plus the existing create/edit/frontend tests.

- [ ] **Step 7: Commit**

```bash
git add packages/folio/src/Http/AdminController.php packages/folio/tests/Integration/FolioAppTest.php
git commit -m "fix(folio): catch ValidationException, re-render form at 422; registry save path"
```

---

### Task 6: Demo showcase + full-suite verification

**Files:**
- Modify: `examples/folio-demo/config/models/page.json`
- Test: whole repo suite

**Interfaces:**
- Consumes: `label`/`help` parsing (Task 1) and registry rendering (Task 4).

- [ ] **Step 1: Add label + help to a demo field**

In `examples/folio-demo/config/models/page.json`, give the `body` field a label and help (showcasing the new field-def keys). Replace the `body` line so the `fields` object reads:

```json
    "fields": {
        "title": { "type": "string", "validate": ["required"] },
        "slug": { "type": "string", "validate": ["required"] },
        "body": { "type": "text", "label": "Body content", "help": "Supports plain text for now; rich text arrives in Phase 2." },
        "status": { "type": "string" }
    }
```

- [ ] **Step 2: Verify the demo smoke test still passes**

Run: `vendor/bin/phpunit examples/folio-demo/tests`
Expected: PASS (2 tests) — the demo app still boots and serves `/folio`.

- [ ] **Step 3: Run the full repo suite**

Run: `vendor/bin/phpunit`
Expected: PASS — all suites green (Data, Folio, Examples, and the rest). The only expected non-failures are the pre-existing 72 PHPUnit deprecations + 1 skip. If any test fails, investigate before committing.

- [ ] **Step 4: Commit**

```bash
git add examples/folio-demo/config/models/page.json
git commit -m "docs(folio): demo a field label/help on the page model"
```

---

## Self-Review

**Spec coverage (Phase 1 portion of the field/editor spec):**
- Field-type framework (interface + registry, userland-extensible, replaces `FieldMapper`) → Tasks 2, 3, 4.
- Extend `TypeFieldDefinition` with `label`/`help`/`config` → Task 1.
- Pass full field defs to the template (label/help/config reach the editor) → Task 4 (`FieldContext`).
- Validation round-trip (catch `ValidationException`, re-render at 422 with values + errors) → Task 5.
- Registry-driven save path (`normalizeInput`/`toStorage`) → Task 5.
- Built-in `string`/`text`/`number` as field types; preserve old numeric aliases → Tasks 3, 4.
- Deferred to Phase 2 (explicitly, per Global Constraints): asset-route generalization, `admin.js`, frontend registry rendering, and any `assets()` consumption — no Phase-1 field type needs them. Frontend `page.twig` is unchanged this phase.

**Placeholder scan:** No `TBD`/`TODO`. Task 4 Step 2's note is an explicit explanation of a benign assertion overlap (old/new output both contain `<textarea name="body"`), not a deferral — the real gate is the Step 7 suite run with the new constructor arg.

**Type consistency:** `FieldTypeRegistry` methods (`register`/`alias`/`has`/`get`) identical across Tasks 2, 4, 5. `FieldContext` constructor (named args `name`,`label`,`help`,`value`,`errors`,`config`,`required`) identical in Tasks 2, 3, 4. `FieldType` method set identical across the interface (Task 2), the base class (Task 3), and the registry test's anonymous class (Task 2). `AdminController` constructor arg order (`…, ActionResolver, FieldTypeRegistry, string $prefix`) matches the provider binding (Task 4). `form()` signature with trailing `int $status = 200` defined in Task 4 and used by Task 5. `collectFieldData(TypeDefinition, array): array` defined and used in Task 5.
