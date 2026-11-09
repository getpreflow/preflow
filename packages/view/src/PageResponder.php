<?php

declare(strict_types=1);

namespace Preflow\View;

use Nyholm\Psr7\Response;
use Preflow\Core\Http\RequestContext;
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
    ) {}

    /**
     * The `request` template variable. File pages and controller pages share it.
     */
    public static function requestVariable(ServerRequestInterface $request): object
    {
        return (object) [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod(),
            'isHtmx' => $request->getHeaderLine('HX-Request') === 'true',
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
                ? self::requestVariable($request)
                : (object) [
                    'path' => $this->context?->path ?? '',
                    'method' => $this->context?->method ?? 'GET',
                    'isHtmx' => false,
                    'query' => [],
                ],
        ];

        $html = $this->engine->render($template, $context + $base);
        $html = $this->assets->injectInto($html);

        return new Response($status, $headers + ['Content-Type' => 'text/html; charset=UTF-8'], $html);
    }
}
