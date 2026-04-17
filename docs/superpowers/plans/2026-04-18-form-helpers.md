# Form Package & Helpers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `preflow/form` package (FormBuilder, field rendering, model binding, hypermedia-aware inline validation), distribute color helpers into `preflow/core` and responsive image helper into `preflow/view`, and add validation scenarios to `preflow/validation`.

**Architecture:** FormBuilder is a lightweight stateful object created per form via `form_begin()` template function. It renders fields using overridable Twig templates, auto-detects component context for hypermedia driver enhancement, and reads `#[Validate]` attributes with scenario support for model binding. Color helpers are pure static functions in `preflow/core`. Responsive image uses a pluggable `ImageUrlTransformer` interface in `preflow/view`.

**Tech Stack:** PHP 8.4+, PHPUnit 11, Twig, existing Preflow packages (validation, view, components, htmx)

---

## File Map

### New files

| File | Responsibility |
|------|---------------|
| `packages/form/composer.json` | Package manifest |
| `packages/form/src/FormBuilder.php` | Core builder: state, field calls, model binding, hypermedia detection |
| `packages/form/src/FieldRenderer.php` | Renders individual field blocks using templates |
| `packages/form/src/GroupRenderer.php` | Renders field groups |
| `packages/form/src/ModelIntrospector.php` | Reads `#[Validate]` attributes, infers types, filters by scenario |
| `packages/form/src/FormExtensionProvider.php` | Template function registration (`form_begin`, `form_end`) |
| `packages/form/src/templates/field.twig` | Default field template |
| `packages/form/src/templates/group.twig` | Default group template |
| `packages/form/tests/ModelIntrospectorTest.php` | Tests for model introspection |
| `packages/form/tests/FieldRendererTest.php` | Tests for field rendering |
| `packages/form/tests/GroupRendererTest.php` | Tests for group rendering |
| `packages/form/tests/FormBuilderTest.php` | Tests for the form builder |
| `packages/core/src/Helpers/Color.php` | Color utility functions |
| `packages/core/src/Helpers/HelpersExtensionProvider.php` | Template functions for color + image helpers |
| `packages/core/tests/Helpers/ColorTest.php` | Color helper tests |
| `packages/view/src/FormHypermediaDriver.php` | Interface for form-specific hypermedia attributes |
| `packages/view/src/ImageUrlTransformer.php` | Interface for image URL transformation |
| `packages/view/src/PathBasedTransformer.php` | Default transformer using query params |
| `packages/view/src/ResponsiveImage.php` | Generates `<img>` tags with srcset/sizes |
| `packages/view/tests/ResponsiveImageTest.php` | Tests for responsive image helper |

### Modified files

| File | Change |
|------|--------|
| `packages/validation/src/Attributes/Validate.php` | Add `on` property for scenarios |
| `packages/data/src/ModelMetadata.php` | Store scenario metadata, add `validationRulesForScenario()` |
| `packages/data/src/DataManager.php` | Accept `scenario` parameter in save/insert/update |
| `packages/htmx/src/HtmxDriver.php` | Implement `FormHypermediaDriver` interface |
| `packages/htmx/src/HypermediaDriver.php` | Extend `FormHypermediaDriver` |
| `packages/core/src/Application.php` | Add `bootHelpers()` and `bootForm()` methods |
| `composer.json` | Add `preflow/form` path repository and dev dependency |
| `phpunit.xml` | Add Form test suite |
| `.github/workflows/split.yml` | Add form package to split matrix |

---

### Task 1: Validation Scenarios — `#[Validate]` `on` Parameter

**Files:**
- Modify: `packages/validation/src/Attributes/Validate.php`
- Modify: `packages/data/src/ModelMetadata.php`
- Modify: `packages/data/src/DataManager.php`
- Test: `packages/validation/tests/ValidateAttributeTest.php`
- Test: `packages/data/tests/ModelMetadataTest.php`

- [ ] **Step 1: Write test for Validate attribute `on` parameter**

Create `packages/validation/tests/ValidateAttributeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Validation\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Validation\Attributes\Validate;

final class ValidateAttributeTest extends TestCase
{
    public function test_rules_without_on(): void
    {
        $attr = new Validate('required', 'email');
        $this->assertSame(['required', 'email'], $attr->rules);
        $this->assertNull($attr->on);
    }

    public function test_rules_with_on_scenario(): void
    {
        $attr = new Validate('required', 'min:8', 'on:create');
        $this->assertSame(['required', 'min:8'], $attr->rules);
        $this->assertSame('create', $attr->on);
    }

    public function test_on_scenario_only(): void
    {
        $attr = new Validate('nullable', 'on:update');
        $this->assertSame(['nullable'], $attr->rules);
        $this->assertSame('update', $attr->on);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/validation/tests/ValidateAttributeTest.php -v`
Expected: FAIL — `on` property does not exist on Validate

- [ ] **Step 3: Implement `on` parameter in Validate attribute**

Replace `packages/validation/src/Attributes/Validate.php` with:

```php
<?php

declare(strict_types=1);

namespace Preflow\Validation\Attributes;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
final class Validate
{
    /** @var list<string> */
    public readonly array $rules;

    public readonly ?string $on;

    public function __construct(string ...$rules)
    {
        $on = null;
        $filtered = [];

        foreach ($rules as $rule) {
            if (str_starts_with($rule, 'on:')) {
                $on = substr($rule, 3);
            } else {
                $filtered[] = $rule;
            }
        }

        $this->rules = $filtered;
        $this->on = $on;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/validation/tests/ValidateAttributeTest.php -v`
Expected: PASS (3 tests)

- [ ] **Step 5: Write test for ModelMetadata scenario filtering**

Add to `packages/data/tests/ModelMetadataTest.php` (create if needed). First create a test model fixture:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\ModelMetadata;
use Preflow\Data\Model;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Id;
use Preflow\Validation\Attributes\Validate;

#[Entity('users')]
class ScenarioTestModel extends Model
{
    #[Id]
    public int $id = 0;

    #[Validate('required', 'email')]
    public string $email = '';

    #[Validate('required', 'min:8', 'on:create')]
    #[Validate('nullable', 'min:8', 'on:update')]
    public string $password = '';

    #[Validate('required')]
    public string $name = '';
}

final class ModelMetadataScenarioTest extends TestCase
{
    protected function setUp(): void
    {
        ModelMetadata::clearCache();
    }

    public function test_validation_rules_returns_all_rules_without_scenario(): void
    {
        $meta = ModelMetadata::for(ScenarioTestModel::class);
        // validationRules should contain all rules (backward compat)
        $this->assertArrayHasKey('email', $meta->validationRules);
        $this->assertArrayHasKey('password', $meta->validationRules);
        $this->assertArrayHasKey('name', $meta->validationRules);
    }

    public function test_validation_rules_for_create_scenario(): void
    {
        $meta = ModelMetadata::for(ScenarioTestModel::class);
        $rules = $meta->validationRulesForScenario('create');

        $this->assertSame(['required', 'email'], $rules['email']);
        $this->assertSame(['required', 'min:8'], $rules['password']);
        $this->assertSame(['required'], $rules['name']);
    }

    public function test_validation_rules_for_update_scenario(): void
    {
        $meta = ModelMetadata::for(ScenarioTestModel::class);
        $rules = $meta->validationRulesForScenario('update');

        $this->assertSame(['required', 'email'], $rules['email']);
        $this->assertSame(['nullable', 'min:8'], $rules['password']);
        $this->assertSame(['required'], $rules['name']);
    }

    public function test_validation_rules_for_null_scenario_returns_all(): void
    {
        $meta = ModelMetadata::for(ScenarioTestModel::class);
        $rules = $meta->validationRulesForScenario(null);

        // Null scenario: only rules without 'on' restriction
        $this->assertSame(['required', 'email'], $rules['email']);
        $this->assertSame(['required'], $rules['name']);
        // password has no rules without 'on', so it should be absent
        $this->assertArrayNotHasKey('password', $rules);
    }
}
```

- [ ] **Step 6: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/data/tests/ModelMetadataScenarioTest.php -v`
Expected: FAIL — `validationRulesForScenario` method does not exist

- [ ] **Step 7: Implement scenario support in ModelMetadata**

In `packages/data/src/ModelMetadata.php`:

Add a new private property `rawValidationRules` to the constructor and add the `validationRulesForScenario()` method.

Change the constructor to accept `rawValidationRules`:

```php
private function __construct(
    public readonly string $modelClass,
    public readonly string $table,
    public readonly string $storage,
    public readonly string $idField,
    public readonly array $fields,
    public readonly array $searchableFields,
    public readonly bool $hasTimestamps,
    public readonly array $transformers = [],
    public readonly array $validationRules = [],
    /** @var array<string, list<array{rule: string, on: ?string}>> */
    private readonly array $rawValidationRules = [],
) {}
```

Replace the validation rules reading block (around lines 99-108) in the `for()` method:

```php
$validationRules = [];
$rawValidationRules = [];
foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
    $validateAttrs = $prop->getAttributes(Validate::class);
    if ($validateAttrs !== []) {
        $rules = [];
        $rawEntries = [];
        foreach ($validateAttrs as $attr) {
            $instance = $attr->newInstance();
            foreach ($instance->rules as $rule) {
                $rules[] = $rule;
                $rawEntries[] = ['rule' => $rule, 'on' => $instance->on];
            }
        }
        $validationRules[$prop->getName()] = $rules;
        $rawValidationRules[$prop->getName()] = $rawEntries;
    }
}
```

Pass `rawValidationRules` to the constructor:

```php
$meta = new self(
    // ... existing args ...
    validationRules: $validationRules,
    rawValidationRules: $rawValidationRules,
);
```

Add the filtering method:

```php
/**
 * Get validation rules filtered by scenario.
 * Rules without 'on' apply to all scenarios.
 * Rules with 'on' only apply when that scenario is active.
 *
 * @return array<string, list<string>>
 */
public function validationRulesForScenario(?string $scenario): array
{
    $filtered = [];
    foreach ($this->rawValidationRules as $field => $entries) {
        $fieldRules = [];
        foreach ($entries as $entry) {
            if ($entry['on'] === null || $entry['on'] === $scenario) {
                $fieldRules[] = $entry['rule'];
            }
        }
        if ($fieldRules !== []) {
            $filtered[$field] = $fieldRules;
        }
    }
    return $filtered;
}
```

