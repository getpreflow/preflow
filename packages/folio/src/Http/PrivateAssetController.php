<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Folio\Assets\AssetLibrary;
use Preflow\Folio\Content\FrontendGate;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * {private_url}/{id}: a private asset, only to a signed-in user with
 * assets.private_permission (FrontendGate, fails closed). Anyone else gets a
 * 403, or goes to assets.private_login with the URL as "next". The file is
 * confined to the private directory with realpath and never cached; images
 * are inline, everything else (SVG too) an attachment with its original name.
 */
final class PrivateAssetController
{
    private const string NO_STORE = 'private, no-store';

    public function __construct(
        private readonly AssetLibrary $library,
        private readonly FrontendGate $gate,
        private readonly string $permission,
        private readonly ?string $login = null,
    ) {}

    public function serve(ServerRequestInterface $request): ResponseInterface
    {
        // The permission first: a refused visitor learns nothing about which ids exist.
        if (!$this->gate->allows($this->permission)) {
            if ($this->login === null) {
                return new Response(403, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => self::NO_STORE], 'Forbidden');
            }
            $next = '/' . ltrim($request->getUri()->getPath(), '/\\');

            return new Response(302, [
                'Location' => $this->login . (str_contains($this->login, '?') ? '&' : '?') . 'next=' . rawurlencode($next),
                'Cache-Control' => self::NO_STORE,
            ]);
        }

        $asset = $this->library->find((string) $request->getAttribute('id', ''));
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
        ], (string) file_get_contents($full));
    }
}
