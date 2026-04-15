# Validation Package Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a standalone `preflow/validation` package with declarative model integration, container-resolved custom validators, and layered error results.

**Architecture:** Standalone `Preflow\Validation` package containing the engine, rules, and result objects. `preflow/data` gains a thin integration layer that reads `#[Validate]` attributes and JSON `"validate"` keys, then calls into the validation engine during `DataManager` save operations. Custom validators are resolved through the DI container for dependency injection.

**Tech Stack:** PHP 8.4+, PHPUnit 11, Preflow Core container, existing Preflow attribute/reflection patterns

**Spec:** `docs/superpowers/specs/2026-04-15-validation-package-design.md`

---

## File Structure

### New files (packages/validation/)

```
packages/validation/
    composer.json
    src/
        ValidationRule.php              # Interface — all rules implement this
        ValidationContext.php            # Context passed to rules (field, data, subject)
        Validator.php                   # Engine — runs rules against data
        ValidatorFactory.php            # Container-wired factory for Validator
        ValidationResult.php            # Thin result object (field => messages)
        ErrorBag.php                    # Rich wrapper with template convenience
        ValidationException.php         # Exception carrying ValidationResult
        RuleFactory.php                 # Resolves aliases/FQCNs to rule instances
        RuleAlias.php                   # #[RuleAlias] attribute for custom rules
        Attributes/
            Validate.php                # #[Validate] attribute for model properties
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
        ValidationRuleContractTest.php  # Tests each built-in rule
        ValidatorTest.php               # Engine tests
        ValidationResultTest.php        # Result object tests
        ErrorBagTest.php                # ErrorBag tests
        RuleFactoryTest.php             # Resolution and override tests
        DataManagerValidationTest.php   # Integration with DataManager
```

### Modified files

```
packages/data/composer.json             # Add preflow/validation dependency
packages/data/src/ModelMetadata.php     # Add validationRules() method
packages/data/src/TypeFieldDefinition.php # Add validate property
packages/data/src/TypeDefinition.php    # Add validationRules() method
packages/data/src/TypeRegistry.php      # Read "validate" key from JSON
packages/data/src/DataManager.php       # Auto-validate on save/insert/update
composer.json                           # Add validation path repository + require-dev
phpunit.xml                             # Add Validation test suite
.github/workflows/split.yml            # Add validation to split matrix
```

---

### Task 1: Package Scaffold & Core Interface

**Files:**
- Create: `packages/validation/composer.json`
- Create: `packages/validation/src/ValidationRule.php`
- Create: `packages/validation/src/ValidationContext.php`
- Modify: `composer.json` (root)
- Modify: `phpunit.xml`

- [ ] **Step 1: Create the package composer.json**

```json
{
    "name": "preflow/validation",
    "description": "Preflow validation — rule engine, built-in rules, error bags",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.4"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0"
    },
    "autoload": {
        "psr-4": {
            "Preflow\\Validation\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Preflow\\Validation\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 2: Create ValidationRule interface**

```php
<?php

declare(strict_types=1);

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

- [ ] **Step 3: Create ValidationContext**

```php
<?php

declare(strict_types=1);

namespace Preflow\Validation;

final readonly class ValidationContext
{
    /**
     * @param array<string, mixed> $data All data being validated
     */
    public function __construct(
        public string $field,
        public array $data,
        public mixed $subject = null,
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

- [ ] **Step 4: Register package in root composer.json**

Add to the `repositories` array in `composer.json`:
```json
{
    "type": "path",
    "url": "packages/validation",
    "options": { "symlink": true }
}
```

Add to `require-dev`:
```json
"preflow/validation": "@dev"
```

Add to `autoload-dev.psr-4`:
```json
"Preflow\\Validation\\Tests\\": "packages/validation/tests/"
```

- [ ] **Step 5: Add Validation test suite to phpunit.xml**

Add inside `<testsuites>`:
```xml
<testsuite name="Validation">
    <directory>packages/validation/tests</directory>
</testsuite>
```

Add inside `<source><include>`:
```xml
<directory>packages/validation/src</directory>
```

- [ ] **Step 6: Run composer update to wire the package**

Run: `composer update preflow/validation --prefer-source`
Expected: Package symlinked, autoloader updated

- [ ] **Step 7: Commit**

```bash
git add packages/validation/composer.json packages/validation/src/ValidationRule.php packages/validation/src/ValidationContext.php composer.json phpunit.xml
git commit -m "feat(validation): package scaffold with ValidationRule interface and ValidationContext"
```

---

### Task 2: ValidationResult & ErrorBag

**Files:**
- Create: `packages/validation/src/ValidationResult.php`
- Create: `packages/validation/src/ErrorBag.php`
- Create: `packages/validation/src/ValidationException.php`
- Create: `packages/validation/tests/ValidationResultTest.php`
- Create: `packages/validation/tests/ErrorBagTest.php`

- [ ] **Step 1: Write failing tests for ValidationResult**

```php
<?php

declare(strict_types=1);

namespace Preflow\Validation\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Validation\ValidationResult;

final class ValidationResultTest extends TestCase
{
    public function test_passes_with_no_errors(): void
    {
        $result = new ValidationResult([]);

        $this->assertTrue($result->passes());
        $this->assertFalse($result->fails());
    }

    public function test_fails_with_errors(): void
    {
        $result = new ValidationResult([
            'email' => ['Must be a valid email'],
        ]);

        $this->assertFalse($result->passes());
        $this->assertTrue($result->fails());
    }

    public function test_errors_returns_all_field_errors(): void
    {
        $result = new ValidationResult([
            'email' => ['Required', 'Must be a valid email'],
            'name' => ['Required'],
        ]);

        $this->assertSame([
            'email' => ['Required', 'Must be a valid email'],
            'name' => ['Required'],
        ], $result->errors());
    }

    public function test_field_errors_returns_errors_for_single_field(): void
    {
        $result = new ValidationResult([
            'email' => ['Required', 'Must be a valid email'],
        ]);

        $this->assertSame(['Required', 'Must be a valid email'], $result->fieldErrors('email'));
    }

    public function test_field_errors_returns_empty_for_valid_field(): void
    {
        $result = new ValidationResult([]);

        $this->assertSame([], $result->fieldErrors('email'));
    }

    public function test_first_error_returns_first_message(): void
    {
        $result = new ValidationResult([
            'email' => ['Required', 'Must be a valid email'],
        ]);

        $this->assertSame('Required', $result->firstError('email'));
    }

    public function test_first_error_returns_null_for_valid_field(): void
    {
        $result = new ValidationResult([]);

        $this->assertNull($result->firstError('email'));
    }

