<?php

declare(strict_types=1);

namespace Preflow\Htmx;

use Preflow\Components\Component;
use Preflow\Components\ComponentRenderer;
use Preflow\Core\Context\Preview\PreviewUrls;
use Preflow\Core\Http\Hypermedia\ActionCall;
use Preflow\Core\Http\Hypermedia\ActionOptions;
use Preflow\Core\Http\Hypermedia\DrivesComponentActions;
use Preflow\View\SafeHtml;
use Preflow\View\TemplateFunctionDefinition;
use Preflow\View\TemplateExtensionProvider;

/**
 * The `hd` helpers of templates. Two ways to call an action:
 *
 * - the short call (since 0.86), inside a component's template: `hd.post('add', {row: 3},
 *   {target: 'rows'})`, `hd.get(…)`, `hd.on('cartUpdated')`, `hd.region('rows')`. The component
 *   is the one whose template renders (ComponentRenderer::current()); its state goes with the
 *   call; options are the neutral ones (ActionOptions); the markup comes back as SafeHtml (no
 *   `| raw`). Needs a renderer and a driver that drives component actions (DrivesComponentActions).
 * - the old form, `hd.post('add', componentClass, componentId, props, swap, extra)`, recognised by
 *   its second argument, a class name: the attributes as text, exactly as in 0.85.
 */
final class HtmxExtensionProvider implements TemplateExtensionProvider
{
    /**
     * @param (\Closure(): string)|null $pathPrefix Put before every component URL, read
     *        each time a URL is built (e.g. the page's locale prefix '/de').
     * @param ComponentRenderer|null $renderer tells the short call which component renders (and its parent)
     * @param bool $debug misused action options of a short call fail (ActionOptions::from())
     * @param (\Closure(string): void)|null $log where a short call's option warnings go outside debug (error_log() without one)
     */
    public function __construct(
        private readonly HypermediaDriver $driver,
        private readonly ComponentToken $token,
        private readonly string $endpointPrefix = '/--component',
        private readonly ?HtmxAssets $assets = null,
        private readonly ?\Closure $pathPrefix = null,
        private readonly ?ComponentRenderer $renderer = null,
        private readonly bool $debug = false,
        private readonly ?\Closure $log = null,
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
        return PreviewUrls::carry($this->endpoint() . '/action?token=' . urlencode($this->token->encode($componentClass, $props, $action)));
    }

    /**
     * The attributes of an element that runs $action with a POST.
     *
     * Short call: `post(string $action, array $values = [], array $options = [])` runs $action of
     * the component whose template renders (or, with option `component: 'parent'`, of the one that
     * rendered it), sending $values (unsigned: the action gets them as input) and the component's
     * state; $options are ActionOptions. Returns SafeHtml.
     *
     * Old form: `post(string $action, string $componentClass, string $componentId, array $props = [],
     * SwapStrategy $swap = SwapStrategy::OuterHTML, array $extra = [])`, as in 0.85. Returns text.
     *
     * @throws \LogicException a short call outside a component's render, without a parent for
     *         `component: 'parent'`, or under a driver that does not drive component actions
     * @throws \InvalidArgumentException bad options (an unknown one in debug)
     */
    public function post(string $action, mixed ...$args): SafeHtml|string
    {
        return self::isOldForm($args)
            ? $this->actionAttrs('post', $action, ...$args)
            : $this->call('post', $action, ...$args);
    }

    /**
     * The attributes of an element that runs $action with a GET: as post() (a short call on a
     * component with state carries the state in the URL; prefer post()).
     *
     * @throws \LogicException see post()
     * @throws \InvalidArgumentException see post()
     */
    public function get(string $action, mixed ...$args): SafeHtml|string
    {
        return self::isOldForm($args)
            ? $this->actionAttrs('get', $action, ...$args)
            : $this->call('get', $action, ...$args);
    }

    /**
     * The attributes of an element that renders a component again when $event happens on the page
     * (an action's answer fired it).
     *
     * Short call: `on(string $event, array $options = [])` refreshes the component whose template
     * renders, with its state (no mount() again); `target` names a region to render alone.
     * Returns SafeHtml.
     *
     * Old form: `on(string $event, string $componentClass, string $componentId, array $props = [])`,
     * as in 0.85 (a component with state mounts again from $props). Returns text.
     *
     * @throws \LogicException see post()
     * @throws \InvalidArgumentException bad options, the option `on`, a bad event name
     */
    public function on(string $event, mixed ...$args): SafeHtml|string
    {
        return self::isOldForm($args)
            ? $this->listenAttrs($event, ...$args)
            : $this->listen($event, ...$args);
    }

