<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Serves Folio's package-owned admin assets (CSS + JS, incl. vendored editor
 * bundles) from disk via a strict allowlist. URLs are content-hash versioned
 * (see FolioServiceProvider::folio_asset), so responses cache immutably.
 */
final class AssetController
{
    private readonly AdminAssets $allowlist;

    /** @param array<string, string>|AdminAssets $allowlist flat URL filename => path relative to $baseDir (or absolute) */
    public function __construct(
        private readonly string $baseDir,
        array|AdminAssets $allowlist,
    ) {
        $this->allowlist = $allowlist instanceof AdminAssets ? $allowlist : new AdminAssets($allowlist);
    }

    public function serve(ServerRequestInterface $request): ResponseInterface
    {
        $file = (string) $request->getAttribute('file', '');
        $rel = $this->allowlist->get($file);
        if ($rel === null) {
            return new Response(404, [], 'Not found');
        }

        $path = str_starts_with($rel, '/') ? $rel : $this->baseDir . '/' . $rel; // absolute: a file from another package
        if (!is_file($path)) {
            return new Response(404, [], 'Not found');
        }

        return new Response(
            200,
            [
                'Content-Type' => $this->contentType($file) . '; charset=UTF-8',
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ],
            (string) file_get_contents($path),
        );
    }

    private function contentType(string $file): string
    {
        return match (true) {
            str_ends_with($file, '.css') => 'text/css',
            str_ends_with($file, '.js') => 'text/javascript',
            str_ends_with($file, '.svg') => 'image/svg+xml',
            default => 'application/octet-stream',
        };
    }
}
