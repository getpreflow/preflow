# Preflow Validation Package Design

**Date:** 2026-04-15
**Package:** `preflow/validation`
**Namespace:** `Preflow\Validation`
**Status:** Approved design, pending implementation

## Overview

A standalone validation package for Preflow that works at three levels:

1. **Standalone** -- validate arbitrary data with arbitrary rules, no model involvement
2. **Declarative** -- rules declared on typed models (via `#[Validate]` attribute) and dynamic models (via JSON `"validate"` key) are automatically collected
3. **Integrated** -- `DataManager` auto-validates before save/insert/update using declared rules, with full bypass and override control

The validation engine is a single system. Model declarations are just one source of rules that feed into it -- not a separate mechanism.

## Package Boundary

`preflow/validation` is a standalone package with no dependency on `preflow/data`. It contains the engine, rule interface, built-in rules, result objects, error bag, and template helpers.

`preflow/data` depends on `preflow/validation`. The data package contains the integration layer: reading `#[Validate]` attributes from `ModelMetadata`, reading `"validate"` keys from `TypeDefinition`, and calling the validator during `DataManager` save operations.

```
preflow/validation (standalone)
    ^
    |  depends on
    |
preflow/data (integration layer)
```

## Core Interfaces

### ValidationRule

Every rule -- built-in or custom -- implements this interface:

```php
namespace Preflow\Validation;

interface ValidationRule
{
    /**
     * Validate a value.
     *
     * @return true|string True on success, error message string on failure
     */
    public function validate(mixed $value, ValidationContext $context): true|string;
}
```

### ValidationContext

Carries contextual information for cross-field and cross-model validation:

```php
namespace Preflow\Validation;

final class ValidationContext
{
    public function __construct(
        public readonly string $field,           // Current field name
        public readonly array $data,             // All data being validated
        public readonly mixed $subject = null,   // Optional: model instance, record, etc.
    ) {}

    /**
     * Get another field's value from the data being validated.
     */
    public function getValue(string $field): mixed
    {
        return $this->data[$field] ?? null;
    }
}
```

The `subject` is the model instance or `DynamicRecord` when validation is triggered from `DataManager`. It's `null` for standalone validation. Custom validators that need the model (e.g., uniqueness check excluding current ID) read it from here.

### Validator

The engine. Accepts data and rules, runs validation, returns a result:

```php
namespace Preflow\Validation;

final class Validator
{
    /**
     * @param array<string, list<ValidationRule|string>> $rules Field => rules
     * @param array<string, mixed> $data Field => value
     */
    public function __construct(
        private RuleFactory $ruleFactory,
        private array $rules,
        private array $data,
        private mixed $subject = null,
    ) {}

    public function validate(): ValidationResult;
}
```

### ValidatorFactory

Convenience factory for creating `Validator` instances with the `RuleFactory` pre-wired. Registered in the container:

```php
namespace Preflow\Validation;

final class ValidatorFactory
{
    public function __construct(private RuleFactory $ruleFactory) {}

    public function make(array $rules, array $data, mixed $subject = null): Validator
    {
        return new Validator($this->ruleFactory, $rules, $data, $subject);
    }
}
```

Rules per field are processed in order. If a `nullable` rule is present and the value is null, the chain stops early (passes). If a `required` rule fails, remaining rules for that field are skipped.

### ValidationResult (Thin Layer)

Pure data object, no template awareness:

```php
namespace Preflow\Validation;

final class ValidationResult
{
    public function passes(): bool;
    public function fails(): bool;

    /** @return array<string, list<string>> Field => error messages */
    public function errors(): array;

    /** @return list<string> Error messages for a specific field */
    public function fieldErrors(string $field): array;

    /** First error message for a field, or null */
    public function firstError(string $field): ?string;

    /** Flat list of all error messages */
    public function all(): array;
}
```

### ErrorBag (Rich Layer)

Wraps `ValidationResult` with convenience methods for templates and components:

```php
namespace Preflow\Validation;

final class ErrorBag
{
    public function __construct(private ValidationResult $result) {}

    public function has(string $field): bool;
    public function first(string $field): ?string;
    public function get(string $field): array;
    public function all(): array;
    public function count(): int;
    public function isEmpty(): bool;
    public function toArray(): array;
    public function getResult(): ValidationResult;
}
```

## Built-in Rules

Ship with the package, referenced by string shorthand:

