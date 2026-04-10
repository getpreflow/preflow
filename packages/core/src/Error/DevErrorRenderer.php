<?php

declare(strict_types=1);

namespace Preflow\Core\Error;

use Psr\Http\Message\ServerRequestInterface;

final class DevErrorRenderer implements ErrorRendererInterface
{
    public function render(\Throwable $exception, ServerRequestInterface $request, int $statusCode): string
    {
        $class = $exception::class;
        $message = $this->escape($exception->getMessage());
        $file = $this->escape($exception->getFile());
        $line = $exception->getLine();
        $trace = $this->escape($exception->getTraceAsString());
        $method = $this->escape($request->getMethod());
        $uri = $this->escape((string) $request->getUri());

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$statusCode} — {$this->escape($class)}</title>
            <style>
                * { box-sizing: border-box; }
                body { font-family: system-ui, sans-serif; margin: 0; padding: 2rem; background: #1a1a2e; color: #eee; }
                .header { background: #e74c3c; padding: 1.5rem 2rem; border-radius: 0.5rem; margin-bottom: 1.5rem; }
                .header h1 { margin: 0; font-size: 1.25rem; font-weight: 600; }
                .header .message { margin-top: 0.5rem; font-size: 1rem; opacity: 0.9; }
                .section { background: #16213e; padding: 1.5rem; border-radius: 0.5rem; margin-bottom: 1rem; }
                .section h2 { margin: 0 0 1rem; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.05em; color: #888; }
                .meta { display: grid; grid-template-columns: auto 1fr; gap: 0.25rem 1rem; font-size: 0.875rem; }
                .meta dt { color: #888; }
                .meta dd { margin: 0; font-family: monospace; }
                pre { margin: 0; font-size: 0.8125rem; line-height: 1.6; overflow-x: auto; white-space: pre-wrap; word-break: break-all; }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>{$this->escape($class)}</h1>
                <div class="message">{$message}</div>
            </div>

            <div class="section">
                <h2>Request</h2>
                <dl class="meta">
                    <dt>Method</dt><dd>{$method}</dd>
                    <dt>URI</dt><dd>{$uri}</dd>
                </dl>
            </div>

            <div class="section">
                <h2>Location</h2>
                <dl class="meta">
                    <dt>File</dt><dd>{$file}:{$line}</dd>
                </dl>
            </div>

            <div class="section">
                <h2>Stack Trace</h2>
                <pre>{$trace}</pre>
            </div>
        </body>
        </html>
        HTML;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
