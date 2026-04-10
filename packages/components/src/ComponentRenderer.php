<?php

declare(strict_types=1);

namespace Preflow\Components;

use Preflow\View\TemplateEngineInterface;

final class ComponentRenderer
{
    public function __construct(
        private readonly TemplateEngineInterface $templateEngine,
        private readonly ErrorBoundary $errorBoundary,
    ) {}

    /**
     * Render a component with wrapper HTML and error boundary.
     */
    public function render(Component $component): string
    {
        try {
            $component->resolveState();
            $innerHtml = $this->renderTemplate($component);
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
            return $this->renderTemplate($component);
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
            $innerHtml = $this->renderTemplate($component);
            return $this->wrapHtml($component, $innerHtml);
        } catch (\Throwable $e) {
            return $this->errorBoundary->render($e, $component, $this->detectPhase($e));
        }
    }

    private function renderTemplate(Component $component): string
    {
        $templatePath = $component->getTemplatePath();
        $context = $component->getTemplateContext();

        return $this->templateEngine->render($templatePath, $context);
    }

    private function wrapHtml(Component $component, string $innerHtml): string
    {
        $tag = $component->getTag();
        $id = htmlspecialchars($component->getComponentId(), ENT_QUOTES, 'UTF-8');

        return "<{$tag} id=\"{$id}\">{$innerHtml}</{$tag}>";
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
