<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Publishing\Publisher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Publishing actions that are not a form save: unpublish, discard. */
final class PublishingController
{
    public function __construct(
        private readonly TypeCatalog $catalog,
        private readonly Publisher $publisher,
        private readonly string $prefix,
    ) {}

    public function unpublish(ServerRequestInterface $request): ResponseInterface
    {
        [$type, $id] = $this->target($request);
        if ($type === null) {
            return new Response(404, [], 'Unknown type');
        }
        $this->publisher->unpublish($type, $id);

        return $this->backToEditor($type, $id);
    }

    public function discard(ServerRequestInterface $request): ResponseInterface
    {
        [$type, $id] = $this->target($request);
        if ($type === null) {
            return new Response(404, [], 'Unknown type');
        }
        $this->publisher->discard($type, $id);

        return $this->backToEditor($type, $id);
    }

    /** @return array{0: string|null, 1: string} the type (null unless it publishes) and id */
    private function target(ServerRequestInterface $request): array
    {
        $type = (string) $request->getAttribute('type', '');
        $id = (string) $request->getAttribute('id', '');

        return [$this->catalog->has($type) && $this->publisher->enabled($type) ? $type : null, $id];
    }

    private function backToEditor(string $type, string $id): ResponseInterface
    {
        $url = $this->catalog->get($type)?->singleton === true
            ? $this->prefix . '/' . $type
            : $this->prefix . '/' . $type . '/' . rawurlencode($id) . '/edit';

        return new Response(302, ['Location' => $url]);
    }
}
