<?php

declare(strict_types=1);

namespace Preflow\Core\Error;

use Psr\Http\Message\ServerRequestInterface;

interface ErrorRendererInterface
{
    public function render(\Throwable $exception, ServerRequestInterface $request, int $statusCode): string;
}
