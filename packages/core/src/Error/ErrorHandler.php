<?php

declare(strict_types=1);

namespace Preflow\Core\Error;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Preflow\Core\Exceptions\HttpException;

final class ErrorHandler
{
    public function __construct(
        private readonly ErrorRendererInterface $renderer,
    ) {}

    public function handleException(\Throwable $exception, ServerRequestInterface $request): ResponseInterface
    {
        $statusCode = $exception instanceof HttpException
            ? $exception->statusCode
            : 500;

        $body = $this->renderer->render($exception, $request, $statusCode);

        return new Response($statusCode, ['Content-Type' => 'text/html; charset=UTF-8'], $body);
    }
}
