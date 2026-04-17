<?php

declare(strict_types=1);

namespace Preflow\Htmx\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Htmx\HtmxDriver;
use Preflow\Htmx\ResponseHeaders;
use Preflow\View\FormHypermediaDriver;

final class HtmxFormDriverTest extends TestCase
{
    private HtmxDriver $driver;

    protected function setUp(): void
    {
        $this->driver = new HtmxDriver(new ResponseHeaders());
    }

    public function test_implements_form_hypermedia_driver(): void
    {
        $this->assertInstanceOf(FormHypermediaDriver::class, $this->driver);
    }

    public function test_form_attributes(): void
    {
        $attrs = $this->driver->formAttributes('/submit', 'post');
        $this->assertSame('/submit', $attrs['hx-post']);
        $this->assertArrayHasKey('hx-target', $attrs);
    }

    public function test_form_attributes_with_target(): void
    {
        $attrs = $this->driver->formAttributes('/submit', 'post', ['target' => '#result']);
        $this->assertSame('#result', $attrs['hx-target']);
    }

    public function test_inline_validation_attributes(): void
    {
        $attrs = $this->driver->inlineValidationAttributes('/validate', 'email');
        $this->assertSame('/validate', $attrs['hx-post']);
        $this->assertStringContainsString('blur', $attrs['hx-trigger']);
    }

    public function test_inline_validation_custom_trigger(): void
    {
        $attrs = $this->driver->inlineValidationAttributes('/validate', 'search', 'change');
        $this->assertStringContainsString('change', $attrs['hx-trigger']);
    }

    public function test_submit_attributes(): void
    {
        $attrs = $this->driver->submitAttributes('#form-wrapper');
        $this->assertSame('#form-wrapper', $attrs['hx-target']);
    }
}
