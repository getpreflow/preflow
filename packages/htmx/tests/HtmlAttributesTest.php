<?php

declare(strict_types=1);

namespace Preflow\Htmx\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Htmx\HtmlAttributes;

final class HtmlAttributesTest extends TestCase
{
    public function test_renders_single_attribute(): void
    {
        $attrs = new HtmlAttributes(['hx-get' => '/api/data']);

        $this->assertSame('hx-get="/api/data"', (string) $attrs);
    }

    public function test_renders_multiple_attributes(): void
    {
        $attrs = new HtmlAttributes([
            'hx-post' => '/action',
            'hx-target' => '#result',
            'hx-swap' => 'outerHTML',
        ]);

        $result = (string) $attrs;

        $this->assertStringContainsString('hx-post="/action"', $result);
        $this->assertStringContainsString('hx-target="#result"', $result);
        $this->assertStringContainsString('hx-swap="outerHTML"', $result);
    }

    public function test_escapes_values(): void
    {
        $attrs = new HtmlAttributes(['data-name' => 'O\'Brien & Co']);

        $result = (string) $attrs;

        $this->assertStringContainsString('data-name="O&#039;Brien &amp; Co"', $result);
    }

    public function test_merge(): void
    {
        $a = new HtmlAttributes(['hx-get' => '/a', 'hx-target' => '#x']);
        $b = new HtmlAttributes(['hx-swap' => 'innerHTML']);

        $merged = $a->merge($b);

        $result = (string) $merged;
        $this->assertStringContainsString('hx-get="/a"', $result);
        $this->assertStringContainsString('hx-swap="innerHTML"', $result);
    }

    public function test_empty_renders_empty_string(): void
    {
        $attrs = new HtmlAttributes([]);

        $this->assertSame('', (string) $attrs);
    }

    public function test_to_array(): void
    {
        $attrs = new HtmlAttributes(['hx-get' => '/test']);

        $this->assertSame(['hx-get' => '/test'], $attrs->toArray());
    }
}
