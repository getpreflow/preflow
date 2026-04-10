<?php

declare(strict_types=1);

namespace Preflow\Testing;

use PHPUnit\Framework\TestCase;
use Preflow\Components\Component;

abstract class ComponentTestCase extends TestCase
{
    protected TestApplication $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new TestApplication(debug: false);
    }

    /**
     * Create a component instance with props set.
     *
     * @template T of Component
     * @param class-string<T> $class
     * @param array<string, mixed> $props
     * @return T
     */
    protected function createComponent(string $class, array $props = []): Component
    {
        $component = new $class();
        $component->setProps($props);
        return $component;
    }

    /**
     * Render a component and return the HTML output.
     *
     * @param class-string<Component> $class
     * @param array<string, mixed> $props
     */
    protected function renderComponent(string $class, array $props = []): string
    {
        $component = $this->createComponent($class, $props);
        return $this->app->renderer->render($component);
    }
}
