<?php

declare(strict_types=1);

namespace Preflow\Media\Http;

use Nyholm\Psr7\Response;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Core\Http\InternalRender;
use Preflow\Media\Asset;
use Preflow\Media\Image\Formats;
use Preflow\Media\Image\ImageWidths;
use Preflow\Media\MediaLibrary;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A missing image variant under the media URL ({base}-{width}.{avif|webp}, and .jpg for box files) reaches PHP
 * once: it is made, written next to the original and returned; from then on the web
 * server serves the file. The base must be one of the asset's current ones:
 * - the delivery image's stem ({Y}/{m}/{id}[-v{n}], an edited image's master
 *   {…}-edit-{key}, a public document's preview {…}-preview), with a width the presets allow;
 * - the base of one of its box plans ({delivery stem}-{preset}-{key}), with a width of that plan.
 * Anything else under the media URL (an old key, an unknown preset, another width) is a
 * 404 that writes nothing. An internal render (InternalRender) makes nothing either: it gets
 * the variant if it exists; one that would be made but is not yet on disk is a 404 with
 * "X-Preflow-Variant: pending", so it can be told from one that does not exist.
 */
final class VariantController
{
    /** Header on an internal render's 404 for a variant that would be made on a visitor's request (since 0.54). */
    public const string PENDING_HEADER = 'X-Preflow-Variant';

    private const string VARIANT = '#^(\d{4}/\d{2}/([a-f0-9]{32})(?:-[a-z0-9-]+?)?)-([1-9]\d{0,4})\.(avif|webp|jpg)$#';

    public function __construct(
        private readonly MediaLibrary $library,
        private readonly ImageWidths $widths,
        private readonly ?Formats $formats = null,
    ) {}

    public function serve(ServerRequestInterface $request): ResponseInterface
    {
        $path = (string) $request->getAttribute('path', '');
        if (preg_match(self::VARIANT, $path, $m) !== 1) {
            throw new NotFoundHttpException('Not found');
        }
        [, $base, $id, $width, $format] = $m;
        $width = (int) $width;
        $asset = $this->library->find($id) ?? throw new NotFoundHttpException('Not found');

        // The delivery image's own widths (the image, an edited image's master, a document's preview).
        $delivery = $this->library->deliveryAsset($asset);
        if ($format !== 'jpg' && $delivery !== null && self::stem($delivery) === $base) {
            if (!$this->library->allowsVariant($delivery, $width, $format, $this->widths->allowed())) {
                throw new NotFoundHttpException('Not found');
            }
            if (InternalRender::is($request)) {
                return $this->existing($path, $format);
            }
            $file = $this->library->variantFile($delivery, $width, $format, $this->widths->allowed());

            return $file !== null ? self::file($file, $format) : $this->fallback($delivery, $width, $format);
        }

        // A box preset's file, when the name is one of the asset's current plans.
        foreach (array_keys($this->formats?->boxes() ?? []) as $preset) {
            $plan = $this->library->boxPlan($asset, $preset);
            if ($plan === null || $plan->base !== $base) {
                continue;
            }
            if (!$this->library->allowsBox($plan, $width, $format)) {
                throw new NotFoundHttpException('Not found');
            }
            if (InternalRender::is($request)) {
                return $this->existing($path, $format);
            }
            $file = $this->library->boxFile($plan, $width, $format);

            return $file !== null ? self::file($file, $format) : $this->fallback($plan->source, $width, $format);
        }

        throw new NotFoundHttpException('Not found');
    }

    private static function stem(Asset $asset): string
    {
        return substr($asset->path, 0, (int) strrpos($asset->path, '.'));
    }

    /** The allowed variant at $path (relative to the media dir) as it is on disk; a pending 404 when not yet made. */
    private function existing(string $path, string $format): ResponseInterface
    {
        $file = $this->library->absolute($path);

        return is_file($file) ? self::file($file, $format) : new Response(404, [self::PENDING_HEADER => 'pending']);
    }

    private static function file(string $file, string $format): ResponseInterface
    {
        return new Response(200, [
            'Content-Type' => 'image/' . ($format === 'jpg' ? 'jpeg' : $format),
            'Content-Length' => (string) filesize($file),
            'Cache-Control' => 'public, max-age=31536000',
        ], (string) file_get_contents($file));
    }

    /**
     * The encode failed (memory, time, encoder): never a broken image in a srcset.
     * Point at the closest existing size, uncached, so it retries later.
     */
    private function fallback(Asset $delivery, int $width, string $format): ResponseInterface
    {
        return new Response(302, [
            'Location' => $this->library->url($this->library->nearestVariant($delivery, $width, $format)),
            'Cache-Control' => 'no-store',
        ]);
    }
}
