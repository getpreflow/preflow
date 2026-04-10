<?php

declare(strict_types=1);

namespace Preflow\Components\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Components\Component;

class SimpleComponent extends Component
{
    public string $title = '';

    public function resolveState(): void
    {
        $this->title = $this->props['title'] ?? 'Default';
    }

    public function actions(): array
    {
        return ['refresh'];
    }

    public function actionRefresh(): void
    {
        $this->title = 'Refreshed';
    }
}

class ComponentWithFallback extends Component
{
    public function resolveState(): void
    {
        throw new \RuntimeException('Broken on purpose');
    }

    public function fallback(\Throwable $e): string
    {
        return '<div class="error">Fallback: ' . $e->getMessage() . '</div>';
    }
}

class ComponentWithTag extends Component
{
    public string $tag = 'section';
}

final class ComponentTest extends TestCase
{
    public function test_component_id_generated_from_class(): void
    {
        $component = new SimpleComponent();

        $this->assertNotEmpty($component->getComponentId());
        $this->assertStringContainsString('SimpleComponent', $component->getComponentId());
    }

    public function test_component_id_includes_props_hash(): void
    {
        $a = new SimpleComponent();
        $a->setProps(['id' => '1']);

        $b = new SimpleComponent();
        $b->setProps(['id' => '2']);

        $this->assertNotSame($a->getComponentId(), $b->getComponentId());
    }

    public function test_same_props_produce_same_id(): void
    {
        $a = new SimpleComponent();
        $a->setProps(['id' => '1']);

        $b = new SimpleComponent();
        $b->setProps(['id' => '1']);

        $this->assertSame($a->getComponentId(), $b->getComponentId());
    }

    public function test_resolve_state_sets_properties(): void
    {
        $component = new SimpleComponent();
        $component->setProps(['title' => 'Hello']);
        $component->resolveState();

        $this->assertSame('Hello', $component->title);
    }

    public function test_resolve_state_uses_default(): void
    {
        $component = new SimpleComponent();
        $component->resolveState();

        $this->assertSame('Default', $component->title);
    }

    public function test_actions_returns_whitelist(): void
    {
        $component = new SimpleComponent();

        $this->assertSame(['refresh'], $component->actions());
    }

    public function test_handle_action_calls_method(): void
    {
        $component = new SimpleComponent();
        $component->resolveState();
        $component->handleAction('refresh');

        $this->assertSame('Refreshed', $component->title);
    }

    public function test_handle_action_throws_on_unlisted(): void
    {
        $component = new SimpleComponent();

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('not allowed');
        $component->handleAction('delete');
    }

    public function test_handle_action_throws_on_nonexistent(): void
    {
        $component = new SimpleComponent();

        $this->expectException(\BadMethodCallException::class);
        $component->handleAction('nonexistent');
    }

    public function test_fallback_returns_null_by_default(): void
    {
        $component = new SimpleComponent();

        $this->assertNull($component->fallback(new \RuntimeException('test')));
    }

    public function test_fallback_returns_custom_html(): void
    {
        $component = new ComponentWithFallback();

        $result = $component->fallback(new \RuntimeException('oops'));

        $this->assertStringContainsString('Fallback: oops', $result);
    }

    public function test_get_tag_defaults_to_div(): void
    {
        $component = new SimpleComponent();

        $this->assertSame('div', $component->getTag());
    }

    public function test_get_tag_can_be_overridden(): void
    {
        $component = new ComponentWithTag();

        $this->assertSame('section', $component->getTag());
    }

    public function test_get_template_path_from_class_location(): void
    {
        $component = new SimpleComponent();

        $path = $component->getTemplatePath();

        // Should look for SimpleComponent.twig in same dir as class file
        $this->assertStringEndsWith('SimpleComponent.twig', $path);
    }

    public function test_get_template_context_includes_public_properties(): void
    {
        $component = new SimpleComponent();
        $component->setProps(['title' => 'Test']);
        $component->resolveState();

        $context = $component->getTemplateContext();

        $this->assertSame('Test', $context['title']);
        $this->assertArrayHasKey('componentId', $context);
    }
}
