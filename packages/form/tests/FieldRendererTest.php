<?php

declare(strict_types=1);

namespace Preflow\Form\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Form\FieldRenderer;

final class FieldRendererTest extends TestCase
{
    private FieldRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new FieldRenderer();
    }

    public function test_renders_text_input(): void
    {
        $html = $this->renderer->renderInput('name', 'text', '', []);
        $this->assertStringContainsString('<input', $html);
        $this->assertStringContainsString('type="text"', $html);
        $this->assertStringContainsString('name="name"', $html);
    }

    public function test_renders_textarea(): void
    {
        $html = $this->renderer->renderInput('bio', 'textarea', 'Hello', ['rows' => '5']);
        $this->assertStringContainsString('<textarea', $html);
        $this->assertStringContainsString('name="bio"', $html);
        $this->assertStringContainsString('rows="5"', $html);
        $this->assertStringContainsString('>Hello</textarea>', $html);
    }

    public function test_renders_select(): void
    {
        $html = $this->renderer->renderInput('role', 'select', 'editor', [
            'options' => ['admin' => 'Admin', 'editor' => 'Editor', 'viewer' => 'Viewer'],
        ]);
        $this->assertStringContainsString('<select', $html);
        $this->assertStringContainsString('<option', $html);
        $this->assertStringContainsString('selected', $html);
    }

    public function test_renders_checkbox(): void
    {
        $html = $this->renderer->renderInput('active', 'checkbox', '1', []);
        $this->assertStringContainsString('type="checkbox"', $html);
        $this->assertStringContainsString('checked', $html);
    }

    public function test_renders_hidden(): void
    {
        $html = $this->renderer->renderInput('token', 'hidden', 'abc', []);
        $this->assertStringContainsString('type="hidden"', $html);
        $this->assertStringContainsString('value="abc"', $html);
    }

    public function test_renders_file(): void
    {
        $html = $this->renderer->renderInput('avatar', 'file', '', []);
        $this->assertStringContainsString('type="file"', $html);
    }

    public function test_custom_attrs_override(): void
    {
        $html = $this->renderer->renderInput('search', 'text', '', [
            'hx-get' => '/search',
            'hx-trigger' => 'keyup changed delay:300ms',
        ]);
        $this->assertStringContainsString('hx-get="/search"', $html);
        $this->assertStringContainsString('hx-trigger="keyup changed delay:300ms"', $html);
    }

    public function test_renders_field_block(): void
    {
        $html = $this->renderer->renderField('email', [
            'type' => 'email',
            'value' => 'test@example.com',
            'label' => 'Email Address',
            'required' => true,
            'errors' => ['Invalid email'],
        ]);
        $this->assertStringContainsString('form-group', $html);
        $this->assertStringContainsString('has-error', $html);
        $this->assertStringContainsString('Email Address', $html);
        $this->assertStringContainsString('form-required', $html);
        $this->assertStringContainsString('type="email"', $html);
        $this->assertStringContainsString('value="test@example.com"', $html);
        $this->assertStringContainsString('Invalid email', $html);
    }

    public function test_renders_field_with_help_text(): void
    {
        $html = $this->renderer->renderField('password', [
            'type' => 'password',
            'help' => 'Must be at least 8 characters',
        ]);
        $this->assertStringContainsString('form-help', $html);
        $this->assertStringContainsString('Must be at least 8 characters', $html);
    }

    public function test_label_auto_generated_from_field_name(): void
    {
        $html = $this->renderer->renderField('first_name', []);
        $this->assertStringContainsString('First Name', $html);
    }

    public function test_escapes_html_in_values(): void
    {
        $html = $this->renderer->renderInput('name', 'text', '<script>alert("xss")</script>', []);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
