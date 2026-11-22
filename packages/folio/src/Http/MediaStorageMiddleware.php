<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Data\DataManager;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\FolioText;
use Preflow\Media\MediaConfig;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The media library's routes need the storage driver config/media.php names ("json" unless set).
 * A site whose config/data.php lacks it (no media at all, say) boots and works; here the answer is
 * the missing driver and where to set it, a 503, never the data layer's exception as a 500. For
 * htmx a message the toast shows, as Forbidden does.
 *
 * @since 0.84.0
 */
final class MediaStorageMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly MediaConfig $media,
        private readonly DataManager $dm,
        private readonly TemplateEngineInterface $engine,
        private readonly TypeCatalog $catalog,
        private readonly string $prefix,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array($this->media->storage, $this->dm->driverNames(), true)) {
            return $handler->handle($request);
        }

        $message = FolioText::get('folio.assets.storage_missing', ['driver' => $this->media->storage]);
        if ($request->getHeaderLine('HX-Request') === 'true') {
            return new Response(503, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'HX-Trigger' => (string) json_encode(['folio:error' => ['message' => $message]]),
            ], $message);
        }
        if ($request->getHeaderLine('Sec-Fetch-Dest') === 'empty' || str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return new Response(503, ['Content-Type' => 'text/plain; charset=UTF-8'], $message);
        }

        return new Response(503, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-store'], $this->engine->render('@folio/admin/assets/unavailable.twig', [
            'prefix' => $this->prefix,
            'types' => $this->catalog->all(),
            'message' => $message,
            'layout' => $request->getHeaderLine('Sec-Fetch-Dest') === 'iframe' || ($request->getQueryParams()['_drawer'] ?? null) === '1'
                ? '@folio/admin/_drawer_layout.twig'
                : '@folio/admin/_layout.twig',
        ]));
    }
}