| Shorthand | Class | Behavior |
|---|---|---|
| `required` | `Rules\Required` | Not null, not empty string, not empty array |
| `nullable` | `Rules\Nullable` | Stops chain if value is null (passes) |
| `email` | `Rules\Email` | Valid email format (filter_var) |
| `url` | `Rules\Url` | Valid URL format |
| `numeric` | `Rules\Numeric` | Is numeric |
| `integer` | `Rules\IsInteger` | Is integer |
| `min:N` | `Rules\MinLength` | String length >= N or numeric value >= N |
| `max:N` | `Rules\MaxLength` | String length <= N or numeric value <= N |
| `between:N,M` | `Rules\Between` | Value within range (inclusive) |
| `in:a,b,c` | `Rules\InList` | Value is one of the listed options |
| `regex:/pattern/` | `Rules\Regex` | Matches regex pattern |
| `confirmed` | `Rules\Confirmed` | Field matches `{field}_confirmation` (cross-field via context) |

All built-in rules implement `ValidationRule`. No special treatment -- they're resolved through `RuleFactory` like any other rule.

## RuleFactory & Rule Resolution

`RuleFactory` resolves rule strings to `ValidationRule` instances:

```php
namespace Preflow\Validation;

final class RuleFactory
{
    public function __construct(
        private ContainerInterface $container,
    ) {}

    /**
     * Resolve a rule string or instance to a ValidationRule.
     *
     * Resolution order:
     * 1. Already a ValidationRule instance -- return as-is
     * 2. App-level rules (discovered from app/Rules/ or registered via config)
     * 3. Package/plugin rules (registered via service provider)
     * 4. Built-in rules (required, email, min, etc.)
     * 5. FQCN fallback -- resolve class from container
     */
    public function resolve(string|ValidationRule $rule): ValidationRule;

    /**
     * Register a rule class under an alias. Later registrations override earlier ones.
     */
    public function register(string $alias, string $ruleClass): void;
}
```

### Parameterized rules

`min:3` is parsed as alias `min` with parameter `3`. The parameter is passed to the rule constructor. For class-based rules resolved from the container, constructor parameters are split into:
- **Services** -- type-hinted dependencies, injected by the container
- **Configuration** -- scalar values from the rule declaration, passed as remaining constructor arguments

### Custom Rule Aliases

Custom rules can declare a shorthand alias via the `#[RuleAlias]` attribute:

```php
namespace Preflow\Validation;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class RuleAlias
{
    public function __construct(public readonly string $alias) {}
}
```

Usage:

```php
use Preflow\Validation\RuleAlias;
use Preflow\Validation\ValidationRule;

#[RuleAlias('valid-zip-code')]
final class ValidZipCode implements ValidationRule
{
    public function __construct(private HttpClient $http) {}

    public function validate(mixed $value, ValidationContext $context): true|string
    {
        // Call ZIP validation API
        // Return true or 'Invalid ZIP code'
    }
}
```

This rule is now usable as `'valid-zip-code'` in both typed model attributes and dynamic model JSON schemas.

### Discovery & Override

Rule discovery follows Preflow's existing auto-discovery pattern:

1. **Auto-discovery** -- classes in `app/Rules/` implementing `ValidationRule` are scanned for `#[RuleAlias]` attributes and registered automatically
2. **Explicit registration** -- additional rules can be registered in `config/validation.php` or via a service provider
3. **Override** -- app-level rules shadow package rules, which shadow built-ins. Same alias at a higher level wins.

Resolution priority (highest to lowest):
1. App-level rules (`app/Rules/` auto-discovery + config registration)
2. Package/plugin rules (registered via service providers)
3. Built-in rules (`required`, `email`, etc.)

This means a developer can override any built-in by placing a class with the same alias in `app/Rules/`.

## Model Integration: Typed Models

### #[Validate] Attribute

New attribute for typed model properties:

```php
namespace Preflow\Validation\Attributes;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
final class Validate
{
    /** @var list<string> */
    public readonly array $rules;

    public function __construct(string ...$rules)
    {
        $this->rules = $rules;
    }
}
```

Usage on a model:

```php
use Preflow\Data\Model;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Attributes\Field;
use Preflow\Validation\Attributes\Validate;

#[Entity(table: 'games', storage: 'sqlite')]
final class Game extends Model
{
    #[Id]
    public int $id = 0;

    #[Field(searchable: true)]
    #[Validate('required', 'min:3', 'max:255')]
    public string $name = '';

    #[Field]
    #[Validate('required', 'email')]
    public string $contact_email = '';

    #[Field]
    #[Validate('nullable', 'integer', 'between:1,10')]
    public ?int $complexity = null;

    #[Field]
    #[Validate('required', 'valid-zip-code')]
    public string $zip = '';

    #[Field]
    #[Validate('required', 'in:draft,published,archived')]
    public string $status = 'draft';
}
```

### rules() Escape Hatch

For context-dependent rules that can't be expressed in attributes:

```php
final class Game extends Model
{
    // ... #[Validate] attributes as above ...

    public function rules(): array
    {
        return [
            'slug' => ['required', new Unique('games', 'slug', excludeId: $this->id)],
        ];
    }
}
```

Rules from `rules()` are merged with attribute-declared rules. If both declare rules for the same field, `rules()` wins (more specific overrides more general).

