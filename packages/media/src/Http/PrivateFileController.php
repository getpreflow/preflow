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
 *
 * {private_url}/{id}/v/{n}: an earlier file of any asset (public or private), with
 * the same gate and headers, confined to the versions directory. Version files are
 * always private: the gate is asked with the asset marked private.
 *
 * {private_url}/{id}/preview: a private document's preview (a PNG in the private dir),
 * with the same gate and headers. A public asset's preview is under the media URL: 404.
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

        $file = $asset !== null && $asset->private ? $this->library->file($asset) : null;

        return $this->send($file, $this->library->privateDir(), $asset?->mime ?? '', $asset?->originalName ?? '');
    }

    public function serveVersion(ServerRequestInterface $request): ResponseInterface
    {
        $id = (string) $request->getAttribute('id', '');
        $number = (int) $request->getAttribute('n', '0');
        $asset = $this->library->find($id);
        // Earlier files are always private, also of a public asset: the gate sees it as private.
        $gated = $asset !== null ? new Asset(...[...get_object_vars($asset), 'private' => true]) : self::placeholder($id);
        $refused = $this->access->decide($request, $gated);
        if ($refused !== null) {
            return $refused;
        }

        $version = null;
        foreach ($asset?->versions ?? [] as $v) {
            $version = $v->number === $number ? $v : $version;
        }
        $name = is_string($version?->meta['original_name'] ?? null) ? $version->meta['original_name'] : '';

        return $this->send($this->library->versionFile($id, $number), $this->library->versionsDir(), $version?->mime ?? '', $name);
    }

    public function servePreview(ServerRequestInterface $request): ResponseInterface
    {
        $id = (string) $request->getAttribute('id', '');
        $asset = $this->library->find($id);
        $refused = $this->access->decide($request, $asset ?? self::placeholder($id));
        if ($refused !== null) {
            return $refused;
        }

        $file = $asset !== null && $asset->private ? $this->library->previewFile($asset) : null;
        $name = $asset !== null ? pathinfo($asset->originalName, PATHINFO_FILENAME) . '-preview.png' : '';

        return $this->send($file, $this->library->privateDir(), $file !== null ? 'image/png' : '', $name);
    }

    /** The file, if it is inside $dir (realpath), else a 404; never cached. */
    private function send(?string $file, ?string $dir, string $mime, string $originalName): ResponseInterface
    {
        $base = $dir !== null ? realpath($dir) : false;
        $full = $file !== null ? realpath($file) : false;
        if ($base === false || $full === false || $mime === ''
            || !str_starts_with($full, $base . DIRECTORY_SEPARATOR)
            || !is_file($full)
        ) {
            return new Response(404, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => self::NO_STORE], 'Not found');
        }

        $inline = str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml';
        $name = str_replace(['"', '\\', "\r", "\n"], '', $originalName);
        $ascii = (string) preg_replace('/[^\x20-\x7E]/', '_', $name);

        return new Response(200, [
            'Content-Type' => $mime,
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
