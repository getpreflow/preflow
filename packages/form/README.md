# preflow/form

Form builder for Preflow. Renders fields with labels, errors, and help text from a simple template API. Binds to models, reads `#[Validate]` attributes for type inference and required detection, and auto-enhances forms with inline validation when a hypermedia driver is active.

## Installation

```bash
composer require preflow/form
```

Requires `preflow/validation` and `preflow/view`. Optionally integrates with `preflow/data` (model binding) and `preflow/htmx` (inline validation).

## Quick Start

### Plain form (no model)

```twig
{% set form = form_begin({action: '/contact', csrf_token: csrf_token()}) %}
{{ form.begin()|raw }}
    {{ form.field('name')|raw }}
    {{ form.field('email', {type: 'email'})|raw }}
    {{ form.field('message', {type: 'textarea'})|raw }}
    {{ form.submit('Send')|raw }}
{{ form_end()|raw }}
```

### Model-bound form (inside a component)

```twig
{% set form = form_begin({model: job, errorBag: errors}) %}
<form {{ hd.post('save', componentClass, componentId, props)|raw }}>
    {{ form.field('name')|raw }}
    {{ form.select('status', {options: {draft: 'Draft', published: 'Published'}})|raw }}
    {{ form.field('description', {type: 'textarea'})|raw }}
    {{ form.submit('Save')|raw }}
</form>
```

The form builder reads `#[Validate]` attributes from the model to infer input types (`email` rule = `type="email"`), detect required fields, and populate select options from `in:` rules. A form without a model does the same from its own `rules:` option (since 0.84.2): `form_begin({rules: {email: ['required', 'email']}})` renders `type="email"`.

## Field Methods

| Method | Description |
|--------|-------------|
| `form.field(name, options)` | Text, email, password, number, date, url, tel, hidden |
| `form.select(name, options)` | Dropdown with `options` map |
| `form.checkbox(name, options)` | Single checkbox |
| `form.radio(name, options)` | One radio button (a group is one call per button, see below) |
| `form.file(name, options)` | File upload input |
| `form.hidden(name, value)` | Hidden input (no label/wrapper) |
| `form.submit(label, options)` | Submit button |

A radio button's `value` is the value it sends; it is checked only when you say so. A group
is one call per button, each with its own `id`:

```twig
{% for key, label in {draft: 'Draft', published: 'Published'} %}
    {{ form.radio('status', {value: key, id: 'status-' ~ key, label: label, attrs: {checked: job.status == key}})|raw }}
{% endfor %}
```

## Field Options

| Option | Description |
|--------|-------------|
| `type` | Input type (auto-inferred from model rules if bound) |
| `label` | Label text (auto-generated from field name if omitted) |
| `value` | Field value; without it: the old input, then the model property |
| `required` | Required indicator (auto-detected from `required` rule) |
| `help` | Help text below the field |
| `errors` | Error messages (auto-populated from ErrorBag) |
| `attrs` | Raw HTML attributes passed to the input (escape hatch) |
| `call` | A short call for the control, like `hd.post(...)` (see below) |
| `width` | Width hint for use inside groups (`'1/3'`, `'2/3'`) |
| `options` | Key-value map for select fields |

## A short call on a field

A field's `call` option takes what a short call returns (`hd.post(...)` from preflow/htmx). The
form builder writes it, as it is, into the control's opening tag. This works for every control:
input, textarea, select and checkbox.

```twig
{{ form.field('email', {
    type: 'email',
    call: hd.post('check', {field: 'email'}, {on: 'blur', target: 'email'})
})|raw }}
```

When the visitor leaves the email field, the component's `check` action runs with `field: 'email'`.

In Blade, take the builder as a value first (`@php($form = $fn->form_begin([...]))`, then `{!! $form->begin() !!}`; `@form_begin(...)` can't print a builder and throws). Then it is the same call: `$form->field('email', ['call' => $hd->post('check', ['field' => 'email'], ['on' => 'blur', 'target' => 'email'])])`.

Some rules:

- `call` takes only a short call's result (a `Preflow\View\SafeHtml`). A string or a map is
  refused with an `InvalidArgumentException`. Plain attributes go in `attrs`.
- `attrs` works next to `call`. But an attribute may be written only once. If `attrs` sets an
  attribute the call writes too (say `hx-trigger`), the field is refused, and the message names it.
  Leave it out of `attrs`, or set it to `false` there.
- A form with inline validation (see below) writes `hx-*` attributes on each field itself. A field
  with a call then needs `validate: false`, or it is refused.

## Field Groups

Group fields for side-by-side layout:

```twig
{{ form.group({class: 'form-row', label: 'Address'})|raw }}
    {{ form.field('zip', {width: '1/3'})|raw }}
    {{ form.field('city', {width: '2/3'})|raw }}
{{ form.endGroup()|raw }}
```

## Auto-Generation

Generate fields from model `#[Validate]` attributes:

```twig
{# All validated fields #}
{{ form.fields()|raw }}

{# Cherry-pick #}
{{ form.fields({only: ['name', 'email']})|raw }}

{# Exclude #}
{{ form.fields({except: ['password_hash', 'created_at']})|raw }}

{# Override specific fields #}
{{ form.fields({only: ['name', 'email', 'role'], overrides: {role: {type: 'select', options: roles}}})|raw }}
```

## Validation Scenarios

Override rules per form for create/update differences:

```twig
{% set form = form_begin({
    model: user,
    rules: isEdit
        ? {password: ['nullable', 'min:8']}
        : {password: ['required', 'min:8']}
}) %}
```

Or use `on:` directives on the model:

```php
#[Validate('required', 'min:8', 'on:create')]
#[Validate('nullable', 'min:8', 'on:update')]
public string $password = '';
```

```twig
{% set form = form_begin({model: user, scenario: 'create'}) %}
```

## Hypermedia Integration

When inside a component with a hypermedia driver active, the form builder can auto-add inline validation attributes. The driver is the app's `HypermediaDriver` (htmx, or the class `app.hypermedia` names) when it also implements `Preflow\View\FormHypermediaDriver`; otherwise forms are plain. The `attrs` escape hatch lets you pass any driver-specific attributes:

```twig
{{ form.field('email', {
    attrs: {
        'hx-post': validateUrl,
        'hx-trigger': 'blur delay:300ms',
        'hx-target': 'closest .form-group',
        'hx-swap': 'outerHTML'
    }
})|raw }}
```

## License

MIT
