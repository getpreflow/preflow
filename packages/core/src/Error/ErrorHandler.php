<?php

declare(strict_types=1);

namespace Preflow\Core\Error;

use Nyholm\Psr7\Response;
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
     */
    public function __construct(
        private readonly ErrorRendererInterface $renderer,
        private readonly ?ErrorRendererInterface $internal = null,
    ) {}

    public function handleException(\Throwable $exception, ServerRequestInterface $request): ResponseInterface
    {
        $statusCode = $exception instanceof HttpException
            ? $exception->statusCode
            : 500;

        $renderer = $this->internal !== null && InternalRender::is($request) ? $this->internal : $this->renderer;
        $body = $renderer->render($exception, $request, $statusCode);

        return new Response($statusCode, ['Content-Type' => 'text/html; charset=UTF-8'], $body);
    }
}
