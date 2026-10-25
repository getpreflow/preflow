<?php

declare(strict_types=1);

namespace Preflow\Media\Http;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Preflow\Media\Access\PrivateFileAccess;
use Preflow\Media\Asset;
use Preflow\Media\MediaLibrary;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * {private_url}/{id}: a private asset, to whom PrivateFileAccess allows (the core's
 * default allows nobody). A refusal is its response (a 403, a redirect to a login).
 * The file is confined to the private directory with realpath and never cached;
 * images are inline, everything else (SVG too) an attachment with its original name.
 */
final class PrivateFileController
{
    private const string NO_STORE = 'private, no-store';

    public function __construct(
        private readonly MediaLibrary $library,
        private readonly PrivateFileAccess $access,
    ) {}

    public function serve(ServerRequestInterface $request): ResponseInterface
    {
        $id = (string) $request->getAttribute('id', '');
        $asset = $this->library->find($id);
        // Access first, also for an unknown id: a refused visitor learns nothing about which ids exist.
        $refused = $this->access->decide($request, $asset ?? self::placeholder($id));
        if ($refused !== null) {
            return $refused;
        }

        $dir = $this->library->privateDir();
        $base = $dir !== null ? realpath($dir) : false;
        $file = $asset !== null && $asset->private ? $this->library->file($asset) : null;
        $full = $file !== null ? realpath($file) : false;
        if ($asset === null || $base === false || $full === false
            || !str_starts_with($full, $base . DIRECTORY_SEPARATOR)
            || !is_file($full)
        ) {
            return new Response(404, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => self::NO_STORE], 'Not found');
        }

        $inline = $asset->kind() === 'image' && $asset->mime !== 'image/svg+xml';
        $name = str_replace(['"', '\\', "\r", "\n"], '', $asset->originalName);
        $ascii = (string) preg_replace('/[^\x20-\x7E]/', '_', $name);

        return new Response(200, [
            'Content-Type' => $asset->mime,
            'Content-Length' => (string) filesize($full),
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name),
            'Cache-Control' => self::NO_STORE,
            'X-Content-Type-Options' => 'nosniff',
        ], Stream::create(fopen($full, 'rb'))); // streamed: member files may be large
    }

    /** For an id with no stored asset: only the id, private, no file. */
    private static function placeholder(string $id): Asset
    {
        return new Asset(
            id: $id, path: '', originalName: '', mime: '', size: 0, width: null, height: null, duration: null,
            alt: [], title: '', focalX: 50, focalY: 50, posterId: null, variants: [], createdAt: '', createdBy: null,
            private: true,
        );
    }
}
