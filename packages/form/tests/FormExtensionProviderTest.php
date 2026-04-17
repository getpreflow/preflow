<?php

declare(strict_types=1);

namespace Preflow\Form\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Form\FieldRenderer;
use Preflow\Form\FormBuilder;
use Preflow\Form\FormExtensionProvider;
use Preflow\Form\GroupRenderer;
use Preflow\Form\ModelIntrospector;
use Preflow\View\TemplateExtensionProvider;
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
}
