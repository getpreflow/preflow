<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\RenderContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Admin routes (including the live preview) render working copies of referenced records: the
 * request's frame reads working copies for the rest of the request (RenderContext::workingCopies()),
 * work after the response included. It runs first on every admin route, outside the admin
 * surface's stretch (AdminLocaleMiddleware), so the narrow holds for the whole frame.
 */
final class WorkingCopyMiddleware implements MiddlewareInterface
{
    /** @since 0.81.0 takes the nav only (the read mode is the context's, not LiveContent's) */
    public function __construct(private readonly AdminNav $nav) {}

    /** @throws \Preflow\Core\Context\NoRenderContext outside every render */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        Contexts::narrow(static fn (RenderContext $ctx): RenderContext => $ctx->workingCopies());
        $this->nav->setCurrentPath($request->getUri()->getPath()); // every admin route, with or without auth

        return $handler->handle($request);
    }
}