### ModelMetadata Extension

`ModelMetadata` in `preflow/data` gains a `validationRules()` method that collects `#[Validate]` attributes from all properties and returns them as a normalized `['field' => ['rule1', 'rule2', ...]]` array. This is the bridge between the data package's reflection system and the validation engine.

## Model Integration: Dynamic Models

### JSON Schema "validate" Key

The `"validate"` key is added to field definitions in JSON model schemas:

```json
{
    "key": "event",
    "table": "events",
    "storage": "sqlite",
    "fields": {
        "title": {
            "type": "string",
            "searchable": true,
            "validate": ["required", "min:3"]
        },
        "capacity": {
            "type": "integer",
            "validate": ["required", "integer", "min:1"]
        },
        "zip": {
            "type": "string",
            "validate": ["required", "valid-zip-code"]
        },
        "status": {
            "type": "string",
            "validate": ["required", "in:active,cancelled,completed"]
        }
    }
}
```

### TypeFieldDefinition Extension

`TypeFieldDefinition` gains a `validate` property (array of rule strings). `TypeDefinition` gains a `validationRules()` method that collects rules from all fields, mirroring `ModelMetadata::validationRules()`.

## DataManager Integration

### Auto-validation on Save

`DataManager::save()`, `insert()`, and `update()` auto-validate before persisting:

```php
public function save(Model $model, bool $validate = true, array $rules = []): void
{
    if ($validate) {
        $this->validateModel($model, $rules);
    }
    // ... existing save logic ...
}

private function validateModel(Model $model, array $extraRules = []): void
{
    $meta = ModelMetadata::for($model::class);
    $rules = $meta->validationRules();

    if (method_exists($model, 'rules')) {
        $rules = array_merge($rules, $model->rules());
    }

    if (!empty($extraRules)) {
        $rules = array_merge($rules, $extraRules);
    }

    if (empty($rules)) {
        return;
    }

    $validator = $this->validatorFactory->make($rules, $model->toArray(), subject: $model);
    $result = $validator->validate();

    if ($result->fails()) {
        throw new ValidationException($result);
    }
}
```

Same pattern for `saveType()` with `DynamicRecord` -- rules come from `TypeDefinition::validationRules()`.

### Bypass & Override

Three levels of control on every save/insert/update call:

1. **Skip validation entirely:** `$dm->save($model, validate: false)`
2. **Add extra rules at call time:** `$dm->save($model, rules: ['slug' => [new Unique(...)]])`
3. **Validate manually, then save without auto-validation:**
   ```php
   $result = $validator->validate($data, $rules);
   if ($result->passes()) {
       $model->fill($data);
       $dm->save($model, validate: false);
   }
   ```

### ValidationException

Thrown when auto-validation fails. Carries the full `ValidationResult`:

```php
namespace Preflow\Validation;

final class ValidationException extends \RuntimeException
{
    public function __construct(private ValidationResult $result)
    {
        parent::__construct('Validation failed.');
    }

    public function result(): ValidationResult
    {
        return $this->result;
    }

    public function errors(): array
    {
        return $this->result->errors();
    }

    public function errorBag(): ErrorBag
    {
        return new ErrorBag($this->result);
    }
}
```

## Template Integration

### Template Functions

Registered in the view layer (Twig/Blade), following the same pattern as `csrf_token()` and `asset_url()`:

| Function | Returns | Purpose |
|---|---|---|
| `validation_errors(field)` | `?string` | First error message for field, or null |
| `validation_has_errors(field)` | `bool` | Whether field has any errors |
| `old(field, default?)` | `mixed` | Previous input value for form re-population |

These read from a request-scoped `ErrorBag` set by the component or controller.

### Component Usage Pattern

```php
public function handleAction(string $action, array $params): mixed
{
    $result = $this->validator->validate($params, $this->rules());
    if ($result->fails()) {
        $this->errors = new ErrorBag($result);
        $this->oldInput = $params;
        return null;  // re-renders with errors
    }
    // ... proceed with save
}
```

```twig
<form>
    <label for="email">Email</label>
    <input type="email" name="email" value="{{ old('email') }}"
           class="{{ validation_has_errors('email') ? 'is-invalid' : '' }}">
    {% if validation_has_errors('email') %}
        <span class="error">{{ validation_errors('email') }}</span>
    {% endif %}
</form>
```

### HTMX Inline Validation

No special infrastructure. Standard component action that validates a single field:

```php
#[Action]
public function validateField(string $field, string $value): string
{
    $rules = $this->rules()[$field] ?? [];
    if (empty($rules)) {
        return '';
    }
    $result = $this->validator->validate([$field => $value], [$field => $rules]);
    return $result->fails() ? $result->firstError($field) : '';
}
```

