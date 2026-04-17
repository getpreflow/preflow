<?php

declare(strict_types=1);

namespace Preflow\Validation\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Validation\Attributes\Validate;

final class ValidateAttributeTest extends TestCase
{
    public function test_rules_without_on_are_stored_as_is(): void
    {
        $attr = new Validate('required', 'min:2', 'max:255');

        $this->assertSame(['required', 'min:2', 'max:255'], $attr->rules);
        $this->assertNull($attr->on);
    }

    public function test_on_directive_is_extracted_from_rules(): void
    {
        $attr = new Validate('required', 'on:create', 'min:2');

        $this->assertSame(['required', 'min:2'], $attr->rules);
        $this->assertSame('create', $attr->on);
    }

    public function test_on_update_scenario(): void
    {
        $attr = new Validate('required', 'on:update');

        $this->assertSame(['required'], $attr->rules);
        $this->assertSame('update', $attr->on);
    }

    public function test_on_directive_is_not_included_in_rules(): void
    {
        $attr = new Validate('on:create', 'required', 'email');

        $this->assertNotContains('on:create', $attr->rules);
        $this->assertSame(['required', 'email'], $attr->rules);
    }

    public function test_no_on_directive_results_in_null(): void
    {
        $attr = new Validate('required');

        $this->assertNull($attr->on);
    }

    public function test_empty_rules_with_no_on(): void
    {
        $attr = new Validate();

        $this->assertSame([], $attr->rules);
        $this->assertNull($attr->on);
    }

    public function test_on_only_leaves_empty_rules(): void
    {
        $attr = new Validate('on:create');

        $this->assertSame([], $attr->rules);
        $this->assertSame('create', $attr->on);
    }
}
