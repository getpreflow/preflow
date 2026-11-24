<?php

declare(strict_types=1);

namespace Preflow\Form\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Form\FieldRenderer;
use Preflow\Form\FormBuilder;
use Preflow\Form\GroupRenderer;
use Preflow\Form\ModelIntrospector;
use Preflow\View\FormHypermediaDriver;
use Preflow\View\SafeHtml;

/** A field's `call` option: the SafeHtml of a short call, written as it is into the control's opening tag. */
final class FieldCallTest extends TestCase
{
    private const CALL = 'hx-post="/check?f=email&amp;x=1" hx-trigger="blur" hx-target="#c-email" hx-vals=\'{"field":"email"}\'';

    private FieldRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new FieldRenderer();
    }

    /** @return array<string, array{string, string, array<string, mixed>}> */
    public static function controls(): array
    {
        return [
            'input'    => ['email', '<input type="email" name="email" value="a@b.c" id="email" ' . self::CALL . '>', []],
            'textarea' => ['textarea', '<textarea name="email" id="email" ' . self::CALL . ">\na@b.c</textarea>", []],
            'select'   => ['select', '<select name="email" id="email" ' . self::CALL . '><option value="x">X</option></select>', ['options' => ['x' => 'X']]],
            'checkbox' => ['checkbox', '<input type="checkbox" name="email" id="email" ' . self::CALL . '>', []],
        ];
    }

    /** @param array<string, mixed> $attrs */
    #[DataProvider('controls')]
    public function test_the_call_ends_the_opening_tag_of_each_control(string $type, string $control, array $attrs): void
    {
        $html = $this->renderer->renderField('email', [
            'type'  => $type,
            'value' => 'a@b.c',
            'attrs' => $attrs,
            'call'  => new SafeHtml(self::CALL),
        ]);

        $this->assertStringContainsString($control, $html);
    }

    public function test_attrs_still_work_next_to_a_call(): void
    {
        $html = $this->renderer->renderField('email', [
            'attrs' => ['class' => 'wide', 'autocomplete' => 'email', 'required' => true],
            'call'  => new SafeHtml('hx-post="/check"'),
        ]);

        $this->assertStringContainsString('<input type="text" name="email" value="" id="email" class="wide" autocomplete="email" required hx-post="/check">', $html);
    }

    public function test_no_call_and_a_null_call_render_as_before(): void
    {
        $plain = $this->renderer->renderField('email', []);

        $this->assertSame($plain, $this->renderer->renderField('email', ['call' => null]));
        $this->assertStringContainsString('<input type="text" name="email" value="" id="email">', $plain);
    }

    public function test_render_input_takes_the_call_too(): void
    {
        $this->assertSame(
            '<textarea name="bio" hx-post="/x">' . "\nHi</textarea>",
            $this->renderer->renderInput('bio', 'textarea', 'Hi', [], new SafeHtml('hx-post="/x"')),
        );
    }

    /** @return array<string, array{mixed, string}> */
    public static function notACall(): array
    {
        return [
            'string' => ['hx-post="/x"', 'a string'],
            'array'  => [['hx-post' => '/x'], 'an array'],
            'object' => [new \ArrayObject(), 'ArrayObject'],
            'int'    => [3, 'an int'],
        ];
    }

    #[DataProvider('notACall')]
    public function test_a_call_that_is_not_safe_html_is_refused(mixed $call, string $named): void
    {
        try {
            $this->renderer->renderField('email', ['call' => $call]);
            $this->fail('refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("Field 'email'", $e->getMessage());
            $this->assertStringContainsString($named, $e->getMessage());
            $this->assertStringContainsString('short call', $e->getMessage());
            $this->assertStringContainsString('attrs', $e->getMessage(), 'names where plain attributes go');
        }
    }

    /** Review focus: attrs and the call writing the same attribute is refused, never printed twice. */
    public function test_an_attribute_both_attrs_and_the_call_write_is_refused(): void
    {
        try {
            $this->renderer->renderField('email', [
                'attrs' => ['class' => 'x', 'HX-Trigger' => 'change'],
                'call'  => new SafeHtml(self::CALL),
            ]);
            $this->fail('refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(
                "Field 'email': its call writes hx-trigger, and attrs (or the field itself) set it too. "
                . 'An attribute may be written once: leave it out of attrs, or change the call.',
                $e->getMessage(),
            );
        }
    }

    public function test_an_attribute_the_field_writes_itself_is_refused_too(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('its call writes name, id');

        $this->renderer->renderField('email', ['call' => new SafeHtml('name="other" id=x hx-post="/x"')]);
    }

    public function test_an_attribute_attrs_leaves_out_with_false_is_no_duplicate(): void
    {
        $html = $this->renderer->renderField('email', [
            'attrs' => ['hx-post' => false, 'hx-trigger' => null],
            'call'  => new SafeHtml('hx-post="/x" hx-trigger="blur"'),
        ]);

        $this->assertStringContainsString('<input type="text" name="email" value="" id="email" hx-post="/x" hx-trigger="blur">', $html);
    }

    public function test_the_form_builder_passes_the_call_on(): void
    {
        $builder = new FormBuilder(new FieldRenderer(), new GroupRenderer(), new ModelIntrospector());

        $this->assertStringContainsString(
            '<select name="status" id="status" hx-post="/x"><option value="a">A</option></select>',
            $builder->select('status', ['options' => ['a' => 'A'], 'call' => new SafeHtml('hx-post="/x"')]),
        );
        $this->assertStringContainsString(
            '<input type="checkbox" name="ok" id="ok" hx-post="/y">',
            $builder->checkbox('ok', ['call' => new SafeHtml('hx-post="/y"')]),
        );
    }

    /** The form's inline validation writes hx-* attributes itself: with a call on the field, that's a duplicate. */
    public function test_a_call_on_a_field_with_inline_validation_is_refused_and_validate_false_resolves_it(): void
    {
        $driver = new class implements FormHypermediaDriver {
            public function formAttributes(string $action, string $method, array $options = []): array
            {
                return [];
            }

            public function inlineValidationAttributes(string $endpoint, string $field, string $trigger = 'blur'): array
            {
                return ['hx-post' => $endpoint, 'hx-trigger' => $trigger];
            }

            public function submitAttributes(string $target, array $options = []): array
            {
                return [];
            }
        };
        $builder = new FormBuilder(new FieldRenderer(), new GroupRenderer(), new ModelIntrospector(), [
            'driver' => $driver, 'componentId' => 'c1', 'validate_endpoint' => '/validate',
        ]);

        try {
            $builder->field('email', ['call' => new SafeHtml('hx-post="/check" hx-trigger="blur"')]);
            $this->fail('refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(
                "Field 'email': its call writes hx-post, hx-trigger, and the form's inline validation writes them too. "
                . 'Pass validate: false on this field to use the call.',
                $e->getMessage(),
            );
        }

        $this->assertStringContainsString(
            '<input type="text" name="email" value="" id="email" hx-post="/check" hx-trigger="blur">',
            $builder->field('email', ['validate' => false, 'call' => new SafeHtml('hx-post="/check" hx-trigger="blur"')]),
        );
    }
}
