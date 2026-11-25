<?php

declare(strict_types=1);

namespace Preflow\Blade\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Blade\BladeEngine;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;
use Preflow\View\TemplateFunctionDefinition;

final class TemplateFunctionsValueTest extends TestCase
{
    private BladeEngine $engine;
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/preflow_blade_fn_' . uniqid();
        mkdir($this->dir . '/cache', 0755, true);
        $this->engine = new BladeEngine(
            templateDirs: [$this->dir],
            assetCollector: new AssetCollector(new NonceGenerator()),
            cachePath: $this->dir . '/cache',
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/cache/*') ?: [] as $f) {
            unlink($f);
        }
        foreach (glob($this->dir . '/*.php') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir . '/cache');
        rmdir($this->dir);
    }

    private function template(string $name, string $source): void
    {
        file_put_contents($this->dir . "/{$name}.blade.php", $source);
    }

    /** Illuminate wraps what a template throws in a ViewException; the LogicException is its cause. */
    private function expectRenderRefusal(string $template, string $messagePart): void
    {
        try {
            $this->engine->render($template);
            $this->fail('Expected the render to be refused.');
        } catch (\Illuminate\View\ViewException $e) {
            $this->assertInstanceOf(\LogicException::class, $e->getPrevious());
            $this->assertStringContainsString($messagePart, $e->getPrevious()->getMessage());
        }
    }

    private function register(string $name, callable $fn, bool $safe = false): void
    {
        $this->engine->addFunction(new TemplateFunctionDefinition($name, $fn, $safe));
    }

    public function test_fn_returns_the_value_as_it_is(): void
    {
        $this->register('make_box', fn (string $label): object => new class ($label) {
            public function __construct(public string $label) {}
            public function open(): string
            {
                return '<div title="' . $this->label . '">';
            }
        });
        $this->template('v', '@php($box = $fn->make_box("x")){!! $box->open() !!}');

        $this->assertSame('<div title="x">', $this->engine->render('v'));
    }

    public function test_directive_of_an_object_function_throws_naming_the_value_form(): void
    {
        $this->register('make_box', fn (): object => new \ArrayObject());
        $this->template('d', '@make_box()');

        $this->expectRenderRefusal('d', 'make_box returns a ArrayObject, not text: @make_box can\'t print it. Take it as a value: @php($x = $fn->make_box(...))');
    }

    public function test_directive_of_an_array_function_throws(): void
    {
        $this->register('list_it', fn (): array => [1]);
        $this->template('a', '@list_it()');

        $this->expectRenderRefusal('a', '$fn->list_it(...)');
    }

    public function test_text_functions_echo_as_before(): void
    {
        $this->register('raw_fn', fn (): string => '<b>x</b>', true);
        $this->register('esc_fn', fn (): string => '<b>x</b>');
        $this->register('num_fn', fn (): int => 7);
        $this->register('null_fn', fn (): ?string => null);
        $this->register('str_fn', fn (): \Stringable => new class implements \Stringable {
            public function __toString(): string
            {
                return '<i>';
            }
        });
        $this->template('t', '@raw_fn()|@esc_fn()|@num_fn()|@null_fn()|@str_fn()');

        $this->assertSame('<b>x</b>|&lt;b&gt;x&lt;/b&gt;|7||&lt;i&gt;', $this->engine->render('t'));
    }

    public function test_fn_with_text_result_returns_it_unescaped(): void
    {
        $this->register('esc_fn', fn (): string => '<b>');
        $this->template('s', '{!! $fn->esc_fn() !!}');

        $this->assertSame('<b>', $this->engine->render('s'));
    }

    public function test_unknown_function_through_fn_throws_naming_it(): void
    {
        $this->register('known_one', fn (): string => '');
        $this->template('u', '{!! $fn->nope() !!}');

        $this->expectRenderRefusal('u', 'nope');
    }

    public function test_unknown_function_message_lists_known_functions(): void
    {
        $this->register('known_one', fn (): string => '');
        $this->template('u2', '{!! $fn->nope() !!}');

        $this->expectRenderRefusal('u2', 'known_one');
        $this->expectRenderRefusal('u2', 'addFunction');
    }

    public function test_dollar_fn_prefixed_variables_keep_working(): void
    {
        $this->register('hi', fn (): string => 'hi');
        $this->template('p', '{{ $__fn_hi() }}');

        $this->assertSame('hi', $this->engine->render('p'));
    }

    public function test_form_begin_builder_renders_its_form_tag_through_fn(): void
    {
        if (!class_exists(\Preflow\Form\FormExtensionProvider::class)) {
            $this->markTestSkipped('preflow/form is not installed.');
        }
        $provider = new \Preflow\Form\FormExtensionProvider(
            new \Preflow\Form\FieldRenderer(),
            new \Preflow\Form\GroupRenderer(),
            new \Preflow\Form\ModelIntrospector(),
        );
        foreach ($provider->getTemplateFunctions() as $def) {
            $this->engine->addFunction($def);
        }
        $this->template('f', '@php($form = $fn->form_begin(["action" => "/go"])){!! $form->begin() !!}{!! $form->end() !!}');

        $html = $this->engine->render('f');
        $this->assertStringContainsString('<form', $html);
        $this->assertStringContainsString('action="/go"', $html);
        $this->assertStringContainsString('</form>', $html);
    }

    public function test_form_begin_directive_throws_naming_the_fn_form(): void
    {
        if (!class_exists(\Preflow\Form\FormExtensionProvider::class)) {
            $this->markTestSkipped('preflow/form is not installed.');
        }
        $provider = new \Preflow\Form\FormExtensionProvider(
            new \Preflow\Form\FieldRenderer(),
            new \Preflow\Form\GroupRenderer(),
            new \Preflow\Form\ModelIntrospector(),
        );
        foreach ($provider->getTemplateFunctions() as $def) {
            $this->engine->addFunction($def);
        }
        $this->template('g', '@form_begin()');

        $this->expectRenderRefusal('g', '$fn->form_begin(...)');
    }
}
