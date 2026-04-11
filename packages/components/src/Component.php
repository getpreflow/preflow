<?php

declare(strict_types=1);

namespace Preflow\Components;

abstract class Component
{
    /** @var array<string, mixed> */
    protected array $props = [];

    protected string $tag = 'div';

    private ?string $componentId = null;

    /**
     * Load state from database, session, cache, etc.
     * Override in subclass.
     */
    public function resolveState(): void
    {
    }

    /**
     * Return a list of action names that can be called via the component endpoint.
     *
     * @return string[]
     */
    public function actions(): array
    {
        return [];
    }

    /**
     * Return fallback HTML when this component fails to render.
     * Override in subclass. Return null to use the default error handling.
     */
    public function fallback(\Throwable $e): ?string
    {
        return null;
    }

    /**
     * Dispatch an action by name.
     *
     * @param array<string, mixed> $params POST/request parameters
     */
    public function handleAction(string $action, array $params = []): void
    {
        if (!in_array($action, $this->actions(), true)) {
            throw new \BadMethodCallException(
                "Action [{$action}] is not allowed on " . static::class . "."
            );
        }

        $method = 'action' . ucfirst($action);

        if (!method_exists($this, $method)) {
            throw new \BadMethodCallException(
                "Action method [{$method}] does not exist on " . static::class . "."
            );
        }

        $this->{$method}($params);
    }

    /**
     * @param array<string, mixed> $props
     */
    public function setProps(array $props): void
    {
        $this->props = $props;
        $this->componentId = null; // reset on prop change
    }

    /**
     * @return array<string, mixed>
     */
    public function getProps(): array
    {
        return $this->props;
    }

    /**
     * Get the unique component ID (class name + props hash).
     */
    public function getComponentId(): string
    {
        if ($this->componentId === null) {
            $shortName = (new \ReflectionClass($this))->getShortName();
            $propsHash = substr(hash('xxh3', serialize($this->props)), 0, 8);
            $this->componentId = $shortName . '-' . $propsHash;
        }

        return $this->componentId;
    }

    /**
     * Get the wrapper HTML tag.
     */
    public function getTag(): string
    {
        return $this->tag;
    }

    /**
     * Get the path to the co-located template file.
     */
    public function getTemplatePath(string $extension = 'twig'): string
    {
        $ref = new \ReflectionClass($this);
        $dir = dirname($ref->getFileName());
        $name = $ref->getShortName();

        return $dir . '/' . $name . '.' . $extension;
    }

    /**
     * Get template context variables (all public properties + componentId).
     *
     * @return array<string, mixed>
     */
    public function getTemplateContext(): array
    {
        $ref = new \ReflectionClass($this);
        $context = [];

        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isInitialized($this)) {
                $context[$prop->getName()] = $prop->getValue($this);
            }
        }

        $context['componentId'] = $this->getComponentId();
        $context['componentClass'] = static::class;
        $context['props'] = $this->props;

        return $context;
    }
}
