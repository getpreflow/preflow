<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\Types\BooleanFieldType;
use Preflow\Folio\Field\Types\DateFieldType;
use Preflow\Folio\Field\Types\SelectFieldType;
use Preflow\Folio\Field\Types\TextFieldType;

/** boolean, date and select field types, plus the monospace text option. */
final class ChoiceFieldTypesTest extends TestCase
{
    public function test_keys(): void
    {
        $this->assertSame('boolean', (new BooleanFieldType())->key());
        $this->assertSame('date', (new DateFieldType())->key());
        $this->assertSame('select', (new SelectFieldType())->key());
    }

    public function test_boolean_normalizes_missing_to_false(): void
    {
        $type = new BooleanFieldType();

        foreach (['1', 'on', 'true', true] as $raw) {
            $this->assertTrue($type->normalizeInput($raw, []));
        }
        foreach ([null, '0', '', 'false', false] as $raw) {
            $this->assertFalse($type->normalizeInput($raw, []));
        }
    }

    public function test_boolean_editor_has_hidden_zero_and_checked_state(): void
    {
        $checked = (new BooleanFieldType())->renderEditor(new FieldContext(name: 'featured', value: true));
        $unchecked = (new BooleanFieldType())->renderEditor(new FieldContext(name: 'featured', value: false));

        $this->assertStringContainsString('<input type="hidden" name="featured" value="0">', $checked);
        $this->assertStringContainsString('type="checkbox"', $checked);
        $this->assertStringContainsString('value="1"', $checked);
        $this->assertStringContainsString('checked', $checked);
        $this->assertStringNotContainsString(' checked', $unchecked);
    }

    public function test_boolean_frontend_is_yes_no(): void
    {
        $this->assertSame('Yes', (new BooleanFieldType())->renderFrontend(true, []));
        $this->assertSame('No', (new BooleanFieldType())->renderFrontend(false, []));
    }

    public function test_date_input_type_and_rules(): void
    {
        $date = new DateFieldType();

        $this->assertStringContainsString('type="date"', $date->renderEditor(new FieldContext(name: 'd', value: '2026-10-19')));
        $this->assertStringContainsString('value="2026-10-19"', $date->renderEditor(new FieldContext(name: 'd', value: '2026-10-19')));
        $this->assertStringContainsString(
            'type="datetime-local"',
            $date->renderEditor(new FieldContext(name: 'd', config: ['time' => true])),
        );
        $this->assertSame(['regex:/^\d{4}-\d{2}-\d{2}$/'], $date->rules([]));
        $this->assertSame(['regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/'], $date->rules(['time' => true]));
    }

    public function test_select_options_map_and_list(): void
    {
        $select = new SelectFieldType();
        $map = ['options' => ['draft' => 'Draft', 'published' => 'Published']];

        $html = $select->renderEditor(new FieldContext(name: 'status', value: 'published', config: $map));
        $this->assertStringContainsString('<select', $html);
        $this->assertStringContainsString('<option value="published" selected>Published</option>', $html);
        $this->assertSame(['in:draft,published'], $select->rules($map));
        $this->assertSame('Published', $select->renderFrontend('published', $map));

        $list = ['options' => ['red', 'green']];
        $this->assertSame(['in:red,green'], $select->rules($list));
        $this->assertStringContainsString(
            '<option value="green" selected>green</option>',
            $select->renderEditor(new FieldContext(name: 'c', value: 'green', config: $list)),
        );
    }

    public function test_select_stale_value_is_kept_visible(): void
    {
        $html = (new SelectFieldType())->renderEditor(new FieldContext(
            name: 'status',
            value: 'archived',
            config: ['options' => ['draft' => 'Draft', 'published' => 'Published']],
        ));

        $this->assertStringContainsString('<option value="archived" selected>archived (no longer an option)</option>', $html);
    }

    public function test_select_empty_input_normalizes_to_null(): void
    {
        $this->assertNull((new SelectFieldType())->normalizeInput('', ['options' => ['a', 'b']]));
        $this->assertSame('a', (new SelectFieldType())->normalizeInput('a', ['options' => ['a', 'b']]));
    }

    public function test_select_radio_display(): void
    {
        $html = (new SelectFieldType())->renderEditor(new FieldContext(
            name: 'size',
            value: 'm',
            config: ['options' => ['s' => 'Small', 'm' => 'Medium'], 'display' => 'radio'],
        ));

        $this->assertStringContainsString('type="radio"', $html);
        $this->assertMatchesRegularExpression('/value="m"[^>]*checked/', $html);
        $this->assertStringNotContainsString('<select', $html);
    }

    public function test_text_monospace_option(): void
    {
        $mono = (new TextFieldType())->renderEditor(new FieldContext(name: 'code', config: ['monospace' => true]));
        $plain = (new TextFieldType())->renderEditor(new FieldContext(name: 'code'));

        $this->assertStringContainsString('folio-input-mono', $mono);
        $this->assertStringNotContainsString('folio-input-mono', $plain);
    }
}
