<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Session;

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
        if ($request->getAttribute(self::WITHOUT_SESSION) === true) {
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

        $response = $handler->handle($request);

        $this->session->close();

        return $response;
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
