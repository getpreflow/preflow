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
        private readonly ?HtmxAssets $assets = null,
    ) {}

    /**
     * Mark htmx as needed on this page (for templates that write hx-* attributes
     * by hand). Returns an empty string so it can be used as {{ hd.require() }}.
     */
    public function require(): string
    {
        $this->assets?->require();
        return '';
    }

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
     * A plain URL for a component action, e.g. a form's action or a link.
     *
     * @param array<string, mixed> $props
     */
    public function actionUrl(string $action, string $componentClass, array $props = []): string
    {
        return $this->endpointPrefix . '/action?token=' . urlencode($this->token->encode($componentClass, $props, $action));
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
        $this->assets?->require();
        $tokenStr = $this->token->encode($componentClass, $props, 'render');
        $url = $this->endpointPrefix . '/render?token=' . urlencode($tokenStr);

        $attrs = $this->driver->listenAttrs($event, $url, $componentId);

        return (string) $attrs;
    }

    /**
     * Get the hypermedia library asset tag.
     */
    /**
     * Marks htmx as needed on this page and returns '' (htmx is inlined by
     * HtmxAssets, never loaded through a tag).
     */
    public function assetTag(): string
    {
        $this->assets?->require();
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
        $this->assets?->require();
        $tokenStr = $this->token->encode($componentClass, $props, $action);
        $url = $this->endpointPrefix . '/action?token=' . urlencode($tokenStr);

        $attrs = $this->driver->actionAttrs($method, $url, $componentId, $swap, $extra);

        return (string) $attrs;
    }
}
