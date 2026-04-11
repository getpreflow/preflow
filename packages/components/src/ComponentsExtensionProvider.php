<?php

declare(strict_types=1);

namespace Preflow\Components;

use Preflow\View\TemplateFunctionDefinition;
use Preflow\View\TemplateExtensionProvider;

final class ComponentsExtensionProvider implements TemplateExtensionProvider
{
    /** @var callable(string, array): Component|null */
    private $componentFactory;

    /**
     * @param ComponentRenderer $renderer
     * @param array<string, class-string<Component>> $componentMap Short name -> FQCN
     * @param callable(string $class, array $props): Component|null $componentFactory
     */
    public function __construct(
        private readonly ComponentRenderer $renderer,
        private readonly array $componentMap = [],
        ?callable $componentFactory = null,
    ) {
        $this->componentFactory = $componentFactory;
    }

    public function getTemplateFunctions(): array
    {
        return [
            new TemplateFunctionDefinition(
                name: 'component',
                callable: fn (string $name, array $props = []) => $this->renderComponent($name, $props),
                isSafe: true,
            ),
        ];
    }

    public function getTemplateGlobals(): array
    {
        return [];
    }

    public function renderComponent(string $name, array $props = []): string
    {
        $className = $this->resolveClass($name);

        if ($this->componentFactory !== null) {
            $component = ($this->componentFactory)($className, $props);
        } else {
            $component = new $className();
            $component->setProps($props);
        }

        return $this->renderer->render($component);
    }

    /**
     * @return class-string<Component>
     */
    private function resolveClass(string $name): string
    {
        if (isset($this->componentMap[$name])) {
            return $this->componentMap[$name];
        }

        if (class_exists($name) && is_subclass_of($name, Component::class)) {
            return $name;
        }

        throw new \InvalidArgumentException(
            "Unknown component [{$name}]. Register it in the component map or pass a fully qualified class name."
        );
    }
}
