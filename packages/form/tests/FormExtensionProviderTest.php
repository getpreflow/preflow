<?php

declare(strict_types=1);

namespace Preflow\Form\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\OldInput;
use Preflow\Form\FieldRenderer;
use Preflow\Form\FormBuilder;
use Preflow\Form\FormExtensionProvider;
use Preflow\Form\GroupRenderer;
use Preflow\Form\ModelIntrospector;
use Preflow\View\TemplateExtensionProvider;
use Preflow\Testing\ArraySession;
use Preflow\View\TemplateFunctionDefinition;

final class FormExtensionProviderTest extends TestCase
{
    private FormExtensionProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new FormExtensionProvider(
            new FieldRenderer(),
            new GroupRenderer(),
            new ModelIntrospector(),
        );
    }

    public function test_implements_template_extension_provider(): void
    {
        $this->assertInstanceOf(TemplateExtensionProvider::class, $this->provider);
    }

    public function test_registers_form_begin(): void
    {
        $functions = $this->provider->getTemplateFunctions();
        $names = array_map(fn (TemplateFunctionDefinition $f) => $f->name, $functions);
        $this->assertContains('form_begin', $names);
    }

    public function test_registers_form_end(): void
    {
        $functions = $this->provider->getTemplateFunctions();
        $names = array_map(fn (TemplateFunctionDefinition $f) => $f->name, $functions);
        $this->assertContains('form_end', $names);
    }

    public function test_form_begin_returns_form_builder(): void
    {
        $functions = $this->provider->getTemplateFunctions();
        $formBegin = null;
        foreach ($functions as $f) {
            if ($f->name === 'form_begin') {
                $formBegin = $f;
                break;
            }
        }
        $this->assertNotNull($formBegin);
        $result = ($formBegin->callable)(['action' => '/test']);
        $this->assertInstanceOf(FormBuilder::class, $result);
    }

    public function test_form_end_returns_closing_tag(): void
    {
        $functions = $this->provider->getTemplateFunctions();
        $formEnd = null;
        foreach ($functions as $f) {
            if ($f->name === 'form_end') {
                $formEnd = $f;
                break;
            }
        }
        $this->assertNotNull($formEnd);
        $result = ($formEnd->callable)();
        $this->assertSame('</form>', $result);
    }

    public function test_field_value_falls_back_to_flashed_input(): void
    {
        $session = new ArraySession();
        $session->start();
        OldInput::keep($session, ['name' => 'Ada', 'city' => 'Bern']);
        $session->ageFlash();

        $provider = new FormExtensionProvider(new FieldRenderer(), new GroupRenderer(), new ModelIntrospector(), new OldInput(static fn () => $session));
        $provider->setOldInput(['name' => 'Grace']);
        $formBegin = array_values(array_filter($provider->getTemplateFunctions(), fn (TemplateFunctionDefinition $f) => $f->name === 'form_begin'))[0];
        $form = ($formBegin->callable)(['action' => '/test']);

        $this->assertStringContainsString('value="Grace"', $form->field('name'));
        $this->assertStringContainsString('value="Bern"', $form->field('city'));
    }
}
