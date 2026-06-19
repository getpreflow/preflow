<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Serves Folio's package-owned admin stylesheet from disk. The URL is
 * content-hash versioned (see FolioServiceProvider), so the response is safe to
 * cache immutably. Keeping the CSS a real file (not a PHP string) leaves it
 * publish-ready for a future asset-publishing system.
 */
final class AssetController
{
    public function __construct(private readonly string $cssPath) {}

    public function adminCss(ServerRequestInterface $request): ResponseInterface
    {
        if (!is_file($this->cssPath)) {
            return new Response(404, [], 'Not found');
        }

        return new Response(
            200,
            [
                'Content-Type' => 'text/css; charset=UTF-8',
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ],
            (string) file_get_contents($this->cssPath),
        );
    }
}
