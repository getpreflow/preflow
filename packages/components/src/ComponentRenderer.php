<?php

declare(strict_types=1);

namespace Preflow\Components;

use Preflow\Core\Context\RefusedInPreview;
use Preflow\Core\Exceptions\HttpException;
use Preflow\Core\PageCache\LiveParts;
use Preflow\View\AssetCollector;
use Preflow\View\Geo\GeoCollector;
use Preflow\View\Regions;
use Preflow\View\RendersRegions;
use Preflow\View\TemplateEngineInterface;

final class ComponentRenderer
{
    /** The kind of a live component's placeholder (LiveParts). */
    public const string LIVE_KIND = 'component';

    private ?LiveParts $live = null;

    private ?\Preflow\Core\PageCache\PageCache $pageCache = null;

    /** @var array<class-string, Freshness|false> */
    private static array $freshnessOf = [];

    /** @var array<class-string, bool> */
    private static array $liveClasses = [];

    /** @var array<class-string, class-string<StateStore>> */
    private static array $storeClassOf = [];

    /** @var (\Closure(class-string<StateStore>): StateStore)|null */
    private ?\Closure $storeFor = null;

    private bool $stateDebug = false;

    /** @var (\Closure(string, string): string)|null */
    private ?\Closure $stateCarrier = null;

    /** @var list<Component> the components whose templates are rendering, innermost last */
    private array $stack = [];

    /** @var \WeakMap<Component, Component> */
    private \WeakMap $parents;

    /** @var \WeakMap<Component, string> */
    private \WeakMap $carried;

    /**
     * The parent on the page of a component rendered alone (placeInside(), placeInsideLazily()):
     * its class and key, and the parent itself once built.
     *
     * @var \WeakMap<Component, array{ref: ParentRef, parent: ?Component, build: ?\Closure(): Component}>
     */
    private \WeakMap $placedIn;

    /** @var \WeakMap<Component, true> parents that stand in for the one on the page (placeInside(), or placeInsideLazily() once built) */
    private \WeakMap $standIns;

    /** @var \WeakMap<Component, true> */
    private \WeakMap $restored;

    public function __construct(
        private readonly TemplateEngineInterface $templateEngine,
        private readonly ErrorBoundary $errorBoundary,
        private readonly ?\Preflow\Core\Debug\DebugCollector $collector = null,
        private readonly ?AssetCollector $assetCollector = null,
        private readonly ?GeoCollector $geoCollector = null,
    ) {
        $this->parents = new \WeakMap();
        $this->carried = new \WeakMap();
        $this->placedIn = new \WeakMap();
        $this->standIns = new \WeakMap();
        $this->restored = new \WeakMap();
    }

    /**
     * Makes components marked #[Live] live parts of cached pages: while a page renders for the
     * page cache, render() returns their placeholder (class and props) instead; when the page is
     * served, each is made with $factory and rendered here, after one prefetchLive() call per
     * class that implements PrefetchesLive.
     *
     * @param \Closure(class-string<Component>, array<string, mixed>): Component $factory
     */
    public function enableLive(LiveParts $live, \Closure $factory): void
    {
        $this->live = $live;
        $live->register(
            self::LIVE_KIND,
            function (array $recipe) use ($factory): string {
                return $this->render($factory(self::liveClass($recipe), is_array($recipe['p'] ?? null) ? $recipe['p'] : []));
            },
            static function (array $recipes) use ($factory): void {
                $byClass = [];
                foreach ($recipes as $recipe) {
                    try {
                        $class = self::liveClass($recipe);
                    } catch (\Throwable) {
                        continue; // not a live component: never rendered either
                    }
                    $byClass[$class][] = is_array($recipe['p'] ?? null) ? $recipe['p'] : [];
                }
                // Each class on its own: one that fails doesn't keep the others from prefetching.
                $failed = null;
                foreach ($byClass as $class => $propsList) {
                    if (!is_subclass_of($class, PrefetchesLive::class)) {
                        continue;
                    }
                    try {
                        $prefetcher = $factory($class, []);
                        if ($prefetcher instanceof PrefetchesLive) {
                            $prefetcher->prefetchLive($propsList);
                        }
                    } catch (\Throwable $e) {
                        $failed ??= new \RuntimeException($class . ': ' . $e->getMessage(), 0, $e);
                    }
                }
                if ($failed !== null) {
                    throw $failed; // logged by LiveParts
                }
            },
        );
    }

