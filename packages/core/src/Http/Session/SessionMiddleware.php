<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Session;

use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\RenderContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SessionMiddleware implements MiddlewareInterface
{
    /**
     * Request attribute: true runs the request without a session (no start, no
     * cookie, nobody signed in), as for an internal anonymous render.
     */
    public const string WITHOUT_SESSION = 'preflow.session.without';

    /**
     * Request attribute: true when the session started outside the session paths only because
     * the visitor brought its cookie (a signed-in visitor on a public page).
     */
    public const string RESUMED_BY_COOKIE = 'preflow.session.resumed_by_cookie';

    /**
     * @param list<string>|null $paths path prefixes that get a session; null means every request.
     *                                 Elsewhere a session starts only when the request carries
     *                                 the session cookie (it resumes: a signed-in visitor keeps
     *                                 their session on public pages); a visitor without one gets
     *                                 none: no session start, no cookie.
     * @param string|null $cookie the session cookie's name; session_name() when null
     */
    public function __construct(
        private readonly SessionInterface $session,
        private readonly ?array $paths = null,
        private readonly ?string $cookie = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // An internal render (audit, GEO, rebuild, verify, preview) is an anonymous visitor's: no session.
        // Nor has a role view (view as role, since 0.81.0), whatever its purpose: the session the
        // request carries is the editor's, never started, aged, read, written or closed by it.
        if ($request->getAttribute(self::WITHOUT_SESSION) === true || Contexts::depth() > 0 && (Contexts::current()->purpose->isInternal() || Contexts::current()->viewer->isSynthetic())) {
            return $handler->handle($request);
        }
        $onPath = $this->applies($request->getUri()->getPath());
        if (!$onPath && !$this->carriesCookie($request)) {
            return $handler->handle($request);
        }

        $this->session->start();
        $this->session->ageFlash();

        $request = $request->withAttribute(SessionInterface::class, $this->session);
        if (!$onPath) {
            $request = $request->withAttribute(self::RESUMED_BY_COOKIE, true);
        }
        if (Contexts::depth() > 0) {
            // The render's request carries its session from here on (ofCurrentRender()).
            Contexts::narrow(static fn (RenderContext $ctx): RenderContext => $ctx->for($request));
        }

        $response = $handler->handle($request);

        $this->session->close();

        return $response;
    }

    /**
     * The session of the current render: the SessionInterface attribute its request carries once
     * this middleware started the session. Null outside every render, and in a render without a
     * session (an internal render, a public path without the session cookie), and under a role
     * view (view as role), also one narrowed into a role view after its session started. flash(),
     * csrf_token() and old() read it, so a render never reads another render's session.
     *
     * @since 0.81.0
     */
    public static function ofCurrentRender(): ?SessionInterface
    {
        if (Contexts::depth() > 0 && Contexts::current()->viewer->isSynthetic()) {
            return null;
        }
        $session = Contexts::peek()?->request?->request?->getAttribute(SessionInterface::class);

        return $session instanceof SessionInterface ? $session : null;
    }

    private function carriesCookie(ServerRequestInterface $request): bool
    {
        $name = $this->cookie ?? session_name();
        $value = is_string($name) && $name !== '' ? ($request->getCookieParams()[$name] ?? null) : null;

        return is_string($value) && $value !== '';
    }

    private function applies(string $path): bool
    {
        if ($this->paths === null) {
            return true;
        }
        foreach ($this->paths as $prefix) {
            $prefix = rtrim($prefix, '/');
            if ($prefix === '' || $path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }
}
