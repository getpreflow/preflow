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
