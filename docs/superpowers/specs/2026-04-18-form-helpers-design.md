# Form Package & Helpers Design

**Date:** 2026-04-18
**Status:** Draft
**Packages affected:** new `preflow/form`, modified `preflow/core`, `preflow/view`, `preflow/validation`, `preflow/htmx`

---

## Overview

Three pieces of work:

1. **`preflow/form`** — A new package providing a FormBuilder, field template functions, model binding with validation introspection, auto-generation, field groups, and hypermedia-driver-aware inline validation.
2. **Helpers distributed into existing packages** — Color helpers into `preflow/core`, responsive image helper into `preflow/view`.
3. **Supporting changes** — `HypermediaDriver` interface in `preflow/view`, validation scenarios in `preflow/validation`, HTMX driver implementation in `preflow/htmx`.

### Design Principles

- Strongly opinionated but flexible and open
- 90% of typical forms doable within minutes
- No client-side validation — server-side only, feels instant via hypermedia
- Works in plain page templates and inside components
- Hypermedia-driver-agnostic (HTMX today, Datastar tomorrow)
- No new packages beyond `preflow/form`

---

## 1. `preflow/form` Package

### 1.1 Dependencies

- **Required:** `preflow/validation` (ErrorBag, model attribute reading, scenarios)
- **Required:** `preflow/view` (TemplateExtensionProvider, HypermediaDriver interface, TemplateFunctionDefinition)
- **No hard dependency** on `preflow/components` or `preflow/htmx` — context detected at runtime

### 1.2 FormBuilder

A lightweight object created per form that carries form state: model reference, action URL, method, ErrorBag, old input, hypermedia context, field template overrides.

#### Template API

```twig
{# Component context — auto-detected #}
{% set form = form_begin({model: user}) %}
  {{ form.field('name') }}
  {{ form.field('email', {type: 'email'}) }}
  {{ form.field('password', {type: 'password'}) }}
  {{ form.submit('Save') }}
{{ form_end() }}

{# Plain page template — explicit action #}
{% set form = form_begin({action: '/contact', method: 'post'}) %}
  {{ form.field('name', {label: 'Your Name', required: true}) }}
  {{ form.field('message', {type: 'textarea', rows: 5}) }}
  {{ form.submit('Send') }}
{{ form_end() }}
```

#### `form_begin(options)` behavior

1. Creates a `FormBuilder` instance
2. If `model` is passed, reads `#[Validate]` attributes to know field rules (filtered by `scenario` if provided)
3. Binds the current `ErrorBag` (from `ValidationExtensionProvider`) and old input
4. Detects component context: if inside a component, picks up component ID and hypermedia driver
5. Outputs opening `<form>` tag with CSRF token and appropriate attributes
6. Returns the builder for field calls

#### `form_end()` behavior

Outputs closing `</form>` tag. Cleans up the current FormBuilder context.

### 1.3 Field Rendering

`form.field(name, options)` renders a complete field block: wrapper, label, input, error message.

#### Default output

```html
<div class="form-group has-error">
  <label for="form-email">Email</label>
  <input type="email" id="form-email" name="email" value="old-value" class="form-control is-invalid">
  <div class="form-error">Please enter a valid email address.</div>
</div>
```

#### Field options

| Option | Description |
|--------|-------------|
| `type` | Input type: `text`, `email`, `password`, `number`, `tel`, `url`, `date`, `datetime-local`, `hidden`, `textarea`, `file` |
| `label` | Label text. Auto-generated from field name if omitted (e.g., `min_players` becomes `Min Players`) |
| `required` | Shows required indicator. Auto-detected from `#[Validate('required')]` when model-bound |
| `value` | Explicit value. Falls back to old input, then model property value |
| `help` | Help text displayed below the input |
| `attrs` | Raw HTML attributes passed directly to the input element (escape hatch) |
| `wrapper` | Custom field template path (overrides form and global defaults) |
| `width` | Width hint for use inside groups (e.g., `'1/3'`, `'2/3'`, `'1/2'`) |
| `validate` | `false` to disable inline validation for this field. Default: `true` when hypermedia driver is active |
| `validate_on` | Trigger for inline validation. Default: `'blur'` |

