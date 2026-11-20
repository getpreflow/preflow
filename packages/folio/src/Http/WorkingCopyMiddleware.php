<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\SyntheticUser;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\RenderContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The first middleware of every admin route (signed-in screens, htmx fragments, JSON endpoints,
 * the sign-in and password pages, with or without config/auth.php; only the static files under
 * _assets and _uploads have none):
 * - a synthetic viewer (view as role: a role view's frame, or its SyntheticUser on the request)
 *   is refused with 403 (since 0.81.0): the admin never runs as a role view, so nothing it
 *   writes (an import, the extensions file) can be reached from a preview;
 * - admin routes (including the live preview) render working copies of referenced records: the
 *   request's frame reads working copies for the rest of the request
 *   (RenderContext::workingCopies()), work after the response included. It runs outside the
 *   admin surface's stretch (AdminLocaleMiddleware), so the narrow holds for the whole frame.
 */
final class WorkingCopyMiddleware implements MiddlewareInterface
{
    /** @since 0.81.0 takes the nav only (the read mode is the context's, not LiveContent's) */
    public function __construct(private readonly AdminNav $nav) {}

    /** @throws \Preflow\Core\Context\NoRenderContext outside every render */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (self::refusesViewer($request)) {
            return new Response(403, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'private, no-store'], 'Forbidden');
        }
        Contexts::narrow(static fn (RenderContext $ctx): RenderContext => $ctx->workingCopies());
        $this->nav->setCurrentPath($request->getUri()->getPath()); // every admin route, with or without auth

        return $handler->handle($request);
    }

    /**
     * Whether $request comes from a synthetic viewer (view as role), which no admin route serves.
     *
     * @internal Folio's admin middleware
     * @since 0.81.0
     */
    public static function refusesViewer(ServerRequestInterface $request): bool
    {
        return Contexts::current()->viewer->isSynthetic() || $request->getAttribute(Authenticatable::class) instanceof SyntheticUser;
    }
}
