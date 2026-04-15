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
