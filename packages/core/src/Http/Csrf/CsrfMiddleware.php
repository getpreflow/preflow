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
 * when the browser sent no origin signal. Sets the CsrfToken attribute on every request.
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
        if ($request->hasHeader('Authorization') && $request->getCookieParams() === [] && !$request->hasHeader('Cookie')) {
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

        // No session, or no session cookie: nothing in the visitor's name to forge.
        if ($session === null || !$this->hasSessionCookie($request)) {
            if ($verdict === OriginVerdict::Pass) {
                return $handler->handle($request);
            }
            $this->warnIfDisabled();
            if (!$this->stateless->verify($submitted)) {
                throw $this->refuse('no-signal');
            }
            return $handler->handle($request);
        }

        if ($sessionHadToken) {
            if ($token === null || !hash_equals($token->getValue(), $submitted)) {
                throw $this->refuse('token');
            }
            return $handler->handle($request);
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
        foreach ($this->config->exempt as $prefix) {
            if ($prefix !== '' && str_starts_with($path, $prefix)) {
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

        if (is_array($body) && isset($body[self::FORM_FIELD]) && is_scalar($body[self::FORM_FIELD])) {
            return (string) $body[self::FORM_FIELD];
        }

        $header = $request->getHeaderLine(self::HEADER_NAME);
        if ($header !== '') {
            return $header;
        }

        return null;
    }
}
