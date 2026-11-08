<?php

declare(strict_types=1);

namespace Preflow\Components;

use Preflow\Core\PageCache\LiveParts;
use Preflow\View\AssetCollector;
use Preflow\View\Geo\GeoCollector;
use Preflow\View\TemplateEngineInterface;

final class ComponentRenderer
{
    /** The kind of a live component's placeholder (LiveParts). */
    public const string LIVE_KIND = 'component';

    private ?LiveParts $live = null;

    /** @var array<class-string, bool> */
    private static array $liveClasses = [];

    public function __construct(
        private readonly TemplateEngineInterface $templateEngine,
        private readonly ErrorBoundary $errorBoundary,
        private readonly ?\Preflow\Core\Debug\DebugCollector $collector = null,
        private readonly ?AssetCollector $assetCollector = null,
        private readonly ?GeoCollector $geoCollector = null,
    ) {}

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
                        continue; // rendering it reports the error
                    }
                    $byClass[$class][] = is_array($recipe['p'] ?? null) ? $recipe['p'] : [];
                }
                foreach ($byClass as $class => $propsList) {
                    if (is_subclass_of($class, PrefetchesLive::class)) {
                        $prefetcher = $factory($class, []);
                        if ($prefetcher instanceof PrefetchesLive) {
                            $prefetcher->prefetchLive($propsList);
                        }
                    }
                }
            },
        );
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
            throw new \RuntimeException('A live placeholder names no live component: ' . (is_string($class) ? $class : get_debug_type($class)) . '.');
        }

        return $class;
    }

    /**
     * Render a component with wrapper HTML and error boundary. A #[Live] component on a page
     * being rendered for the page cache is its placeholder instead (enableLive()).
     */
    public function render(Component $component): string
    {
        try {
            if ($this->live !== null && self::isLive($component::class)) {
                \Preflow\Core\PageCache\LivePlaceholder::assertPlain($component->getProps());
                $placeholder = $this->live->placeholder(self::LIVE_KIND, ['c' => $component::class, 'p' => $component->getProps()]);
                if ($placeholder !== null) {
                    return $placeholder;
                }
            }
            $component->resolveState();
            $start = hrtime(true);
            $innerHtml = $this->renderInner($component);
            if ($this->collector !== null) {
                $durationMs = (hrtime(true) - $start) / 1_000_000;
                $this->collector->logComponent(
                    $component::class,
                    $component->getComponentId(),
                    $durationMs,
                    $component->getProps(),
                );
            }
            return $this->wrapHtml($component, $innerHtml);
        } catch (\Throwable $e) {
            return $this->errorBoundary->render($e, $component, $this->detectPhase($e));
        }
    }

    /**
     * Render a component fragment (inner HTML only, no wrapper).
     * Used for HTMX partial responses.
     */
    public function renderFragment(Component $component): string
    {
        try {
            $component->resolveState();
            $start = hrtime(true);
            $html = $this->renderInner($component);
            if ($this->collector !== null) {
                $durationMs = (hrtime(true) - $start) / 1_000_000;
                $this->collector->logComponent(
                    $component::class,
                    $component->getComponentId(),
                    $durationMs,
                    $component->getProps(),
                );
            }
            return $html;
        } catch (\Throwable $e) {
            return $this->errorBoundary->render($e, $component, $this->detectPhase($e));
        }
    }

    /**
     * Render a component fragment after state has already been resolved/mutated
     * (e.g. after an action dispatch). Skips resolveState() and the wrapper div,
     * returning inner HTML only.
     */
    public function renderResolvedFragment(Component $component): string
    {
        try {
            $start = hrtime(true);
            $html = $this->renderInner($component);
            if ($this->collector !== null) {
                $durationMs = (hrtime(true) - $start) / 1_000_000;
                $this->collector->logComponent(
                    $component::class,
                    $component->getComponentId(),
                    $durationMs,
                    $component->getProps(),
                );
            }
            return $html;
        } catch (\Throwable $e) {
            return $this->errorBoundary->render($e, $component, $this->detectPhase($e));
        }
    }

    /**
     * Render a component whose state has already been resolved/mutated
     * (e.g. after an action dispatch). Skips resolveState() so that
     * action side-effects are preserved in the rendered output.
     */
    public function renderResolved(Component $component): string
    {
        try {
            $start = hrtime(true);
            $innerHtml = $this->renderInner($component);
            if ($this->collector !== null) {
                $durationMs = (hrtime(true) - $start) / 1_000_000;
                $this->collector->logComponent(
                    $component::class,
                    $component->getComponentId(),
                    $durationMs,
                    $component->getProps(),
                );
            }
            return $this->wrapHtml($component, $innerHtml);
        } catch (\Throwable $e) {
            return $this->errorBoundary->render($e, $component, $this->detectPhase($e));
        }
    }

    /** What the error boundary shows for a component that failed outside rendering (e.g. in an action). */
    public function renderError(Component $component, \Throwable $e, string $phase = 'action'): string
    {
        return $this->errorBoundary->render($e, $component, $phase);
    }

    /**
     * The component's inner HTML. While GEO captures, the render runs in its own
     * frame; a component that declared a geo block is replaced by its placeholder.
     */
    private function renderInner(Component $component): string
    {
        $geo = $this->geoCollector;
        if ($geo === null || !$geo->capturing()) {
            return $this->renderTemplate($component);
        }

        $geo->enter();
        try {
            $html = $this->renderTemplate($component);
        } finally {
            $block = $geo->leave();
        }

        return $block === null ? $html : $geo->placeholder($block);
    }

    private function renderTemplate(Component $component): string
    {
        $templatePath = $component->getTemplatePath($this->templateEngine->getTemplateExtension());
        $context = $component->getTemplateContext();

        // Set CSS scope if component has scoping enabled
        if ($this->assetCollector !== null && $component->hasCssScoping()) {
            $previousScope = $this->assetCollector->getCssScope();
            $this->assetCollector->setCssScope($component->getCssClass());
            $html = $this->templateEngine->render($templatePath, $context);
            $this->assetCollector->setCssScope($previousScope);
            return $html;
        }

        return $this->templateEngine->render($templatePath, $context);
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
            }
        }

        return 'render';
    }
}
