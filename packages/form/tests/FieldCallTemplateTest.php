<?php

declare(strict_types=1);

namespace Preflow\Form\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Blade\BladeEngine;
use Preflow\Form\FieldRenderer;
use Preflow\Form\FormExtensionProvider;
use Preflow\Form\GroupRenderer;
use Preflow\Form\ModelIntrospector;
use Preflow\Twig\TwigEngine;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;
use Preflow\View\SafeHtml;
use Preflow\View\TemplateEngineInterface;

/** form.field(name, {call: hd.post(...)}) in Twig and Blade: the call ends the control's opening tag. */
final class FieldCallTemplateTest extends TestCase
{
    private const INPUT = '<input type="email" name="email" value="" id="email" hx-post="/c/check" hx-trigger="blur" hx-target="#c-email">';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/preflow_form_call_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/cache', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function test_twig(): void
    {
        file_put_contents($this->dir . '/f.twig', <<<'TWIG'
            {% set form = form_begin() %}
            {{ form.field('email', {type: 'email', call: hd.post('check', {field: 'email'}, {on: 'blur', target: 'email'})})|raw }}
            TWIG);
        $engine = $this->withForms(new TwigEngine([$this->dir], new AssetCollector(new NonceGenerator())));

        $this->assertStringContainsString(self::INPUT, $engine->render('f.twig', ['hd' => $this->hd()]));
    }

    public function test_twig_refuses_a_call_that_is_not_a_short_call(): void
    {
        file_put_contents($this->dir . '/f.twig', "{% set form = form_begin() %}{{ form.field('email', {call: 'hx-post=\"/x\"'})|raw }}");
        $engine = $this->withForms(new TwigEngine([$this->dir], new AssetCollector(new NonceGenerator())));

        $this->expectExceptionMessage("Field 'email': call takes the result of a short call (hd.post(...), a SafeHtml), a string given.");
        $engine->render('f.twig');
    }

    public function test_blade(): void
    {
        file_put_contents($this->dir . '/f.blade.php', <<<'BLADE'
            @php($form = $__fn_form_begin([]))
            {!! $form->field('email', ['type' => 'email', 'call' => $hd->post('check', ['field' => 'email'], ['on' => 'blur', 'target' => 'email'])]) !!}
            BLADE);
        $engine = $this->withForms(new BladeEngine([$this->dir], new AssetCollector(new NonceGenerator()), cachePath: $this->dir . '/cache'));

        $this->assertStringContainsString(self::INPUT, $engine->render('f', ['hd' => $this->hd()]));
    }

    private function withForms(TemplateEngineInterface $engine): TemplateEngineInterface
    {
        $provider = new FormExtensionProvider(new FieldRenderer(), new GroupRenderer(), new ModelIntrospector());
        foreach ($provider->getTemplateFunctions() as $function) {
            $engine->addFunction($function);
        }

        return $engine;
    }

    /** A stand-in for the short calls: post() returns the SafeHtml attribute string, as hd.post does. */
    private function hd(): object
    {
        return new class {
            /**
             * @param array<string, mixed> $params
             * @param array<string, string> $options
             */
            public function post(string $action, array $params = [], array $options = []): SafeHtml
            {
                return new SafeHtml(sprintf(
                    'hx-post="/c/%s" hx-trigger="%s" hx-target="#c-%s"',
                    $action,
                    $options['on'] ?? 'click',
                    $options['target'] ?? 'self',
                ));
            }
        };
    }
}
