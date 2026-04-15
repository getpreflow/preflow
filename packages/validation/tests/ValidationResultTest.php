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