#### Specialized field methods

- `form.select(name, {options: items, ...})` — `<select>` dropdown
- `form.checkbox(name, options)` — single checkbox
- `form.radio(name, {options: items, ...})` — radio group
- `form.file(name, options)` — file input
- `form.hidden(name, value)` — hidden input (no label/wrapper)
- `form.submit(label, options)` — submit button

### 1.4 Field Groups

Groups wrap multiple fields for layout purposes. No effect on validation or data binding — purely structural.

```twig
{{ form.group({class: 'form-row'}) }}
  {{ form.field('zip', {width: '1/3'}) }}
  {{ form.field('city', {width: '2/3'}) }}
{{ form.end_group() }}

{# With group label #}
{{ form.group({label: 'Address', class: 'form-row'}) }}
  {{ form.field('street', {width: '1/1'}) }}
  {{ form.field('zip', {width: '1/4'}) }}
  {{ form.field('city', {width: '3/4'}) }}
{{ form.end_group() }}
```

Groups can be nested for complex layouts. The group template is overridable at global, per-form, and per-group levels.

### 1.5 Field Templates

The entire field block (wrapper + label + input + error + help text) is rendered by a template. Overridable at three levels:

1. **Global** — set in application config, applies to all forms
2. **Per-form** — passed to `form_begin({field_template: '...'})`, applies to all fields in that form
3. **Per-field** — passed to `form.field('name', {wrapper: '...'})`, overrides just that field

The field template receives full context:

| Variable | Description |
|----------|-------------|
| `name` | Field name |
| `id` | Generated HTML id |
| `type` | Input type |
| `value` | Current value |
| `label` | Label text |
| `required` | Whether the field is required |
| `errors` | Array of error messages for this field |
| `help` | Help text |
| `attrs` | All HTML attributes for the input |
| `input_html` | Pre-rendered input HTML (for templates that only want to customize the wrapper) |
| `width` | Width hint (when inside a group) |

Example custom field template:

```twig
{# Floating label style #}
<div class="form-floating {{ errors ? 'has-error' : '' }}">
  {{ input_html|raw }}
  <label for="{{ id }}">{{ label }}{% if required %} *{% endif %}</label>
  {% if help %}<small class="form-help">{{ help }}</small>{% endif %}
  {% if errors %}
    <div class="form-error">{{ errors|first }}</div>
  {% endif %}
</div>
```

### 1.6 Model Integration & Auto-Generation

#### Model binding

When a model is passed to `form_begin()`:

1. Reads `#[Validate]` attributes to know field rules (filtered by scenario)
2. Populates values from model properties (overridden by old input after failed submission)
3. Binds ErrorBag field errors to corresponding form fields
4. Infers input types from validation rules (see below)

#### Type inference

| Validation Rule | Inferred Type |
|----------------|---------------|
| `email` | `type="email"` |
| `url` | `type="url"` |
| `integer`, `numeric` | `type="number"` |
| `in:a,b,c` | `<select>` with those options |
| `required` | Required indicator on label |
| Property type `bool` | Checkbox |

All inference is overridable per-field.

#### Auto-generation with `form.fields()`

Generates fields for all `#[Validate]` properties on the model:

```twig
{# All fields #}
{{ form.fields() }}

{# Only specific fields #}
{{ form.fields({only: ['name', 'email', 'role']}) }}

{# Exclude fields #}
{{ form.fields({except: ['password_hash', 'created_at']}) }}

{# Override specific fields while auto-generating the rest #}
{{ form.fields({
  only: ['name', 'email', 'role'],
  override: {
    role: {type: 'select', options: roles}
  }
}) }}
```

### 1.7 Validation Scenarios

Addition to `preflow/validation`. The `#[Validate]` attribute gains an optional `on` parameter:

```php
class User extends TypedModel
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|min:8', on: 'create')]
    #[Validate('nullable|min:8', on: 'update')]
    public string $password = '';

    #[Validate('required')]
    public string $name = '';
}
```

Rules without `on` apply to all scenarios. Rules with `on` only apply when that scenario is active.

Usage in forms:

```twig
{% set form = form_begin({model: user, scenario: 'update'}) %}
```

Usage in code:

