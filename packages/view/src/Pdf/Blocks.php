<?php

declare(strict_types=1);

namespace Preflow\View\Pdf;

use Preflow\View\Qr\QrCode;
use Psr\Container\ContainerInterface;

/**
 * The PDF building blocks behind the pdf_* template functions. Inside
 * PdfRenderer::render() they write mPDF's markup; everywhere else (the
 * browser preview, an ordinary page) they write HTML a browser shows
 * approximately: page numbers as nothing, a page break as a dashed line.
 *
 * The template functions are registered once per engine, so which of the
 * two applies is kept per render in a static stack that PdfRenderer opens
 * and closes around each render.
 */
final class Blocks
{
    public const int DEFAULT_MAX_PX = 1200;

    // About 160 MB of GD memory; a bigger image is refused rather than risking the request.
    private const int DECODE_MAX_PIXELS = 40_000_000;

    // The media library's class, named as a string: preflow/media is not a dependency of preflow/view.
    private const string MEDIA_LIBRARY = 'Preflow\Media\MediaLibrary';

    /** @var list<array{config: ?PdfConfig, temp: list<string>}> the open renders, innermost last */
    private static array $renders = [];

    /**
     * @param ?ContainerInterface $container where a media asset id is looked up, when preflow/media is installed
     */
    public function __construct(private readonly ?ContainerInterface $container = null)
    {
    }

    /**
     * Runs a PDF render: the blocks write mPDF markup, and scaled image
     * copies go into the config's temp dir and are deleted afterwards, also
     * when the render throws.
     *
     * @template T
     * @param \Closure(): T $render
     * @return T
     */
    public static function forPdf(PdfConfig $config, \Closure $render): mixed
    {
        return self::scoped($config, $render);
    }

    /**
     * Runs a browser preview render. Blocks write browser HTML and nothing
     * touches the disk.
     *
     * @template T
     * @param \Closure(): T $render
     * @return T
     */
    public static function forPreview(\Closure $render): mixed
    {
        return self::scoped(null, $render);
    }

    /** The current page's number (in the preview: nothing). */
    public function pageNumber(): string
    {
        return self::pdf() !== null ? '{PAGENO}' : '';
    }

    /** The document's page count (in the preview: nothing). */
    public function pageCount(): string
    {
        return self::pdf() !== null ? '{nbpg}' : '';
    }

    /** Starts a new page (in the preview: a dashed line). */
    public function pageBreak(): string
    {
        return self::pdf() !== null
            ? '<pagebreak />'
            : '<div class="pdf-page-break" style="border-top: 1px dashed #999; margin: 2em 0;"></div>';
    }

    /**
     * An image from an absolute file path or a preflow/media asset id. An
     * image wider or taller than max_px goes into the PDF as a scaled JPEG
     * copy, never as the full original.
     *
     * @param array{width_mm?: int|float, max_px?: int, allow_private?: bool, alt?: string} $options
     */
    public function image(string $assetIdOrPath, array $options = []): string
    {
        $maxPx = (int) ($options['max_px'] ?? self::DEFAULT_MAX_PX);
        if ($maxPx < 1) {
            throw new \InvalidArgumentException("pdf_image's max_px must be at least 1; got {$maxPx}.");
        }
        [$file, $url] = $this->imageSource($assetIdOrPath, (bool) ($options['allow_private'] ?? false));
        $pdf = self::pdf();

        $size = @getimagesize($file);
        // Only what GD reads is scaled; anything else (an SVG, say) goes in as it is.
        $scale = $size !== false && max($size[0], $size[1]) > $maxPx && in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true);

        if ($pdf !== null) {
            $src = $scale ? $this->scaledFile($file, $maxPx, $pdf) : $file;
        } elseif ($scale) {
            $src = 'data:image/jpeg;base64,' . base64_encode(self::scaledJpeg($file, $maxPx));
        } else {
            // The browser cannot read a file path; a media asset has a URL of its own.
            $src = $url ?? 'data:' . ($size['mime'] ?? (mime_content_type($file) ?: 'application/octet-stream')) . ';base64,' . base64_encode((string) file_get_contents($file));
        }

        $style = isset($options['width_mm']) ? ' style="width: ' . self::number((float) $options['width_mm']) . 'mm;"' : '';

