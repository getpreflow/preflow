<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Folio\Media\MediaAvailability;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\FolioText;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The media library's routes need the storage driver config/media.php names ("json" unless set).
 * A site whose config/data.php lacks it (no media at all, say) boots and works; here the answer is
 * the missing driver and where to set it, never the data layer's exception as a 500. A page or
 * drawer (a GET for HTML) answers 200: no media is a legitimate state, not an outage. An htmx or
 * fetch/JSON action did not happen, so it gets a 503 (htmx: the message in the toast, as
 * Forbidden does).
 *
 * @since 0.84.0
 */
final class MediaStorageMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly MediaAvailability $availability,
        private readonly TemplateEngineInterface $engine,
        private readonly TypeCatalog $catalog,
        private readonly string $prefix,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->availability->usable()) {
            return $handler->handle($request);
        }

        $message = FolioText::get('folio.assets.storage_missing', ['driver' => $this->availability->driver()]);
        if ($request->getHeaderLine('HX-Request') === 'true') {
            return new Response(503, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'HX-Trigger' => (string) json_encode(['folio:error' => ['message' => $message]]),
            ], $message);
        }
        if ($request->getHeaderLine('Sec-Fetch-Dest') === 'empty' || str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return new Response(503, ['Content-Type' => 'text/plain; charset=UTF-8'], $message);
        }

        // A form post (no htmx, no JSON) did not happen either; only a page that is read answers 200.
        return new Response($request->getMethod() === 'GET' || $request->getMethod() === 'HEAD' ? 200 : 503, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-store'], $this->engine->render('@folio/admin/assets/unavailable.twig', [
            'prefix' => $this->prefix,
            'types' => $this->catalog->all(),
            'message' => $message,
            'layout' => $request->getHeaderLine('Sec-Fetch-Dest') === 'iframe' || ($request->getQueryParams()['_drawer'] ?? null) === '1'
                ? '@folio/admin/_drawer_layout.twig'
                : '@folio/admin/_layout.twig',
        ]));
    }
}
