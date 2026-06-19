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
    /** @param array<string, string> $allowlist flat URL filename => path relative to $baseDir */
    public function __construct(
        private readonly string $baseDir,
        private readonly array $allowlist,
    ) {}

    public function serve(ServerRequestInterface $request): ResponseInterface
    {
        $file = (string) $request->getAttribute('file', '');
        $rel = $this->allowlist[$file] ?? null;
        if ($rel === null) {
            return new Response(404, [], 'Not found');
        }

        $path = $this->baseDir . '/' . $rel;
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
            default => 'application/octet-stream',
        };
    }
}
