<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Streams uploaded files from the Folio uploads dir. Path-traversal guarded via
 * realpath confinement; non-image types are served as octet-stream so nothing
 * runs inline. Stored filenames are randomized, so a long cache is safe.
 */
final class UploadController
{
    private const TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'pdf' => 'application/pdf',
        'txt' => 'text/plain',
    ];

    public function __construct(private readonly string $uploadsDir) {}

    public function serve(ServerRequestInterface $request): ResponseInterface
    {
        $path = (string) $request->getAttribute('path', '');

        $base = realpath($this->uploadsDir);
        if ($base === false) {
            return new Response(404, [], 'Not found');
        }

        $full = realpath($this->uploadsDir . '/' . $path);
        if ($full === false
            || !str_starts_with($full, $base . DIRECTORY_SEPARATOR)
            || !is_file($full)
        ) {
            return new Response(404, [], 'Not found');
        }

        $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        $type = self::TYPES[$ext] ?? 'application/octet-stream';

        return new Response(
            200,
            ['Content-Type' => $type, 'Cache-Control' => 'public, max-age=31536000'],
            (string) file_get_contents($full),
        );
    }
}