    /**
     * Makes #[Freshness] count: a component marked with it resolves and renders inside
     * PageCache::within() with its freshness.
     */
    public function enableFreshness(\Preflow\Core\PageCache\PageCache $pageCache): void
    {
        $this->pageCache = $pageCache;
    }

    /** The #[Freshness] of $class (itself, else the nearest parent's), null without one. */
    public static function freshnessOf(string $class): ?Freshness
    {
        if (!isset(self::$freshnessOf[$class])) {
            $found = false;
            if (class_exists($class)) {
                for ($ref = new \ReflectionClass($class); $ref !== false; $ref = $ref->getParentClass()) {
                    $attributes = $ref->getAttributes(Freshness::class);
                    if ($attributes !== []) {
                        $found = $attributes[0]->newInstance();
                        break;
                    }
                }
            }
            self::$freshnessOf[$class] = $found;
        }

        return self::$freshnessOf[$class] ?: null;
    }

    /**
     * Runs $fn (resolve and render) with $component's #[Freshness], when the cache is wired.
     *
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    private function withFreshness(Component $component, \Closure $fn): mixed
    {
        $freshness = $this->pageCache !== null ? self::freshnessOf($component::class) : null;

        return $freshness === null
            ? $fn()
            : $this->pageCache->within($freshness->freshness, $freshness->seconds, $fn);
    }

    /** Whether $class is marked #[Live] (itself or a parent). */
    public static function isLive(string $class): bool
    {
        if (isset(self::$liveClasses[$class])) {
            return self::$liveClasses[$class];
        }
        $live = false;
        if (class_exists($class)) {
            for ($ref = new \ReflectionClass($class); $ref !== false; $ref = $ref->getParentClass()) {
                if ($ref->getAttributes(Live::class) !== []) {
                    $live = true;
                    break;
                }
            }
        }

        return self::$liveClasses[$class] = $live;
    }

    /**
     * The class a live placeholder names, when it is a component marked #[Live].
     *
     * @param array<string, mixed> $recipe
     * @return class-string<Component>
     */
    private static function liveClass(array $recipe): string
    {
        $class = $recipe['c'] ?? null;
        if (!is_string($class) || !is_subclass_of($class, Component::class) || !self::isLive($class)) {
            throw new \Preflow\Core\PageCache\LiveRecipeRejected('A live placeholder names no live component: ' . (is_string($class) ? $class : get_debug_type($class)) . '.');
        }

        return $class;
    }

    /**
     * Keeps the state of components marked #[State]: $storeFor gives the StateStore of a class
     * (SignedStateStore unless the component names another with #[StateIn]). In $debug, misuse
     * fails the render instead of reaching the error boundary: resolveState() on a component with
     * state, or a session store on a page the page cache may store (outside debug that page is
     * not stored instead).
     *
     * @param \Closure(class-string<StateStore>): StateStore $storeFor
     * @since 0.86.0
     */
    public function enableState(\Closure $storeFor, bool $debug = false): void
    {
        $this->storeFor = $storeFor;
        $this->stateDebug = $debug;
    }

    /**
     * Puts the state a component carries on the page: $carrier gives the markup for a component
     * id and its state string (the hypermedia driver's carrier, DrivesComponentActions), which
     * goes first inside the wrapper of every component with #[State]. Without it the wrapper
     * carries nothing: old-style calls still work, a component then mounts from its props again.
     *
     * @param \Closure(string $componentId, string $carried): string $carrier
     * @since 0.86.0
     */
    public function carryStateWith(\Closure $carrier): void
    {
        $this->stateCarrier = $carrier;
    }

    /**
     * The regions $component's template has, null when the template engine renders no regions
     * (RendersRegions): what renderAfterAction() accepts as its region.
     *
     * @return list<string>|null
     * @since 0.86.0
     */
    public function regionsOf(Component $component): ?array
    {
        $engine = $this->templateEngine;
        if (!$engine instanceof RendersRegions) {
            return null;
        }

        return $engine->regionsOf($component->getTemplatePath($engine->getTemplateExtension()));
    }

    /**
     * The component whose template is rendering now (the innermost one), null outside every
     * component render. Also while an action's response renders (renderAfterAction()).
     *
     * @since 0.86.0
     */
    public function current(): ?Component
    {
        return $this->stack === [] ? null : $this->stack[array_key_last($this->stack)];
    }

