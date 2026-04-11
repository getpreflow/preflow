<?php

declare(strict_types=1);

namespace Preflow\DevTools;

use Preflow\Core\Debug\DebugCollector;

final class DebugToolbar
{
    private const float SLOW_QUERY_MS = 100.0;
    private const float SLOW_COMPONENT_MS = 50.0;

    public function render(DebugCollector $collector): string
    {
        $styles = $this->renderStyles();
        $bar = $this->renderBar($collector);
        $panel = $this->renderPanel($collector);
        $script = $this->renderScript();

        return <<<HTML
        <div id="preflow-debug-toolbar">
        {$styles}
        {$bar}
        {$panel}
        {$script}
        </div>
        HTML;
    }

    private function renderStyles(): string
    {
        return <<<'CSS'
        <style>
        #preflow-debug-toolbar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            z-index: 99999;
            font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
            font-size: 11px;
            color: #c8c8d0;
            line-height: 1.4;
        }
        #preflow-debug-toolbar .pdt-bar {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 6px 12px;
            background: #1a1a2e;
            border-top: 2px solid #e94560;
            cursor: pointer;
            user-select: none;
        }
        #preflow-debug-toolbar .pdt-badge {
            color: #e94560;
            font-weight: bold;
        }
        #preflow-debug-toolbar .pdt-separator {
            color: #333;
        }
        #preflow-debug-toolbar .pdt-toggle {
            margin-left: auto;
            color: #666;
        }
        #preflow-debug-toolbar .pdt-panel {
            display: none;
            max-height: 50vh;
            overflow-y: auto;
            background: #0f0f23;
            border-top: 1px solid #16213e;
        }
        #preflow-debug-toolbar.expanded .pdt-panel {
            display: block;
        }
        #preflow-debug-toolbar .pdt-section {
            padding: 8px 12px;
            border-bottom: 1px solid #16213e;
        }
        #preflow-debug-toolbar .pdt-section-title {
            color: #e94560;
            font-weight: bold;
            margin-bottom: 4px;
            text-transform: uppercase;
            font-size: 10px;
            letter-spacing: 0.5px;
        }
        #preflow-debug-toolbar .pdt-row {
            padding: 2px 0;
            display: flex;
            gap: 8px;
        }
        #preflow-debug-toolbar .pdt-duration {
            color: #98c379;
        }
        #preflow-debug-toolbar .pdt-slow {
            color: #e94560;
        }
        #preflow-debug-toolbar .pdt-sql {
            color: #98c379;
            word-break: break-all;
        }
        #preflow-debug-toolbar .pdt-dim {
            color: #555;
        }
        #preflow-debug-toolbar .pdt-param-key {
            color: #e94560;
        }
        #preflow-debug-toolbar .pdt-param-val {
            color: #98c379;
        }
        </style>
        CSS;
    }

    private function renderBar(DebugCollector $collector): string
    {
        $queryCount = count($collector->getQueries());
        $queryTime = number_format($collector->getQueryTime(), 1);
        $componentCount = count($collector->getComponents());
        $assets = $collector->getAssets();
        $totalTime = number_format($collector->getTotalTime(), 1);

        $sep = '<span class="pdt-separator">|</span>';

        return <<<HTML
        <div class="pdt-bar">
        <span class="pdt-badge">&#9889; Preflow</span>
        {$sep}
        <span>{$componentCount} components</span>
        {$sep}
        <span>{$queryCount} queries ({$queryTime}ms)</span>
        {$sep}
        <span>{$assets['css_count']} CSS / {$assets['js_count']} JS</span>
        {$sep}
        <span>{$totalTime}ms</span>
        <span class="pdt-toggle">&#9650; expand</span>
        </div>
        HTML;
    }

    private function renderPanel(DebugCollector $collector): string
    {
        $components = $this->renderComponentsSection($collector);
        $queries = $this->renderQueriesSection($collector);
        $assets = $this->renderAssetsSection($collector);
        $route = $this->renderRouteSection($collector);

        return <<<HTML
        <div class="pdt-panel">
        {$components}
        {$queries}
        {$assets}
        {$route}
        </div>
        HTML;
    }

    private function renderComponentsSection(DebugCollector $collector): string
    {
        $rows = '';
        foreach ($collector->getComponents() as $comp) {
            $shortClass = $this->shortClass($comp['class']);
            $duration = number_format($comp['duration_ms'], 1);
            $slow = $comp['duration_ms'] > self::SLOW_COMPONENT_MS
                ? ' <span class="pdt-slow">&#9888; slow</span>'
                : '';

            $escapedClass = htmlspecialchars($shortClass, ENT_QUOTES, 'UTF-8');
            $escapedId = htmlspecialchars($comp['id'], ENT_QUOTES, 'UTF-8');

            $rows .= <<<HTML
            <div class="pdt-row">
            <span>{$escapedClass}</span>
            <span class="pdt-dim">{$escapedId}</span>
            <span class="pdt-duration">{$duration}ms</span>{$slow}
            </div>
            HTML;
        }

        return <<<HTML
        <div class="pdt-section">
        <div class="pdt-section-title">Components</div>
        {$rows}
        </div>
        HTML;
    }

    private function renderQueriesSection(DebugCollector $collector): string
    {
        $rows = '';
        foreach ($collector->getQueries() as $query) {
            $sql = htmlspecialchars($query['sql'], ENT_QUOTES, 'UTF-8');
            $bindings = $query['bindings'] ? ' [' . htmlspecialchars(implode(', ', array_map(
                static fn(mixed $v): string => is_string($v) ? "'{$v}'" : (string) $v,
                $query['bindings'],
            )), ENT_QUOTES, 'UTF-8') . ']' : '';
            $duration = number_format($query['duration_ms'], 1);
            $slow = $query['duration_ms'] > self::SLOW_QUERY_MS
                ? ' <span class="pdt-slow">&#9888; slow</span>'
                : '';

            $rows .= <<<HTML
            <div class="pdt-row">
            <span class="pdt-sql">{$sql}{$bindings}</span>
            <span class="pdt-duration">{$duration}ms</span>{$slow}
            </div>
            HTML;
        }

        return <<<HTML
        <div class="pdt-section">
        <div class="pdt-section-title">Queries</div>
        {$rows}
        </div>
        HTML;
    }

    private function renderAssetsSection(DebugCollector $collector): string
    {
        $assets = $collector->getAssets();

        return <<<HTML
        <div class="pdt-section">
        <div class="pdt-section-title">Assets</div>
        <div class="pdt-row">
        <span>{$assets['css_count']} CSS files ({$this->formatBytes($assets['css_bytes'])})</span>
        </div>
        <div class="pdt-row">
        <span>{$assets['js_count']} JS files ({$this->formatBytes($assets['js_bytes'])})</span>
        </div>
        </div>
        HTML;
    }

    private function renderRouteSection(DebugCollector $collector): string
    {
        $route = $collector->getRoute();
        if ($route === null) {
            return <<<HTML
            <div class="pdt-section">
            <div class="pdt-section-title">Route</div>
            <div class="pdt-row pdt-dim">No route info</div>
            </div>
            HTML;
        }

        $mode = htmlspecialchars($route['mode'], ENT_QUOTES, 'UTF-8');
        $handler = htmlspecialchars($route['handler'], ENT_QUOTES, 'UTF-8');
        $params = '';
        foreach ($route['parameters'] as $key => $value) {
            $k = htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8');
            $v = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            $params .= " <span class=\"pdt-param-key\">{$k}</span>=<span class=\"pdt-param-val\">{$v}</span>";
        }

        return <<<HTML
        <div class="pdt-section">
        <div class="pdt-section-title">Route</div>
        <div class="pdt-row">
        <span>{$mode} &rarr; {$handler}</span>{$params}
        </div>
        </div>
        HTML;
    }

    private function renderScript(): string
    {
        return <<<'JS'
        <script>
        (function() {
            var toolbar = document.getElementById('preflow-debug-toolbar');
            var bar = toolbar.querySelector('.pdt-bar');
            bar.addEventListener('click', function() {
                toolbar.classList.toggle('expanded');
            });
        })();
        </script>
        JS;
    }

    private function shortClass(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');
        return $pos !== false ? substr($fqcn, $pos + 1) : $fqcn;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . 'B';
        }

        return number_format($bytes / 1024, 1) . 'KB';
    }
}
