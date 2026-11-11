<?php

declare(strict_types=1);

namespace Preflow\Auth\Http;

use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Preflow\Core\Exceptions\UnauthorizedHttpException;
use Preflow\Core\Http\Api\ApiConfig;
use Preflow\Core\Http\Hypermedia\HypermediaProtocols;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AuthMiddleware implements MiddlewareInterface
{
    /**
     * @param string|null $guard the guard whose users may pass; null is the default guard
     * @param ApiConfig|null $api tells browsers from API clients; without it nobody is redirected and signed-out requests get 401
     * @param HypermediaProtocols|null $hypermedia the clients whose fragment requests are told to navigate to the
     *        login; without it, the ones core put on the request (an app's own AuthMiddleware gets them too)
     */
    public function __construct(
        private readonly AuthManager $authManager,
        private readonly ?string $guard = null,
        private readonly ?ApiConfig $api = null,
        private readonly ?HypermediaProtocols $hypermedia = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Only this guard's users pass: a signed-in member is no staff user, and vice versa.
        $name = $this->guard ?? $this->authManager->defaultGuardName();
        $user = $this->authManager->guardName() === $name ? $this->authManager->user() : null;
        $user ??= $this->authManager->guard($name)->user($request);
        if ($user === null) {
            $login = $this->api === null ? null : $this->authManager->loginPath($name);
            if ($login !== null) {
                // A hypermedia client would swap a redirected login page into a fragment; ask it to
                // navigate instead (htmx: HX-Redirect). Told in headers only, the 401 page is the error page.
                $hypermedia = $this->hypermedia ?? $request->getAttribute(HypermediaProtocols::class);
                $navigated = $hypermedia instanceof HypermediaProtocols ? $hypermedia->navigate($request, new Response(401), $login) : null;
                if ($navigated !== null) {
                    if ((string) $navigated->getBody() !== '') {
                        return $navigated;
                    }
                    $headers = [];
                    foreach (array_keys($navigated->getHeaders()) as $header) {
                        $headers[$header] = $navigated->getHeaderLine($header);
                    }
                    throw new UnauthorizedHttpException(headers: $headers);
                }
                if (in_array($request->getMethod(), ['GET', 'HEAD'], true) && !$this->api->wantsJson($request)) {
                    // Path and query only: never an absolute URL, so `next` cannot point off-site.
                    $uri = $request->getUri();
                    $next = $uri->getPath() . ($uri->getQuery() !== '' ? '?' . $uri->getQuery() : '');
                    $glue = str_contains($login, '?') ? '&' : '?';
                    return new Response(302, ['Location' => $login . $glue . 'next=' . rawurlencode($next)]);
                }
            }
            throw new UnauthorizedHttpException();
        }
        if ($this->authManager->user() !== $user) {
            $this->authManager->setUser($user, $name);
        }
        $request = $request->withAttribute(Authenticatable::class, $user);
        return $handler->handle($request);
    }
}