    /**
     * The component whose template rendered $component, null for one rendered on its own (by a
     * page template, the endpoint, a live part) unless placeInside() or placeInsideLazily() named
     * its parent on the page.
     *
     * @since 0.86.0
     */
    public function parentOf(Component $component): ?Component
    {
        if (isset($this->parents[$component])) {
            return $this->parents[$component];
        }
        $placed = $this->placedIn[$component] ?? null;
        if ($placed === null) {
            return null;
        }
        if ($placed['parent'] === null && $placed['build'] !== null) {
            // Placed lazily: built the first time something asks for the parent itself.
            $parent = ($placed['build'])();
            if (!$parent instanceof $placed['ref']->class) {
                throw new \LogicException(sprintf(
                    'The parent of %s placed with placeInsideLazily() built a %s; the parent on the page is a %s. The builder must return a %s.',
                    $component::class,
                    get_debug_type($parent),
                    $placed['ref']->class,
                    $placed['ref']->class,
                ));
            }
            $this->standIns[$parent] = true;
            $this->placedIn[$component] = ['ref' => $placed['ref'], 'parent' => $parent, 'build' => null];

            return $parent;
        }

        return $placed['parent'];
    }

    /**
     * The class and key of $component's parent (see parentOf()), null without one. Answers from
     * what is known about the parent, without building a parent placed with placeInsideLazily().
     *
     * @since 0.87.0
     */
    public function parentRefOf(Component $component): ?ParentRef
    {
        if (isset($this->parents[$component])) {
            return ParentRef::of($this->parents[$component]);
        }

        return ($this->placedIn[$component] ?? null)['ref'] ?? null;
    }

    /**
     * Whether $component has a parent on the page: it renders inside another component's
     * template, or placeInside() or placeInsideLazily() named the parent it sits in on the page.
     * Answers from what is known about the parent being there, without asking for the parent
     * itself.
     *
     * @since 0.87.0
     */
    public function hasParent(Component $component): bool
    {
        return isset($this->parents[$component]) || isset($this->placedIn[$component]);
    }

    /**
     * Renders $component, which sits inside $parent's template on the page, on its own here (the
     * endpoint renders a child alone for its action or its refresh): parentOf() gives $parent
     * while $component renders without another component's template around it. $parent stands in
     * for the one on the page (standsIn()): built from its class and key, never mounted, restored
     * nor rendered here, so only its class, its id and its key are known.
     *
     * @since 0.86.0
     */
    public function placeInside(Component $component, Component $parent): void
    {
        $this->placedIn[$component] = ['ref' => ParentRef::of($parent), 'parent' => $parent, 'build' => null];
        $this->standIns[$parent] = true;
    }

    /**
     * Like placeInside(), with the parent known by class and key ($parent) and built by $build
     * only when something asks for it (parentOf(), e.g. a `component: 'parent'` call).
     * hasParent() and parentRefOf() answer without it, so a parent that can't be built here fails
     * only what needs it. $build must return a $parent->class with $parent->key; it runs at most once.
     *
     * @param \Closure(): Component $build
     * @since 0.87.0
     */
    public function placeInsideLazily(Component $component, ParentRef $parent, \Closure $build): void
    {
        $this->placedIn[$component] = ['ref' => $parent, 'parent' => null, 'build' => $build];
    }

    /**
     * Whether $component stands in for a parent on the page (placeInside(), or placeInsideLazily()
     * once built): its props other
     * than its key and its state are not known here.
     *
     * @since 0.86.0
     */
    public function standsIn(Component $component): bool
    {
        return isset($this->standIns[$component]);
    }

    /**
     * The string the page carries for $component's state, from its store, after its last render;
     * null for a component without #[State] or not rendered yet.
     *
     * @throws \LogicException a component with state on a renderer without enableState()
     * @since 0.86.0
     */
    public function stateFor(Component $component): ?string
    {
        if (isset($this->carried[$component])) {
            return $this->carried[$component];
        }
        if ($component->hasState() && $this->storeFor === null) {
            throw new \LogicException(sprintf('%s has #[State], but the component renderer keeps no state: call enableState() with its stores.', $component::class));
        }

        return null;
    }