        return '<img src="' . self::e($src) . '" alt="' . self::e((string) ($options['alt'] ?? '')) . '"' . $style . ' />';
    }

    /**
     * A table whose header row mPDF repeats on every page it spans.
     * $columns maps a key of each row to its header text; a list of
     * headers reads list rows by position.
     *
     * @param iterable<array<array-key, mixed>> $rows
     * @param array<array-key, string>          $columns
     * @param array{class?: string, widths?: array<array-key, string>} $options widths: column key => CSS width (e.g. "30mm")
     */
    public function table(iterable $rows, array $columns, array $options = []): string
    {
        $widths = (array) ($options['widths'] ?? []);
        $class = trim('pdf-table ' . (string) ($options['class'] ?? ''));

        $head = '';
        foreach ($columns as $key => $label) {
            $width = isset($widths[$key]) ? ' style="width: ' . self::e((string) $widths[$key]) . ';"' : '';
            $head .= "<th{$width}>" . self::e((string) $label) . '</th>';
        }

        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr>';
            foreach (array_keys($columns) as $key) {
                $body .= '<td>' . self::e(self::cell($row[$key] ?? null, $key)) . '</td>';
            }
            $body .= '</tr>';
        }

        return '<table class="' . self::e($class) . '"><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table>';
    }

    /**
     * A QR code as vector SVG, sized in mm (QrCode::svg() options apply,
     * except its pixel size).
     *
     * @param array{size_mm?: int|float, margin?: int, ecc?: string, color?: string, background?: string} $options
     */
    public function qr(string $data, array $options = []): string
    {
        $mm = (float) ($options['size_mm'] ?? 30);
        if ($mm <= 0) {
            throw new \InvalidArgumentException("pdf_qr's size_mm must be above 0; got {$mm}.");
        }
        unset($options['size_mm'], $options['size']);
        $size = self::number($mm) . 'mm';

        return (string) preg_replace('/^<svg width="\d+" height="\d+"/', "<svg width=\"{$size}\" height=\"{$size}\"", QrCode::svg($data, $options), 1);
    }

    /**
     * @template T
     * @param \Closure(): T $render
     * @return T
     */
    private static function scoped(?PdfConfig $config, \Closure $render): mixed
    {
        self::$renders[] = ['config' => $config, 'temp' => []];
        $at = array_key_last(self::$renders);
        try {
            return $render();
        } finally {
            foreach (self::$renders[$at]['temp'] as $file) {
                @unlink($file);
            }
            array_splice(self::$renders, $at);
        }
    }

    /** The config of the PDF being rendered; null in a preview or outside any render. */
    private static function pdf(): ?PdfConfig
    {
        return self::$renders === [] ? null : self::$renders[array_key_last(self::$renders)]['config'];
    }

    /**
     * The image's file, and its URL when it is a media asset.
     *
     * @return array{string, ?string}
     */
    private function imageSource(string $assetIdOrPath, bool $allowPrivate): array
    {
        if (str_starts_with($assetIdOrPath, '/') || preg_match('~^[a-z]:[\\\\/]~i', $assetIdOrPath)) {
            if (!is_file($assetIdOrPath)) {
                throw new \InvalidArgumentException("pdf_image: there is no file at '{$assetIdOrPath}'.");
            }

            return [$assetIdOrPath, null];
        }

        if (!class_exists(self::MEDIA_LIBRARY) || $this->container === null || !$this->container->has(self::MEDIA_LIBRARY)) {
            throw new \InvalidArgumentException(
                "pdf_image: '{$assetIdOrPath}' is not an absolute file path, and there is no media library (preflow/media) to look it up as an asset id.",
            );
        }

        /** @var \Preflow\Media\MediaLibrary $library */
        $library = $this->container->get(self::MEDIA_LIBRARY);
        $asset = $library->find($assetIdOrPath)
            ?? throw new \InvalidArgumentException("pdf_image: there is no media asset '{$assetIdOrPath}'.");
        if ($asset->private && !$allowPrivate) {
            throw new \InvalidArgumentException(
                "pdf_image: the media asset '{$assetIdOrPath}' is private. Pass allow_private: true to put it into the PDF.",
            );
        }
        if ($asset->kind() !== 'image') {
            throw new \InvalidArgumentException("pdf_image: the media asset '{$assetIdOrPath}' is not an image.");
        }
        // An edited image (crop, rotation) is printed as it is shown: its rendered master.
        $shown = $library->deliveryAsset($asset) ?? $asset;
        $file = $library->file($shown);
        if ($file === null || !is_file($file)) {
            throw new \InvalidArgumentException("pdf_image: the file of the media asset '{$assetIdOrPath}' is missing.");
        }

        return [$file, $library->url($asset)];
    }

    /** A scaled JPEG copy in the temp dir, deleted when the render ends. */
    private function scaledFile(string $file, int $maxPx, PdfConfig $config): string
    {
        $dir = $config->tempDir;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("The PDF temp dir '{$dir}' cannot be created.");
        }
        $path = $dir . '/pdf-image-' . bin2hex(random_bytes(8)) . '.jpg';
        $jpeg = self::scaledJpeg($file, $maxPx);
        // Registered before writing, so even a half-written copy is cleaned up.
        self::$renders[array_key_last(self::$renders)]['temp'][] = $path;
        if (file_put_contents($path, $jpeg) === false) {
            throw new \RuntimeException("The scaled image copy '{$path}' cannot be written.");
        }

        return $path;
    }

    /**
     * The image at most $maxPx on its longer side, as JPEG bytes;
     * transparency becomes white.
     */
    private static function scaledJpeg(string $file, int $maxPx): string
    {
        if (!function_exists('imagecreatefromstring')) {
            throw new \RuntimeException("Scaling images for PDFs needs PHP's gd extension.");
        }
        $size = getimagesize($file);
        if ($size === false || $size[0] * $size[1] > self::DECODE_MAX_PIXELS) {
            throw new \RuntimeException("The image '{$file}' cannot be read, or is too large to scale.");
        }
        $image = @imagecreatefromstring((string) file_get_contents($file));
        if ($image === false) {
            throw new \RuntimeException("The image '{$file}' cannot be read.");
        }
        $k = $maxPx / max(imagesx($image), imagesy($image));
        $w = max(1, (int) round(imagesx($image) * $k));
        $h = max(1, (int) round(imagesy($image) * $k));
        $out = imagecreatetruecolor($w, $h);
        imagefill($out, 0, 0, (int) imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $image, 0, 0, 0, 0, $w, $h, imagesx($image), imagesy($image));
        unset($image);

        ob_start();
        imagejpeg($out, null, 85);

        return (string) ob_get_clean();
    }

    private static function cell(mixed $value, int|string $key): string
    {
        return match (true) {
            $value === null => '',
            is_scalar($value), $value instanceof \Stringable => (string) $value,
            default => throw new \InvalidArgumentException("pdf_table: the value in column '{$key}' cannot be printed as text."),
        };
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.2F', $value), '0'), '.');
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