```php
$validator = $validatorFactory->make($rules, $data, scenario: 'update');
// or
$dataManager->save($user, scenario: 'update');
```

The form-level escape hatch — override rules per-field regardless of model:

```twig
{% set form = form_begin({model: user, rules: {
    password: 'nullable|min:8'
}}) %}
```

### 1.8 Hypermedia Driver Integration

#### HypermediaDriver interface (added to `preflow/view`)

```php
namespace Preflow\View;

interface HypermediaDriver
{
    /**
     * Generate attributes for a form tag.
     */
    public function formAttributes(string $action, string $method, array $options = []): array;

    /**
     * Generate attributes for inline field validation.
     */
    public function inlineValidationAttributes(string $endpoint, string $field, string $trigger = 'blur'): array;

    /**
     * Generate attributes for form submission behavior.
     */
    public function submitAttributes(string $target, array $options = []): array;
}
```

The `preflow/htmx` package provides the HTMX implementation. A future Datastar package would provide its own.

#### Context detection

When FormBuilder renders, it checks:

1. Is there a component ID in the template context?
2. Is a `HypermediaDriver` registered in the container?

If both: form and fields are automatically enhanced with driver attributes.
If not: standard HTML forms with `method`/`action`.

No configuration needed — detection is automatic.

#### Enhanced output example (HTMX driver)

```html
<input type="email" id="form-email" name="email" value=""
       hx-post="/hd/App.Components.UserForm/validateField"
       hx-trigger="blur"
       hx-target="closest .form-group"
       hx-swap="outerHTML">
```

#### Escape hatch

The `attrs` parameter on any field or form passes raw HTML attributes directly, bypassing the driver. If an `attrs` key collides with a driver-generated attribute, the explicit value wins:

```twig
{# Driver-specific overrides — user intent always wins #}
{{ form.field('search', {
  attrs: {
    'hx-get': '/search/results',
    'hx-trigger': 'keyup changed delay:300ms',
    'hx-target': '#search-results',
    'hx-swap': 'innerHTML',
    'hx-indicator': '#spinner'
  }
}) }}

{# Form-level driver attributes #}
{% set form = form_begin({model: user, attrs: {
  'hx-boost': 'true',
  'hx-push-url': 'true'
}}) %}
```

### 1.9 FormExtensionProvider

Implements `TemplateExtensionProvider`. Registers:

- `form_begin(options)` — creates FormBuilder, outputs `<form>` tag
- `form_end()` — closes form, cleans up builder context

The FormBuilder instance returned by `form_begin()` provides all field methods via Twig method calls.

### 1.10 Package Structure

```
packages/form/
  composer.json
  src/
    FormBuilder.php           — Core builder: field rendering, model binding, context detection
    FormExtensionProvider.php — Template function registration
    FieldRenderer.php         — Renders individual field blocks using templates
    GroupRenderer.php         — Renders field groups
    ModelIntrospector.php     — Reads #[Validate] attributes, infers types, filters by scenario
    templates/
      field.twig              — Default field template
      group.twig              — Default group template
  tests/
    FormBuilderTest.php
    FieldRendererTest.php
    ModelIntrospectorTest.php
```

---

## 2. Color Helpers — `preflow/core`

Added as `Preflow\Core\Helpers\Color`:

```php
Color::lighten('#FF9E1B', 0.2): string
Color::darken('#FF9E1B', 0.2): string
Color::hexToRgb('#FF9E1B'): array  // [r, g, b]
Color::rgbToHex(255, 158, 27): string
Color::contrastRatio('#FF9E1B', '#FFFFFF'): float  // WCAG 2.1
Color::luminance('#FF9E1B'): float
Color::adjustForContrast('#FF9E1B', '#FFFFFF', 4.5): string  // Meet minimum ratio
```

Registered as template functions via `HelpersExtensionProvider` in `preflow/core`:

- `color_lighten(hex, percent)`
- `color_darken(hex, percent)`
- `color_contrast(color1, color2)`
- `color_adjust_contrast(text_color, bg_color, min_ratio)`

---

## 3. Responsive Image Helper — `preflow/view`

### 3.1 ImageUrlTransformer interface