    /**
     * Writes the state the page carried ($carried, from stateFor() of an earlier render) into
     * $component, from its store. Its next render does not mount() it again.
     *
     * @throws StateRefused tampered, foreign, expired or unreadable state, a shape the component
     *         no longer has, or state for a component without #[State]
     * @since 0.86.0
     */
    public function restore(Component $component, string $carried): void
    {
        if (!$component->hasState()) {
            throw StateRefused::keepsNone($component);
        }
        $state = $this->storeOf($component)->get($component::class, $component->getComponentId(), $carried);
        StateProperties::write($component, $state);
        $this->restored[$component] = true;
    }

    /**
     * Renders $component after one of its actions ran, with its wrapper: prepare() for a
     * component with state (no mount(): the action ran on its restored state), nothing for one
     * without (its resolveState() ran before the action). With $region, only that region of its
     * template renders: its contents, without the region element or the component's wrapper (both
     * stay on the page), with the component's template context (RendersRegions).
     *
     * @throws \InvalidArgumentException its template has no region $region (the message names its regions)
     * @throws \LogicException for a region: the template engine renders no regions (named in the message)
     * @since 0.86.0
     */
    public function renderAfterAction(Component $component, ?string $region = null): string
    {
        if ($region === null) {
            return $this->renderInBoundary($component, self::AFTER_ACTION, true);
        }
        $engine = $this->templateEngine;
        if (!$engine instanceof RendersRegions) {
            throw Regions::notRendered($engine);
        }
        // An unknown region is the request's mistake, never the component's: not its error boundary.
        $template = $component->getTemplatePath($engine->getTemplateExtension());
        $regions = $engine->regionsOf($template);
        if (!in_array($region, $regions, true)) {
            throw Regions::unknown($template, $region, $regions);
        }

        return $this->renderInBoundary($component, self::AFTER_ACTION, false, $region);
    }

    /**
     * Render a component with wrapper HTML and error boundary. A #[Live] component on a page
     * being rendered for the page cache is its placeholder instead (enableLive()).
     *
     * A component without #[State] runs resolveState() first; one with #[State] runs mount() on
     * its first render (not after restore()) and prepare() before every render.
     *
     * The boundary catches every failure except an HttpException (a 403, a 404…): that one is
     * rethrown in every render method, so the page or the action answers with its status
     * (since 0.63.2; before, the boundary showed its fallback with a 200). So is a write the
     * render refused, or anything else a preview refuses (RefusedInPreview, since 0.81.0): the request answers 403.
     */
    public function render(Component $component): string
    {
        if ($component instanceof PreviewsFailure) {
            // What its boundary shows when it fails, without it failing (no error reported). A
            // preview that throws is a real failure.
            try {
                $failure = $component->previewFailure();
            } catch (\Throwable $e) {
                return $this->errorBoundary->render($e, $component, 'previewFailure');
            }

            return $this->errorBoundary->preview($failure, $component, 'resolveState');
        }
        if ($this->live !== null && self::isLive($component::class)) {
            try {
                \Preflow\Core\PageCache\LivePlaceholder::assertPlain($component->getProps());
                $placeholder = $this->live->placeholder(self::LIVE_KIND, ['c' => $component::class, 'p' => $component->getProps()]);
            } catch (HttpException|RefusedInPreview $e) {
                throw $e;
            } catch (\Throwable $e) {
                return $this->errorBoundary->render($e, $component, $this->detectPhase($e));
            }
            if ($placeholder !== null) {
                return $placeholder;
            }
        }

        return $this->renderInBoundary($component, self::FIRST, true);
    }

    /**
     * Render a component fragment (inner HTML only, no wrapper).
     * Used for HTMX partial responses.
     */
    public function renderFragment(Component $component): string
    {
        return $this->renderInBoundary($component, self::FIRST, false);
    }

    /**
     * Render a component fragment after state has already been resolved/mutated
     * (e.g. after an action dispatch). Skips resolveState() and the wrapper div,
     * returning inner HTML only.
     */
    public function renderResolvedFragment(Component $component): string
    {
        return $this->renderInBoundary($component, self::RESOLVED, false);
    }

    /**
     * Render a component whose state has already been resolved/mutated
     * (e.g. after an action dispatch). Skips resolveState() so that
     * action side-effects are preserved in the rendered output.
     */
    public function renderResolved(Component $component): string
    {
        return $this->renderInBoundary($component, self::RESOLVED, true);
    }

    /** A first render, or a refresh of a restored component: resolveState(), or mount() and prepare(). */
    private const int FIRST = 0;

