<?php

declare(strict_types=1);

namespace Preflow\Core\Error;

use Psr\Http\Message\ServerRequestInterface;
use Preflow\Core\Exceptions\HttpException;

final class ProdErrorRenderer implements ErrorRendererInterface
{
    private const STATUS_MESSAGES = [
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
    ];

    public function render(\Throwable $exception, ServerRequestInterface $request, int $statusCode): string
    {
        if ($exception instanceof HttpException) {
            $title = self::STATUS_MESSAGES[$statusCode] ?? 'Error';
            $message = $exception->getMessage();
        } else {
            $title = self::STATUS_MESSAGES[$statusCode] ?? 'Internal Server Error';
            $message = $title;
        }

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$statusCode} — {$title}</title>
            <style>
                body { font-family: system-ui, sans-serif; display: grid; place-items: center; min-height: 100vh; margin: 0; background: #f8f9fa; color: #333; }
                .error { text-align: center; }
                .code { font-size: 4rem; font-weight: 700; color: #dee2e6; }
                .message { margin-top: 1rem; font-size: 1.125rem; }
            </style>
        </head>
        <body>
            <div class="error">
                <div class="code">{$statusCode}</div>
                <div class="message">{$this->escape($message)}</div>
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
