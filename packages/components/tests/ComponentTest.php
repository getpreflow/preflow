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

class ComponentWithParam extends Component
{
    public ?string $result = null;

    public function actions(): array
    {
        return ['delete', 'update'];
    }

    protected function actionDelete(array $params): void
    {
        // Uses param() to get 'id' from props (token-encoded identity)
        $this->result = 'deleted:' . $this->param('id', 'none');
    }

    protected function actionUpdate(array $params): void
    {
        // Uses param() — 'name' comes from POST, 'id' from props
        $this->result = $this->param('id') . ':' . $this->param('name');
    }
}

final class ComponentTest extends TestCase
{
    public function test_component_id_generated_from_class(): void
    {
        $component = new SimpleComponent();

        $this->assertNotEmpty($component->getComponentId());
        $this->assertStringContainsString('SimpleComponent', $component->getComponentId());
    }

    public function test_component_id_is_stable_regardless_of_props(): void
    {
        $a = new SimpleComponent();
        $a->setProps(['id' => '1']);

        $b = new SimpleComponent();
        $b->setProps(['id' => '2']);

        // Same component class, no key = same ID
        $this->assertSame($a->getComponentId(), $b->getComponentId());
    }

    public function test_key_prop_disambiguates_instances(): void
    {
        $a = new SimpleComponent();
        $a->setProps(['key' => 'cuvee']);

        $b = new SimpleComponent();
        $b->setProps(['key' => 'brink']);

        // Different keys = different IDs
        $this->assertNotSame($a->getComponentId(), $b->getComponentId());
        $this->assertStringEndsWith('-cuvee', $a->getComponentId());
        $this->assertStringEndsWith('-brink', $b->getComponentId());
    }

    public function test_no_key_prop_produces_base_id(): void
    {
        $component = new SimpleComponent();
        $component->setProps(['title' => 'Hello']);

        // No key = just class hash, no suffix
        $id = $component->getComponentId();
        $this->assertMatchesRegularExpression('/^SimpleComponent-[a-f0-9]{8}$/', $id);
    }

    public function test_same_class_same_key_produces_same_id(): void
    {
        $a = new SimpleComponent();
        $a->setProps(['key' => 'test']);

        $b = new SimpleComponent();
        $b->setProps(['key' => 'test']);

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

    public function test_param_returns_action_param_first(): void
    {
        $component = new ComponentWithParam();
        $component->setProps(['id' => '10', 'name' => 'from-props']);
        $component->handleAction('update', ['name' => 'from-post']);

        // 'id' comes from props, 'name' from POST (POST wins)
        $this->assertSame('10:from-post', $component->result);
    }

    public function test_param_falls_back_to_props(): void
    {
        $component = new ComponentWithParam();
        $component->setProps(['id' => '42']);
        $component->handleAction('delete', []);

        // 'id' not in POST params, falls back to props
        $this->assertSame('deleted:42', $component->result);
    }

    public function test_param_returns_default_when_missing(): void
    {
        $component = new ComponentWithParam();
        $component->setProps([]);
        $component->handleAction('delete', []);

        $this->assertSame('deleted:none', $component->result);
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
