<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\Types\NumberFieldType;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\Folio\Field\Types\TextFieldType;

final class ScalarFieldTypesTest extends TestCase
{
    public function test_keys(): void
    {
        $this->assertSame('string', (new StringFieldType())->key());
        $this->assertSame('text', (new TextFieldType())->key());
        $this->assertSame('number', (new NumberFieldType())->key());
    }

    public function test_string_renders_text_input_with_label_and_value(): void
    {
        $html = (new StringFieldType())->renderEditor(new FieldContext(
            name: 'title', label: 'Title', value: 'Hello', required: true,
        ));
        $this->assertStringContainsString('name="title"', $html);
        $this->assertStringContainsString('type="text"', $html);
        $this->assertStringContainsString('value="Hello"', $html);
        $this->assertStringContainsString('Title', $html);
        $this->assertStringContainsString('form-required', $html); // required marker
    }

    public function test_text_renders_textarea(): void
    {
        $html = (new TextFieldType())->renderEditor(new FieldContext(name: 'body'));
        $this->assertStringContainsString('<textarea', $html);
        $this->assertStringContainsString('name="body"', $html);
    }

    public function test_number_renders_number_input(): void
    {
        $html = (new NumberFieldType())->renderEditor(new FieldContext(name: 'qty'));
        $this->assertStringContainsString('type="number"', $html);
    }

    public function test_errors_render(): void
    {
        $html = (new StringFieldType())->renderEditor(new FieldContext(
            name: 'title', errors: ['Title is required'],
        ));
        $this->assertStringContainsString('has-error', $html);
        $this->assertStringContainsString('Title is required', $html);
    }

    public function test_storage_roundtrip_and_safe_frontend(): void
    {
        $t = new StringFieldType();
        $this->assertSame('x', $t->toStorage($t->normalizeInput('x', [])));
        $this->assertSame('x', $t->fromStorage('x'));
        $this->assertSame('&lt;b&gt;hi&lt;/b&gt;', $t->renderFrontend('<b>hi</b>', []));
    }
}
