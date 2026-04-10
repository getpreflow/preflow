<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests;

use Preflow\Components\Component;
use Preflow\Testing\ComponentTestCase;

class CounterComponent extends Component
{
    public int $count = 0;
    public string $label = '';

    public function resolveState(): void
    {
        $this->count = (int) ($this->props['initial'] ?? 0);
        $this->label = $this->props['label'] ?? 'Counter';
    }

    public function actions(): array
    {
        return ['increment'];
    }

    public function actionIncrement(array $params = []): void
    {
        $this->count++;
    }
}

class BrokenTestComponent extends Component
{
    public function resolveState(): void
    {
        throw new \RuntimeException('Deliberately broken');
    }

    public function fallback(\Throwable $e): ?string
    {
        return '<p>Fallback content</p>';
    }
}

final class ComponentTestCaseTest extends ComponentTestCase
{
    public function test_render_returns_result(): void
    {
        $result = $this->renderComponent(CounterComponent::class, ['initial' => 5, 'label' => 'Test']);

        $this->assertNotEmpty($result);
    }

    public function test_render_contains_component_id(): void
    {
        $result = $this->renderComponent(CounterComponent::class);

        $this->assertStringContainsString('CounterComponent', $result);
    }

    public function test_action_executes(): void
    {
        $component = $this->createComponent(CounterComponent::class, ['initial' => 0]);
        $component->resolveState();
        $component->handleAction('increment');

        $this->assertSame(1, $component->count);
    }

    public function test_error_boundary_catches(): void
    {
        $result = $this->renderComponent(BrokenTestComponent::class);

        $this->assertStringContainsString('Fallback content', $result);
    }

    public function test_create_component_sets_props(): void
    {
        $component = $this->createComponent(CounterComponent::class, ['initial' => 10]);
        $component->resolveState();

        $this->assertSame(10, $component->count);
    }
}
