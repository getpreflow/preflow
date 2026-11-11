<?php

declare(strict_types=1);

namespace Preflow\View;

use Nyholm\Psr7\Response;
use Preflow\Core\Http\RequestContext;
use Preflow\Core\Http\Hypermedia\HypermediaProtocols;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders a page template from a controller the way a file page is rendered: the same
 * `request` and `route` template variables, the collected CSS/JS injected, an HTML response.
 * A controller can pick the status (a 404 page) or the template, which a file page cannot.
 */
final class PageResponder
{
    public function __construct(
        private readonly TemplateEngineInterface $engine,
        private readonly AssetCollector $assets,
        private readonly ?RequestContext $context = null,
        private readonly ?HypermediaProtocols $hypermedia = null,
    ) {}

    /**
     * The `request` template variable. File pages and controller pages share it. `isFragment`:
     * a hypermedia client (the app's driver) sent it to put the answer into the page (not a
     * history restore); `isHtmx`: a client sent it at all (history restores too) — the name
     * is older than other drivers, it is not about htmx only.
     */
    public static function requestVariable(ServerRequestInterface $request, ?HypermediaProtocols $hypermedia = null): object
    {
        return (object) [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod(),
            'isHtmx' => $hypermedia?->isFragmentRequest($request) ?? false,
            'isFragment' => $hypermedia?->wantsFragment($request) ?? false,
            'query' => $request->getQueryParams(),
        ];
    }

    /**
     * @param array<string, mixed> $context Template variables; they win over `request` and `route`.
     * @param array<string, string|string[]> $headers
     */
    public function render(string $template, array $context = [], int $status = 200, array $headers = []): ResponseInterface
    {
        $request = $this->context?->request;
        $base = [
            // Route parameters are the controller's own business: pass `route` to override.
            'route' => (object) [],
            'request' => $request !== null
                ? self::requestVariable($request, $this->hypermedia)
                : (object) [
                    'path' => $this->context?->path ?? '',
                    'method' => $this->context?->method ?? 'GET',
                    'isHtmx' => false,
                    'isFragment' => false,
                    'query' => [],
                ],
        ];

        $html = $this->engine->render($template, $context + $base);
        $html = $this->assets->injectInto($html);

        return new Response($status, $headers + ['Content-Type' => 'text/html; charset=UTF-8'], $html);
    }
}
