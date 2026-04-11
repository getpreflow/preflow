<?php

declare(strict_types=1);

namespace Preflow\View\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\View\TemplateFunctionDefinition;

final class TemplateFunctionDefinitionTest extends TestCase
{
    public function test_stores_name(): void
    {
        $fn = new TemplateFunctionDefinition(
            name: 'component',
            callable: fn () => '',
        );

        $this->assertSame('component', $fn->name);
    }

    public function test_stores_callable(): void
    {
        $callable = fn (string $name) => "<div>{$name}</div>";
        $fn = new TemplateFunctionDefinition(
            name: 'test',
            callable: $callable,
        );

        $this->assertSame('<div>hello</div>', ($fn->callable)('hello'));
    }

    public function test_is_safe_defaults_to_false(): void
    {
        $fn = new TemplateFunctionDefinition(
            name: 'test',
            callable: fn () => '',
        );

        $this->assertFalse($fn->isSafe);
    }

    public function test_is_safe_can_be_true(): void
    {
        $fn = new TemplateFunctionDefinition(
            name: 'test',
            callable: fn () => '',
            isSafe: true,
        );

        $this->assertTrue($fn->isSafe);
    }

    public function test_is_readonly(): void
    {
        $reflection = new \ReflectionClass(TemplateFunctionDefinition::class);

        $this->assertTrue($reflection->isReadOnly());
    }
}
