<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Api;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * CORS for the API paths (config/api.php `cors`). Every API response varies on Origin, so a shared
 * cache never serves one origin's answer to another; the CORS headers go to requests that carry an allowed Origin.
 * Registered outermost, so page-cache hits get the headers and stored pages never hold them;
 * the Kernel adds them to error responses through decorate().
 */
final class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ApiConfig $config) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->config->isApi($request)) {
            return $handler->handle($request);
        }

        if ($request->hasHeader('Origin') && $request->getMethod() === 'OPTIONS' && $request->hasHeader('Access-Control-Request-Method')) {
            return $this->preflight($request);
        }

        return $this->decorate($request, $handler->handle($request));
    }

    /** Adds Vary: Origin to an API response, and the CORS headers when the Origin is allowed. */
    public function decorate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->config->isApi($request)) {
            return $response;
        }

        $response = $this->withVary($response);
        if (!$this->applies($request) || !$this->allowed($request)) {
            return $response;
        }

        $response = $this->withOrigin($request, $response);
        if ($this->config->corsExpose !== []) {
            $response = $response->withHeader('Access-Control-Expose-Headers', implode(', ', $this->config->corsExpose));
        }

        return $response;
    }

    /** A disallowed origin gets an empty 204 without CORS headers (only Vary): the browser then blocks the call. */
    private function preflight(ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->withVary(new Response(204));
        if (!$this->allowed($request)) {
            return $response;
        }

        return $this->withOrigin($request, $response)
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $this->config->corsMethods))
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $this->config->corsHeaders))
            ->withHeader('Access-Control-Max-Age', (string) $this->config->corsMaxAge);
    }

    private function applies(ServerRequestInterface $request): bool
    {
        return $request->hasHeader('Origin') && $this->config->isApi($request);
    }

    private function allowed(ServerRequestInterface $request): bool
    {
        return in_array('*', $this->config->corsOrigins, true)
            || in_array($request->getHeaderLine('Origin'), $this->config->corsOrigins, true);
    }

    private function withOrigin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // Browsers refuse `*` with credentials, so then the origin itself is sent.
        $origin = in_array('*', $this->config->corsOrigins, true) && !$this->config->corsCredentials
            ? '*'
            : $request->getHeaderLine('Origin');

        $response = $response->withHeader('Access-Control-Allow-Origin', $origin);
        if ($this->config->corsCredentials) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }

    private function withVary(ResponseInterface $response): ResponseInterface
    {
        // Appended: the response may already vary on other headers (Accept-Encoding).
        $vary = array_map(trim(...), explode(',', strtolower($response->getHeaderLine('Vary'))));
        if (!in_array('origin', $vary, true) && !in_array('*', $vary, true)) {
            $response = $response->withAddedHeader('Vary', 'Origin');
        }

        return $response;
    }
}
