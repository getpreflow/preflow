<?php

declare(strict_types=1);

namespace Preflow\Components;

final class ErrorBoundary
{
    public function __construct(
        private readonly bool $debug = false,
    ) {}

    public function render(
        \Throwable $exception,
        Component $component,
        string $phase = 'unknown',
    ): string {
        // If the component provides a custom fallback, always use it —
        // even in debug mode. A custom fallback is intentional.
        $fallback = $component->fallback($exception);
        if ($fallback !== null) {
            return $fallback;
        }

        if ($this->debug) {
            return $this->renderDev($exception, $component, $phase);
        }

        return $this->renderProd($exception, $component);
    }

    private function renderDev(\Throwable $exception, Component $component, string $phase): string
    {
        $class = $this->esc($exception::class);
        $message = $this->esc($exception->getMessage());
        $componentClass = $this->esc($component::class);
        $componentId = $this->esc($component->getComponentId());
        $file = $this->esc($exception->getFile());
        $line = $exception->getLine();
        $trace = $this->esc($exception->getTraceAsString());
        $phase = $this->esc($phase);
        $props = $this->esc(json_encode($component->getProps(), JSON_PRETTY_PRINT));

        return <<<HTML
        <div style="border:2px solid #e74c3c;background:#1a1a2e;color:#eee;padding:1rem;border-radius:0.5rem;margin:0.5rem 0;font-family:system-ui,sans-serif;font-size:0.875rem;">
            <div style="background:#e74c3c;margin:-1rem -1rem 1rem;padding:0.75rem 1rem;border-radius:0.375rem 0.375rem 0 0;">
                <strong>{$class}</strong>: {$message}
            </div>
            <dl style="display:grid;grid-template-columns:auto 1fr;gap:0.25rem 1rem;margin:0;">
                <dt style="color:#888;">Component</dt><dd style="margin:0;font-family:monospace;">{$componentClass}</dd>
                <dt style="color:#888;">ID</dt><dd style="margin:0;font-family:monospace;">{$componentId}</dd>
                <dt style="color:#888;">Phase</dt><dd style="margin:0;font-family:monospace;">{$phase}</dd>
                <dt style="color:#888;">Props</dt><dd style="margin:0;font-family:monospace;white-space:pre-wrap;">{$props}</dd>
                <dt style="color:#888;">File</dt><dd style="margin:0;font-family:monospace;">{$file}:{$line}</dd>
            </dl>
            <details style="margin-top:0.75rem;">
                <summary style="cursor:pointer;color:#888;">Stack Trace</summary>
                <pre style="margin:0.5rem 0 0;font-size:0.75rem;overflow-x:auto;white-space:pre-wrap;">{$trace}</pre>
            </details>
        </div>
        HTML;
    }

    private function renderProd(\Throwable $exception, Component $component): string
    {
        // fallback() was already checked in render() — this is the no-fallback case
        return '<div style="display:none;" data-component-error="true"></div>';
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
