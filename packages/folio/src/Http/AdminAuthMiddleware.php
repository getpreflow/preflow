<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Preflow\Auth\SyntheticUser;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\Viewer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Gates the Folio admin: unauthenticated requests are redirected (302) to the
 * login page instead of throwing 401 (the framework AuthMiddleware behavior).
 * The signed-in staff user becomes the frame's viewer (Contexts::narrow(), since 0.81.0).
 * A synthetic viewer (view as role: a role view's frame, or its SyntheticUser on the request)
 * is refused with 403: the admin never runs as a role view, so nothing it writes (an import,
 * the extensions file) can be reached from a preview.
 */
final class AdminAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly string $prefix,
        private readonly AdminNav $nav,
        /** Folio's staff guard (StaffGuard); null: the default guard */
        private readonly ?string $guard = null,
    ) {}

    /** @throws \Preflow\Core\Context\NoRenderContext outside every render */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // WorkingCopyMiddleware, first on every admin route, refuses these already; this copy keeps a
        // route that runs this middleware alone safe too.
        if (WorkingCopyMiddleware::refusesViewer($request)) {
            return new Response(403, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'private, no-store'], 'Forbidden');
        }
        // Only Folio's staff guard signs into the admin; a user resolved for it already (or set in
        // tests) counts. A user of another guard on the request (an employer, a member) does not.
        $guard = $this->guard ?? $this->auth->defaultGuardName();
        $preset = $request->getAttribute(Authenticatable::class);
        $presetGuard = $request->getAttribute(AuthManager::GUARD_ATTRIBUTE) ?? $this->auth->defaultGuardName();
        $user = $preset instanceof Authenticatable && $presetGuard === $guard
            ? $preset
            : $this->auth->guard($guard)->user($request);
        if ($user === null) {
            // htmx requests (autosave, block picker) would follow a 302 and swap the
            // login page into the editor: tell htmx to navigate instead. Folio's admin ships its
            // own htmx (@folio/htmx.js), whatever the app's hypermedia driver is, so its header is read here.
            if ($request->getHeaderLine('HX-Request') === 'true') {
                return new Response(401, ['HX-Redirect' => $this->prefix . '/login']);
            }
            return new Response(302, ['Location' => $this->prefix . '/login']);
        }
        if ($user instanceof SyntheticUser) {
            return new Response(403, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'private, no-store'], 'Forbidden');
        }
        Contexts::narrow(static fn (RenderContext $ctx): RenderContext => $ctx->as(new Viewer([$guard => $user], $guard)));
        $this->nav->setCurrentPath($request->getUri()->getPath());
        $request = $request->withAttribute(Authenticatable::class, $user);
        $response = $handler->handle($request);

        // A staff screen and the parts it loads (?part=…) answer on one URL: the browser's back/forward
        // cache must show neither again (the bare part as a page), nor keep the page for a signed-out user.
        return $response->hasHeader('Cache-Control') ? $response : $response->withHeader('Cache-Control', 'no-store');
    }
}
