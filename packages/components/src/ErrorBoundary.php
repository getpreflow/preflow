<?php

declare(strict_types=1);

namespace Preflow\Components;

use Preflow\Core\DebugLevel;

final class ErrorBoundary
{
    public function __construct(
        private readonly DebugLevel $debug = DebugLevel::Off,
    ) {}

    public function render(
        \Throwable $exception,
        Component $component,
        string $phase = 'unknown',
    ): string {
        // Verbose mode: always show dev panel, even if component has a custom fallback
        if ($this->debug === DebugLevel::Verbose) {
            $fallback = $component->fallback($exception);
            return $this->renderDev($exception, $component, $phase, $fallback !== null);
        }

        // If the component provides a custom fallback, always use it —
        // even in debug mode. A custom fallback is intentional.
        $fallback = $component->fallback($exception);
        if ($fallback !== null) {
            return $fallback;
        }

        if ($this->debug->isDebug()) {
            return $this->renderDev($exception, $component, $phase);
        }

        return $this->renderProd();
    }

    private function renderDev(
        \Throwable $exception,
        Component $component,
        string $phase,
        bool $fallbackSuppressed = false,
    ): string {
        $class = $this->esc($exception::class);
        $message = $this->esc($exception->getMessage());
        $componentClass = $this->esc($component::class);
        $componentId = $this->esc($component->getComponentId());
        $file = $this->esc($exception->getFile());
        $line = $exception->getLine();
        $trace = $this->esc($exception->getTraceAsString());
        $phase = $this->esc($phase);
        $props = $this->esc(json_encode($component->getProps(), JSON_PRETTY_PRINT));

        $suppressedNote = $fallbackSuppressed
            ? '<div style="background:#f39c12;color:#1a1a2e;padding:0.5rem 1rem;margin:-1rem -1rem 1rem;font-size:0.8rem;">This component defines a custom fallback (suppressed by debug level 2)</div>'
            : '';

        return <<<HTML
        <div style="border:2px solid #e74c3c;background:#1a1a2e;color:#eee;padding:1rem;border-radius:0.5rem;margin:0.5rem 0;font-family:system-ui,sans-serif;font-size:0.875rem;">
            <div style="background:#e74c3c;margin:-1rem -1rem 1rem;padding:0.75rem 1rem;border-radius:0.375rem 0.375rem 0 0;">
                <strong>{$class}</strong>: {$message}
            </div>
            {$suppressedNote}
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

    private function renderProd(): string
    {
        return '<div style="display:none;" data-component-error="true"></div>';
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
