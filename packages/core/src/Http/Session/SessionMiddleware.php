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
     * @param list<string>|null $paths path prefixes that get a session; null means every request.
     *                                 Other requests get none: no session start, no cookie.
     */
    public function __construct(
        private readonly SessionInterface $session,
        private readonly ?array $paths = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getAttribute(self::WITHOUT_SESSION) === true || !$this->applies($request->getUri()->getPath())) {
            return $handler->handle($request);
        }

        $this->session->start();
        $this->session->ageFlash();

        $request = $request->withAttribute(SessionInterface::class, $this->session);

        $response = $handler->handle($request);

        $this->session->close();

        return $response;
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
