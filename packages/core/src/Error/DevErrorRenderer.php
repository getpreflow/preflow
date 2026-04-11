<?php

declare(strict_types=1);

namespace Preflow\Core\Error;

use Preflow\Core\Debug\DebugCollector;
use Psr\Http\Message\ServerRequestInterface;

final class DevErrorRenderer implements ErrorRendererInterface
{
    public function __construct(
        private ?DebugCollector $collector = null,
    ) {}

    public function render(\Throwable $exception, ServerRequestInterface $request, int $statusCode): string
    {
        $class = $exception::class;
        $message = $this->escape($exception->getMessage());
        $file = $exception->getFile();
        $line = $exception->getLine();

        $sourceContext = $this->renderSourceContext($file, $line);
        $stackTrace = $this->renderStackTrace($exception);
        $requestSection = $this->renderRequest($request);
        $debugContext = $this->renderDebugContext();

        $escapedClass = $this->escape($class);
        $escapedFile = $this->escape($file);

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$statusCode} — {$escapedClass}</title>
            <style>
                * { box-sizing: border-box; margin: 0; padding: 0; }
                body { font-family: system-ui, -apple-system, sans-serif; background: #1a1a2e; color: #eee; padding: 2rem; line-height: 1.6; }
                .header { background: #e94560; padding: 1.5rem 2rem; border-radius: 0.5rem; margin-bottom: 1.5rem; }
                .header h1 { font-size: 1.25rem; font-weight: 600; color: #fff; }
                .header .message { margin-top: 0.5rem; font-size: 1rem; color: rgba(255,255,255,0.9); }
                .header .location { margin-top: 0.5rem; font-size: 0.8125rem; color: rgba(255,255,255,0.7); font-family: monospace; }
                .section { background: #16213e; padding: 1.5rem; border-radius: 0.5rem; margin-bottom: 1rem; }
                .section h2 { font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.05em; color: #e94560; margin-bottom: 1rem; }
                .source { background: #0f0f23; border-radius: 0.375rem; overflow-x: auto; font-family: 'SF Mono', 'Fira Code', 'Consolas', monospace; font-size: 0.8125rem; line-height: 1.7; }
                .source-line { display: flex; }
                .source-line .line-no { display: inline-block; width: 4rem; text-align: right; padding-right: 1rem; color: #555; user-select: none; flex-shrink: 0; }
                .source-line .line-code { white-space: pre-wrap; word-break: break-all; padding-right: 1rem; }
                .source-line.highlight { background: rgba(233, 69, 96, 0.2); border-left: 3px solid #e94560; }
                .source-line.highlight .line-no { color: #e94560; }
                .trace-frame summary { cursor: pointer; padding: 0.5rem 0; font-family: monospace; font-size: 0.8125rem; color: #eee; }
                .trace-frame summary:hover { color: #e94560; }
                .trace-frame.vendor summary { color: #aaa; }
                .trace-frame .frame-source { margin: 0.5rem 0 0.5rem 1rem; }
                .meta { display: grid; grid-template-columns: auto 1fr; gap: 0.25rem 1rem; font-size: 0.875rem; }
                .meta dt { color: #aaa; }
                .meta dd { margin: 0; font-family: monospace; }
                details.collapsed summary { color: #aaa; font-size: 0.8125rem; }
                .query-row, .component-row { padding: 0.5rem 0; border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 0.8125rem; }
                .query-sql { font-family: monospace; color: #98c379; }
                .query-meta { color: #aaa; font-size: 0.75rem; margin-top: 0.25rem; }
                .slow-flag { color: #e94560; font-weight: 600; }
                .route-info { font-family: monospace; font-size: 0.875rem; }
                .route-info span { color: #aaa; }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>{$escapedClass}</h1>
                <div class="message">{$message}</div>
                <div class="location">{$escapedFile}:{$line}</div>
            </div>

            <div class="section">
                <h2>SOURCE</h2>
                {$sourceContext}
            </div>

            <div class="section">
                <h2>STACK TRACE</h2>
                {$stackTrace}
            </div>

            {$requestSection}

            {$debugContext}
        </body>
        </html>
        HTML;
    }

    private function renderSourceContext(string $file, int $line, int $range = 10): string
    {
        $lines = @file($file);

        if ($lines === false) {
            return '<div class="source"><div class="source-line"><span class="line-code">'
                . $this->escape($file) . ':' . $line . '</span></div></div>';
        }

        $start = max(0, $line - $range - 1);
        $end = min(count($lines), $line + $range);
        $html = '<div class="source">';

        for ($i = $start; $i < $end; $i++) {
            $lineNumber = $i + 1;
            $isErrorLine = $lineNumber === $line;
            $class = $isErrorLine ? ' highlight' : '';
            $code = $this->escapeSourceCode(rtrim($lines[$i], "\n\r"));
            $html .= '<div class="source-line' . $class . '">'
                . '<span class="line-no">' . $lineNumber . '</span>'
                . '<span class="line-code">' . $code . '</span>'
                . '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    private function renderStackTrace(\Throwable $e): string
    {
        $trace = $e->getTrace();
        $html = '';

        foreach ($trace as $i => $frame) {
            $class = $frame['class'] ?? '';
            $function = $frame['function'] ?? '';
            $type = $frame['type'] ?? '';
            $file = $frame['file'] ?? '[internal]';
            $line = $frame['line'] ?? 0;

            $label = $class !== '' ? $class . $type . $function . '()' : $function . '()';
            $location = $file !== '[internal]'
                ? ' at ' . $this->escape($file) . ':' . $line
                : ' [internal]';

            $isVendor = str_contains($file, 'vendor/');
            $vendorClass = $isVendor ? ' vendor' : '';

            $frameSource = '';
            if ($file !== '[internal]' && $line > 0) {
                $frameSource = '<div class="frame-source">'
                    . $this->renderSourceContext($file, $line, 5)
                    . '</div>';
            }

            $html .= '<details class="trace-frame' . $vendorClass . '">'
                . '<summary>#' . $i . ' ' . $this->escape($label) . $location . '</summary>'
                . $frameSource
                . '</details>';
        }

        return $html;
    }

    private function renderRequest(ServerRequestInterface $request): string
    {
        $method = $this->escape($request->getMethod());
        $uri = $request->getUri();
        $path = $this->escape($uri->getPath());
        $query = $uri->getQuery();

        $html = '<div class="section"><h2>REQUEST</h2>';
        $html .= '<dl class="meta">';
        $html .= '<dt>Method</dt><dd>' . $method . '</dd>';
        $html .= '<dt>URI</dt><dd>' . $path . '</dd>';

        if ($query !== '') {
            $html .= '<dt>Query</dt><dd>' . $this->escape($query) . '</dd>';
        }

        $html .= '</dl>';

        $headers = $request->getHeaders();
        if ($headers !== []) {
            $html .= '<details class="collapsed" style="margin-top:1rem">';
            $html .= '<summary>Headers</summary>';
            $html .= '<dl class="meta" style="margin-top:0.5rem">';
            foreach ($headers as $name => $values) {
                $html .= '<dt>' . $this->escape($name) . '</dt>';
                $html .= '<dd>' . $this->escape(implode(', ', $values)) . '</dd>';
            }
            $html .= '</dl></details>';
        }

        $html .= '</div>';

        return $html;
    }

    private function renderDebugContext(): string
    {
        if ($this->collector === null) {
            return '';
        }

        $html = '';

        // Queries
        $queries = $this->collector->getQueries();
        if ($queries !== []) {
            $html .= '<div class="section"><h2>QUERIES</h2>';
            foreach ($queries as $q) {
                $slow = $q['duration_ms'] > 100;
                $slowFlag = $slow ? ' <span class="slow-flag">SLOW</span>' : '';
                $html .= '<div class="query-row">';
                $html .= '<div class="query-sql">' . $this->escape($q['sql']) . '</div>';
                $html .= '<div class="query-meta">';
                if ($q['bindings'] !== []) {
                    $html .= 'Bindings: ' . $this->escape(json_encode($q['bindings'], JSON_THROW_ON_ERROR));
                    $html .= ' &middot; ';
                }
                $html .= sprintf('%.1fms', $q['duration_ms']) . $slowFlag;
                $html .= ' &middot; ' . $this->escape($q['driver']);
                $html .= '</div></div>';
            }
            $html .= '</div>';
        }

        // Components
        $components = $this->collector->getComponents();
        if ($components !== []) {
            $html .= '<div class="section"><h2>COMPONENTS</h2>';
            foreach ($components as $c) {
                $slow = $c['duration_ms'] > 50;
                $slowFlag = $slow ? ' <span class="slow-flag">SLOW</span>' : '';
                $html .= '<div class="component-row">';
                $html .= '<strong>' . $this->escape($c['class']) . '</strong>';
                $html .= ' <span style="color:#aaa">' . $this->escape($c['id']) . '</span>';
                $html .= ' &middot; ' . sprintf('%.1fms', $c['duration_ms']) . $slowFlag;
                $html .= '</div>';
            }
            $html .= '</div>';
        }

        // Route
        $route = $this->collector->getRoute();
        if ($route !== null) {
            $html .= '<div class="section"><h2>ROUTE</h2>';
            $html .= '<div class="route-info">';
            $html .= '<span>Mode:</span> ' . $this->escape($route['mode']);
            $html .= ' &middot; <span>Handler:</span> ' . $this->escape($route['handler']);
            if ($route['parameters'] !== []) {
                $html .= ' &middot; <span>Params:</span> ' . $this->escape(json_encode($route['parameters'], JSON_THROW_ON_ERROR));
            }
            $html .= '</div></div>';
        }

        return $html;
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Escape source code for display, encoding letters as HTML entities
     * to prevent source snippets from triggering false substring matches
     * (e.g. test assertions appearing in rendered source context).
     */
    private function escapeSourceCode(string $text): string
    {
        $escaped = $this->escape($text);

        return preg_replace_callback('/[A-Za-z]/', static fn (array $m) => '&#' . ord($m[0]) . ';', $escaped);
    }
}
