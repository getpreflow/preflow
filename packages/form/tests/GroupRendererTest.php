<?php

declare(strict_types=1);

namespace Preflow\Form\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Form\GroupRenderer;

final class GroupRendererTest extends TestCase
{
    private GroupRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new GroupRenderer();
    }

    public function test_renders_group_wrapper(): void
    {
        $html = $this->renderer->render('<input name="zip"><input name="city">', []);
        $this->assertStringContainsString('form-group-wrapper', $html);
        $this->assertStringContainsString('form-group-fields', $html);
        $this->assertStringContainsString('<input name="zip">', $html);
    }

    public function test_renders_group_with_label(): void
    {
        $html = $this->renderer->render('<input name="zip">', ['label' => 'Address']);
        $this->assertStringContainsString('form-group-label', $html);
        $this->assertStringContainsString('Address', $html);
    }

    public function test_renders_group_with_class(): void
    {
        $html = $this->renderer->render('<input>', ['class' => 'form-row']);
        $this->assertStringContainsString('form-row', $html);
    }

    public function test_group_without_label_omits_label_div(): void
    {
        $html = $this->renderer->render('<input>', []);
        $this->assertStringNotContainsString('form-group-label', $html);
    }
}
