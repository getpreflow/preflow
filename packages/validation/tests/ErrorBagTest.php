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
