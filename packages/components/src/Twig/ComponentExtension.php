<?php

declare(strict_types=1);

namespace Preflow\Components\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Preflow\Components\Component;
use Preflow\Components\ComponentRenderer;

final class ComponentExtension extends AbstractExtension
{
    /**
     * @param ComponentRenderer $renderer
     * @param array<string, class-string<Component>> $componentMap Short name → FQCN
     */
    public function __construct(
        private readonly ComponentRenderer $renderer,
        private readonly array $componentMap = [],
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('component', $this->renderComponent(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * @param array<string, mixed> $props
     */
    public function renderComponent(string $name, array $props = []): string
    {
        $className = $this->resolveClass($name);
        $component = new $className();
        $component->setProps($props);

        return $this->renderer->render($component);
    }

    /**
     * @return class-string<Component>
     */
    private function resolveClass(string $name): string
    {
        // Check the component map first
        if (isset($this->componentMap[$name])) {
            return $this->componentMap[$name];
        }

        // Check if it's already a FQCN
        if (class_exists($name) && is_subclass_of($name, Component::class)) {
            return $name;
        }

        throw new \InvalidArgumentException(
            "Unknown component [{$name}]. Register it in the component map or pass a fully qualified class name."
        );
    }
}