    public function test_all_returns_flat_list(): void
    {
        $result = new ValidationResult([
            'email' => ['Required'],
            'name' => ['Too short', 'Invalid characters'],
        ]);

        $this->assertSame(['Required', 'Too short', 'Invalid characters'], $result->all());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit packages/validation/tests/ValidationResultTest.php -v`
Expected: FAIL — class not found

- [ ] **Step 3: Implement ValidationResult**

```php
<?php

declare(strict_types=1);

namespace Preflow\Validation;

final class ValidationResult
{
    /**
     * @param array<string, list<string>> $errors Field => error messages
     */
    public function __construct(
        private readonly array $errors = [],
    ) {}

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return list<string>
     */
    public function fieldErrors(string $field): array
    {
        return $this->errors[$field] ?? [];
    }

    public function firstError(string $field): ?string
    {
        return $this->errors[$field][0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_merge(...array_values($this->errors)) ?: [];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/validation/tests/ValidationResultTest.php -v`
Expected: 8 tests, 8 assertions, all PASS

- [ ] **Step 5: Write failing tests for ErrorBag**

```php
<?php

declare(strict_types=1);

namespace Preflow\Validation\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Validation\ErrorBag;
use Preflow\Validation\ValidationResult;

final class ErrorBagTest extends TestCase
{
    public function test_has_returns_true_for_field_with_errors(): void
    {
        $bag = new ErrorBag(new ValidationResult(['email' => ['Required']]));

        $this->assertTrue($bag->has('email'));
    }

    public function test_has_returns_false_for_valid_field(): void
    {
        $bag = new ErrorBag(new ValidationResult([]));

        $this->assertFalse($bag->has('email'));
    }

    public function test_first_returns_first_error_message(): void
    {
        $bag = new ErrorBag(new ValidationResult([
            'email' => ['Required', 'Must be a valid email'],
        ]));

        $this->assertSame('Required', $bag->first('email'));
    }

    public function test_first_returns_null_for_valid_field(): void
    {
        $bag = new ErrorBag(new ValidationResult([]));

        $this->assertNull($bag->first('email'));
    }

    public function test_get_returns_all_errors_for_field(): void
    {
        $bag = new ErrorBag(new ValidationResult([
            'email' => ['Required', 'Must be a valid email'],
        ]));

        $this->assertSame(['Required', 'Must be a valid email'], $bag->get('email'));
    }

    public function test_all_returns_flat_list(): void
    {
        $bag = new ErrorBag(new ValidationResult([
            'email' => ['Required'],
            'name' => ['Too short'],
        ]));

        $this->assertSame(['Required', 'Too short'], $bag->all());
    }

    public function test_count_returns_total_error_count(): void
    {
        $bag = new ErrorBag(new ValidationResult([
            'email' => ['Required', 'Must be a valid email'],
            'name' => ['Required'],
        ]));

        $this->assertSame(3, $bag->count());
    }

    public function test_is_empty_with_no_errors(): void
    {
        $bag = new ErrorBag(new ValidationResult([]));

        $this->assertTrue($bag->isEmpty());
    }

    public function test_is_empty_with_errors(): void
    {
        $bag = new ErrorBag(new ValidationResult(['email' => ['Required']]));

        $this->assertFalse($bag->isEmpty());
    }

    public function test_to_array_returns_structured_errors(): void
    {
        $errors = ['email' => ['Required'], 'name' => ['Too short']];
        $bag = new ErrorBag(new ValidationResult($errors));

        $this->assertSame($errors, $bag->toArray());
    }

    public function test_get_result_returns_underlying_result(): void
    {
        $result = new ValidationResult(['email' => ['Required']]);
        $bag = new ErrorBag($result);

        $this->assertSame($result, $bag->getResult());
    }
}
```

- [ ] **Step 6: Run tests to verify they fail**

Run: `./vendor/bin/phpunit packages/validation/tests/ErrorBagTest.php -v`
Expected: FAIL — class not found

- [ ] **Step 7: Implement ErrorBag**

```php
<?php

declare(strict_types=1);

namespace Preflow\Validation;

final class ErrorBag
{
    public function __construct(
        private readonly ValidationResult $result,
    ) {}

    public function has(string $field): bool
    {
        return $this->result->fieldErrors($field) !== [];
    }

    public function first(string $field): ?string
    {
        return $this->result->firstError($field);
    }

    /**
     * @return list<string>
     */
    public function get(string $field): array
    {
        return $this->result->fieldErrors($field);
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->result->all();
    }

    public function count(): int
    {
        return count($this->result->all());
    }

    public function isEmpty(): bool
    {
        return $this->result->passes();
    }

    /**
     * @return array<string, list<string>>
     */
    public function toArray(): array
    {
        return $this->result->errors();
    }

    public function getResult(): ValidationResult
    {
        return $this->result;
    }
}
```

- [ ] **Step 8: Implement ValidationException**

```php
<?php

declare(strict_types=1);

namespace Preflow\Validation;

final class ValidationException extends \RuntimeException
{
    public function __construct(
        private readonly ValidationResult $result,
    ) {
        parent::__construct('Validation failed.');
    }

    public function result(): ValidationResult
    {
        return $this->result;
    }

    /**
     * @return array<string, list<string>>
     */
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

- [ ] **Step 9: Run all tests to verify they pass**

Run: `./vendor/bin/phpunit packages/validation/tests/ -v`
Expected: 19 tests, all PASS

- [ ] **Step 10: Commit**

```bash
git add packages/validation/src/ValidationResult.php packages/validation/src/ErrorBag.php packages/validation/src/ValidationException.php packages/validation/tests/ValidationResultTest.php packages/validation/tests/ErrorBagTest.php
git commit -m "feat(validation): ValidationResult, ErrorBag, and ValidationException"
```

---

### Task 3: Built-in Rules

**Files:**
- Create: `packages/validation/src/Rules/Required.php`
- Create: `packages/validation/src/Rules/Nullable.php`
- Create: `packages/validation/src/Rules/Email.php`
- Create: `packages/validation/src/Rules/Url.php`
- Create: `packages/validation/src/Rules/Numeric.php`
- Create: `packages/validation/src/Rules/IsInteger.php`
- Create: `packages/validation/src/Rules/MinLength.php`
- Create: `packages/validation/src/Rules/MaxLength.php`
- Create: `packages/validation/src/Rules/Between.php`
- Create: `packages/validation/src/Rules/InList.php`
- Create: `packages/validation/src/Rules/Regex.php`
- Create: `packages/validation/src/Rules/Confirmed.php`
- Create: `packages/validation/tests/ValidationRuleContractTest.php`

- [ ] **Step 1: Write failing tests for all built-in rules**

```php
<?php

declare(strict_types=1);

namespace Preflow\Validation\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\Rules\Required;
use Preflow\Validation\Rules\Nullable;
use Preflow\Validation\Rules\Email;
use Preflow\Validation\Rules\Url;
use Preflow\Validation\Rules\Numeric;
use Preflow\Validation\Rules\IsInteger;
use Preflow\Validation\Rules\MinLength;
use Preflow\Validation\Rules\MaxLength;
use Preflow\Validation\Rules\Between;
use Preflow\Validation\Rules\InList;
use Preflow\Validation\Rules\Regex;
use Preflow\Validation\Rules\Confirmed;

final class ValidationRuleContractTest extends TestCase
{
    private function context(string $field = 'test', array $data = []): ValidationContext
    {
        return new ValidationContext($field, $data);
    }

    // --- Required ---

    public function test_required_passes_for_non_empty_string(): void
    {
        $this->assertTrue((new Required())->validate('hello', $this->context()));
    }

    public function test_required_fails_for_null(): void
    {
        $this->assertIsString((new Required())->validate(null, $this->context()));
    }

    public function test_required_fails_for_empty_string(): void
    {
        $this->assertIsString((new Required())->validate('', $this->context()));
    }

    public function test_required_fails_for_empty_array(): void
    {
        $this->assertIsString((new Required())->validate([], $this->context()));
    }

    public function test_required_passes_for_zero(): void
    {
        $this->assertTrue((new Required())->validate(0, $this->context()));
    }

    public function test_required_passes_for_false(): void
    {
        $this->assertTrue((new Required())->validate(false, $this->context()));
    }

    // --- Nullable ---

    public function test_nullable_returns_true_for_any_value(): void
    {
        $rule = new Nullable();
        $this->assertTrue($rule->validate('hello', $this->context()));
        $this->assertTrue($rule->validate(null, $this->context()));
        $this->assertTrue($rule->validate('', $this->context()));
    }

    // --- Email ---

    public function test_email_passes_for_valid_email(): void
    {
        $this->assertTrue((new Email())->validate('user@example.com', $this->context()));
    }

    public function test_email_fails_for_invalid_email(): void
    {
        $this->assertIsString((new Email())->validate('not-an-email', $this->context()));
    }

    public function test_email_passes_for_null(): void
    {
        $this->assertTrue((new Email())->validate(null, $this->context()));
    }

    // --- Url ---

    public function test_url_passes_for_valid_url(): void
    {
        $this->assertTrue((new Url())->validate('https://example.com', $this->context()));
    }

    public function test_url_fails_for_invalid_url(): void
    {
        $this->assertIsString((new Url())->validate('not a url', $this->context()));
    }

    public function test_url_passes_for_null(): void
    {
        $this->assertTrue((new Url())->validate(null, $this->context()));
    }

    // --- Numeric ---

    public function test_numeric_passes_for_number(): void
    {
        $rule = new Numeric();
        $this->assertTrue($rule->validate(42, $this->context()));
        $this->assertTrue($rule->validate(3.14, $this->context()));
        $this->assertTrue($rule->validate('42', $this->context()));
    }

    public function test_numeric_fails_for_non_numeric(): void
    {
        $this->assertIsString((new Numeric())->validate('abc', $this->context()));
    }

    public function test_numeric_passes_for_null(): void
    {
        $this->assertTrue((new Numeric())->validate(null, $this->context()));
    }

    // --- IsInteger ---

    public function test_integer_passes_for_int(): void
    {
        $rule = new IsInteger();
        $this->assertTrue($rule->validate(42, $this->context()));
        $this->assertTrue($rule->validate('42', $this->context()));
    }

    public function test_integer_fails_for_float(): void
    {
        $this->assertIsString((new IsInteger())->validate(3.14, $this->context()));
    }

    public function test_integer_fails_for_string(): void
    {
        $this->assertIsString((new IsInteger())->validate('abc', $this->context()));
    }

    public function test_integer_passes_for_null(): void
    {
        $this->assertTrue((new IsInteger())->validate(null, $this->context()));
    }

    // --- MinLength ---

    public function test_min_length_passes_for_long_enough_string(): void
    {
        $this->assertTrue((new MinLength(3))->validate('hello', $this->context()));
    }

    public function test_min_length_fails_for_short_string(): void
    {
        $this->assertIsString((new MinLength(3))->validate('hi', $this->context()));
    }

    public function test_min_length_passes_for_exact_length(): void
    {
        $this->assertTrue((new MinLength(3))->validate('abc', $this->context()));
    }

    public function test_min_length_works_as_numeric_minimum(): void
    {
        $this->assertTrue((new MinLength(5))->validate(10, $this->context()));
        $this->assertIsString((new MinLength(5))->validate(3, $this->context()));
    }

    public function test_min_length_passes_for_null(): void
    {
        $this->assertTrue((new MinLength(3))->validate(null, $this->context()));
    }

    // --- MaxLength ---

    public function test_max_length_passes_for_short_enough_string(): void
    {
        $this->assertTrue((new MaxLength(5))->validate('hi', $this->context()));
    }

    public function test_max_length_fails_for_long_string(): void
    {
        $this->assertIsString((new MaxLength(3))->validate('hello', $this->context()));
    }

    public function test_max_length_passes_for_exact_length(): void
    {
        $this->assertTrue((new MaxLength(3))->validate('abc', $this->context()));
    }

    public function test_max_length_works_as_numeric_maximum(): void
    {
        $this->assertTrue((new MaxLength(10))->validate(5, $this->context()));
        $this->assertIsString((new MaxLength(10))->validate(15, $this->context()));
    }

    public function test_max_length_passes_for_null(): void
    {
        $this->assertTrue((new MaxLength(5))->validate(null, $this->context()));
    }

    // --- Between ---

    public function test_between_passes_for_value_in_range(): void
    {
        $this->assertTrue((new Between(1, 10))->validate(5, $this->context()));
    }

    public function test_between_passes_for_boundary_values(): void
    {
        $rule = new Between(1, 10);
        $this->assertTrue($rule->validate(1, $this->context()));
        $this->assertTrue($rule->validate(10, $this->context()));
    }

    public function test_between_fails_for_value_out_of_range(): void
    {
        $this->assertIsString((new Between(1, 10))->validate(15, $this->context()));
        $this->assertIsString((new Between(1, 10))->validate(0, $this->context()));
    }

    public function test_between_works_for_string_length(): void
    {
        $this->assertTrue((new Between(2, 5))->validate('abc', $this->context()));
        $this->assertIsString((new Between(2, 5))->validate('a', $this->context()));
        $this->assertIsString((new Between(2, 5))->validate('abcdef', $this->context()));
    }

    public function test_between_passes_for_null(): void
    {
        $this->assertTrue((new Between(1, 10))->validate(null, $this->context()));
    }

    // --- InList ---

    public function test_in_list_passes_for_valid_value(): void
    {
        $this->assertTrue((new InList(['draft', 'published', 'archived']))->validate('draft', $this->context()));
    }

    public function test_in_list_fails_for_invalid_value(): void
    {
        $this->assertIsString((new InList(['draft', 'published']))->validate('deleted', $this->context()));
    }

    public function test_in_list_passes_for_null(): void
    {
        $this->assertTrue((new InList(['a', 'b']))->validate(null, $this->context()));
    }

    // --- Regex ---

    public function test_regex_passes_for_matching_value(): void
    {
        $this->assertTrue((new Regex('/^[A-Z]{3}$/'))->validate('ABC', $this->context()));
    }

    public function test_regex_fails_for_non_matching_value(): void
    {
        $this->assertIsString((new Regex('/^[A-Z]{3}$/'))->validate('abc', $this->context()));
    }

    public function test_regex_passes_for_null(): void
    {
        $this->assertTrue((new Regex('/^[A-Z]+$/'))->validate(null, $this->context()));
    }

    // --- Confirmed ---

    public function test_confirmed_passes_when_fields_match(): void
    {
        $ctx = new ValidationContext('password', [
            'password' => 'secret',
            'password_confirmation' => 'secret',
        ]);

        $this->assertTrue((new Confirmed())->validate('secret', $ctx));
    }

    public function test_confirmed_fails_when_fields_differ(): void
    {
        $ctx = new ValidationContext('password', [
            'password' => 'secret',
            'password_confirmation' => 'different',
        ]);

        $this->assertIsString((new Confirmed())->validate('secret', $ctx));
    }

    public function test_confirmed_fails_when_confirmation_missing(): void
    {
        $ctx = new ValidationContext('password', [
            'password' => 'secret',
        ]);

        $this->assertIsString((new Confirmed())->validate('secret', $ctx));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit packages/validation/tests/ValidationRuleContractTest.php -v`
Expected: FAIL — classes not found

- [ ] **Step 3: Implement all built-in rules**

Create `packages/validation/src/Rules/Required.php`:
```php
<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

final class Required implements ValidationRule
{
    public function validate(mixed $value, ValidationContext $context): true|string
    {
        if ($value === null || $value === '' || $value === []) {
            return 'This field is required.';
        }

        return true;
    }
}
```

Create `packages/validation/src/Rules/Nullable.php`:
```php
<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/**
 * Marker rule: when present in a rule chain and the value is null,
 * the Validator stops the chain early (passes). This rule itself always passes.
 */
final class Nullable implements ValidationRule
{
    public function validate(mixed $value, ValidationContext $context): true|string
    {
        return true;
    }
}
```

Create `packages/validation/src/Rules/Email.php`:
```php
<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

final class Email implements ValidationRule
{
    public function validate(mixed $value, ValidationContext $context): true|string
    {
        if ($value === null || $value === '') {
            return true;
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return 'Must be a valid email address.';
        }

        return true;
    }
}
```

Create `packages/validation/src/Rules/Url.php`:
```php
<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

final class Url implements ValidationRule
{
    public function validate(mixed $value, ValidationContext $context): true|string
    {
        if ($value === null || $value === '') {
            return true;
        }

        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return 'Must be a valid URL.';
        }

        return true;
    }
}
```

Create `packages/validation/src/Rules/Numeric.php`:
```php
<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

final class Numeric implements ValidationRule
{
    public function validate(mixed $value, ValidationContext $context): true|string
    {
        if ($value === null) {
            return true;
        }

        if (!is_numeric($value)) {
            return 'Must be a number.';
        }

        return true;
    }
}
```

Create `packages/validation/src/Rules/IsInteger.php`:
```php
<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

final class IsInteger implements ValidationRule
{
    public function validate(mixed $value, ValidationContext $context): true|string
    {
        if ($value === null) {
            return true;
        }

        if (filter_var($value, FILTER_VALIDATE_INT) === false && !is_int($value)) {
            return 'Must be an integer.';
        }

        return true;
    }
}
```

Create `packages/validation/src/Rules/MinLength.php`:
```php
<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

final class MinLength implements ValidationRule
{
    public function __construct(
        private readonly int|float $min,
    ) {}

    public function validate(mixed $value, ValidationContext $context): true|string
    {
        if ($value === null) {
            return true;
        }

        if (is_numeric($value)) {
            if ($value < $this->min) {
                return "Must be at least {$this->min}.";
            }
            return true;
        }

        if (is_string($value) && mb_strlen($value) < $this->min) {
            return "Must be at least {$this->min} characters.";
        }

        return true;
    }
}
```

Create `packages/validation/src/Rules/MaxLength.php`:
```php
<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

final class MaxLength implements ValidationRule
{
    public function __construct(
        private readonly int|float $max,
    ) {}

    public function validate(mixed $value, ValidationContext $context): true|string
    {
        if ($value === null) {
            return true;
        }

        if (is_numeric($value)) {
            if ($value > $this->max) {
                return "Must be at most {$this->max}.";
            }
            return true;
        }

        if (is_string($value) && mb_strlen($value) > $this->max) {
            return "Must be at most {$this->max} characters.";
        }

        return true;
    }
}
```

Create `packages/validation/src/Rules/Between.php`:
```php
<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

final class Between implements ValidationRule
{
    public function __construct(
        private readonly int|float $min,
        private readonly int|float $max,
    ) {}

    public function validate(mixed $value, ValidationContext $context): true|string
    {
        if ($value === null) {
            return true;
        }

        $size = is_numeric($value) ? $value : (is_string($value) ? mb_strlen($value) : 0);

        if ($size < $this->min || $size > $this->max) {
            return "Must be between {$this->min} and {$this->max}.";
        }

        return true;
    }
}
```

Create `packages/validation/src/Rules/InList.php`:
```php
<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

final class InList implements ValidationRule
{
    /**
     * @param list<string> $values
     */
    public function __construct(
        private readonly array $values,
    ) {}

    public function validate(mixed $value, ValidationContext $context): true|string
    {
        if ($value === null) {
            return true;
        }

        if (!in_array($value, $this->values, false)) {
            $list = implode(', ', $this->values);
            return "Must be one of: {$list}.";
        }

        return true;
    }
}
```

Create `packages/validation/src/Rules/Regex.php`:
```php
<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

final class Regex implements ValidationRule
{
    public function __construct(
        private readonly string $pattern,
    ) {}

    public function validate(mixed $value, ValidationContext $context): true|string
    {
        if ($value === null || $value === '') {
            return true;
        }

        if (!preg_match($this->pattern, (string) $value)) {
            return 'Format is invalid.';
        }

        return true;
    }
}
```

Create `packages/validation/src/Rules/Confirmed.php`:
```php
<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

final class Confirmed implements ValidationRule
{
    public function validate(mixed $value, ValidationContext $context): true|string
    {
        $confirmation = $context->getValue($context->field . '_confirmation');

        if ($value !== $confirmation) {
            return 'Confirmation does not match.';
        }

        return true;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/validation/tests/ValidationRuleContractTest.php -v`
Expected: All tests PASS

- [ ] **Step 5: Commit**

```bash
git add packages/validation/src/Rules/ packages/validation/tests/ValidationRuleContractTest.php
git commit -m "feat(validation): 12 built-in validation rules"
```

---

### Task 4: RuleFactory & RuleAlias

**Files:**
- Create: `packages/validation/src/RuleFactory.php`
- Create: `packages/validation/src/RuleAlias.php`
- Create: `packages/validation/tests/RuleFactoryTest.php`

- [ ] **Step 1: Write failing tests for RuleFactory**

```php
<?php

declare(strict_types=1);

namespace Preflow\Validation\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Validation\RuleAlias;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;
use Preflow\Validation\Rules\Required;
use Preflow\Validation\Rules\Email;
use Preflow\Validation\Rules\MinLength;
use Preflow\Validation\Rules\InList;
use Preflow\Validation\Rules\Regex;

#[RuleAlias('custom-test')]
final class CustomTestRule implements ValidationRule
{
    public function validate(mixed $value, ValidationContext $context): true|string
    {
        return $value === 'valid' ? true : 'Must be valid.';
    }
}

#[RuleAlias('required')]
final class OverrideRequired implements ValidationRule
{
    public function validate(mixed $value, ValidationContext $context): true|string
    {
        return $value === null ? 'Overridden required.' : true;
    }
}

final class RuleFactoryTest extends TestCase
{
    private RuleFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new RuleFactory();
    }

    public function test_resolves_rule_instance_as_is(): void
    {
        $rule = new Required();
        $this->assertSame($rule, $this->factory->resolve($rule));
    }

    public function test_resolves_built_in_by_alias(): void
    {
        $rule = $this->factory->resolve('required');
        $this->assertInstanceOf(Required::class, $rule);
    }

    public function test_resolves_email_alias(): void
    {
        $rule = $this->factory->resolve('email');
        $this->assertInstanceOf(Email::class, $rule);
    }

    public function test_resolves_parameterized_rule(): void
    {
        $rule = $this->factory->resolve('min:3');
        $this->assertInstanceOf(MinLength::class, $rule);
    }

    public function test_resolves_in_list_with_parameters(): void
    {
        $rule = $this->factory->resolve('in:draft,published,archived');
        $this->assertInstanceOf(InList::class, $rule);

        $ctx = new ValidationContext('status', ['status' => 'draft']);
        $this->assertTrue($rule->validate('draft', $ctx));
        $this->assertIsString($rule->validate('deleted', $ctx));
    }

    public function test_resolves_regex_with_pattern(): void
    {
        $rule = $this->factory->resolve('regex:/^[A-Z]+$/');
        $this->assertInstanceOf(Regex::class, $rule);
    }

    public function test_resolves_fqcn(): void
    {
        $rule = $this->factory->resolve(Required::class);
        $this->assertInstanceOf(Required::class, $rule);
    }

    public function test_register_custom_alias(): void
    {
        $this->factory->register('custom-test', CustomTestRule::class);
        $rule = $this->factory->resolve('custom-test');
        $this->assertInstanceOf(CustomTestRule::class, $rule);
    }

    public function test_registered_alias_overrides_built_in(): void
    {
        $this->factory->register('required', OverrideRequired::class);
        $rule = $this->factory->resolve('required');
        $this->assertInstanceOf(OverrideRequired::class, $rule);
    }

    public function test_discover_reads_rule_alias_attribute(): void
    {
        $this->factory->discover([CustomTestRule::class]);
        $rule = $this->factory->resolve('custom-test');
        $this->assertInstanceOf(CustomTestRule::class, $rule);
    }

    public function test_throws_for_unknown_rule(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown validation rule');
        $this->factory->resolve('nonexistent');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit packages/validation/tests/RuleFactoryTest.php -v`
Expected: FAIL — classes not found

- [ ] **Step 3: Implement RuleAlias attribute**

```php
<?php

declare(strict_types=1);

namespace Preflow\Validation;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class RuleAlias
{
    public function __construct(
        public readonly string $alias,
    ) {}
}
```

- [ ] **Step 4: Implement RuleFactory**

```php
<?php

declare(strict_types=1);

namespace Preflow\Validation;

use Preflow\Validation\Rules\Between;
use Preflow\Validation\Rules\Confirmed;
use Preflow\Validation\Rules\Email;
use Preflow\Validation\Rules\InList;
use Preflow\Validation\Rules\IsInteger;
use Preflow\Validation\Rules\MaxLength;
use Preflow\Validation\Rules\MinLength;
use Preflow\Validation\Rules\Nullable;
use Preflow\Validation\Rules\Numeric;
use Preflow\Validation\Rules\Regex;
use Preflow\Validation\Rules\Required;
use Preflow\Validation\Rules\Url;

final class RuleFactory
{
    /** @var array<string, string> Alias => FQCN */
    private array $aliases = [];

    private const BUILT_IN = [
        'required' => Required::class,
        'nullable' => Nullable::class,
        'email' => Email::class,
        'url' => Url::class,
        'numeric' => Numeric::class,
        'integer' => IsInteger::class,
        'min' => MinLength::class,
        'max' => MaxLength::class,
        'between' => Between::class,
        'in' => InList::class,
        'regex' => Regex::class,
        'confirmed' => Confirmed::class,
    ];

    /**
     * Resolve a rule string or instance to a ValidationRule.
     *
     * Resolution order:
     * 1. Already a ValidationRule instance — return as-is
     * 2. Registered aliases (app-level overrides)
     * 3. Built-in aliases
     * 4. FQCN fallback
     */
    public function resolve(string|ValidationRule $rule): ValidationRule
    {
        if ($rule instanceof ValidationRule) {
            return $rule;
        }

        // Parse "alias:param1,param2" format
        $parts = explode(':', $rule, 2);
        $alias = $parts[0];
        $paramString = $parts[1] ?? null;

        // Check registered aliases first (app overrides)
        if (isset($this->aliases[$alias])) {
            return $this->instantiate($this->aliases[$alias], $alias, $paramString);
        }

        // Check built-in aliases
        if (isset(self::BUILT_IN[$alias])) {
            return $this->instantiate(self::BUILT_IN[$alias], $alias, $paramString);
        }

        // FQCN fallback
        if (class_exists($rule)) {
            return new $rule();
        }

        throw new \RuntimeException("Unknown validation rule: {$rule}");
    }

    /**
     * Register a rule class under an alias. Later registrations override earlier ones.
     */
    public function register(string $alias, string $ruleClass): void
    {
        $this->aliases[$alias] = $ruleClass;
    }

    /**
     * Discover rule aliases from class list by reading #[RuleAlias] attributes.
     *
     * @param list<class-string> $classes
     */
    public function discover(array $classes): void
    {
        foreach ($classes as $class) {
            $ref = new \ReflectionClass($class);
            $attrs = $ref->getAttributes(RuleAlias::class);
            if ($attrs !== []) {
                $alias = $attrs[0]->newInstance()->alias;
                $this->aliases[$alias] = $class;
            }
        }
    }

    private function instantiate(string $class, string $alias, ?string $paramString): ValidationRule
    {
        if ($paramString === null) {
            return new $class();
        }

        // Special handling for regex — don't split on commas inside the pattern
        if ($alias === 'regex') {
            return new $class($paramString);
        }

        $params = explode(',', $paramString);

        // Cast numeric params
        $params = array_map(function (string $p): string|int|float {
            if (ctype_digit($p) || (str_starts_with($p, '-') && ctype_digit(substr($p, 1)))) {
                return (int) $p;
            }
            if (is_numeric($p)) {
                return (float) $p;
            }
            return $p;
        }, $params);

        // For InList, pass the full array as a single argument
        if ($alias === 'in') {
            return new $class($params);
        }

        return new $class(...$params);
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/validation/tests/RuleFactoryTest.php -v`
Expected: All tests PASS

- [ ] **Step 6: Commit**

```bash
git add packages/validation/src/RuleFactory.php packages/validation/src/RuleAlias.php packages/validation/tests/RuleFactoryTest.php
git commit -m "feat(validation): RuleFactory with alias resolution, discovery, and override"
```

---

### Task 5: Validator Engine

**Files:**
- Create: `packages/validation/src/Validator.php`
- Create: `packages/validation/src/ValidatorFactory.php`
- Create: `packages/validation/tests/ValidatorTest.php`

- [ ] **Step 1: Write failing tests for Validator**

```php
<?php

declare(strict_types=1);

namespace Preflow\Validation\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;
use Preflow\Validation\Validator;
use Preflow\Validation\ValidatorFactory;

final class ValidatorTest extends TestCase
{
    private RuleFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new RuleFactory();
    }

    private function validate(array $rules, array $data, mixed $subject = null): \Preflow\Validation\ValidationResult
    {
        return (new Validator($this->factory, $rules, $data, $subject))->validate();
    }

    public function test_passes_with_no_rules(): void
    {
        $result = $this->validate([], ['name' => 'Alice']);
        $this->assertTrue($result->passes());
    }

    public function test_passes_with_valid_data(): void
    {
        $result = $this->validate(
            ['name' => ['required', 'min:2'], 'email' => ['required', 'email']],
            ['name' => 'Alice', 'email' => 'alice@example.com'],
        );
        $this->assertTrue($result->passes());
    }

    public function test_fails_with_invalid_data(): void
    {
        $result = $this->validate(
            ['name' => ['required'], 'email' => ['required', 'email']],
            ['name' => '', 'email' => 'not-email'],
        );

        $this->assertTrue($result->fails());
        $this->assertNotEmpty($result->fieldErrors('name'));
        $this->assertNotEmpty($result->fieldErrors('email'));
    }

    public function test_required_stops_chain_on_failure(): void
    {
        $result = $this->validate(
            ['name' => ['required', 'min:3']],
            ['name' => ''],
        );

        // Should only have the "required" error, not also "min:3"
        $this->assertCount(1, $result->fieldErrors('name'));
    }

    public function test_nullable_stops_chain_for_null_value(): void
    {
        $result = $this->validate(
            ['name' => ['nullable', 'min:3']],
            ['name' => null],
        );

        $this->assertTrue($result->passes());
    }

    public function test_nullable_continues_chain_for_non_null(): void
    {
        $result = $this->validate(
            ['name' => ['nullable', 'min:3']],
            ['name' => 'ab'],
        );

        $this->assertTrue($result->fails());
        $this->assertNotEmpty($result->fieldErrors('name'));
    }

    public function test_accepts_rule_instances(): void
    {
        $customRule = new class implements ValidationRule {
            public function validate(mixed $value, ValidationContext $context): true|string
            {
                return $value === 'magic' ? true : 'Must be magic.';
            }
        };

        $result = $this->validate(
            ['code' => [$customRule]],
            ['code' => 'nope'],
        );

        $this->assertTrue($result->fails());
        $this->assertSame('Must be magic.', $result->firstError('code'));
    }

    public function test_mixed_string_and_instance_rules(): void
    {
        $customRule = new class implements ValidationRule {
            public function validate(mixed $value, ValidationContext $context): true|string
            {
                return true;
            }
        };

        $result = $this->validate(
            ['name' => ['required', $customRule, 'min:2']],
            ['name' => 'Alice'],
        );

        $this->assertTrue($result->passes());
    }

    public function test_multi_field_validation(): void
    {
        $result = $this->validate(
            [
                'name' => ['required', 'min:2'],
                'email' => ['required', 'email'],
                'age' => ['nullable', 'integer'],
            ],
            ['name' => 'A', 'email' => 'bad', 'age' => null],
        );

        $this->assertTrue($result->fails());
        $this->assertNotEmpty($result->fieldErrors('name'));
        $this->assertNotEmpty($result->fieldErrors('email'));
        $this->assertEmpty($result->fieldErrors('age'));
    }

    public function test_cross_field_rule_via_confirmed(): void
    {
        $result = $this->validate(
            ['password' => ['required', 'min:6', 'confirmed']],
            ['password' => 'secret123', 'password_confirmation' => 'secret123'],
        );

        $this->assertTrue($result->passes());
    }

    public function test_subject_is_passed_to_context(): void
    {
        $subjectCapture = null;

        $rule = new class($subjectCapture) implements ValidationRule {
            public function __construct(private mixed &$capture) {}
            public function validate(mixed $value, ValidationContext $context): true|string
            {
                $this->capture = $context->subject;
                return true;
            }
        };

        $subject = new \stdClass();
        $this->validate(['field' => [$rule]], ['field' => 'val'], $subject);

        $this->assertSame($subject, $subjectCapture);
    }

    public function test_missing_field_is_treated_as_null(): void
    {
        $result = $this->validate(
            ['name' => ['required']],
            [],
        );

        $this->assertTrue($result->fails());
    }

    public function test_validator_factory_creates_validator(): void
    {
        $factory = new ValidatorFactory($this->factory);
        $validator = $factory->make(
            ['name' => ['required']],
            ['name' => 'Alice'],
        );

        $this->assertInstanceOf(Validator::class, $validator);
        $this->assertTrue($validator->validate()->passes());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit packages/validation/tests/ValidatorTest.php -v`
Expected: FAIL — classes not found

- [ ] **Step 3: Implement Validator**

```php
<?php

declare(strict_types=1);

namespace Preflow\Validation;

use Preflow\Validation\Rules\Nullable;
use Preflow\Validation\Rules\Required;

final class Validator
{
    /**
     * @param array<string, list<ValidationRule|string>> $rules Field => rules
     * @param array<string, mixed> $data Field => value
     */
    public function __construct(
        private readonly RuleFactory $ruleFactory,
        private readonly array $rules,
        private readonly array $data,
        private readonly mixed $subject = null,
    ) {}

    public function validate(): ValidationResult
    {
        $errors = [];

        foreach ($this->rules as $field => $fieldRules) {
            $value = $this->data[$field] ?? null;
            $fieldErrors = $this->validateField($field, $value, $fieldRules);

            if ($fieldErrors !== []) {
                $errors[$field] = $fieldErrors;
            }
        }

        return new ValidationResult($errors);
    }

    /**
     * @param list<ValidationRule|string> $rules
     * @return list<string>
     */
    private function validateField(string $field, mixed $value, array $rules): array
    {
        $errors = [];
        $hasNullable = false;

        // Check for nullable rule in the chain
        foreach ($rules as $rule) {
            $resolved = $rule instanceof ValidationRule ? $rule : $this->ruleFactory->resolve($rule);
            if ($resolved instanceof Nullable) {
                $hasNullable = true;
                break;
            }
        }

        // If nullable and value is null, skip all validation
        if ($hasNullable && ($value === null || $value === '')) {
            return [];
        }

        $context = new ValidationContext($field, $this->data, $this->subject);

        foreach ($rules as $rule) {
            $resolved = $rule instanceof ValidationRule ? $rule : $this->ruleFactory->resolve($rule);

            // Skip the nullable marker itself
            if ($resolved instanceof Nullable) {
                continue;
            }

            $result = $resolved->validate($value, $context);

            if ($result !== true) {
                $errors[] = $result;

                // Required failure stops the chain — no point checking format of empty value
                if ($resolved instanceof Required) {
                    break;
                }
            }
        }

        return $errors;
    }
}
```

- [ ] **Step 4: Implement ValidatorFactory**

```php
<?php

declare(strict_types=1);

namespace Preflow\Validation;

final class ValidatorFactory
{
    public function __construct(
        private readonly RuleFactory $ruleFactory,
    ) {}

    /**
     * @param array<string, list<ValidationRule|string>> $rules
     * @param array<string, mixed> $data
     */
    public function make(array $rules, array $data, mixed $subject = null): Validator
    {
        return new Validator($this->ruleFactory, $rules, $data, $subject);
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/validation/tests/ValidatorTest.php -v`
Expected: All tests PASS

- [ ] **Step 6: Run the full validation test suite**

Run: `./vendor/bin/phpunit packages/validation/tests/ -v`
Expected: All tests across all test files PASS

- [ ] **Step 7: Commit**

```bash
git add packages/validation/src/Validator.php packages/validation/src/ValidatorFactory.php packages/validation/tests/ValidatorTest.php
git commit -m "feat(validation): Validator engine with nullable/required chain logic and ValidatorFactory"
```

---

### Task 6: #[Validate] Attribute & ModelMetadata Integration

**Files:**
- Create: `packages/validation/src/Attributes/Validate.php`
- Modify: `packages/data/src/ModelMetadata.php`
- Modify: `packages/data/composer.json`

- [ ] **Step 1: Create the #[Validate] attribute**

```php
<?php

declare(strict_types=1);

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

- [ ] **Step 2: Add preflow/validation dependency to data package**

In `packages/data/composer.json`, add to `require`:
```json
"preflow/validation": "^0.1 || @dev"
```

- [ ] **Step 3: Run composer update**

Run: `composer update preflow/data preflow/validation --prefer-source`
Expected: Dependencies resolved

- [ ] **Step 4: Add validationRules() method to ModelMetadata**

In `packages/data/src/ModelMetadata.php`, add the import at the top of the file:
```php
use Preflow\Validation\Attributes\Validate;
```

Add a new property to the constructor:
```php
/** @var array<string, list<string>> */
public readonly array $validationRules,
```

In the `for()` method, add validation rule scanning alongside the existing property loop. After the existing `foreach ($ref->getProperties(...))` loop, add:
```php
$validationRules = [];
foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
    $validateAttrs = $prop->getAttributes(Validate::class);
    if ($validateAttrs !== []) {
        $rules = [];
        foreach ($validateAttrs as $attr) {
            $rules = array_merge($rules, $attr->newInstance()->rules);
        }
        $validationRules[$prop->getName()] = $rules;
    }
}
```

Pass `$validationRules` to the constructor call:
```php
$meta = new self(
    // ... existing params ...
    validationRules: $validationRules,
);
```

- [ ] **Step 5: Run existing data tests to verify nothing broke**

Run: `./vendor/bin/phpunit packages/data/tests/ModelMetadataTest.php -v`
Expected: All existing tests PASS

- [ ] **Step 6: Commit**

```bash
git add packages/validation/src/Attributes/Validate.php packages/data/src/ModelMetadata.php packages/data/composer.json
git commit -m "feat(validation): #[Validate] attribute and ModelMetadata.validationRules integration"
```

---

### Task 7: Dynamic Model Validation Rules (TypeDefinition / TypeRegistry)

**Files:**
- Modify: `packages/data/src/TypeFieldDefinition.php`
- Modify: `packages/data/src/TypeDefinition.php`
- Modify: `packages/data/src/TypeRegistry.php`

- [ ] **Step 1: Add validate property to TypeFieldDefinition**

Replace the full file `packages/data/src/TypeFieldDefinition.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data;

final readonly class TypeFieldDefinition
{
    /**
     * @param list<string> $validate
     */
    public function __construct(
        public string $name,
        public string $type = 'string',
        public bool $searchable = false,
        public ?string $transform = null,
        public array $validate = [],
    ) {}
}
```

- [ ] **Step 2: Add validationRules() to TypeDefinition**

Replace the full file `packages/data/src/TypeDefinition.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Data;

final readonly class TypeDefinition
{
    /**
     * @param array<string, TypeFieldDefinition> $fields
     * @param string[] $searchableFields
     * @param array<string, FieldTransformer> $transformers
     */
    public function __construct(
        public string $key,
        public string $table,
        public string $storage,
        public array $fields,
        public string $idField = 'uuid',
        public array $searchableFields = [],
        public array $transformers = [],
    ) {}

    /**
     * Collect validation rules from all field definitions.
     *
     * @return array<string, list<string>>
     */
    public function validationRules(): array
    {
        $rules = [];

        foreach ($this->fields as $name => $field) {
            if ($field->validate !== []) {
                $rules[$name] = $field->validate;
            }
        }

        return $rules;
    }
}
```

- [ ] **Step 3: Read "validate" key in TypeRegistry**

In `packages/data/src/TypeRegistry.php`, inside the `foreach ($schema['fields'] ?? [] as $name => $fieldDef)` loop, after the `$transform` assignment add:

```php
$validate = $fieldDef['validate'] ?? [];
```

And update the `TypeFieldDefinition` construction:

```php
$fields[$name] = new TypeFieldDefinition(
    name: $name,
    type: $fieldType,
    searchable: $searchable,
    transform: $transform,
    validate: $validate,
);
```

- [ ] **Step 4: Run existing data tests to verify nothing broke**

Run: `./vendor/bin/phpunit packages/data/tests/ -v`
Expected: All existing tests PASS

- [ ] **Step 5: Commit**

```bash
git add packages/data/src/TypeFieldDefinition.php packages/data/src/TypeDefinition.php packages/data/src/TypeRegistry.php
git commit -m "feat(validation): dynamic model validate key in TypeFieldDefinition, TypeDefinition, TypeRegistry"
```

---

### Task 8: DataManager Auto-Validation

**Files:**
- Modify: `packages/data/src/DataManager.php`
- Create: `packages/validation/tests/DataManagerValidationTest.php`

- [ ] **Step 1: Write failing integration tests**

```php
<?php

declare(strict_types=1);

namespace Preflow\Validation\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Model;
use Preflow\Data\ModelMetadata;
use Preflow\Data\TypeRegistry;
use Preflow\Validation\Attributes\Validate;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidatorFactory;
use Preflow\Validation\ValidationException;

#[Entity(table: 'test_users', storage: 'default')]
class ValidatedUser extends Model
{
    #[Id]
    public int $id = 0;

    #[Field]
    #[Validate('required', 'min:2')]
    public string $name = '';

    #[Field]
    #[Validate('required', 'email')]
    public string $email = '';

    #[Field]
    #[Validate('nullable', 'integer')]
    public ?int $age = null;
}

final class DataManagerValidationTest extends TestCase
{
    private DataManager $dm;
    private \PDO $pdo;

    protected function setUp(): void
    {
        ModelMetadata::clearCache();

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE test_users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT, age INTEGER)');

        $driver = new SqliteDriver($this->pdo);
        $validatorFactory = new ValidatorFactory(new RuleFactory());
        $this->dm = new DataManager(
            drivers: ['default' => $driver],
            validatorFactory: $validatorFactory,
        );
    }

    public function test_save_passes_with_valid_data(): void
    {
        $user = new ValidatedUser();
        $user->name = 'Alice';
        $user->email = 'alice@example.com';
        $user->age = 30;

        $this->dm->save($user);

        $this->assertGreaterThan(0, $user->id);
    }

    public function test_save_throws_validation_exception_on_invalid_data(): void
    {
        $user = new ValidatedUser();
        $user->name = '';
        $user->email = 'not-an-email';

        $this->expectException(ValidationException::class);
        $this->dm->save($user);
    }

    public function test_validation_exception_carries_field_errors(): void
    {
        $user = new ValidatedUser();
        $user->name = 'A';
        $user->email = 'bad';

        try {
            $this->dm->save($user);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $this->assertArrayHasKey('name', $errors);
            $this->assertArrayHasKey('email', $errors);
        }
    }

    public function test_save_with_validate_false_skips_validation(): void
    {
        $user = new ValidatedUser();
        $user->name = '';
        $user->email = 'bad';

        // Should not throw
        $this->dm->save($user, validate: false);
        $this->assertGreaterThan(0, $user->id);
    }

    public function test_save_with_extra_rules(): void
    {
        $user = new ValidatedUser();
        $user->name = 'Alice';
        $user->email = 'alice@example.com';
        $user->age = 200;

        $this->expectException(ValidationException::class);
        $this->dm->save($user, rules: ['age' => ['max:150']]);
    }

    public function test_insert_validates(): void
    {
        $user = new ValidatedUser();
        $user->name = '';
        $user->email = '';

        $this->expectException(ValidationException::class);
        $this->dm->insert($user);
    }

    public function test_update_validates(): void
    {
        // First insert a valid record
        $user = new ValidatedUser();
        $user->name = 'Alice';
        $user->email = 'alice@example.com';
        $this->dm->save($user);

        // Now try to update with invalid data
        $user->name = '';
        $this->expectException(ValidationException::class);
        $this->dm->update($user);
    }

    public function test_nullable_field_passes_with_null(): void
    {
        $user = new ValidatedUser();
        $user->name = 'Alice';
        $user->email = 'alice@example.com';
        $user->age = null;

        $this->dm->save($user);
        $this->assertGreaterThan(0, $user->id);
    }

    public function test_dynamic_record_validation(): void
    {
        $this->pdo->exec('CREATE TABLE events (uuid TEXT PRIMARY KEY, title TEXT, capacity INTEGER)');

        $tmpDir = sys_get_temp_dir() . '/preflow_val_test_' . uniqid();
        mkdir($tmpDir, 0755, true);
        file_put_contents($tmpDir . '/event.json', json_encode([
            'key' => 'event',
            'table' => 'events',
            'storage' => 'default',
            'fields' => [
                'title' => ['type' => 'string', 'searchable' => true, 'validate' => ['required', 'min:3']],
                'capacity' => ['type' => 'integer', 'validate' => ['required', 'integer']],
            ],
        ]));

        $registry = new TypeRegistry($tmpDir);
        $driver = new SqliteDriver($this->pdo);
        $dm = new DataManager(
            drivers: ['default' => $driver],
            typeRegistry: $registry,
            validatorFactory: new ValidatorFactory(new RuleFactory()),
        );

        $typeDef = $registry->get('event');
        $record = new \Preflow\Data\DynamicRecord($typeDef, ['uuid' => 'e1', 'title' => '', 'capacity' => 'abc']);

        try {
            $dm->saveType($record);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('title', $e->errors());
            $this->assertArrayHasKey('capacity', $e->errors());
        }

        // Cleanup
        unlink($tmpDir . '/event.json');
        rmdir($tmpDir);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit packages/validation/tests/DataManagerValidationTest.php -v`
Expected: FAIL — DataManager constructor signature doesn't match yet

- [ ] **Step 3: Modify DataManager to accept ValidatorFactory and auto-validate**

Replace `packages/data/src/DataManager.php` with the updated version. Key changes:

1. Add `ValidatorFactory` as an optional constructor parameter
2. Add `validate` and `rules` parameters to `save()`, `insert()`, `update()`
3. Add `validate` parameter to `saveType()`
4. Add private `validateModel()` and `validateDynamicRecord()` methods

```php
<?php

declare(strict_types=1);

namespace Preflow\Data;

use Preflow\Validation\ValidatorFactory;
use Preflow\Validation\ValidationException;

final class DataManager
{
    /**
     * @param array<string, StorageDriver> $drivers Named drivers (e.g., 'sqlite' => SqliteDriver)
     */
    public function __construct(
        private readonly array $drivers,
        private readonly string $defaultDriver = 'default',
        private readonly ?TypeRegistry $typeRegistry = null,
        private readonly ?ValidatorFactory $validatorFactory = null,
    ) {}

    public function getTypeRegistry(): ?TypeRegistry
    {
        return $this->typeRegistry;
    }

    /**
     * Find a single model by ID.
     *
     * @template T of Model
     * @param class-string<T> $modelClass
     * @return T|null
     */
    public function find(string $modelClass, string|int $id): ?Model
    {
        $meta = ModelMetadata::for($modelClass);
        $driver = $this->resolveDriver($meta->storage);

        $data = $driver->findOne($meta->table, $id, $meta->idField);

        if ($data === null) {
            return null;
        }

        $model = new $modelClass();
        $model->fill($data);

        return $model;
    }

    /**
     * Start a query for a typed model.
     *
     * @template T of Model
     * @param class-string<T> $modelClass
     * @return QueryBuilder<T>
     */
    public function query(string $modelClass): QueryBuilder
    {
        $meta = ModelMetadata::for($modelClass);
        $driver = $this->resolveDriver($meta->storage);

        return new QueryBuilder($driver, $meta, $modelClass);
    }

    /**
     * Execute a raw SQL query and return results.
     * Use for JOINs, subqueries, aggregates, and complex WHERE clauses
     * that QueryBuilder can't express.
     *
     * @param string $sql Raw SQL query
     * @param array<int|string, mixed> $bindings Parameter bindings
     * @param string $storage Driver name (defaults to 'default')
     * @return array<int, array<string, mixed>> Array of associative arrays
     */
    public function raw(string $sql, array $bindings = [], string $storage = 'default'): array
    {
        $driver = $this->resolveDriver($storage);
        return $driver->rawQuery($sql, $bindings);
    }

    /**
     * Save a model.
     *
     * @param array<string, list<string>> $rules Extra validation rules to merge
     */
    public function save(Model $model, bool $validate = true, array $rules = []): void
    {
        if ($validate) {
            $this->validateModel($model, $rules);
        }

        $meta = ModelMetadata::for($model::class);
        $driver = $this->resolveDriver($meta->storage);
        $data = $model->toArray();
        $id = $data[$meta->idField] ?? null;
        $isEmpty = $id === null || $id === '' || $id === 0 || $id === '0';

        $driver->save($meta->table, $id ?? '', $data, $meta->idField);

        if ($isEmpty) {
            $newId = $driver->lastInsertId();
            if ($newId !== '' && $newId !== 0) {
                $model->{$meta->idField} = is_int($newId) ? $newId : (is_numeric($newId) ? (int) $newId : $newId);
            }
        }
    }

    /**
     * Insert a new model. Sets the auto-generated ID on the model.
     * Use this when you explicitly want INSERT behavior (not upsert).
     *
     * @param array<string, list<string>> $rules Extra validation rules to merge
     */
    public function insert(Model $model, bool $validate = true, array $rules = []): void
    {
        if ($validate) {
            $this->validateModel($model, $rules);
        }

        $meta = ModelMetadata::for($model::class);
        $driver = $this->resolveDriver($meta->storage);
        $data = $model->toArray();

        // Force INSERT by passing empty ID (PdoDriver strips the ID field and does plain INSERT)
        $driver->save($meta->table, 0, $data, $meta->idField);

        $newId = $driver->lastInsertId();
        if ($newId !== '' && $newId !== 0) {
            $model->{$meta->idField} = is_int($newId) ? $newId : (is_numeric($newId) ? (int) $newId : $newId);
        }
    }

    /**
     * Update an existing model. Throws if ID is empty.
     * Use this when you explicitly want UPDATE behavior (not upsert).
     *
     * @param array<string, list<string>> $rules Extra validation rules to merge
     */
    public function update(Model $model, bool $validate = true, array $rules = []): void
    {
        if ($validate) {
            $this->validateModel($model, $rules);
        }

        $meta = ModelMetadata::for($model::class);
        $driver = $this->resolveDriver($meta->storage);
        $data = $model->toArray();
        $id = $data[$meta->idField] ?? null;

        if ($id === null || $id === '' || $id === 0 || $id === '0') {
            throw new \RuntimeException("Cannot update model without an ID. Use insert() for new records.");
        }

        $driver->save($meta->table, $id, $data, $meta->idField);
    }

    /**
     * Delete a model by class+ID or by model instance.
     *
     * @param class-string<Model>|Model $modelClassOrInstance
     */
    public function delete(string|Model $modelClassOrInstance, string|int|null $id = null): void
    {
        if ($modelClassOrInstance instanceof Model) {
            $meta = ModelMetadata::for($modelClassOrInstance::class);
            $driver = $this->resolveDriver($meta->storage);
            $data = $modelClassOrInstance->toArray();
            $modelId = $data[$meta->idField] ?? throw new \RuntimeException("Model missing ID field [{$meta->idField}].");
            $driver->delete($meta->table, $modelId, $meta->idField);
            return;
        }

        // Original path: class + ID
        if ($id === null) {
            throw new \RuntimeException('ID is required when deleting by class name.');
        }
        $meta = ModelMetadata::for($modelClassOrInstance);
        $driver = $this->resolveDriver($meta->storage);
        $driver->delete($meta->table, $id, $meta->idField);
    }

    /**
     * Find a single dynamic record by type and ID.
     */
    public function findType(string $type, string $id): ?DynamicRecord
    {
        $typeDef = $this->requireTypeRegistry()->get($type);
        $driver = $this->resolveDriver($typeDef->storage);
        $data = $driver->findOne($typeDef->table, $id, $typeDef->idField);

        if ($data === null) {
            return null;
        }

        return DynamicRecord::fromArray($typeDef, $data);
    }

    /**
     * Start a query for a dynamic type.
     */
    public function queryType(string $type): QueryBuilder
    {
        $typeDef = $this->requireTypeRegistry()->get($type);
        $driver = $this->resolveDriver($typeDef->storage);

        return QueryBuilder::forType($driver, $typeDef);
    }

    /**
     * Save a dynamic record.
     *
     * @param array<string, list<string>> $rules Extra validation rules to merge
     */
    public function saveType(DynamicRecord $record, bool $validate = true, array $rules = []): void
    {
        if ($validate) {
            $this->validateDynamicRecord($record, $rules);
        }

        $typeDef = $record->getType();
        $driver = $this->resolveDriver($typeDef->storage);
        $id = $record->getId();

        if ($id === null) {
            throw new \RuntimeException("DynamicRecord must have an ID ({$typeDef->idField}) before saving.");
        }

        $driver->save($typeDef->table, $id, $record->toArray(), $typeDef->idField);
    }

    /**
     * Delete a dynamic record by type and ID.
     */
    public function deleteType(string $type, string|int $id): void
    {
        $typeDef = $this->requireTypeRegistry()->get($type);
        $driver = $this->resolveDriver($typeDef->storage);
        $driver->delete($typeDef->table, $id, $typeDef->idField);
    }

    /**
     * @param array<string, list<string>> $extraRules
     */
    private function validateModel(Model $model, array $extraRules = []): void
    {
        if ($this->validatorFactory === null) {
            return;
        }

        $meta = ModelMetadata::for($model::class);
        $rules = $meta->validationRules;

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

    /**
     * @param array<string, list<string>> $extraRules
     */
    private function validateDynamicRecord(DynamicRecord $record, array $extraRules = []): void
    {
        if ($this->validatorFactory === null) {
            return;
        }

        $typeDef = $record->getType();
        $rules = $typeDef->validationRules();

        if ($extraRules !== []) {
            $rules = array_merge($rules, $extraRules);
        }

        if ($rules === []) {
            return;
        }

        $validator = $this->validatorFactory->make($rules, $record->toArray(), subject: $record);
        $result = $validator->validate();

        if ($result->fails()) {
            throw new ValidationException($result);
        }
    }

    private function requireTypeRegistry(): TypeRegistry
    {
        if ($this->typeRegistry === null) {
            throw new \RuntimeException('TypeRegistry is not configured. Set data.models_path in config.');
        }
        return $this->typeRegistry;
    }

    private function resolveDriver(string $name): StorageDriver
    {
        if (isset($this->drivers[$name])) {
            return $this->drivers[$name];
        }

        if (isset($this->drivers[$this->defaultDriver])) {
            return $this->drivers[$this->defaultDriver];
        }

        throw new \RuntimeException("Storage driver [{$name}] not configured.");
    }
}
```

- [ ] **Step 4: Run integration tests to verify they pass**

Run: `./vendor/bin/phpunit packages/validation/tests/DataManagerValidationTest.php -v`
Expected: All tests PASS

- [ ] **Step 5: Run ALL existing data tests to ensure no regressions**

Run: `./vendor/bin/phpunit packages/data/tests/ -v`
Expected: All existing tests PASS (the new optional `validatorFactory` param doesn't break anything)

- [ ] **Step 6: Commit**

```bash
git add packages/data/src/DataManager.php packages/validation/tests/DataManagerValidationTest.php
git commit -m "feat(validation): DataManager auto-validation on save/insert/update with bypass and override"
```

---

### Task 9: Monorepo Wiring (split workflow, composer)

**Files:**
- Modify: `.github/workflows/split.yml`

- [ ] **Step 1: Add validation package to the split workflow matrix**

In `.github/workflows/split.yml`, add to the `matrix.package` list:
```yaml
- { local: 'packages/validation', remote: 'validation' }
```

- [ ] **Step 2: Run the full test suite to verify everything works together**

Run: `./vendor/bin/phpunit -v`
Expected: All tests across all packages PASS (570 existing + new validation tests)

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/split.yml
git commit -m "chore: add validation package to monorepo split workflow"
```

---

### Task 10: Application Boot Integration

**Files:**
- Modify: `packages/core/src/Application.php`

This task wires the validation package into the framework's boot sequence so that `DataManager` automatically gets a `ValidatorFactory`, rule discovery runs on `app/Rules/`, and the `ValidatorFactory` is available in the container.

- [ ] **Step 1: Check current Application::bootDataLayer() to find exact insertion point**

Read `packages/core/src/Application.php` and find the `bootDataLayer()` method where `DataManager` is instantiated. The `ValidatorFactory` and `RuleFactory` need to be created and passed to the `DataManager` constructor.

- [ ] **Step 2: Add validation wiring in the data layer boot**

In `Application.php`, after the `DataManager` is created and before it's registered in the container, add:

```php
// Wire validation if the package is installed
$validatorFactory = null;
if (class_exists(\Preflow\Validation\RuleFactory::class)) {
    $ruleFactory = new \Preflow\Validation\RuleFactory();

    // Auto-discover custom rules from app/Rules/
    $rulesPath = $this->basePath('app/Rules');
    if (is_dir($rulesPath)) {
        $ruleClasses = [];
        foreach (glob($rulesPath . '/*.php') as $file) {
            $className = 'App\\Rules\\' . pathinfo($file, PATHINFO_FILENAME);
            if (class_exists($className)) {
                $ruleClasses[] = $className;
            }
        }
        $ruleFactory->discover($ruleClasses);
    }

    $validatorFactory = new \Preflow\Validation\ValidatorFactory($ruleFactory);
    $this->container->instance(\Preflow\Validation\RuleFactory::class, $ruleFactory);
    $this->container->instance(\Preflow\Validation\ValidatorFactory::class, $validatorFactory);
}
```

Then update the `DataManager` constructor call to pass `validatorFactory: $validatorFactory`.

- [ ] **Step 3: Run the full test suite**

Run: `./vendor/bin/phpunit -v`
Expected: All tests PASS

- [ ] **Step 4: Commit**

```bash
git add packages/core/src/Application.php
git commit -m "feat(validation): wire RuleFactory, ValidatorFactory, and rule discovery into Application boot"
```

---

### Task 11: Template Function Integration

**Files:**
- Create: `packages/validation/src/ValidationExtensionProvider.php`

This task adds `validation_errors()`, `validation_has_errors()`, and `old()` template functions.

- [ ] **Step 1: Create ValidationExtensionProvider**

```php
<?php

declare(strict_types=1);

namespace Preflow\Validation;

use Preflow\View\TemplateExtensionProvider;
use Preflow\View\TemplateFunctionDefinition;

final class ValidationExtensionProvider implements TemplateExtensionProvider
{
    private ?ErrorBag $errorBag = null;

    /** @var array<string, mixed> */
    private array $oldInput = [];

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

    public function getTemplateFunctions(): array
    {
        return [
            new TemplateFunctionDefinition(
                name: 'validation_errors',
                callable: fn (?string $field = null): string|array|null =>
                    $field !== null
                        ? $this->errorBag?->first($field)
                        : ($this->errorBag?->toArray() ?? []),
            ),
            new TemplateFunctionDefinition(
                name: 'validation_has_errors',
                callable: fn (?string $field = null): bool =>
                    $field !== null
                        ? ($this->errorBag?->has($field) ?? false)
                        : ($this->errorBag !== null && !$this->errorBag->isEmpty()),
            ),
            new TemplateFunctionDefinition(
                name: 'old',
                callable: fn (string $field, mixed $default = null): mixed =>
                    $this->oldInput[$field] ?? $default,
            ),
        ];
    }

    public function getTemplateGlobals(): array
    {
        return [];
    }
}
```

- [ ] **Step 2: Add preflow/view as optional dependency**

In `packages/validation/composer.json`, add to `require`:
```json
"preflow/view": "^0.1 || @dev"
```

- [ ] **Step 3: Register the extension provider in Application boot**

In `Application.php`, after the validation wiring from Task 10, add:

```php
if ($validatorFactory !== null && isset($engine)) {
    $validationExtension = new \Preflow\Validation\ValidationExtensionProvider();
    $this->container->instance(\Preflow\Validation\ValidationExtensionProvider::class, $validationExtension);
    $this->registerExtensionProvider($engine, $validationExtension);
}
```

- [ ] **Step 4: Run the full test suite**

Run: `./vendor/bin/phpunit -v`
Expected: All tests PASS

- [ ] **Step 5: Commit**

```bash
git add packages/validation/src/ValidationExtensionProvider.php packages/validation/composer.json packages/core/src/Application.php
git commit -m "feat(validation): template functions validation_errors(), validation_has_errors(), old()"
```

---

### Task 12: Final Verification & Documentation

- [ ] **Step 1: Run the complete test suite**

Run: `./vendor/bin/phpunit -v`
Expected: All tests PASS (previous 570 + new validation tests)

- [ ] **Step 2: Verify the package structure is complete**

Run: `find packages/validation -type f | sort`
Expected: All files from the file structure section are present

- [ ] **Step 3: Update the design spec status**

In `docs/superpowers/specs/2026-04-15-validation-package-design.md`, change the status line:
```
**Status:** Implemented
```

- [ ] **Step 4: Commit**

```bash
git add docs/superpowers/specs/2026-04-15-validation-package-design.md
git commit -m "docs: mark validation package design as implemented"
```
