<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Folio\Extension\ExtensionAssets;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Serves an enabled extension's assets/ files at {prefix}/_assets/x/{extension}/{...path}
 * (public, like _assets/{file}); ExtensionAssets decides what is served, everything else
 * is a 404. Responses cache immutably only for a request whose ?v= is the file's current
 * hash; any other request revalidates (no-cache + ETag, 304 on a match).
 */
final class ExtensionAssetController
{
    public function __construct(
        private readonly ExtensionAssets $assets,
    ) {}

    public function serve(ServerRequestInterface $request): ResponseInterface
    {
        $extension = $request->getAttribute('extension');
        $path = $request->getAttribute('path');
        $real = is_string($extension) && is_string($path) ? $this->assets->path($extension, $path) : null;
        $type = $real === null ? null : ExtensionAssets::type($real);
        $body = $real === null ? false : @file_get_contents($real);
        if ($type === null || $body === false) {
            return new Response(404, [], 'Not found');
        }

        $hash = ExtensionAssets::hash($real);
        $headers = ['X-Content-Type-Options' => 'nosniff'];
        if (($request->getQueryParams()['v'] ?? null) === $hash) {
            $headers['Cache-Control'] = 'public, max-age=31536000, immutable';
        } else {
            $headers['Cache-Control'] = 'no-cache';
            $headers['ETag'] = '"' . $hash . '"';
            foreach (explode(',', $request->getHeaderLine('If-None-Match')) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '*' || $candidate === '"' . $hash . '"' || $candidate === 'W/"' . $hash . '"') {
                    return new Response(304, $headers);
                }
            }
        }

        return new Response(200, ['Content-Type' => $type] + $headers, $body);
    }
}