- [ ] **Step 8: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/data/tests/ModelMetadataScenarioTest.php -v`
Expected: PASS (4 tests)

- [ ] **Step 9: Update DataManager to accept scenario**

In `packages/data/src/DataManager.php`, update `save()`, `insert()`, and `update()` signatures to accept an optional `?string $scenario = null` parameter, and update `validateModel()` to use scenario filtering:

Change `save()` (line 85):
```php
public function save(Model $model, bool $validate = true, array $rules = [], ?string $scenario = null): void
{
    if ($validate) {
        $this->validateModel($model, $rules, $scenario);
    }
    // ... rest unchanged ...
}
```

Change `insert()` (line 111):
```php
public function insert(Model $model, bool $validate = true, array $rules = [], ?string $scenario = null): void
{
    if ($validate) {
        $this->validateModel($model, $rules, $scenario);
    }
    // ... rest unchanged ...
}
```

Change `update()` (line 134):
```php
public function update(Model $model, bool $validate = true, array $rules = [], ?string $scenario = null): void
{
    if ($validate) {
        $this->validateModel($model, $rules, $scenario);
    }
    // ... rest unchanged ...
}
```

Change `validateModel()` (line 235):
```php
private function validateModel(Model $model, array $extraRules = [], ?string $scenario = null): void
{
    if ($this->validatorFactory === null) {
        return;
    }

    $meta = ModelMetadata::for($model::class);
    $rules = $scenario !== null
        ? $meta->validationRulesForScenario($scenario)
        : $meta->validationRules;

    if (method_exists($model, 'rules')) {
        $rules = array_merge($rules, $model->rules());
    }

    if ($extraRules !== []) {
        $rules = array_merge($rules, $extraRules);
    }

    if ($rules === []) {
        return;
    }

    $validator = $this->validatorFactory->make($rules, $model->toArray(), subject: $model);
    $result = $validator->validate();

    if ($result->fails()) {
        throw new ValidationException($result);
    }
}
```

- [ ] **Step 10: Run full validation and data test suites**

Run: `./vendor/bin/phpunit --testsuite=Validation,Data -v`
Expected: All existing tests still pass + new scenario tests pass

- [ ] **Step 11: Commit**

```bash
git add packages/validation/src/Attributes/Validate.php \
       packages/validation/tests/ValidateAttributeTest.php \
       packages/data/src/ModelMetadata.php \
       packages/data/src/DataManager.php \
       packages/data/tests/ModelMetadataScenarioTest.php
git commit -m "feat(validation): add scenario support to #[Validate] attribute

Validate now accepts 'on:scenario' directive to scope rules to create/update
scenarios. ModelMetadata gains validationRulesForScenario() method.
DataManager save/insert/update accept optional scenario parameter."
```

---

### Task 2: Color Helpers in `preflow/core`

**Files:**
- Create: `packages/core/src/Helpers/Color.php`
- Create: `packages/core/tests/Helpers/ColorTest.php`

- [ ] **Step 1: Write color helper tests**

Create `packages/core/tests/Helpers/ColorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Helpers;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Helpers\Color;

final class ColorTest extends TestCase
{
    public function test_hex_to_rgb(): void
    {
        $this->assertSame([255, 158, 27], Color::hexToRgb('#FF9E1B'));
    }

    public function test_hex_to_rgb_shorthand(): void
    {
        $this->assertSame([255, 0, 0], Color::hexToRgb('#F00'));
    }

    public function test_hex_to_rgb_without_hash(): void
    {
        $this->assertSame([255, 158, 27], Color::hexToRgb('FF9E1B'));
    }

    public function test_rgb_to_hex(): void
    {
        $this->assertSame('#ff9e1b', Color::rgbToHex(255, 158, 27));
    }

    public function test_lighten(): void
    {
        $result = Color::lighten('#000000', 0.5);
        // Black lightened 50% — each channel goes halfway to 255
        $this->assertSame('#808080', $result);
    }

    public function test_darken(): void
    {
        $result = Color::darken('#ffffff', 0.5);
        // White darkened 50% — each channel halved
        $this->assertSame('#808080', $result);
    }

    public function test_luminance_black(): void
    {
        $this->assertEqualsWithDelta(0.0, Color::luminance('#000000'), 0.001);
    }

    public function test_luminance_white(): void
    {
        $this->assertEqualsWithDelta(1.0, Color::luminance('#ffffff'), 0.001);
    }

    public function test_contrast_ratio_black_white(): void
    {
        $ratio = Color::contrastRatio('#000000', '#ffffff');
        $this->assertEqualsWithDelta(21.0, $ratio, 0.1);
    }

    public function test_adjust_for_contrast_already_sufficient(): void
    {
        // Black on white is 21:1, already above 4.5
        $result = Color::adjustForContrast('#000000', '#ffffff', 4.5);
        $this->assertSame('#000000', $result);
    }

