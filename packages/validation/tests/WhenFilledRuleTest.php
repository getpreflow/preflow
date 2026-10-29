<?php

declare(strict_types=1);

namespace Preflow\Validation\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Validation\Message;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\Rules\Numeric;
use Preflow\Validation\Rules\Step;
use Preflow\Validation\Rules\WhenFilled;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\Validator;

final class WhenFilledRuleTest extends TestCase
{
    public function test_empty_value_skips_the_wrapped_rules(): void
    {
        $rule = new WhenFilled(new Numeric(), new Step(0.5));
        foreach (['', null] as $value) {
            $this->assertTrue($rule->validate($value, new ValidationContext('f', [])), var_export($value, true));
        }
    }

    public function test_filled_value_answers_with_the_first_failing_rule(): void
    {
        $rule = new WhenFilled(new Numeric(), new Step(0.5));
        $this->assertTrue($rule->validate('2.5', new ValidationContext('f', [])));
        $this->assertSame('validation.numeric', $rule->validate('abc', new ValidationContext('f', []))->key);
        $this->assertSame('validation.step', $rule->validate('2.3', new ValidationContext('f', []))->key);
        $this->assertSame('validation.numeric', $rule->validate(' ', new ValidationContext('f', []))->key);
    }

    public function test_required_still_judges_an_empty_value(): void
    {
        $rules = ['f' => ['required', new WhenFilled(new Numeric(), new Step(0.5))]];
        $result = (new Validator(new RuleFactory(), $rules, ['f' => '']))->validate();
        $this->assertSame(['f' => ['This field is required.']], $result->errors());

        $optional = (new Validator(new RuleFactory(), ['f' => [new WhenFilled(new Numeric())]], ['f' => '']))->validate();
        $this->assertFalse($optional->fails());
    }

    public function test_rule_lists_dedupe_by_string_keep_it(): void
    {
        $a = new WhenFilled(new Numeric());
        $b = new WhenFilled(new Numeric(), new Step(1));
        $this->assertSame([$a, 'numeric', $b], array_values(array_unique([$a, 'numeric', $b, 'numeric'])));
    }
}
