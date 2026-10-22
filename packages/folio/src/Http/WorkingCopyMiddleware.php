<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Preflow\Folio\Content\LiveContent;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Admin routes (including the live preview) render working copies of referenced records. */
final class WorkingCopyMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly LiveContent $live) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->live->showWorkingCopies();
        AdminNav::setCurrentPath($request->getUri()->getPath()); // every admin route, with or without auth

        return $handler->handle($request);
    }
}
