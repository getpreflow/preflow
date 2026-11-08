<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Csrf;

use Preflow\Core\Exceptions\ForbiddenHttpException;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Core\Routing\Route;
use Preflow\Core\Routing\RouterInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Checks every unsafe request, with or without a session: the origin first (a refused origin
 * is refused with any token), then the session token, or without a session the stateless token
 * when the browser sent no origin signal and no cookie. Sets the CsrfToken attribute when there
 * is a token (a session, or a usable app.key). The attribute is taken once per request: after
 * SessionInterface::invalidate() later in the same request it still holds the removed token,
 * so a page rendered then must read CsrfToken::generate($session) instead.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];
    private const FORM_FIELD = '_csrf_token';
    private const HEADER_NAME = 'X-CSRF-Token';

    /**
     * @param ?string   $sessionCookie    the session cookie's name; null means session_name()
     * @param ?\Closure $router           fn(): ?RouterInterface, to find routes marked #[CsrfExempt]
     * @param ?\Closure $expiredResponder fn(ServerRequestInterface): ?ResponseInterface, the framework's
     *                                    answer to an expired session when the site's handler gives none
     * @param ?\Closure $warn             fn(string): void, debug warnings
     */
    public function __construct(
        private readonly CsrfConfig $config,
        private readonly OriginCheck $origin,
        private readonly StatelessCsrfToken $stateless,
        private readonly CsrfSignInPaths $signIn,
        private readonly ?string $sessionCookie,
        private readonly ?\Closure $router,
        private readonly ?ExpiredSessionHandler $expiredSessionHandler,
        private readonly ?\Closure $expiredResponder,
        private readonly ?\Closure $warn = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $request->getAttribute(SessionInterface::class);
        $session = $session instanceof SessionInterface ? $session : null;

        // Before generate(): a session without a token is new (the one the form came from is gone).
        $sessionHadToken = $session !== null && CsrfToken::fromSession($session) !== null;
        $token = match (true) {
            $session !== null => CsrfToken::generate($session),
            $this->stateless->enabled() => CsrfToken::stateless($this->stateless->issue()),
            default => null,
        };
        if ($token !== null) {
            $request = $request->withAttribute(CsrfToken::class, $token);
        }

        if (in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();

        if ($this->isExempt($path)) {
            return $handler->handle($request);
        }

        // API clients: without a cookie there is nothing ambient for a forged request to use.
        if ($request->hasHeader('Authorization') && !$this->hasCookies($request)) {
            return $handler->handle($request);
        }

        $route = $this->matchRoute($request);
        if ($route !== null) {
            // The kernel dispatches this match instead of matching again.
            $request = $request->withAttribute(Route::class, $route);
            if ($route->csrfExempt) {
                return $handler->handle($request);
            }
        }

        $verdict = $this->origin->verdict($request);
        if ($verdict === OriginVerdict::Refuse) {
            throw $this->refuse('origin');
        }

        $submitted = $this->extractSubmittedToken($request) ?? '';

        // No session, or a new one without its cookie: nothing in the visitor's name to forge.
        // A session that held a token is checked on it, however it was resumed. A session token
        // sent with other cookies but without the session's is an expired session (the browser
        // dropped the session cookie and kept the remember-me one), not a public post.
        if ($session === null || (!$sessionHadToken && !$this->hasSessionCookie($request) && !$this->staleSessionToken($request, $submitted))) {
            if ($verdict === OriginVerdict::Pass) {
                return $handler->handle($request);
            }
            // The public token proves nothing about the page; a cookie (remember-me) could act in
            // the visitor's name, so without an origin signal only a cookieless request may use it.
            if ($this->hasCookies($request)) {
                throw $this->refuse('no-signal');
            }
            $this->warnIfDisabled();
            if (!$this->stateless->verify($submitted)) {
                throw $this->refuse('no-signal');
            }
            return $handler->handle($request);
        }

        if ($sessionHadToken) {
            if ($token !== null && hash_equals($token->getValue(), $submitted)) {
                return $handler->handle($request);
            }
            // A public page's form (stateless token) posting into a sign-in form: no authority
            // of the session is used there. Nor on a public path, where the session only resumed
            // because the visitor brought its cookie (session.paths): the form may come from a
            // cached page, as it would for a visitor without one. Everywhere else a live session
            // needs its own token.
            $public = $this->signIn->matches($path) || $request->getAttribute(\Preflow\Core\Http\Session\SessionMiddleware::RESUMED_BY_COOKIE) === true;
            if ($verdict === OriginVerdict::Pass && $public && $this->stateless->verify($submitted)) {
                return $handler->handle($request);
            }
            throw $this->refuse('token');
        }

        // A new session: the form came from a public page (stateless token), or its session expired.
        if ($this->stateless->verify($submitted)) {
            if ($verdict !== OriginVerdict::Pass) {
                throw $this->refuse('no-signal');
            }
            return $handler->handle($request);
        }

        if ($submitted === '') {
            throw $this->refuse('token');
        }

        if ($verdict !== OriginVerdict::Pass) {
            throw new CsrfSessionExpiredException();
        }

        // The posted password is the proof; login CSRF is stopped by the origin check.
        if ($this->signIn->matches($path)) {
            return $handler->handle($request);
        }

        $response = $this->expiredSessionHandler?->handle($request);
        if ($response === null && $this->expiredResponder !== null) {
            $response = ($this->expiredResponder)($request);
        }
        if ($response instanceof ResponseInterface) {
            return $response;
        }

        throw new CsrfSessionExpiredException();
    }

    private function isExempt(string $path): bool
    {
        foreach ([CsrfConfig::COMPONENT_PREFIX, ...$this->config->exempt] as $prefix) {
            if ($prefix === '') {
                continue;
            }
            // Whole segments: '/hooks' covers /hooks and /hooks/stripe, not /hookshot.
            $prefix = rtrim($prefix, '/');
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }
        return false;
    }

    private function matchRoute(ServerRequestInterface $request): ?Route
    {
        $router = $this->router !== null ? ($this->router)() : null;
        if (!$router instanceof RouterInterface) {
            return null;
        }

        try {
            return $router->match($request);
        } catch (NotFoundHttpException) {
            return null; // not exempt; the kernel answers 404 once the check passes
        }
    }

    /** A session-shaped token (not a stateless one) on a request that carries cookies. */
    private function staleSessionToken(ServerRequestInterface $request, string $submitted): bool
    {
        return $submitted !== ''
            && !StatelessCsrfToken::looksStateless($submitted)
            && $this->hasCookies($request);
    }

    private function hasCookies(ServerRequestInterface $request): bool
    {
        return $request->getCookieParams() !== [] || $request->hasHeader('Cookie');
    }

    private function hasSessionCookie(ServerRequestInterface $request): bool
    {
        $name = $this->sessionCookie ?? session_name();
        $cookies = $request->getCookieParams();

        return is_string($name) && isset($cookies[$name]) && $cookies[$name] !== '';
    }

    private function warnIfDisabled(): void
    {
        if ($this->warn !== null && !$this->stateless->enabled()) {
            ($this->warn)('CSRF: a request without origin headers needs the stateless token, which is off'
                . ' while app.key is empty or the built-in default. Set app.key.');
        }
    }

    private function refuse(string $reason): ForbiddenHttpException
    {
        return new ForbiddenHttpException('CSRF check failed: ' . $reason . '.');
    }

    private function extractSubmittedToken(ServerRequestInterface $request): ?string
    {
        $body = $request->getParsedBody();

        $field = is_array($body) && isset($body[self::FORM_FIELD]) && is_scalar($body[self::FORM_FIELD])
            ? (string) $body[self::FORM_FIELD]
            : '';
        if ($field !== '') {
            return $field;
        }

        // An empty field (a form printed without a token) does not hide the header.
        $header = $request->getHeaderLine(self::HEADER_NAME);
        if ($header !== '') {
            return $header;
        }

        return null;
    }
}