```php
namespace Preflow\View;

interface ImageUrlTransformer
{
    public function transform(string $path, int $width, string $format, int $quality): string;
}
```

Ships with `PathBasedTransformer` that appends query parameters (`?w=480&fm=webp&q=75`). Works with Glide, BunnyCDN, Cloudflare, or any URL-based transform service.

Default (no transformer registered): returns the original path unchanged.

### 3.2 Template functions

#### `responsive_image(path, options)`

Outputs a complete `<img>` tag with `srcset` and `sizes`:

```twig
{{ responsive_image('/uploads/hero.jpg', {
  preset: 'hero',
  alt: 'Welcome',
  widths: [480, 768, 1024, 1600],
  sizes: '100vw',
  loading: 'lazy',
  class: 'img-fluid'
}) }}
```

Output:

```html
<img src="/uploads/hero.jpg?w=1600&fm=webp&q=75"
     srcset="/uploads/hero.jpg?w=480&fm=webp&q=75 480w,
            /uploads/hero.jpg?w=768&fm=webp&q=75 768w,
            /uploads/hero.jpg?w=1024&fm=webp&q=75 1024w,
            /uploads/hero.jpg?w=1600&fm=webp&q=75 1600w"
     sizes="100vw"
     alt="Welcome"
     loading="lazy"
     class="img-fluid">
```

#### `image_srcset(path, options)`

Returns just the srcset string — for use inside `<picture>` elements:

```twig
<picture>
  <source type="image/avif"
          srcset="{{ image_srcset('/uploads/hero.jpg', {format: 'avif', widths: [480, 768, 1024]}) }}">
  <source type="image/webp"
          srcset="{{ image_srcset('/uploads/hero.jpg', {format: 'webp', widths: [480, 768, 1024]}) }}">
  {{ responsive_image('/uploads/hero.jpg', {alt: 'Welcome', preset: 'hero'}) }}
</picture>
```

### 3.3 Presets

Configurable via application config with sensible defaults:

| Preset | Widths | Sizes |
|--------|--------|-------|
| `hero` | 480, 768, 1024, 1600, 2000 | `100vw` |
| `card` | 300, 600, 900 | `(max-width: 767px) 100vw, (max-width: 991px) 50vw, 33.333vw` |
| `thumbnail` | 120, 240 | `120px` |
| `content` | 400, 800, 1200 | `(max-width: 767px) 100vw, 50vw` |

### 3.4 Image options

| Option | Description | Default |
|--------|-------------|---------|
| `preset` | Named preset | `null` |
| `widths` | Array of widths for srcset | `[480, 768, 1024]` |
| `sizes` | Sizes attribute | `100vw` |
| `format` | Image format | `webp` |
| `quality` | Compression quality | `75` |
| `alt` | Alt text | `''` |
| `class` | CSS class | `''` |
| `loading` | Loading strategy | `lazy` |
| `width` | Intrinsic width (for aspect ratio) | `null` |
| `height` | Intrinsic height (for aspect ratio) | `null` |
| `attrs` | Raw HTML attributes | `[]` |

---

## 4. Supporting Changes Summary

| Package | Change |
|---------|--------|
| `preflow/validation` | `#[Validate]` gains `on` parameter for scenarios. `Validator` and `DataManager::save()` accept scenario string. |
| `preflow/view` | Add `HypermediaDriver` interface. Add `ImageUrlTransformer` interface. Add `responsive_image()` and `image_srcset()` template functions. |
| `preflow/core` | Add `Preflow\Core\Helpers\Color` class. Add `HelpersExtensionProvider` with color template functions. Register provider in `Application::bootViewLayer()`. |
| `preflow/htmx` | Implement `HypermediaDriver` interface for HTMX-specific attribute generation. |

---

## What This Design Does NOT Include

- **Shareable UI components** (buttons, cards, etc.) — deferred to a future `preflow/ui` package
- **Client-side validation** — not needed, server-side via hypermedia
- **Full HTML tag helpers** (Yii2 `Html::a()`, `Html::tag()` style) — not needed, write HTML directly
- **Asset/upload management** — application-level concern
- **Image processing** — the helper builds URLs, processing is handled by the transform service (Glide, CDN, etc.)
