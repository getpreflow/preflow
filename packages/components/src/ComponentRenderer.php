<?php

declare(strict_types=1);

namespace Preflow\Components;

use Preflow\View\AssetCollector;
use Preflow\View\TemplateEngineInterface;

final class ComponentRenderer
{
    public function __construct(
        private readonly TemplateEngineInterface $templateEngine,
        private readonly ErrorBoundary $errorBoundary,
        private readonly ?\Preflow\Core\Debug\DebugCollector $collector = null,
        private readonly ?AssetCollector $assetCollector = null,
    ) {}

    /**
     * Render a component with wrapper HTML and error boundary.
     */
    public function render(Component $component): string
    {
        try {
            $component->resolveState();
            $start = hrtime(true);
            $innerHtml = $this->renderTemplate($component);
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
            $html = $this->renderTemplate($component);
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
            $html = $this->renderTemplate($component);
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
            $innerHtml = $this->renderTemplate($component);
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
