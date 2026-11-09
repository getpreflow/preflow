<?php

declare(strict_types=1);

namespace Preflow\Core\Error;

use Nyholm\Psr7\Response;
use Preflow\Core\Http\Api\ApiConfig;
use Preflow\Core\Http\InternalRender;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Preflow\Core\Exceptions\HttpException;

final class ErrorHandler
{
    /**
     * @param ?ErrorRendererInterface $internal renders errors of internal renders (InternalRender, since 0.54):
     *                                          the production page, so the SEO audit sees what a visitor sees
     *                                          even where $renderer is the debug one; null uses $renderer
     * @param ?ApiConfig              $api      decides which requests get $json instead of the HTML page (since 0.62)
     * @param ?ErrorRendererInterface $json     renders the error of an API request; both $api and $json are needed
     */
    public function __construct(
        private readonly ErrorRendererInterface $renderer,
        private readonly ?ErrorRendererInterface $internal = null,
        private readonly ?ApiConfig $api = null,
        private readonly ?ErrorRendererInterface $json = null,
    ) {}

    public function handleException(\Throwable $exception, ServerRequestInterface $request): ResponseInterface
    {
        // Core names the validation package without requiring it; instanceof of a missing class is false.
        $statusCode = match (true) {
            $exception instanceof HttpException => $exception->statusCode,
            $exception instanceof \Preflow\Validation\ValidationException => 422,
            default => 500,
        };

        if ($this->internal !== null && InternalRender::is($request)) {
            $renderer = $this->internal;
            $contentType = 'text/html; charset=UTF-8';
        } elseif ($this->json !== null && $this->api?->wantsJson($request)) {
            $renderer = $this->json;
            $contentType = 'application/json';
        } else {
            $renderer = $this->renderer;
            $contentType = 'text/html; charset=UTF-8';
        }

        $headers = $exception instanceof HttpException ? $exception->headers : [];
        // The content type belongs to the renderer, whatever the exception asked for.
        $headers = array_filter($headers, static fn (string $name): bool => strtolower($name) !== 'content-type', ARRAY_FILTER_USE_KEY);

        return new Response($statusCode, ['Content-Type' => $contentType] + $headers, $renderer->render($exception, $request, $statusCode));
    }
}