```twig
<input type="email" name="email"
       hx-post="{{ action_url('validateField') }}"
       hx-vals='{"field": "email", "value": this.value}'
       hx-target="#email-error"
       hx-trigger="blur changed">
<span id="email-error">{{ validation_errors('email') }}</span>
```

## Custom Validator Examples

### Unique (Database-Aware)

```php
use Preflow\Validation\ValidationRule;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\RuleAlias;
use Preflow\Data\DataManager;

#[RuleAlias('unique')]
final class Unique implements ValidationRule
{
    public function __construct(
        private DataManager $dm,
        private string $table,
        private string $column,
        private string|int|null $excludeId = null,
    ) {}

    public function validate(mixed $value, ValidationContext $context): true|string
    {
        $results = $this->dm->raw(
            "SELECT COUNT(*) as cnt FROM {$this->table} WHERE {$this->column} = ?",
            [$value],
        );

        $count = (int) ($results[0]['cnt'] ?? 0);

        if ($this->excludeId !== null) {
            $results = $this->dm->raw(
                "SELECT COUNT(*) as cnt FROM {$this->table} WHERE {$this->column} = ? AND id != ?",
                [$value, $this->excludeId],
            );
            $count = (int) ($results[0]['cnt'] ?? 0);
        }

        return $count === 0 ? true : "This {$this->column} is already taken.";
    }
}
```

### ValidZipCode (External API)

```php
use Preflow\Validation\ValidationRule;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\RuleAlias;

#[RuleAlias('valid-zip-code')]
final class ValidZipCode implements ValidationRule
{
    public function __construct(private HttpClient $http) {}

    public function validate(mixed $value, ValidationContext $context): true|string
    {
        $response = $this->http->get("https://api.example.com/zip/{$value}");

        if ($response->status() === 200) {
            return true;
        }

        return 'Invalid ZIP code.';
    }
}
```

### Configuration Validator (Cross-Field)

```php
use Preflow\Validation\ValidationRule;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\RuleAlias;

#[RuleAlias('valid-player-range')]
final class ValidPlayerRange implements ValidationRule
{
    public function validate(mixed $value, ValidationContext $context): true|string
    {
        $min = $context->getValue('min_players');
        $max = $context->getValue('max_players');

        if ($min !== null && $max !== null && $min > $max) {
            return 'Minimum players cannot exceed maximum players.';
        }

        return true;
    }
}
```

## File Structure

```
packages/validation/
    composer.json
    src/
        ValidationRule.php          # Interface
        ValidationContext.php        # Context object
        Validator.php               # Engine
        ValidationResult.php        # Thin result
        ErrorBag.php                # Rich result wrapper
        ValidationException.php     # Exception with result
        RuleFactory.php             # Alias/FQCN resolution
        ValidatorFactory.php        # Convenience factory (container-wired)
        RuleAlias.php               # Attribute for custom aliases
        Attributes/
            Validate.php            # #[Validate] attribute for model properties
        Rules/
            Required.php
            Nullable.php
            Email.php
            Url.php
            Numeric.php
            IsInteger.php
            MinLength.php
            MaxLength.php
            Between.php
            InList.php
            Regex.php
            Confirmed.php
    tests/
        ValidatorTest.php
        ValidationResultTest.php
        ErrorBagTest.php
        RuleFactoryTest.php
        Rules/
            RequiredTest.php
            EmailTest.php
            MinLengthTest.php
            ... (one per rule)

packages/data/
    src/
        # Modified files:
        DataManager.php             # Add auto-validation in save/insert/update
        ModelMetadata.php           # Add validationRules() method
        TypeDefinition.php          # Add validationRules() method
        TypeFieldDefinition.php     # Add validate property
```

## Testing Strategy

- Each built-in rule gets its own test class with edge cases (null, empty string, type coercion, boundary values)
- `Validator` tests cover: single field, multi-field, cross-field rules, nullable chain stop, required chain stop, custom rule instances, mixed string/instance rules
- `RuleFactory` tests cover: built-in resolution, alias resolution, FQCN resolution, parameterized rules, override priority
- `DataManager` integration tests cover: auto-validation on save, validation bypass, extra rules at call time, `rules()` method merge, dynamic record validation
- `ErrorBag` tests cover: all convenience methods, empty state

## Future Considerations

- **Event system (Approach C):** When Preflow gains a lifecycle event system, auto-validation can move from direct `DataManager` integration to a `BeforeSave` listener. The validation package itself won't change -- only the trigger point.
- **Async validation:** For expensive validators (API calls), a future `AsyncValidationRule` interface could return promises. Not needed now.
- **Conditional rules:** Rules that only apply in certain contexts (e.g., `required` only on create, not update). Could be handled via `rules()` method checking `$this->id` for now.
- **Nested/array validation:** Validating nested data structures (e.g., `items.*.price`). Not in scope for v1.
