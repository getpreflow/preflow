<?php

declare(strict_types=1);

namespace Preflow\Htmx;

use Preflow\View\TemplateFunctionDefinition;
use Preflow\View\TemplateExtensionProvider;

final class HtmxExtensionProvider implements TemplateExtensionProvider
{
    public function __construct(
        private readonly HypermediaDriver $driver,
        private readonly ComponentToken $token,
        private readonly string $endpointPrefix = '/--component',
    ) {}

    public function getTemplateFunctions(): array
    {
        return [
            new TemplateFunctionDefinition(
                name: 'hd',
                callable: fn () => $this,
                isSafe: true,
            ),
        ];
    }

    public function getTemplateGlobals(): array
    {
        return ['hd' => $this];
    }

    /**
     * Generate action attributes for POST.
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
