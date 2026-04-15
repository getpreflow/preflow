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