    /** After an action: prepare() for a component with state, nothing for one without. */
    private const int AFTER_ACTION = 1;

    /** Resolved by the caller: no hook runs. */
    private const int RESOLVED = 2;

    /**
     * The hooks of $moment, the render (of $region only, when given), the state kept, inside the
     * error boundary and the component's #[Freshness]; with the wrapper when $wrap.
     */
    private function renderInBoundary(Component $component, int $moment, bool $wrap, ?string $region = null): string
    {
        if ($this->stateDebug && $moment !== self::RESOLVED) {
            $this->assertStateUse($component); // misuse fails the render, not the boundary
        }
        try {
            $start = hrtime(true);
            $innerHtml = $this->withFreshness($component, function () use ($component, $moment, $region, &$start): string {
                $this->runHooks($component, $moment);
                $start = hrtime(true);
                $html = $this->renderInner($component, $region);
                $this->keepState($component);

                return $html;
            });
            if ($this->collector !== null) {
                $durationMs = (hrtime(true) - $start) / 1_000_000;
                $this->collector->logComponent(
                    $component::class,
                    $component->getComponentId(),
                    $durationMs,
                    $component->getProps(),
                );
            }

            return $wrap ? $this->wrapHtml($component, $innerHtml) : $innerHtml;
        } catch (HttpException|RefusedInPreview $e) {
            throw $e; // a 403/404 the component throws is the page's answer, not a broken component
        } catch (\Throwable $e) {
            return $this->errorBoundary->render($e, $component, $this->detectPhase($e));
        }
    }

    private function runHooks(Component $component, int $moment): void
    {
        if ($moment === self::RESOLVED) {
            return;
        }
        if (!$component->hasState()) {
            if ($moment === self::FIRST) {
                $component->resolveState();
            }

            return;
        }
        if ($moment === self::FIRST && !isset($this->restored[$component])) {
            $component->mount();
        }
        $component->prepare();
    }

    /** Puts the state of a component with #[State] into its store, for stateFor(). */
    private function keepState(Component $component): void
    {
        if ($this->storeFor === null || !$component->hasState()) {
            return;
        }
        $store = $this->storeOf($component);
        if ($store instanceof SessionStateStore && $this->pageCache?->storing() === true) {
            // Every visitor of the stored page would carry this visitor's session reference.
            $this->pageCache->refuse('component-session-state');
        }
        $this->carried[$component] = $store->put($component::class, $component->getComponentId(), StateProperties::read($component));
    }

    /** Debug: resolveState() on a component with state; a session store on a page the cache may store. */
    private function assertStateUse(Component $component): void
    {
        if (!$component->hasState()) {
            return;
        }
        $class = $component::class;
        if ((new \ReflectionMethod($component, 'resolveState'))->getDeclaringClass()->getName() !== Component::class) {
            throw new \LogicException(sprintf(
                '%s has #[State] and defines resolveState(), which a component with state never runs: use mount() and prepare() instead (mount() sets the state from props on the first render, prepare() loads what derives from the state before every render).',
                $class,
            ));
        }
        if ($this->pageCache?->storing() === true && self::storeClassOf($class) === SessionStateStore::class
            && !($this->live !== null && self::isLive($class))) {
            throw new \LogicException(sprintf(
                '%s keeps its state in the session (#[StateIn(%s)]), but it renders on a page the page cache may store for every visitor, who does not have that session. Use the signed store (%s, the default without #[StateIn]) or a page that is not cached (behind a login, or refused with PageCache::refuse()).',
                $class,
                SessionStateStore::class,
                SignedStateStore::class,
            ));
        }
    }

    /** The store $component keeps its state in. */
    private function storeOf(Component $component): StateStore
    {
        if ($this->storeFor === null) {
            throw new \LogicException(sprintf('%s has #[State], but the component renderer keeps no state: call enableState() with its stores.', $component::class));
        }
        $class = self::storeClassOf($component::class);
        $store = ($this->storeFor)($class);
        if (!$store instanceof $class) {
            throw new \LogicException(sprintf('The state store for %s is a %s, not the %s it names.', $component::class, get_debug_type($store), $class));
        }

        return $store;
    }