    public function test_adjust_for_contrast_needs_adjustment(): void
    {
        // Light gray on white has low contrast — should be darkened
        $result = Color::adjustForContrast('#cccccc', '#ffffff', 4.5);
        $ratio = Color::contrastRatio($result, '#ffffff');
        $this->assertGreaterThanOrEqual(4.5, $ratio);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/core/tests/Helpers/ColorTest.php -v`
Expected: FAIL — class `Preflow\Core\Helpers\Color` not found

- [ ] **Step 3: Implement Color class**

Create `packages/core/src/Helpers/Color.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Helpers;

final class Color
{
    /**
     * @return array{int, int, int} [r, g, b]
     */
    public static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    public static function rgbToHex(int $r, int $g, int $b): string
    {
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    public static function lighten(string $hex, float $percent): string
    {
        [$r, $g, $b] = self::hexToRgb($hex);

        $r = (int) min(255, $r + ($percent * (255 - $r)));
        $g = (int) min(255, $g + ($percent * (255 - $g)));
        $b = (int) min(255, $b + ($percent * (255 - $b)));

        return self::rgbToHex($r, $g, $b);
    }

    public static function darken(string $hex, float $percent): string
    {
        [$r, $g, $b] = self::hexToRgb($hex);

        $r = (int) max(0, $r - ($percent * $r));
        $g = (int) max(0, $g - ($percent * $g));
        $b = (int) max(0, $b - ($percent * $b));

        return self::rgbToHex($r, $g, $b);
    }

    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = self::hexToRgb($hex);

        $components = [];
        foreach ([$r, $g, $b] as $c) {
            $c = $c / 255;
            $components[] = $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
        }

        return 0.2126 * $components[0] + 0.7152 * $components[1] + 0.0722 * $components[2];
    }

    public static function contrastRatio(string $color1, string $color2): float
    {
        $l1 = self::luminance($color1);
        $l2 = self::luminance($color2);

        $lighter = max($l1, $l2);
        $darker = min($l1, $l2);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    public static function adjustForContrast(
        string $textColor,
        string $backgroundColor = '#ffffff',
        float $minContrast = 4.5,
    ): string {
        if (self::contrastRatio($textColor, $backgroundColor) >= $minContrast) {
            return $textColor;
        }

        $bgLuminance = self::luminance($backgroundColor);
        $shouldDarken = $bgLuminance > 0.5;
        $step = 0.05;

        for ($i = 0; $i < 20; $i++) {
            $textColor = $shouldDarken
                ? self::darken($textColor, $step)
                : self::lighten($textColor, $step);

            if (self::contrastRatio($textColor, $backgroundColor) >= $minContrast) {
                break;
            }
        }

        return $textColor;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/core/tests/Helpers/ColorTest.php -v`
Expected: PASS (11 tests)

- [ ] **Step 5: Commit**

```bash
git add packages/core/src/Helpers/Color.php packages/core/tests/Helpers/ColorTest.php
git commit -m "feat(core): add Color helper class

Static utility methods for hex/RGB conversion, lighten/darken,
WCAG 2.1 luminance, contrast ratio, and auto-adjustment."
```

---

### Task 3: View Layer Interfaces — FormHypermediaDriver & ImageUrlTransformer

**Files:**
- Create: `packages/view/src/FormHypermediaDriver.php`
- Create: `packages/view/src/ImageUrlTransformer.php`
- Create: `packages/view/src/PathBasedTransformer.php`
- Create: `packages/view/src/ResponsiveImage.php`
- Test: `packages/view/tests/ResponsiveImageTest.php`
- Test: `packages/view/tests/PathBasedTransformerTest.php`

- [ ] **Step 1: Create FormHypermediaDriver interface**

Create `packages/view/src/FormHypermediaDriver.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\View;

/**
 * Interface for generating hypermedia-driver-specific HTML attributes
 * for form elements. Implemented by driver packages (e.g., preflow/htmx).
 *
 * Named FormHypermediaDriver to avoid collision with
 * Preflow\Htmx\HypermediaDriver which handles general component actions.
 */
interface FormHypermediaDriver
{
    /**
     * Generate HTML attributes for a <form> tag.
     *
     * @return array<string, string>
     */
    public function formAttributes(string $action, string $method, array $options = []): array;

    /**
     * Generate HTML attributes for inline field validation (e.g., validate-on-blur).
     *
     * @return array<string, string>
     */
    public function inlineValidationAttributes(string $endpoint, string $field, string $trigger = 'blur'): array;

    /**
     * Generate HTML attributes for form submission behavior.
     *
     * @return array<string, string>
     */
    public function submitAttributes(string $target, array $options = []): array;
}
```

- [ ] **Step 2: Create ImageUrlTransformer interface and PathBasedTransformer**

Create `packages/view/src/ImageUrlTransformer.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\View;

interface ImageUrlTransformer
{
    public function transform(string $path, int $width, string $format, int $quality): string;
}
```

Create `packages/view/src/PathBasedTransformer.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\View;

/**
 * Default transformer: appends width, format, and quality as query parameters.
 * Works with Glide, BunnyCDN, Cloudflare Image Resizing, and similar URL-based
 * transform services.
 */
final class PathBasedTransformer implements ImageUrlTransformer
{
    public function transform(string $path, int $width, string $format, int $quality): string
    {
        $separator = str_contains($path, '?') ? '&' : '?';
        return $path . $separator . http_build_query(['w' => $width, 'fm' => $format, 'q' => $quality]);
    }
}
```

- [ ] **Step 3: Write responsive image tests**

Create `packages/view/tests/PathBasedTransformerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\View\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\View\PathBasedTransformer;

final class PathBasedTransformerTest extends TestCase
{
    public function test_appends_query_params(): void
    {
        $t = new PathBasedTransformer();
        $url = $t->transform('/uploads/hero.jpg', 480, 'webp', 75);
        $this->assertSame('/uploads/hero.jpg?w=480&fm=webp&q=75', $url);
    }

    public function test_appends_with_ampersand_when_query_exists(): void
    {
        $t = new PathBasedTransformer();
        $url = $t->transform('/uploads/hero.jpg?v=2', 768, 'avif', 80);
        $this->assertSame('/uploads/hero.jpg?v=2&w=768&fm=avif&q=80', $url);
    }
}
```

Create `packages/view/tests/ResponsiveImageTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\View\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\View\PathBasedTransformer;
use Preflow\View\ResponsiveImage;

final class ResponsiveImageTest extends TestCase
{
    private ResponsiveImage $helper;

    protected function setUp(): void
    {
        $this->helper = new ResponsiveImage(new PathBasedTransformer());
    }

    public function test_renders_img_with_srcset(): void
    {
        $html = $this->helper->render('/uploads/hero.jpg', [
            'widths' => [480, 1024],
            'alt' => 'Welcome',
            'sizes' => '100vw',
        ]);

        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('srcset=', $html);
        $this->assertStringContainsString('480w', $html);
        $this->assertStringContainsString('1024w', $html);
        $this->assertStringContainsString('alt="Welcome"', $html);
        $this->assertStringContainsString('sizes="100vw"', $html);
    }

    public function test_src_uses_largest_width(): void
    {
        $html = $this->helper->render('/img.jpg', [
            'widths' => [300, 600, 900],
        ]);

        $this->assertStringContainsString('src="/img.jpg?w=900', $html);
    }

    public function test_default_format_is_webp(): void
    {
        $html = $this->helper->render('/img.jpg', [
            'widths' => [480],
        ]);

        $this->assertStringContainsString('fm=webp', $html);
    }

    public function test_custom_format(): void
    {
        $html = $this->helper->render('/img.jpg', [
            'widths' => [480],
            'format' => 'avif',
        ]);

        $this->assertStringContainsString('fm=avif', $html);
    }

    public function test_loading_defaults_to_lazy(): void
    {
        $html = $this->helper->render('/img.jpg', ['widths' => [480]]);
        $this->assertStringContainsString('loading="lazy"', $html);
    }

    public function test_custom_attrs_passed_through(): void
    {
        $html = $this->helper->render('/img.jpg', [
            'widths' => [480],
            'attrs' => ['data-zoom' => 'true'],
        ]);

        $this->assertStringContainsString('data-zoom="true"', $html);
    }

    public function test_srcset_only(): void
    {
        $srcset = $this->helper->srcset('/img.jpg', [480, 1024], 'webp', 75);

        $this->assertStringContainsString('/img.jpg?w=480&fm=webp&q=75 480w', $srcset);
        $this->assertStringContainsString('/img.jpg?w=1024&fm=webp&q=75 1024w', $srcset);
    }

    public function test_preset_overrides_defaults(): void
    {
        $helper = new ResponsiveImage(new PathBasedTransformer(), [
            'hero' => ['widths' => [800, 1600], 'sizes' => '100vw', 'loading' => 'eager'],
        ]);

        $html = $helper->render('/img.jpg', ['preset' => 'hero']);
        $this->assertStringContainsString('800w', $html);
        $this->assertStringContainsString('1600w', $html);
        $this->assertStringContainsString('loading="eager"', $html);
    }
}
```

- [ ] **Step 4: Run tests to verify they fail**

Run: `./vendor/bin/phpunit packages/view/tests/ResponsiveImageTest.php packages/view/tests/PathBasedTransformerTest.php -v`
Expected: FAIL — `ResponsiveImage` class not found

- [ ] **Step 5: Implement ResponsiveImage**

Create `packages/view/src/ResponsiveImage.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\View;

final class ResponsiveImage
{
    private const DEFAULTS = [
        'widths' => [480, 768, 1024],
        'sizes' => '100vw',
        'format' => 'webp',
        'quality' => 75,
        'alt' => '',
        'class' => '',
        'loading' => 'lazy',
        'width' => null,
        'height' => null,
        'attrs' => [],
    ];

    /**
     * @param array<string, array<string, mixed>> $presets
     */
    public function __construct(
        private readonly ImageUrlTransformer $transformer,
        private readonly array $presets = [],
    ) {}

    /**
     * Render a complete <img> tag with srcset and sizes.
     *
     * @param array<string, mixed> $options
     */
    public function render(string $path, array $options = []): string
    {
        $options = $this->resolveOptions($options);
        $widths = $options['widths'];
        $format = $options['format'];
        $quality = $options['quality'];

        $srcsetStr = $this->srcset($path, $widths, $format, $quality);
        $maxWidth = max($widths);
        $src = $this->transformer->transform($path, $maxWidth, $format, $quality);

        $attrs = [
            'src' => $src,
            'srcset' => $srcsetStr,
            'sizes' => $options['sizes'],
            'alt' => $options['alt'],
            'loading' => $options['loading'],
        ];

        if ($options['class'] !== '') {
            $attrs['class'] = $options['class'];
        }
        if ($options['width'] !== null) {
            $attrs['width'] = (string) $options['width'];
        }
        if ($options['height'] !== null) {
            $attrs['height'] = (string) $options['height'];
        }

        // Merge custom attrs (user values override)
        $attrs = array_merge($attrs, $options['attrs']);

        return $this->buildTag($attrs);
    }

    /**
     * Generate just the srcset string for use in <source> elements.
     *
     * @param int[] $widths
     */
    public function srcset(string $path, array $widths, string $format = 'webp', int $quality = 75): string
    {
        $parts = [];
        foreach ($widths as $w) {
            $url = $this->transformer->transform($path, $w, $format, $quality);
            $parts[] = $url . ' ' . $w . 'w';
        }
        return implode(', ', $parts);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function resolveOptions(array $options): array
    {
        // Apply preset first if specified
        $preset = $options['preset'] ?? null;
        $presetDefaults = ($preset !== null && isset($this->presets[$preset]))
            ? $this->presets[$preset]
            : [];

        return array_merge(self::DEFAULTS, $presetDefaults, $options);
    }

    /**
     * @param array<string, string> $attrs
     */
    private function buildTag(array $attrs): string
    {
        $html = '<img';
        foreach ($attrs as $name => $value) {
            if ($value !== null && $value !== '') {
                $escaped = htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $html .= " {$name}=\"{$escaped}\"";
            }
        }
        return $html . '>';
    }
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/view/tests/ResponsiveImageTest.php packages/view/tests/PathBasedTransformerTest.php -v`
Expected: PASS (10 tests)

- [ ] **Step 7: Commit**

```bash
git add packages/view/src/FormHypermediaDriver.php \
       packages/view/src/ImageUrlTransformer.php \
       packages/view/src/PathBasedTransformer.php \
       packages/view/src/ResponsiveImage.php \
       packages/view/tests/ResponsiveImageTest.php \
       packages/view/tests/PathBasedTransformerTest.php
git commit -m "feat(view): add FormHypermediaDriver, ImageUrlTransformer, ResponsiveImage

FormHypermediaDriver interface for form-specific hypermedia attributes.
ImageUrlTransformer + PathBasedTransformer for pluggable image URL generation.
ResponsiveImage renders <img> with srcset/sizes and supports presets."
```

---

### Task 4: HTMX FormHypermediaDriver Implementation

**Files:**
- Modify: `packages/htmx/src/HtmxDriver.php`
- Test: `packages/htmx/tests/HtmxFormDriverTest.php`

- [ ] **Step 1: Write test for HTMX form driver**

Create `packages/htmx/tests/HtmxFormDriverTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Htmx\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Htmx\HtmxDriver;
use Preflow\Htmx\ResponseHeaders;
use Preflow\View\FormHypermediaDriver;

final class HtmxFormDriverTest extends TestCase
{
    private HtmxDriver $driver;

    protected function setUp(): void
    {
        $this->driver = new HtmxDriver(new ResponseHeaders());
    }

    public function test_implements_form_hypermedia_driver(): void
    {
        $this->assertInstanceOf(FormHypermediaDriver::class, $this->driver);
    }

    public function test_form_attributes(): void
    {
        $attrs = $this->driver->formAttributes('/submit', 'post');
        $this->assertSame('/submit', $attrs['hx-post']);
        $this->assertArrayHasKey('hx-target', $attrs);
    }

    public function test_form_attributes_with_target(): void
    {
        $attrs = $this->driver->formAttributes('/submit', 'post', ['target' => '#result']);
        $this->assertSame('#result', $attrs['hx-target']);
    }

    public function test_inline_validation_attributes(): void
    {
        $attrs = $this->driver->inlineValidationAttributes('/validate', 'email');
        $this->assertSame('/validate', $attrs['hx-post']);
        $this->assertStringContainsString('blur', $attrs['hx-trigger']);
    }

    public function test_inline_validation_custom_trigger(): void
    {
        $attrs = $this->driver->inlineValidationAttributes('/validate', 'search', 'change');
        $this->assertStringContainsString('change', $attrs['hx-trigger']);
    }

    public function test_submit_attributes(): void
    {
        $attrs = $this->driver->submitAttributes('#form-wrapper');
        $this->assertSame('#form-wrapper', $attrs['hx-target']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/htmx/tests/HtmxFormDriverTest.php -v`
Expected: FAIL — HtmxDriver does not implement FormHypermediaDriver

- [ ] **Step 3: Implement FormHypermediaDriver on HtmxDriver**

In `packages/htmx/src/HtmxDriver.php`, add the interface and methods:

Add import at top:
```php
use Preflow\View\FormHypermediaDriver;
```

Change class declaration:
```php
final class HtmxDriver implements HypermediaDriver, FormHypermediaDriver
```

Add methods after existing methods:

```php
public function formAttributes(string $action, string $method, array $options = []): array
{
    $attrs = [
        "hx-{$method}" => $action,
        'hx-target' => $options['target'] ?? 'this',
        'hx-swap' => $options['swap'] ?? 'outerHTML',
    ];

    return $attrs;
}

public function inlineValidationAttributes(string $endpoint, string $field, string $trigger = 'blur'): array
{
    return [
        'hx-post' => $endpoint,
        'hx-trigger' => $trigger,
        'hx-target' => 'closest .form-group',
        'hx-swap' => 'outerHTML',
        'hx-include' => "[name=\"{$field}\"]",
    ];
}

public function submitAttributes(string $target, array $options = []): array
{
    return [
        'hx-target' => $target,
        'hx-swap' => $options['swap'] ?? 'outerHTML',
    ];
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/htmx/tests/HtmxFormDriverTest.php -v`
Expected: PASS (6 tests)

- [ ] **Step 5: Run full HTMX test suite for regressions**

Run: `./vendor/bin/phpunit --testsuite=Htmx -v`
Expected: All tests pass

- [ ] **Step 6: Commit**

```bash
git add packages/htmx/src/HtmxDriver.php packages/htmx/tests/HtmxFormDriverTest.php
git commit -m "feat(htmx): implement FormHypermediaDriver interface

HtmxDriver now generates form-specific attributes for form submission,
inline field validation, and submit behavior."
```

---

### Task 5: Form Package Scaffolding + ModelIntrospector

**Files:**
- Create: `packages/form/composer.json`
- Create: `packages/form/src/ModelIntrospector.php`
- Create: `packages/form/tests/ModelIntrospectorTest.php`

- [ ] **Step 1: Create form package directory and composer.json**

```bash
mkdir -p packages/form/src packages/form/tests packages/form/src/templates
```

Create `packages/form/composer.json`:

```json
{
    "name": "preflow/form",
    "description": "Preflow form builder — field rendering, model binding, hypermedia integration",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.4",
        "preflow/validation": "^0.1 || @dev",
        "preflow/view": "^0.1 || @dev"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0"
    },
    "autoload": {
        "psr-4": {
            "Preflow\\Form\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Preflow\\Form\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 2: Write ModelIntrospector tests**

Create `packages/form/tests/ModelIntrospectorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Form\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Model;
use Preflow\Data\ModelMetadata;
use Preflow\Form\ModelIntrospector;
use Preflow\Validation\Attributes\Validate;

#[Entity('test_users')]
class TestFormModel extends Model
{
    #[Id]
    public int $id = 0;

    #[Validate('required', 'email')]
    public string $email = '';

    #[Validate('required', 'min:8', 'on:create')]
    #[Validate('nullable', 'min:8', 'on:update')]
    public string $password = '';

    #[Validate('required')]
    public string $name = '';

    #[Validate('integer')]
    public int $age = 0;

    #[Validate('url')]
    public string $website = '';

    #[Validate('in:admin,editor,viewer')]
    public string $role = 'viewer';

    public bool $active = true;
}

final class ModelIntrospectorTest extends TestCase
{
    private ModelIntrospector $introspector;

    protected function setUp(): void
    {
        ModelMetadata::clearCache();
        $this->introspector = new ModelIntrospector();
    }

    public function test_get_fields_returns_validated_properties(): void
    {
        $fields = $this->introspector->getFields(TestFormModel::class);
        $this->assertArrayHasKey('email', $fields);
        $this->assertArrayHasKey('password', $fields);
        $this->assertArrayHasKey('name', $fields);
        $this->assertArrayNotHasKey('id', $fields);
    }

    public function test_infer_type_from_email_rule(): void
    {
        $type = $this->introspector->inferType('email', TestFormModel::class);
        $this->assertSame('email', $type);
    }

    public function test_infer_type_from_url_rule(): void
    {
        $type = $this->introspector->inferType('website', TestFormModel::class);
        $this->assertSame('url', $type);
    }

    public function test_infer_type_from_integer_rule(): void
    {
        $type = $this->introspector->inferType('age', TestFormModel::class);
        $this->assertSame('number', $type);
    }

    public function test_infer_type_from_in_rule(): void
    {
        $type = $this->introspector->inferType('role', TestFormModel::class);
        $this->assertSame('select', $type);
    }

    public function test_infer_type_from_bool_property(): void
    {
        $type = $this->introspector->inferType('active', TestFormModel::class);
        $this->assertSame('checkbox', $type);
    }

    public function test_is_required(): void
    {
        $this->assertTrue($this->introspector->isRequired('email', TestFormModel::class));
        $this->assertTrue($this->introspector->isRequired('name', TestFormModel::class));
    }

    public function test_is_required_with_scenario(): void
    {
        $this->assertTrue($this->introspector->isRequired('password', TestFormModel::class, 'create'));
        $this->assertFalse($this->introspector->isRequired('password', TestFormModel::class, 'update'));
    }

    public function test_get_in_options(): void
    {
        $options = $this->introspector->getInOptions('role', TestFormModel::class);
        $this->assertSame(['admin', 'editor', 'viewer'], $options);
    }

    public function test_get_values_from_model(): void
    {
        $model = new TestFormModel();
        $model->email = 'test@example.com';
        $model->name = 'Alice';

        $values = $this->introspector->getValues($model);
        $this->assertSame('test@example.com', $values['email']);
        $this->assertSame('Alice', $values['name']);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/form/tests/ModelIntrospectorTest.php -v`
Expected: FAIL — `ModelIntrospector` not found

- [ ] **Step 4: Implement ModelIntrospector**

Create `packages/form/src/ModelIntrospector.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Form;

use Preflow\Data\Model;
use Preflow\Data\ModelMetadata;

final class ModelIntrospector
{
    /**
     * Get all validated fields and their rules for a model class.
     *
     * @param class-string<Model> $modelClass
     * @return array<string, list<string>>
     */
    public function getFields(string $modelClass, ?string $scenario = null): array
    {
        $meta = ModelMetadata::for($modelClass);
        return $scenario !== null
            ? $meta->validationRulesForScenario($scenario)
            : $meta->validationRules;
    }

    /**
     * Infer the HTML input type from validation rules and property type.
     *
     * @param class-string<Model> $modelClass
     */
    public function inferType(string $field, string $modelClass, ?string $scenario = null): string
    {
        $rules = $this->getFieldRules($field, $modelClass, $scenario);

        foreach ($rules as $rule) {
            if ($rule === 'email') {
                return 'email';
            }
            if ($rule === 'url') {
                return 'url';
            }
            if ($rule === 'integer' || $rule === 'numeric') {
                return 'number';
            }
            if (str_starts_with($rule, 'in:')) {
                return 'select';
            }
        }

        // Check PHP property type
        $ref = new \ReflectionClass($modelClass);
        if ($ref->hasProperty($field)) {
            $type = $ref->getProperty($field)->getType();
            if ($type instanceof \ReflectionNamedType && $type->getName() === 'bool') {
                return 'checkbox';
            }
        }

        return 'text';
    }

    /**
     * Check if a field is required for a given scenario.
     *
     * @param class-string<Model> $modelClass
     */
    public function isRequired(string $field, string $modelClass, ?string $scenario = null): bool
    {
        $rules = $this->getFieldRules($field, $modelClass, $scenario);
        return in_array('required', $rules, true);
    }

    /**
     * Extract options from an 'in:a,b,c' rule.
     *
     * @param class-string<Model> $modelClass
     * @return list<string>|null
     */
    public function getInOptions(string $field, string $modelClass, ?string $scenario = null): ?array
    {
        $rules = $this->getFieldRules($field, $modelClass, $scenario);

        foreach ($rules as $rule) {
            if (str_starts_with($rule, 'in:')) {
                return explode(',', substr($rule, 3));
            }
        }

        return null;
    }

    /**
     * Get current property values from a model instance.
     *
     * @return array<string, mixed>
     */
    public function getValues(Model $model): array
    {
        return $model->toArray();
    }

    /**
     * @param class-string<Model> $modelClass
     * @return list<string>
     */
    private function getFieldRules(string $field, string $modelClass, ?string $scenario): array
    {
        $fields = $this->getFields($modelClass, $scenario);
        return $fields[$field] ?? [];
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/form/tests/ModelIntrospectorTest.php -v`
Expected: PASS (10 tests)

- [ ] **Step 6: Commit**

```bash
git add packages/form/
git commit -m "feat(form): add package scaffolding and ModelIntrospector

New preflow/form package. ModelIntrospector reads #[Validate] attributes
with scenario support, infers HTML input types from rules and property
types, and extracts select options from 'in:' rules."
```

---

### Task 6: FieldRenderer + Default Templates

**Files:**
- Create: `packages/form/src/FieldRenderer.php`
- Create: `packages/form/src/templates/field.twig`
- Create: `packages/form/src/templates/group.twig`
- Create: `packages/form/tests/FieldRendererTest.php`

- [ ] **Step 1: Create default Twig templates**

Create `packages/form/src/templates/field.twig`:

```twig
<div class="form-group{{ errors ? ' has-error' : '' }}{{ width ? ' form-width-' ~ width : '' }}">
  {% if type != 'hidden' %}
    <label for="{{ id }}">{{ label }}{% if required %} <span class="form-required">*</span>{% endif %}</label>
  {% endif %}
  {{ input_html|raw }}
  {% if help %}
    <small class="form-help">{{ help }}</small>
  {% endif %}
  {% if errors %}
    <div class="form-error">{{ errors|first }}</div>
  {% endif %}
</div>
```

Create `packages/form/src/templates/group.twig`:

```twig
<div class="form-group-wrapper{{ class ? ' ' ~ class : '' }}">
  {% if label %}
    <div class="form-group-label">{{ label }}</div>
  {% endif %}
  <div class="form-group-fields">
    {{ content|raw }}
  </div>
</div>
```

- [ ] **Step 2: Write FieldRenderer tests**

Create `packages/form/tests/FieldRendererTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Form\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Form\FieldRenderer;

final class FieldRendererTest extends TestCase
{
    private FieldRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new FieldRenderer();
    }

    public function test_renders_text_input(): void
    {
        $html = $this->renderer->renderInput('name', 'text', '', []);
        $this->assertStringContainsString('<input', $html);
        $this->assertStringContainsString('type="text"', $html);
        $this->assertStringContainsString('name="name"', $html);
    }

    public function test_renders_textarea(): void
    {
        $html = $this->renderer->renderInput('bio', 'textarea', 'Hello', ['rows' => '5']);
        $this->assertStringContainsString('<textarea', $html);
        $this->assertStringContainsString('name="bio"', $html);
        $this->assertStringContainsString('rows="5"', $html);
        $this->assertStringContainsString('>Hello</textarea>', $html);
    }

    public function test_renders_select(): void
    {
        $html = $this->renderer->renderInput('role', 'select', 'editor', [
            'options' => ['admin' => 'Admin', 'editor' => 'Editor', 'viewer' => 'Viewer'],
        ]);
        $this->assertStringContainsString('<select', $html);
        $this->assertStringContainsString('<option', $html);
        $this->assertStringContainsString('selected', $html);
    }

    public function test_renders_checkbox(): void
    {
        $html = $this->renderer->renderInput('active', 'checkbox', '1', []);
        $this->assertStringContainsString('type="checkbox"', $html);
        $this->assertStringContainsString('checked', $html);
    }

    public function test_renders_hidden(): void
    {
        $html = $this->renderer->renderInput('token', 'hidden', 'abc', []);
        $this->assertStringContainsString('type="hidden"', $html);
        $this->assertStringContainsString('value="abc"', $html);
    }

    public function test_renders_file(): void
    {
        $html = $this->renderer->renderInput('avatar', 'file', '', []);
        $this->assertStringContainsString('type="file"', $html);
    }

    public function test_custom_attrs_override(): void
    {
        $html = $this->renderer->renderInput('search', 'text', '', [
            'hx-get' => '/search',
            'hx-trigger' => 'keyup changed delay:300ms',
        ]);
        $this->assertStringContainsString('hx-get="/search"', $html);
        $this->assertStringContainsString('hx-trigger="keyup changed delay:300ms"', $html);
    }

    public function test_renders_field_block(): void
    {
        $html = $this->renderer->renderField('email', [
            'type' => 'email',
            'value' => 'test@example.com',
            'label' => 'Email Address',
            'required' => true,
            'errors' => ['Invalid email'],
        ]);

        $this->assertStringContainsString('form-group', $html);
        $this->assertStringContainsString('has-error', $html);
        $this->assertStringContainsString('Email Address', $html);
        $this->assertStringContainsString('form-required', $html);
        $this->assertStringContainsString('type="email"', $html);
        $this->assertStringContainsString('value="test@example.com"', $html);
        $this->assertStringContainsString('Invalid email', $html);
    }

    public function test_renders_field_with_help_text(): void
    {
        $html = $this->renderer->renderField('password', [
            'type' => 'password',
            'help' => 'Must be at least 8 characters',
        ]);

        $this->assertStringContainsString('form-help', $html);
        $this->assertStringContainsString('Must be at least 8 characters', $html);
    }

    public function test_label_auto_generated_from_field_name(): void
    {
        $html = $this->renderer->renderField('first_name', []);
        $this->assertStringContainsString('First Name', $html);
    }

    public function test_escapes_html_in_values(): void
    {
        $html = $this->renderer->renderInput('name', 'text', '<script>alert("xss")</script>', []);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `./vendor/bin/phpunit packages/form/tests/FieldRendererTest.php -v`
Expected: FAIL — `FieldRenderer` not found

- [ ] **Step 4: Implement FieldRenderer**

Create `packages/form/src/FieldRenderer.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Form;

final class FieldRenderer
{
    private ?string $defaultFieldTemplate;

    public function __construct(?string $defaultFieldTemplate = null)
    {
        $this->defaultFieldTemplate = $defaultFieldTemplate;
    }

    /**
     * Render a complete field block: wrapper + label + input + errors.
     *
     * @param array<string, mixed> $options
     */
    public function renderField(string $name, array $options = []): string
    {
        $type = $options['type'] ?? 'text';
        $value = (string) ($options['value'] ?? '');
        $label = $options['label'] ?? $this->humanize($name);
        $required = $options['required'] ?? false;
        $errors = $options['errors'] ?? [];
        $help = $options['help'] ?? null;
        $width = $options['width'] ?? null;
        $id = $options['id'] ?? 'form-' . $name;
        $attrs = $options['attrs'] ?? [];

        $inputHtml = $this->renderInput($name, $type, $value, array_merge(
            ['id' => $id],
            $options['input_options'] ?? [],
            $attrs,
        ));

        // Use built-in template (no Twig dependency for the renderer itself)
        $errorClass = $errors !== [] ? ' has-error' : '';
        $widthClass = $width !== null ? ' form-width-' . str_replace('/', '-', $width) : '';

        $html = '<div class="form-group' . $errorClass . $widthClass . '">';

        if ($type !== 'hidden') {
            $escapedLabel = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $requiredMark = $required ? ' <span class="form-required">*</span>' : '';
            $html .= '<label for="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '">'
                   . $escapedLabel . $requiredMark . '</label>';
        }

        $html .= $inputHtml;

        if ($help !== null) {
            $html .= '<small class="form-help">'
                   . htmlspecialchars($help, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                   . '</small>';
        }

        if ($errors !== []) {
            $html .= '<div class="form-error">'
                   . htmlspecialchars($errors[0], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                   . '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render just the input HTML element.
     *
     * @param array<string, mixed> $attrs Extra HTML attributes
     */
    public function renderInput(string $name, string $type, string $value, array $attrs = []): string
    {
        $id = $attrs['id'] ?? 'form-' . $name;
        unset($attrs['id'], $attrs['options']);

        // Extract special attrs
        $selectOptions = $attrs['options'] ?? null;
        if (isset($attrs['options'])) {
            $selectOptions = $attrs['options'];
            unset($attrs['options']);
        }

        return match ($type) {
            'textarea' => $this->renderTextarea($name, $value, $id, $attrs),
            'select' => $this->renderSelect($name, $value, $id, $selectOptions ?? [], $attrs),
            'checkbox' => $this->renderCheckbox($name, $value, $id, $attrs),
            'file' => $this->renderFileInput($name, $id, $attrs),
            default => $this->renderStandardInput($name, $type, $value, $id, $attrs),
        };
    }

    private function renderStandardInput(string $name, string $type, string $value, string $id, array $attrs): string
    {
        $baseAttrs = [
            'type' => $type,
            'id' => $id,
            'name' => $name,
            'value' => $value,
        ];
        return '<input' . $this->buildAttrString(array_merge($baseAttrs, $attrs)) . '>';
    }

    private function renderTextarea(string $name, string $value, string $id, array $attrs): string
    {
        $baseAttrs = ['id' => $id, 'name' => $name];
        $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<textarea' . $this->buildAttrString(array_merge($baseAttrs, $attrs)) . '>' . $escaped . '</textarea>';
    }

    private function renderSelect(string $name, string $value, string $id, array $options, array $attrs): string
    {
        $baseAttrs = ['id' => $id, 'name' => $name];
        $html = '<select' . $this->buildAttrString(array_merge($baseAttrs, $attrs)) . '>';

        foreach ($options as $optValue => $optLabel) {
            $selected = (string) $optValue === $value ? ' selected' : '';
            $escapedLabel = htmlspecialchars((string) $optLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $escapedValue = htmlspecialchars((string) $optValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= '<option value="' . $escapedValue . '"' . $selected . '>' . $escapedLabel . '</option>';
        }

        $html .= '</select>';
        return $html;
    }

    private function renderCheckbox(string $name, string $value, string $id, array $attrs): string
    {
        $checked = ($value === '1' || $value === 'true' || $value === 'on') ? ' checked' : '';
        $baseAttrs = ['type' => 'checkbox', 'id' => $id, 'name' => $name, 'value' => '1'];
        return '<input' . $this->buildAttrString(array_merge($baseAttrs, $attrs)) . $checked . '>';
    }

    private function renderFileInput(string $name, string $id, array $attrs): string
    {
        $baseAttrs = ['type' => 'file', 'id' => $id, 'name' => $name];
        return '<input' . $this->buildAttrString(array_merge($baseAttrs, $attrs)) . '>';
    }

    /**
     * @param array<string, string> $attrs
     */
    private function buildAttrString(array $attrs): string
    {
        $parts = [];
        foreach ($attrs as $key => $val) {
            $escaped = htmlspecialchars((string) $val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $parts[] = $key . '="' . $escaped . '"';
        }
        return $parts !== [] ? ' ' . implode(' ', $parts) : '';
    }

    /**
     * Convert a field name to a human-readable label.
     * "first_name" → "First Name", "minPlayers" → "Min Players"
     */
    private function humanize(string $name): string
    {
        // Convert camelCase to space-separated
        $result = preg_replace('/([a-z])([A-Z])/', '$1 $2', $name);
        // Convert underscores/hyphens to spaces
        $result = str_replace(['_', '-'], ' ', $result);
        return ucwords($result);
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/form/tests/FieldRendererTest.php -v`
Expected: PASS (11 tests)

- [ ] **Step 6: Commit**

```bash
git add packages/form/src/FieldRenderer.php \
       packages/form/src/templates/field.twig \
       packages/form/src/templates/group.twig \
       packages/form/tests/FieldRendererTest.php
git commit -m "feat(form): add FieldRenderer with input rendering and field blocks

Renders text, email, password, number, textarea, select, checkbox, file,
and hidden inputs. Field blocks include label, error, help text, and
required indicator. Auto-humanizes field names for labels."
```

---

### Task 7: GroupRenderer

**Files:**
- Create: `packages/form/src/GroupRenderer.php`
- Create: `packages/form/tests/GroupRendererTest.php`

- [ ] **Step 1: Write GroupRenderer tests**

Create `packages/form/tests/GroupRendererTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Form\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Form\GroupRenderer;

final class GroupRendererTest extends TestCase
{
    private GroupRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new GroupRenderer();
    }

    public function test_renders_group_wrapper(): void
    {
        $html = $this->renderer->render('<input name="zip"><input name="city">', []);
        $this->assertStringContainsString('form-group-wrapper', $html);
        $this->assertStringContainsString('form-group-fields', $html);
        $this->assertStringContainsString('<input name="zip">', $html);
    }

    public function test_renders_group_with_label(): void
    {
        $html = $this->renderer->render('<input name="zip">', ['label' => 'Address']);
        $this->assertStringContainsString('form-group-label', $html);
        $this->assertStringContainsString('Address', $html);
    }

    public function test_renders_group_with_class(): void
    {
        $html = $this->renderer->render('<input>', ['class' => 'form-row']);
        $this->assertStringContainsString('form-row', $html);
    }

    public function test_group_without_label_omits_label_div(): void
    {
        $html = $this->renderer->render('<input>', []);
        $this->assertStringNotContainsString('form-group-label', $html);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/form/tests/GroupRendererTest.php -v`
Expected: FAIL — `GroupRenderer` not found

- [ ] **Step 3: Implement GroupRenderer**

Create `packages/form/src/GroupRenderer.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Form;

final class GroupRenderer
{
    /**
     * @param array<string, mixed> $options
     */
    public function render(string $content, array $options = []): string
    {
        $label = $options['label'] ?? null;
        $class = $options['class'] ?? null;

        $wrapperClass = 'form-group-wrapper' . ($class !== null ? ' ' . $class : '');

        $html = '<div class="' . htmlspecialchars($wrapperClass, ENT_QUOTES, 'UTF-8') . '">';

        if ($label !== null) {
            $html .= '<div class="form-group-label">'
                   . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                   . '</div>';
        }

        $html .= '<div class="form-group-fields">' . $content . '</div>';
        $html .= '</div>';

        return $html;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/form/tests/GroupRendererTest.php -v`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add packages/form/src/GroupRenderer.php packages/form/tests/GroupRendererTest.php
git commit -m "feat(form): add GroupRenderer for field layout groups"
```

---

### Task 8: FormBuilder

**Files:**
- Create: `packages/form/src/FormBuilder.php`
- Create: `packages/form/tests/FormBuilderTest.php`

- [ ] **Step 1: Write FormBuilder tests**

Create `packages/form/tests/FormBuilderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Form\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\ModelMetadata;
use Preflow\Form\FieldRenderer;
use Preflow\Form\FormBuilder;
use Preflow\Form\GroupRenderer;
use Preflow\Form\ModelIntrospector;
use Preflow\Validation\ErrorBag;
use Preflow\Validation\ValidationResult;

final class FormBuilderTest extends TestCase
{
    private FormBuilder $builder;

    protected function setUp(): void
    {
        ModelMetadata::clearCache();
        $this->builder = $this->createBuilder();
    }

    private function createBuilder(array $options = []): FormBuilder
    {
        return new FormBuilder(
            fieldRenderer: new FieldRenderer(),
            groupRenderer: new GroupRenderer(),
            introspector: new ModelIntrospector(),
            options: $options,
        );
    }

    public function test_begin_renders_form_tag(): void
    {
        $html = $this->builder->begin();
        $this->assertStringContainsString('<form', $html);
        $this->assertStringContainsString('method="post"', $html);
    }

    public function test_begin_with_action(): void
    {
        $builder = $this->createBuilder(['action' => '/contact', 'method' => 'post']);
        $html = $builder->begin();
        $this->assertStringContainsString('action="/contact"', $html);
    }

    public function test_begin_includes_csrf_token(): void
    {
        $builder = $this->createBuilder(['csrf_token' => 'test-token-123']);
        $html = $builder->begin();
        $this->assertStringContainsString('name="csrf_token"', $html);
        $this->assertStringContainsString('value="test-token-123"', $html);
    }

    public function test_end_renders_closing_tag(): void
    {
        $html = $this->builder->end();
        $this->assertSame('</form>', $html);
    }

    public function test_field_renders_field_block(): void
    {
        $html = $this->builder->field('email', ['type' => 'email']);
        $this->assertStringContainsString('type="email"', $html);
        $this->assertStringContainsString('name="email"', $html);
    }

    public function test_field_with_model_binds_value(): void
    {
        $model = new TestFormModel();
        $model->email = 'test@example.com';

        $builder = $this->createBuilder(['model' => $model]);
        $html = $builder->field('email');
        $this->assertStringContainsString('value="test@example.com"', $html);
    }

    public function test_field_with_model_infers_type(): void
    {
        $model = new TestFormModel();
        $builder = $this->createBuilder(['model' => $model]);
        $html = $builder->field('email');
        $this->assertStringContainsString('type="email"', $html);
    }

    public function test_field_with_model_detects_required(): void
    {
        $model = new TestFormModel();
        $builder = $this->createBuilder(['model' => $model]);
        $html = $builder->field('name');
        $this->assertStringContainsString('form-required', $html);
    }

    public function test_field_with_error_bag(): void
    {
        $result = new ValidationResult(['email' => ['Invalid email']]);
        $errorBag = new ErrorBag($result);

        $builder = $this->createBuilder(['errorBag' => $errorBag]);
        $html = $builder->field('email');
        $this->assertStringContainsString('has-error', $html);
        $this->assertStringContainsString('Invalid email', $html);
    }

    public function test_old_input_overrides_model_value(): void
    {
        $model = new TestFormModel();
        $model->email = 'old@example.com';

        $builder = $this->createBuilder([
            'model' => $model,
            'oldInput' => ['email' => 'submitted@example.com'],
        ]);

        $html = $builder->field('email');
        $this->assertStringContainsString('value="submitted@example.com"', $html);
    }

    public function test_select(): void
    {
        $html = $this->builder->select('role', [
            'options' => ['admin' => 'Admin', 'editor' => 'Editor'],
        ]);
        $this->assertStringContainsString('<select', $html);
        $this->assertStringContainsString('Admin', $html);
    }

    public function test_checkbox(): void
    {
        $html = $this->builder->checkbox('active');
        $this->assertStringContainsString('type="checkbox"', $html);
    }

    public function test_hidden(): void
    {
        $html = $this->builder->hidden('id', '42');
        $this->assertStringContainsString('type="hidden"', $html);
        $this->assertStringContainsString('value="42"', $html);
        // Hidden should not have label wrapper
        $this->assertStringNotContainsString('<label', $html);
    }

    public function test_submit(): void
    {
        $html = $this->builder->submit('Save');
        $this->assertStringContainsString('<button', $html);
        $this->assertStringContainsString('type="submit"', $html);
        $this->assertStringContainsString('Save', $html);
    }

    public function test_file(): void
    {
        $html = $this->builder->file('avatar');
        $this->assertStringContainsString('type="file"', $html);
    }

    public function test_group(): void
    {
        $groupOpen = $this->builder->group(['class' => 'form-row']);
        $field1 = $this->builder->field('zip', ['width' => '1/3']);
        $field2 = $this->builder->field('city', ['width' => '2/3']);
        $groupClose = $this->builder->endGroup();

        // Group should capture and wrap fields
        $this->assertStringContainsString('form-group-wrapper', $groupClose);
        $this->assertStringContainsString('form-row', $groupClose);
    }

    public function test_fields_auto_generates_from_model(): void
    {
        $model = new TestFormModel();
        $builder = $this->createBuilder(['model' => $model]);
        $html = $builder->fields();

        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringContainsString('name="name"', $html);
        $this->assertStringContainsString('name="password"', $html);
    }

    public function test_fields_with_only(): void
    {
        $model = new TestFormModel();
        $builder = $this->createBuilder(['model' => $model]);
        $html = $builder->fields(['only' => ['email', 'name']]);

        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringContainsString('name="name"', $html);
        $this->assertStringNotContainsString('name="password"', $html);
    }

    public function test_fields_with_except(): void
    {
        $model = new TestFormModel();
        $builder = $this->createBuilder(['model' => $model]);
        $html = $builder->fields(['except' => ['password', 'age', 'website', 'role', 'active']]);

        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringContainsString('name="name"', $html);
        $this->assertStringNotContainsString('name="password"', $html);
    }

    public function test_scenario_affects_required(): void
    {
        $model = new TestFormModel();
        $builder = $this->createBuilder(['model' => $model, 'scenario' => 'update']);
        $html = $builder->field('password');

        // Password is nullable in update scenario, so no required mark
        $this->assertStringNotContainsString('form-required', $html);
    }

    public function test_form_level_rules_override(): void
    {
        $model = new TestFormModel();
        $builder = $this->createBuilder([
            'model' => $model,
            'rules' => ['password' => ['nullable', 'min:8']],
        ]);
        $html = $builder->field('password');
        // Overridden rules: nullable means not required
        $this->assertStringNotContainsString('form-required', $html);
    }

    public function test_attrs_passed_through(): void
    {
        $html = $this->builder->field('search', [
            'attrs' => ['hx-get' => '/search', 'hx-trigger' => 'keyup'],
        ]);
        $this->assertStringContainsString('hx-get="/search"', $html);
        $this->assertStringContainsString('hx-trigger="keyup"', $html);
    }

    public function test_begin_with_custom_attrs(): void
    {
        $builder = $this->createBuilder([
            'action' => '/save',
            'attrs' => ['hx-boost' => 'true'],
        ]);
        $html = $builder->begin();
        $this->assertStringContainsString('hx-boost="true"', $html);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/form/tests/FormBuilderTest.php -v`
Expected: FAIL — `FormBuilder` not found

- [ ] **Step 3: Implement FormBuilder**

Create `packages/form/src/FormBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Form;

use Preflow\Data\Model;
use Preflow\Validation\ErrorBag;
use Preflow\View\FormHypermediaDriver;

final class FormBuilder
{
    private ?Model $model;
    private ?string $scenario;
    private ?ErrorBag $errorBag;
    /** @var array<string, mixed> */
    private array $oldInput;
    /** @var array<string, list<string>> */
    private array $ruleOverrides;
    private string $action;
    private string $method;
    private ?string $csrfToken;
    /** @var array<string, string> */
    private array $formAttrs;
    private ?FormHypermediaDriver $driver;
    private ?string $componentId;
    private string $validateEndpoint;

    /** @var list<array{content: string, options: array<string, mixed>}> */
    private array $groupStack = [];
    private bool $capturing = false;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        private readonly FieldRenderer $fieldRenderer,
        private readonly GroupRenderer $groupRenderer,
        private readonly ModelIntrospector $introspector,
        array $options = [],
    ) {
        $this->model = $options['model'] ?? null;
        $this->scenario = $options['scenario'] ?? null;
        $this->errorBag = $options['errorBag'] ?? null;
        $this->oldInput = $options['oldInput'] ?? [];
        $this->ruleOverrides = $options['rules'] ?? [];
        $this->action = $options['action'] ?? '';
        $this->method = $options['method'] ?? 'post';
        $this->csrfToken = $options['csrf_token'] ?? null;
        $this->formAttrs = $options['attrs'] ?? [];
        $this->driver = $options['driver'] ?? null;
        $this->componentId = $options['componentId'] ?? null;
        $this->validateEndpoint = $options['validate_endpoint'] ?? '';
    }

    public function begin(): string
    {
        $attrs = ['method' => $this->method];

        if ($this->action !== '') {
            $attrs['action'] = $this->action;
        }

        // Let hypermedia driver add form-level attributes
        if ($this->driver !== null && $this->action !== '') {
            $driverAttrs = $this->driver->formAttributes($this->action, $this->method);
            $attrs = array_merge($attrs, $driverAttrs);
        }

        // User attrs override everything
        $attrs = array_merge($attrs, $this->formAttrs);

        $html = '<form' . $this->buildAttrString($attrs) . '>';

        if ($this->csrfToken !== null) {
            $escaped = htmlspecialchars($this->csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= '<input type="hidden" name="csrf_token" value="' . $escaped . '">';
        }

        return $html;
    }

    public function end(): string
    {
        return '</form>';
    }

    /**
     * @param array<string, mixed> $options
     */
    public function field(string $name, array $options = []): string
    {
        $options = $this->resolveFieldOptions($name, $options);

        // Add hypermedia inline validation attrs if driver is active and not disabled
        $validate = $options['validate'] ?? true;
        if ($validate && $this->driver !== null && $this->componentId !== null && $this->validateEndpoint !== '') {
            $trigger = $options['validate_on'] ?? 'blur';
            $driverAttrs = $this->driver->inlineValidationAttributes($this->validateEndpoint, $name, $trigger);
            // User attrs override driver attrs
            $options['attrs'] = array_merge($driverAttrs, $options['attrs'] ?? []);
        }

        $html = $this->fieldRenderer->renderField($name, $options);

        if ($this->capturing) {
            $this->groupStack[array_key_last($this->groupStack)]['content'] .= $html;
            return '';
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function select(string $name, array $options = []): string
    {
        $options['type'] = 'select';
        return $this->field($name, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function checkbox(string $name, array $options = []): string
    {
        $options['type'] = 'checkbox';
        return $this->field($name, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function radio(string $name, array $options = []): string
    {
        $options['type'] = 'radio';
        return $this->field($name, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function file(string $name, array $options = []): string
    {
        $options['type'] = 'file';
        return $this->field($name, $options);
    }

    public function hidden(string $name, string $value): string
    {
        return $this->field($name, ['type' => 'hidden', 'value' => $value]);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function submit(string $label, array $options = []): string
    {
        $attrs = ['type' => 'submit'];
        $class = $options['class'] ?? null;
        if ($class !== null) {
            $attrs['class'] = $class;
        }

        // Let driver add submit attributes
        if ($this->driver !== null && $this->componentId !== null) {
            $target = $options['target'] ?? '#' . $this->componentId;
            $driverAttrs = $this->driver->submitAttributes($target, $options);
            $attrs = array_merge($attrs, $driverAttrs);
        }

        $attrs = array_merge($attrs, $options['attrs'] ?? []);
        $escaped = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<button' . $this->buildAttrString($attrs) . '>' . $escaped . '</button>';
    }

    /**
     * @param array<string, mixed> $options
     */
    public function group(array $options = []): string
    {
        $this->groupStack[] = ['content' => '', 'options' => $options];
        $this->capturing = true;
        return '';
    }

    public function endGroup(): string
    {
        if ($this->groupStack === []) {
            return '';
        }

        $group = array_pop($this->groupStack);
        $this->capturing = $this->groupStack !== [];

        return $this->groupRenderer->render($group['content'], $group['options']);
    }

    /**
     * Auto-generate fields from model's #[Validate] attributes.
     *
     * @param array<string, mixed> $options
     */
    public function fields(array $options = []): string
    {
        if ($this->model === null) {
            return '';
        }

        $allFields = $this->introspector->getFields($this->model::class, $this->scenario);
        $only = $options['only'] ?? null;
        $except = $options['except'] ?? [];
        $overrides = $options['override'] ?? [];

        $html = '';
        foreach (array_keys($allFields) as $fieldName) {
            if ($only !== null && !in_array($fieldName, $only, true)) {
                continue;
            }
            if (in_array($fieldName, $except, true)) {
                continue;
            }

            $fieldOptions = $overrides[$fieldName] ?? [];
            $html .= $this->field($fieldName, $fieldOptions);
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function resolveFieldOptions(string $name, array $options): array
    {
        // Type inference from model
        if (!isset($options['type']) && $this->model !== null) {
            $options['type'] = $this->introspector->inferType($name, $this->model::class, $this->scenario);
        }

        // Required detection
        if (!isset($options['required']) && $this->model !== null) {
            $ruleOverride = $this->ruleOverrides[$name] ?? null;
            if ($ruleOverride !== null) {
                $options['required'] = in_array('required', $ruleOverride, true);
            } else {
                $options['required'] = $this->introspector->isRequired($name, $this->model::class, $this->scenario);
            }
        }

        // Select options from 'in:' rule
        if (($options['type'] ?? 'text') === 'select' && !isset($options['options']) && $this->model !== null) {
            $inOptions = $this->introspector->getInOptions($name, $this->model::class, $this->scenario);
            if ($inOptions !== null) {
                $options['options'] = array_combine($inOptions, array_map(
                    fn (string $o) => ucfirst($o),
                    $inOptions,
                ));
            }
        }

        // Value: old input > model property > explicit
        if (!isset($options['value'])) {
            if (isset($this->oldInput[$name])) {
                $options['value'] = (string) $this->oldInput[$name];
            } elseif ($this->model !== null) {
                $values = $this->introspector->getValues($this->model);
                $options['value'] = isset($values[$name]) ? (string) $values[$name] : '';
            } else {
                $options['value'] = '';
            }
        }

        // Errors from ErrorBag
        if (!isset($options['errors']) && $this->errorBag !== null && $this->errorBag->has($name)) {
            $options['errors'] = $this->errorBag->get($name);
        }

        return $options;
    }

    /**
     * @param array<string, string> $attrs
     */
    private function buildAttrString(array $attrs): string
    {
        $parts = [];
        foreach ($attrs as $key => $val) {
            $escaped = htmlspecialchars((string) $val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $parts[] = $key . '="' . $escaped . '"';
        }
        return $parts !== [] ? ' ' . implode(' ', $parts) : '';
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/form/tests/FormBuilderTest.php -v`
Expected: PASS (20+ tests)

- [ ] **Step 5: Run full form test suite**

Run: `./vendor/bin/phpunit packages/form/tests/ -v`
Expected: All form tests pass

- [ ] **Step 6: Commit**

```bash
git add packages/form/src/FormBuilder.php packages/form/tests/FormBuilderTest.php
git commit -m "feat(form): add FormBuilder with model binding, groups, and auto-generation

Core form builder ties together FieldRenderer, GroupRenderer, and
ModelIntrospector. Supports model binding with scenario-aware validation,
old input restoration, ErrorBag integration, field groups, auto-generation
via fields(), and hypermedia driver context detection."
```

---

### Task 9: FormExtensionProvider + Template Wiring

**Files:**
- Create: `packages/form/src/FormExtensionProvider.php`
- Create: `packages/form/tests/FormExtensionProviderTest.php`

- [ ] **Step 1: Write FormExtensionProvider tests**

Create `packages/form/tests/FormExtensionProviderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Form\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Form\FieldRenderer;
use Preflow\Form\FormBuilder;
use Preflow\Form\FormExtensionProvider;
use Preflow\Form\GroupRenderer;
use Preflow\Form\ModelIntrospector;
use Preflow\View\TemplateExtensionProvider;
use Preflow\View\TemplateFunctionDefinition;

final class FormExtensionProviderTest extends TestCase
{
    private FormExtensionProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new FormExtensionProvider(
            new FieldRenderer(),
            new GroupRenderer(),
            new ModelIntrospector(),
        );
    }

    public function test_implements_template_extension_provider(): void
    {
        $this->assertInstanceOf(TemplateExtensionProvider::class, $this->provider);
    }

    public function test_registers_form_begin(): void
    {
        $functions = $this->provider->getTemplateFunctions();
        $names = array_map(fn (TemplateFunctionDefinition $f) => $f->name, $functions);
        $this->assertContains('form_begin', $names);
    }

    public function test_registers_form_end(): void
    {
        $functions = $this->provider->getTemplateFunctions();
        $names = array_map(fn (TemplateFunctionDefinition $f) => $f->name, $functions);
        $this->assertContains('form_end', $names);
    }

    public function test_form_begin_returns_form_builder(): void
    {
        $functions = $this->provider->getTemplateFunctions();
        $formBegin = null;
        foreach ($functions as $f) {
            if ($f->name === 'form_begin') {
                $formBegin = $f;
                break;
            }
        }

        $this->assertNotNull($formBegin);
        $result = ($formBegin->callable)(['action' => '/test']);
        $this->assertInstanceOf(FormBuilder::class, $result);
    }

    public function test_form_end_returns_closing_tag(): void
    {
        $functions = $this->provider->getTemplateFunctions();
        $formEnd = null;
        foreach ($functions as $f) {
            if ($f->name === 'form_end') {
                $formEnd = $f;
                break;
            }
        }

        $this->assertNotNull($formEnd);
        $result = ($formEnd->callable)();
        $this->assertSame('</form>', $result);
    }

    public function test_responsive_image_and_color_functions_not_here(): void
    {
        $functions = $this->provider->getTemplateFunctions();
        $names = array_map(fn (TemplateFunctionDefinition $f) => $f->name, $functions);
        $this->assertNotContains('responsive_image', $names);
        $this->assertNotContains('color_lighten', $names);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/form/tests/FormExtensionProviderTest.php -v`
Expected: FAIL — `FormExtensionProvider` not found

- [ ] **Step 3: Implement FormExtensionProvider**

Create `packages/form/src/FormExtensionProvider.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Form;

use Preflow\Validation\ErrorBag;
use Preflow\View\FormHypermediaDriver;
use Preflow\View\TemplateExtensionProvider;
use Preflow\View\TemplateFunctionDefinition;

final class FormExtensionProvider implements TemplateExtensionProvider
{
    private ?ErrorBag $errorBag = null;
    /** @var array<string, mixed> */
    private array $oldInput = [];
    private ?FormHypermediaDriver $driver = null;
    private ?string $componentId = null;
    private ?string $csrfToken = null;
    private ?string $validateEndpoint = null;

    public function __construct(
        private readonly FieldRenderer $fieldRenderer,
        private readonly GroupRenderer $groupRenderer,
        private readonly ModelIntrospector $introspector,
    ) {}

    public function setErrorBag(ErrorBag $errorBag): void
    {
        $this->errorBag = $errorBag;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function setOldInput(array $input): void
    {
        $this->oldInput = $input;
    }

    public function setDriver(FormHypermediaDriver $driver): void
    {
        $this->driver = $driver;
    }

    public function setComponentContext(string $componentId, ?string $validateEndpoint = null): void
    {
        $this->componentId = $componentId;
        $this->validateEndpoint = $validateEndpoint;
    }

    public function setCsrfToken(string $token): void
    {
        $this->csrfToken = $token;
    }

    public function getTemplateFunctions(): array
    {
        return [
            new TemplateFunctionDefinition(
                name: 'form_begin',
                callable: fn (array $options = []): FormBuilder => $this->createBuilder($options),
                isSafe: true,
            ),
            new TemplateFunctionDefinition(
                name: 'form_end',
                callable: fn (): string => '</form>',
                isSafe: true,
            ),
        ];
    }

    public function getTemplateGlobals(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function createBuilder(array $options): FormBuilder
    {
        // Inject current context into options (user options override)
        $defaults = [];

        if ($this->errorBag !== null && !isset($options['errorBag'])) {
            $defaults['errorBag'] = $this->errorBag;
        }
        if ($this->oldInput !== [] && !isset($options['oldInput'])) {
            $defaults['oldInput'] = $this->oldInput;
        }
        if ($this->driver !== null && !isset($options['driver'])) {
            $defaults['driver'] = $this->driver;
        }
        if ($this->componentId !== null && !isset($options['componentId'])) {
            $defaults['componentId'] = $this->componentId;
        }
        if ($this->csrfToken !== null && !isset($options['csrf_token'])) {
            $defaults['csrf_token'] = $this->csrfToken;
        }
        if ($this->validateEndpoint !== null) {
            $defaults['validate_endpoint'] = $this->validateEndpoint;
        }

        return new FormBuilder(
            fieldRenderer: $this->fieldRenderer,
            groupRenderer: $this->groupRenderer,
            introspector: $this->introspector,
            options: array_merge($defaults, $options),
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/form/tests/FormExtensionProviderTest.php -v`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
git add packages/form/src/FormExtensionProvider.php packages/form/tests/FormExtensionProviderTest.php
git commit -m "feat(form): add FormExtensionProvider for template function registration

Registers form_begin() and form_end() template functions. Injects
ErrorBag, old input, CSRF token, and hypermedia driver context into
FormBuilder instances automatically."
```

---

### Task 10: HelpersExtensionProvider (Color + Image Template Functions)

**Files:**
- Create: `packages/core/src/Helpers/HelpersExtensionProvider.php`
- Create: `packages/core/tests/Helpers/HelpersExtensionProviderTest.php`

- [ ] **Step 1: Write HelpersExtensionProvider tests**

Create `packages/core/tests/Helpers/HelpersExtensionProviderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Helpers;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Helpers\HelpersExtensionProvider;
use Preflow\View\PathBasedTransformer;
use Preflow\View\ResponsiveImage;
use Preflow\View\TemplateExtensionProvider;
use Preflow\View\TemplateFunctionDefinition;

final class HelpersExtensionProviderTest extends TestCase
{
    private HelpersExtensionProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new HelpersExtensionProvider(
            new ResponsiveImage(new PathBasedTransformer()),
        );
    }

    public function test_implements_template_extension_provider(): void
    {
        $this->assertInstanceOf(TemplateExtensionProvider::class, $this->provider);
    }

    public function test_registers_color_functions(): void
    {
        $names = array_map(
            fn (TemplateFunctionDefinition $f) => $f->name,
            $this->provider->getTemplateFunctions(),
        );

        $this->assertContains('color_lighten', $names);
        $this->assertContains('color_darken', $names);
        $this->assertContains('color_contrast', $names);
        $this->assertContains('color_adjust_contrast', $names);
    }

    public function test_registers_image_functions(): void
    {
        $names = array_map(
            fn (TemplateFunctionDefinition $f) => $f->name,
            $this->provider->getTemplateFunctions(),
        );

        $this->assertContains('responsive_image', $names);
        $this->assertContains('image_srcset', $names);
    }

    public function test_color_lighten_callable(): void
    {
        $fn = $this->findFunction('color_lighten');
        $result = ($fn->callable)('#000000', 0.5);
        $this->assertSame('#808080', $result);
    }

    public function test_responsive_image_callable(): void
    {
        $fn = $this->findFunction('responsive_image');
        $result = ($fn->callable)('/img.jpg', ['widths' => [480], 'alt' => 'Test']);
        $this->assertStringContainsString('<img', $result);
    }

    public function test_image_srcset_callable(): void
    {
        $fn = $this->findFunction('image_srcset');
        $result = ($fn->callable)('/img.jpg', ['widths' => [480, 1024]]);
        $this->assertStringContainsString('480w', $result);
        $this->assertStringContainsString('1024w', $result);
    }

    private function findFunction(string $name): TemplateFunctionDefinition
    {
        foreach ($this->provider->getTemplateFunctions() as $f) {
            if ($f->name === $name) {
                return $f;
            }
        }
        $this->fail("Function {$name} not found");
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/core/tests/Helpers/HelpersExtensionProviderTest.php -v`
Expected: FAIL — `HelpersExtensionProvider` not found

- [ ] **Step 3: Implement HelpersExtensionProvider**

Create `packages/core/src/Helpers/HelpersExtensionProvider.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Helpers;

use Preflow\View\ResponsiveImage;
use Preflow\View\TemplateExtensionProvider;
use Preflow\View\TemplateFunctionDefinition;

final class HelpersExtensionProvider implements TemplateExtensionProvider
{
    public function __construct(
        private readonly ?ResponsiveImage $responsiveImage = null,
    ) {}

    public function getTemplateFunctions(): array
    {
        $functions = [
            new TemplateFunctionDefinition(
                name: 'color_lighten',
                callable: fn (string $hex, float $percent): string =>
                    Color::lighten($hex, $percent),
                isSafe: true,
            ),
            new TemplateFunctionDefinition(
                name: 'color_darken',
                callable: fn (string $hex, float $percent): string =>
                    Color::darken($hex, $percent),
                isSafe: true,
            ),
            new TemplateFunctionDefinition(
                name: 'color_contrast',
                callable: fn (string $color1, string $color2): float =>
                    Color::contrastRatio($color1, $color2),
            ),
            new TemplateFunctionDefinition(
                name: 'color_adjust_contrast',
                callable: fn (string $text, string $bg = '#ffffff', float $min = 4.5): string =>
                    Color::adjustForContrast($text, $bg, $min),
                isSafe: true,
            ),
        ];

        if ($this->responsiveImage !== null) {
            $img = $this->responsiveImage;
            $functions[] = new TemplateFunctionDefinition(
                name: 'responsive_image',
                callable: fn (string $path, array $options = []): string =>
                    $img->render($path, $options),
                isSafe: true,
            );
            $functions[] = new TemplateFunctionDefinition(
                name: 'image_srcset',
                callable: fn (string $path, array $options = []): string =>
                    $img->srcset(
                        $path,
                        $options['widths'] ?? [480, 768, 1024],
                        $options['format'] ?? 'webp',
                        $options['quality'] ?? 75,
                    ),
                isSafe: true,
            );
        }

        return $functions;
    }

    public function getTemplateGlobals(): array
    {
        return [];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/core/tests/Helpers/HelpersExtensionProviderTest.php -v`
Expected: PASS (7 tests)

- [ ] **Step 5: Commit**

```bash
git add packages/core/src/Helpers/HelpersExtensionProvider.php \
       packages/core/tests/Helpers/HelpersExtensionProviderTest.php
git commit -m "feat(core): add HelpersExtensionProvider for color and image template functions

Registers color_lighten, color_darken, color_contrast, color_adjust_contrast,
responsive_image, and image_srcset as template functions."
```

---

### Task 11: Application Boot Integration

**Files:**
- Modify: `packages/core/src/Application.php`
- Modify: `composer.json` (root)
- Modify: `phpunit.xml`
- Modify: `.github/workflows/split.yml`

- [ ] **Step 1: Add `bootHelpers()` to Application**

In `packages/core/src/Application.php`, add `bootHelpers()` method after `bootValidation()` (around line 404). Also add call in `boot()` method after `bootValidation()`:

In the `boot()` method, add after line 140 (`$this->bootValidation();`):
```php
$this->bootHelpers();
```

Add the method:

```php
private function bootHelpers(): void
{
    if (!$this->container->has(\Preflow\View\TemplateEngineInterface::class)) {
        return;
    }

    $engine = $this->container->get(\Preflow\View\TemplateEngineInterface::class);

    // Responsive image helper
    $responsiveImage = null;
    if (class_exists(\Preflow\View\ResponsiveImage::class)) {
        $transformer = $this->container->has(\Preflow\View\ImageUrlTransformer::class)
            ? $this->container->get(\Preflow\View\ImageUrlTransformer::class)
            : new \Preflow\View\PathBasedTransformer();

        $presets = $this->config->get('image.presets', []);
        $responsiveImage = new \Preflow\View\ResponsiveImage($transformer, $presets);
        $this->container->instance(\Preflow\View\ResponsiveImage::class, $responsiveImage);
    }

    // Color + image template functions
    if (class_exists(\Preflow\Core\Helpers\HelpersExtensionProvider::class)) {
        $helpersProvider = new \Preflow\Core\Helpers\HelpersExtensionProvider($responsiveImage);
        $this->registerExtensionProvider($engine, $helpersProvider);
    }
}
```

- [ ] **Step 2: Add `bootForm()` to Application**

Add after the `bootHelpers()` call in `boot()`:
```php
$this->bootForm();
```

Add the method:

```php
private function bootForm(): void
{
    if (!class_exists(\Preflow\Form\FormExtensionProvider::class)) {
        return;
    }

    if (!$this->container->has(\Preflow\View\TemplateEngineInterface::class)) {
        return;
    }

    $engine = $this->container->get(\Preflow\View\TemplateEngineInterface::class);

    $formProvider = new \Preflow\Form\FormExtensionProvider(
        new \Preflow\Form\FieldRenderer(),
        new \Preflow\Form\GroupRenderer(),
        new \Preflow\Form\ModelIntrospector(),
    );

    // Wire in hypermedia driver if available
    if ($this->container->has(\Preflow\View\FormHypermediaDriver::class)) {
        $formProvider->setDriver($this->container->get(\Preflow\View\FormHypermediaDriver::class));
    }

    $this->container->instance(\Preflow\Form\FormExtensionProvider::class, $formProvider);
    $this->registerExtensionProvider($engine, $formProvider);
}
```

- [ ] **Step 3: Register FormHypermediaDriver in bootComponentLayer**

In the HTMX boot section of `bootComponentLayer()` (around line 449), add after the `HtmxDriver` registration:

```php
$this->container->instance(\Preflow\View\FormHypermediaDriver::class, $htmxDriver);
```

- [ ] **Step 4: Update root composer.json**

Add form package repository and dev dependency.

In `repositories` array, add:
```json
{
    "type": "path",
    "url": "packages/form",
    "options": { "symlink": true }
}
```

In `require-dev`, add:
```json
"preflow/form": "@dev",
```

In `autoload-dev.psr-4`, add:
```json
"Preflow\\Form\\Tests\\": "packages/form/tests/"
```

- [ ] **Step 5: Update phpunit.xml**

Add Form test suite in `<testsuites>`:
```xml
<testsuite name="Form">
    <directory>packages/form/tests</directory>
</testsuite>
```

Add source in `<source><include>`:
```xml
<directory>packages/form/src</directory>
```

- [ ] **Step 6: Update split workflow**

In `.github/workflows/split.yml`, add to the matrix:
```yaml
- { local: 'packages/form', remote: 'form' }
```

- [ ] **Step 7: Run composer update and full test suite**

```bash
cd /Users/smyr/Sites/gbits/flopp && composer update
```

Then run all tests:
```bash
./vendor/bin/phpunit -v
```

Expected: All tests pass (existing + new)

- [ ] **Step 8: Commit**

```bash
git add packages/core/src/Application.php \
       composer.json \
       phpunit.xml \
       .github/workflows/split.yml
git commit -m "feat: wire form package and helpers into application boot

bootHelpers() registers color and responsive image template functions.
bootForm() registers form_begin/form_end and injects hypermedia driver.
FormHypermediaDriver registered in container during HTMX boot.
Added form package to monorepo config, test suites, and split workflow."
```

---

### Task 12: Final Verification

**Files:** None (verification only)

- [ ] **Step 1: Run complete test suite**

```bash
cd /Users/smyr/Sites/gbits/flopp && ./vendor/bin/phpunit -v
```

Expected: All tests pass — should be 668 + new tests

- [ ] **Step 2: Verify test count increased**

Check that the new tests are counted:
```bash
./vendor/bin/phpunit --testsuite=Form -v
./vendor/bin/phpunit --testsuite=Validation -v
./vendor/bin/phpunit --testsuite=Core -v
./vendor/bin/phpunit --testsuite=View -v
./vendor/bin/phpunit --testsuite=Htmx -v
```

- [ ] **Step 3: Verify no regressions in existing suites**

```bash
./vendor/bin/phpunit --testsuite=Data -v
./vendor/bin/phpunit --testsuite=Components -v
```

- [ ] **Step 4: Tag for reference (do not push)**

```bash
git log --oneline -15
```

Review all commits are clean and well-organized.
