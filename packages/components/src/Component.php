<?php

declare(strict_types=1);

namespace Preflow\Components;

abstract class Component
{
    /** @var array<string, mixed> */
    protected array $props = [];

    protected string $tag = 'div';

    /** CSS class for the wrapper element. Also available in templates as componentCssClass. */
    protected string $cssClass = '';

    /** Enable CSS scoping: wraps {% apply css %} content in .cssClass-hash { ... } */
    protected bool $scopeCss = false;

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
    }

    /**
     * @return array<string, mixed>
     */
    public function getProps(): array
    {
        return $this->props;
    }

    /**
     * Get the component ID (short class name + stable FQCN hash + optional key).
     *
     * The hash is based on the fully-qualified class name — stable across
     * requests and prop changes. For multiple instances of the same component
     * on one page, pass a 'key' prop to disambiguate:
     *
     *     {{ component('GameCard', {game: game, key: game.slug}) }}
     *
     * Produces: GameCard-f7687e-cuvee, GameCard-f7687e-brink, etc.
     */
    public function getComponentId(): string
    {
        if ($this->componentId === null) {
            $shortName = (new \ReflectionClass($this))->getShortName();
            $classHash = substr(hash('xxh3', static::class), 0, 8);
            $this->componentId = $shortName . '-' . $classHash;

            $key = $this->props['key'] ?? null;
            if ($key !== null && $key !== '') {
                $this->componentId .= '-' . $key;
            }
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
     * Get the CSS class for the wrapper element.
     * Returns the developer-defined class, optionally with a stable hash suffix for scoping.
     */
    public function getCssClass(): string
    {
        if ($this->cssClass === '') {
            return '';
        }

        if (!$this->scopeCss) {
            return $this->cssClass;
        }

        // Stable hash based on the component's fully-qualified class name (not props)
        $hash = substr(hash('xxh3', static::class), 0, 6);
        return $this->cssClass . '-' . $hash;
    }

    /**
     * Whether CSS scoping is enabled for this component.
     */
    public function hasCssScoping(): bool
    {
        return $this->scopeCss && $this->cssClass !== '';
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
        $context['componentCssClass'] = $this->getCssClass();
        $context['props'] = $this->props;

        return $context;
    }
}
