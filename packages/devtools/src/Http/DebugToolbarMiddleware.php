<?php

declare(strict_types=1);

namespace Preflow\DevTools\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Purpose;
use Preflow\Core\Debug\DebugCollector;
use Preflow\Core\Http\RequestLimits;
use Preflow\Core\Http\ContentSecurityPolicy;
use Preflow\DevTools\DebugToolbar;

/**
 * Adds the debug toolbar to HTML pages; a render that leaves no trace (Purpose::leavesNoTrace():
 * audit, GEO, rebuild, preview) gets none, nor does the page cache's verify render.
 *
 * Since 0.82.0 the toolbar's script and style carry the nonces of the policy the page is sent
 * with: the response's own Content-Security-Policy (else its Report-Only trial,
 * ContentSecurityPolicy::ofResponse()), which a page rendered in a frame of its own brings along
 * (a whole-page preview, answered from the admin's request). Where that policy names no nonce for
 * the tag, or there is none yet, the request's nonce (SecurityHeadersMiddleware adds that policy
 * afterwards). The toolbar also shows what the request's frame asked of PHP's limits
 * (RequestLimits); only that frame's, not those of renders it ran in frames of their own.
 */
final class DebugToolbarMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly DebugCollector $collector,
        /** @var (\Closure(): ?string)|null returns the request's CSP nonce */
        private readonly ?\Closure $nonce = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        // Verify's second render (page cache) reaches nobody: a toolbar there would only make pages differ.
        if (Contexts::depth() > 0 && (Contexts::current()->purpose->leavesNoTrace() || Contexts::current()->purpose === Purpose::Verify)) {
            return $response;
        }

        $contentType = $response->getHeaderLine('Content-Type');
        if (!str_contains($contentType, 'text/html')) {
            return $response;
        }

        $body = (string) $response->getBody();
        if (!str_contains($body, '</body>')) {
            return $response;
        }

        $policy = ContentSecurityPolicy::ofResponse($response);
        $requestNonce = fn (): ?string => $this->nonce !== null ? ($this->nonce)() : null;
        $nonce = $policy?->nonceFor('script') ?? $requestNonce();
        $styleNonce = $policy?->nonceFor('style') ?? $requestNonce();
        // Read from the request's frame (made empty there when nothing asked: debug mode only).
        $limits = Contexts::depth() > 0 ? Contexts::frame()->slot(RequestLimits::class, static fn (): RequestLimits => new RequestLimits()) : null;
        $toolbarHtml = (new DebugToolbar())->render($this->collector, $nonce, $body, $limits, $styleNonce);

        // Before the last </body> only: inlined scripts (e.g. htmx) can carry the tag in a string.
        $body = substr_replace($body, $toolbarHtml, (int) strrpos($body, '</body>'), 0);

        return $response->withBody(\Nyholm\Psr7\Stream::create($body));
    }
}
