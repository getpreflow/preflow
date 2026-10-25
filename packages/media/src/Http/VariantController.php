<?php

declare(strict_types=1);

namespace Preflow\Media\Http;

use Nyholm\Psr7\Response;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Image\ImageWidths;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A missing image variant under the media URL ({Y}/{m}/{id}[-v{n}]-{width}.{avif|webp},
 * or {Y}/{m}/{id}[-v{n}]-preview-{width}.{avif|webp} of a public document's preview)
 * reaches PHP once: if the width is one the presets allow, it is made, written
 * next to the original and returned; from then on the web server serves the
 * file. Anything else under the media URL is a 404 that writes nothing.
 */
final class VariantController
{
    private const string VARIANT = '#^(\d{4}/\d{2}/([a-f0-9]{32})(?:-v([2-9]|[1-9]\d{1,4}))?(-preview)?)-([1-9]\d{0,4})\.(avif|webp)$#';

    public function __construct(
        private readonly MediaLibrary $library,
        private readonly ImageWidths $widths,
    ) {}

    public function serve(ServerRequestInterface $request): ResponseInterface
    {
        $path = (string) $request->getAttribute('path', '');
        if (preg_match(self::VARIANT, $path, $m) !== 1) {
            throw new NotFoundHttpException('Not found');
        }
        [, $base, $id, , $preview, $width, $format] = $m;
        $asset = $this->library->find($id);
        if ($asset !== null && $preview !== '') {
            $asset = $this->library->previewAsset($asset); // the preview as the image the variant is made of
        }
        if ($asset === null || substr($asset->path, 0, (int) strrpos($asset->path, '.')) !== $base) {
            throw new NotFoundHttpException('Not found');
        }
        if (!$this->library->allowsVariant($asset, (int) $width, $format, $this->widths->allowed())) {
            throw new NotFoundHttpException('Not found');
        }
        $file = $this->library->variantFile($asset, (int) $width, $format, $this->widths->allowed());
        if ($file === null) {
            // The encode failed (memory, time, encoder): never a broken image in a
            // srcset. Point at the closest existing size, uncached, so it retries later.
            return new Response(302, [
                'Location' => $this->library->url($this->library->nearestVariant($asset, (int) $width, $format)),
                'Cache-Control' => 'no-store',
            ]);
        }

        return new Response(200, [
            'Content-Type' => 'image/' . $format,
            'Content-Length' => (string) filesize($file),
            'Cache-Control' => 'public, max-age=31536000',
        ], (string) file_get_contents($file));
    }
}