    /**
     * The store class #[StateIn] names on $class (itself, else the nearest parent), else SignedStateStore.
     *
     * @param class-string<Component> $class
     * @return class-string<StateStore>
     */
    private static function storeClassOf(string $class): string
    {
        if (isset(self::$storeClassOf[$class])) {
            return self::$storeClassOf[$class];
        }
        $store = SignedStateStore::class;
        for ($ref = new \ReflectionClass($class); $ref !== false; $ref = $ref->getParentClass()) {
            $attributes = $ref->getAttributes(StateIn::class);
            if ($attributes !== []) {
                $store = $attributes[0]->newInstance()->store;
                break;
            }
        }

        return self::$storeClassOf[$class] = $store;
    }

    /** What the error boundary shows for a component that failed outside rendering (e.g. in an action). */
    public function renderError(Component $component, \Throwable $e, string $phase = 'action'): string
    {
        return $this->errorBoundary->render($e, $component, $phase);
    }

    /**
     * The component's inner HTML. While GEO captures, the render runs in a block of its
     * own; a component that declared a geo block is replaced by its placeholder.
     */
    private function renderInner(Component $component, ?string $region = null): string
    {
        // On the render stack while its template renders: current(), and the parent of what it renders.
        $parent = $this->current();
        if ($parent !== null && $parent !== $component) {
            $this->parents[$component] = $parent;
        } elseif ($parent === null) {
            // Rendered on its own this time: parentOf() falls back to the parent placeInside() or placeInsideLazily() named.
            unset($this->parents[$component]);
        }
        $this->stack[] = $component;
        try {
            return $this->geoCollector === null
                ? $this->renderTemplate($component, $region)
                : $this->geoCollector->component(fn (): string => $this->renderTemplate($component, $region));
        } finally {
            array_pop($this->stack);
        }
    }

    /** The component's template rendered, or only its region $region (RendersRegions). */
    private function renderTemplate(Component $component, ?string $region = null): string
    {
        $templatePath = $component->getTemplatePath($this->templateEngine->getTemplateExtension());
        $context = $component->getTemplateContext();
        $engine = $this->templateEngine;
        $render = match (true) {
            $region === null => static fn (): string => $engine->render($templatePath, $context),
            $engine instanceof RendersRegions => static fn (): string => $engine->renderRegion($templatePath, $region, $context),
            default => throw Regions::notRendered($engine),
        };

        if ($this->assetCollector === null) {
            return $render();
        }

        // The component's own CSS scope, or none: an unscoped child inside a scoped parent
        // doesn't take the parent's. Put back afterwards, also when the template throws.
        $previousScope = $this->assetCollector->getCssScope();
        $this->assetCollector->setCssScope($component->hasCssScoping() ? $component->getCssClass() : null);
        try {
            return $render();
        } finally {
            $this->assetCollector->setCssScope($previousScope);
        }
    }

    private function wrapHtml(Component $component, string $innerHtml): string
    {
        $tag = $component->getTag();
        $id = htmlspecialchars($component->getComponentId(), ENT_QUOTES, 'UTF-8');
        $cssClass = $component->getCssClass();

        $attrs = "id=\"{$id}\"";
        if ($cssClass !== '') {
            $attrs .= ' class="' . htmlspecialchars($cssClass, ENT_QUOTES, 'UTF-8') . '"';
        }
        foreach ($component->getRootAttributes() as $name => $value) {
            $attrs .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
        }

        if ($this->stateCarrier !== null && isset($this->carried[$component])) {
            $innerHtml = ($this->stateCarrier)($component->getComponentId(), $this->carried[$component]) . $innerHtml;
        }

        return "<{$tag} {$attrs}>{$innerHtml}</{$tag}>";
    }

    /**
     * Detect which lifecycle phase failed based on the stack trace.
     */
    private function detectPhase(\Throwable $e): string
    {
        $trace = $e->getTraceAsString();

        if (str_contains($trace, 'resolveState') || str_contains($e->getFile(), 'resolveState')) {
            return 'resolveState';
        }

        // Check if the exception was thrown from within the component class
        foreach ($e->getTrace() as $frame) {
            if (isset($frame['function'])) {
                if ($frame['function'] === 'resolveState') {
                    return 'resolveState';
                }
                if ($frame['function'] === 'handleAction') {
                    return 'handleAction';
                }
                if (in_array($frame['function'], ['mount', 'prepare'], true)
                    && is_a($frame['class'] ?? '', Component::class, true)) {
                    return $frame['function'];
                }
            }
        }

        return 'render';
    }
}
