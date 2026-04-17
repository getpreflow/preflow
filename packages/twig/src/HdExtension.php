<?php

declare(strict_types=1);

namespace Preflow\Twig;

use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;
use Preflow\Htmx\ComponentToken;
use Preflow\Htmx\HtmlAttributes;
use Preflow\Htmx\HypermediaDriver;
use Preflow\Htmx\SwapStrategy;

final class HdExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly HypermediaDriver $driver,
        private readonly ComponentToken $token,
        private readonly string $endpointPrefix = '/--component',
    ) {}

    public function getGlobals(): array
    {
        return ['hd' => $this];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('hd', fn () => $this, ['is_safe' => ['html']]),
        ];
    }

    /**
     * Generate action attributes for POST (most common).
     *
     * @param array<string, mixed> $props
     * @param array<string, string> $extra
     */
    public function post(
        string $action,
        string $componentClass,
        string $componentId,
        array $props = [],
        SwapStrategy $swap = SwapStrategy::OuterHTML,
        array $extra = [],
    ): string {
        return $this->actionAttrs('post', $action, $componentClass, $componentId, $props, $swap, $extra);
    }

    /**
     * Generate action attributes for GET.
     *
     * @param array<string, mixed> $props
     * @param array<string, string> $extra
     */
    public function get(
        string $action,
        string $componentClass,
        string $componentId,
        array $props = [],
        SwapStrategy $swap = SwapStrategy::OuterHTML,
        array $extra = [],
    ): string {
        return $this->actionAttrs('get', $action, $componentClass, $componentId, $props, $swap, $extra);
    }

    /**
     * Generate event listening attributes.
     *
     * @param array<string, mixed> $props
     */
    public function on(
        string $event,
        string $componentClass,
        string $componentId,
        array $props = [],
    ): string {
        $tokenStr = $this->token->encode($componentClass, $props, 'render');
        $url = $this->endpointPrefix . '/render?token=' . urlencode($tokenStr);

        $attrs = $this->driver->listenAttrs($event, $url, $componentId);

        return (string) $attrs;
    }

    /**
     * Get the hypermedia library asset tag.
     */
    public function assetTag(): string
    {
        return $this->driver->assetTag();
    }

    /**
     * Get just the action URL (tokenized) without any HTML attributes.
     * Useful for inline validation where target/swap/trigger need customization.
     *
     * @param array<string, mixed> $props
     */
    public function actionUrl(
        string $action,
        string $componentClass,
        array $props = [],
    ): string {
        $tokenStr = $this->token->encode($componentClass, $props, $action);
        return $this->endpointPrefix . '/action?token=' . urlencode($tokenStr);
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, string> $extra
     */
    private function actionAttrs(
        string $method,
        string $action,
        string $componentClass,
        string $componentId,
        array $props,
        SwapStrategy $swap,
        array $extra,
    ): string {
        $tokenStr = $this->token->encode($componentClass, $props, $action);
        $url = $this->endpointPrefix . '/action?token=' . urlencode($tokenStr);

        $attrs = $this->driver->actionAttrs($method, $url, $componentId, $swap, $extra);

        return (string) $attrs;
    }
}