    /**
     * `id="{componentId}-{name}"`: the id of the region $name of the component whose template
     * renders, for an element made by hand that a call targets (`target: 'name'`). A region the
     * template marks ({% region %}) has it already.
     *
     * @throws \LogicException outside a component's render
     * @throws \InvalidArgumentException a name that is no region name
     * @since 0.86.0
     */
    public function region(string $name): SafeHtml
    {
        $component = $this->rendering(sprintf("hd.region('%s')", $name));
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $name) !== 1) {
            throw new \InvalidArgumentException(sprintf("A region name is a letter, then letters, digits, - and _, not '%s'.", $name));
        }

        return new SafeHtml('id="' . htmlspecialchars($component->getComponentId() . '-' . $name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"');
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
     * The 0.85 action attributes (old form).
     *
     * @param array<string, mixed> $props
     * @param array<string, string> $extra
     */
    private function actionAttrs(
        string $method,
        string $action,
        string $componentClass,
        string $componentId,
        array $props = [],
        SwapStrategy $swap = SwapStrategy::OuterHTML,
        array $extra = [],
    ): string {
        $this->assets?->require();
        $tokenStr = $this->token->encode($componentClass, $props, $action);
        $url = PreviewUrls::carry($this->endpoint() . '/action?token=' . urlencode($tokenStr));

        $attrs = $this->driver->actionAttrs($method, $url, $componentId, $swap, $extra);

        return (string) $attrs;
    }

    /**
     * The 0.85 event listening attributes (old form).
     *
     * @param array<string, mixed> $props
     */
    private function listenAttrs(
        string $event,
        string $componentClass,
        string $componentId,
        array $props = [],
    ): string {
        $this->assets?->require();
        $tokenStr = $this->token->encode($componentClass, $props, 'render');
        $url = PreviewUrls::carry($this->endpoint() . '/render?token=' . urlencode($tokenStr));

        $attrs = $this->driver->listenAttrs($event, $url, $componentId);

        return (string) $attrs;
    }

    /**
     * A short call of $action.
     *
     * @param array<string, mixed> $values
     * @param array<array-key, mixed> $options
     */
    private function call(string $method, string $action, array $values = [], array $options = []): SafeHtml
    {
        $what = sprintf("hd.%s('%s')", $method, $action);
        [$component, $options, $driver] = $this->resolve($what, $options);
        $call = new ActionCall($method, $this->url('action', $component, $action), $component->getComponentId(), $this->targetId($component, $options, $what), $options, $values);

        return $this->attributes($driver->callAttributes($call));
    }

    /**
     * A short call that refreshes the rendering component on $event.
     *
     * @param array<array-key, mixed> $options
     */
    private function listen(string $event, array $options = []): SafeHtml
    {
        $what = sprintf("hd.on('%s')", $event);
        [$component, $options, $driver] = $this->resolve($what, $options);
        // A POST: a component's state goes in the body, whatever its size.
        $call = new ActionCall('post', $this->url('render', $component, 'render'), $component->getComponentId(), $this->targetId($component, $options, $what), $options);

        return $this->attributes($driver->listenAttributes($call, $event));
    }

    /**
     * The component a short call runs on (the rendering one, or its parent for `component:
     * 'parent'`), its options checked, and the driver that writes its attributes.
     *
     * @param array<array-key, mixed> $options
     * @return array{Component, ActionOptions, DrivesComponentActions}
     */
    private function resolve(string $what, array $options): array
    {
        $component = $this->rendering($what);
        $driver = $this->driver instanceof LazyHypermediaDriver ? $this->driver->driver() : $this->driver;
        if (!$driver instanceof DrivesComponentActions) {
            throw new \LogicException(sprintf(
                "%s needs a hypermedia driver that drives component actions (%s); %s does not. Use the long form hd.post('action', componentClass, componentId, props) with it.",
                $what,
                DrivesComponentActions::class,
                $driver::class,
            ));
        }
        $options = ActionOptions::from($options, $this->debug, $this->log);
        if ($options->component === 'parent') {
            $component = $this->renderer?->parentOf($component) ?? throw new \LogicException(sprintf(
                "%s with component: 'parent' in %s's template: %s was not rendered by another component's template.",
                $what,
                $component::class,
                $component::class,
            ));
        }

        return [$component, $options, $driver];
    }

    /** The component whose template renders now. */
    private function rendering(string $what): Component
    {
        return $this->renderer?->current() ?? throw new \LogicException(sprintf(
            "%s belongs in a component's template (the component it calls is the one rendering); none renders now. Outside a component's template use the long form, e.g. hd.post('action', componentClass, componentId, props).",
            $what,
        ));
    }

    /** The id of the element the answer goes into: the component, its parent, or one of its regions. */
    private function targetId(Component $component, ActionOptions $options, string $what): string
    {
        $region = $options->region();
        if ($region !== null) {
            return $component->getComponentId() . '-' . $region;
        }
        if ($options->target === 'parent') {
            $parent = $this->renderer?->parentOf($component) ?? throw new \LogicException(sprintf(
                "%s with target: 'parent': %s was not rendered by another component's template.",
                $what,
                $component::class,
            ));

            return $parent->getComponentId();
        }

        return $component->getComponentId();
    }

    /** The endpoint URL of $action on $component: one token per action and component, whatever the values. */
    private function url(string $path, Component $component, string $action): string
    {
        return PreviewUrls::carry($this->endpoint() . '/' . $path . '?token=' . urlencode($this->token->encodeFor($component, $action)));
    }

    /** @param array<string, string> $attributes */
    private function attributes(array $attributes): SafeHtml
    {
        $this->assets?->require();

        return new SafeHtml((string) new HtmlAttributes($attributes));
    }

    /**
     * The old form: the second argument (positional or named componentClass) is a class name.
     *
     * @param array<array-key, mixed> $args
     */
    private static function isOldForm(array $args): bool
    {
        return is_string($args[0] ?? $args['componentClass'] ?? null);
    }

    private function endpoint(): string
    {
        return ($this->pathPrefix !== null ? ($this->pathPrefix)() : '') . $this->endpointPrefix;
    }
}
