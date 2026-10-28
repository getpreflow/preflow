<?php

declare(strict_types=1);

namespace Preflow\View\Qr;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Data\QRMatrix;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode as Library;
use chillerlan\QRCode\QROptions;

/**
 * Renders QR codes through chillerlan/php-qrcode, which stays an optional
 * dependency: sites that never print a QR code should not have to install it.
 */
final class QrCode
{
    // Level names only: the library's EccLevel is resolved after the availability check.
    private const ECC_LEVELS = ['L', 'M', 'Q', 'H'];

    private static ?string $libraryClass = null;

    /**
     * Whether the QR library is installed.
     */
    public static function available(): bool
    {
        return class_exists(self::$libraryClass ?? Library::class);
    }

    /**
     * An inline `<svg>` element. Colours are written as attributes the
     * library escapes itself, so the result is safe to print as HTML.
     *
     * @param array{size?: int, margin?: int, ecc?: string, color?: string, background?: string} $options
     */
    public static function svg(string $data, array $options = []): string
    {
        self::requireLibrary();
        $o = self::normalise($options);
        $svg = (string) (new Library(self::options($o, QROutputInterface::MARKUP_SVG)))->render($data);

        // The library sizes the SVG by viewBox only; pin the pixel size.
        $svg = preg_replace(
            '/<svg\b/',
            sprintf('<svg width="%1$d" height="%1$d"', $o['size']),
            $svg,
            1,
        );

        return (string) $svg;
    }

    /**
     * Raw PNG bytes. The size is rounded down to a whole pixel count per
     * module, so the image stays crisp and decodable.
     *
     * @param array{size?: int, margin?: int, ecc?: string, color?: string, background?: string} $options
     */
    public static function png(string $data, array $options = []): string
    {
        self::requireLibrary();
        $o = self::normalise($options);
        $options = self::options($o, QROutputInterface::GDIMAGE_PNG);

        $modules = (new Library($options))->getQRMatrix($data)->getSize();
        $options->scale = max(1, intdiv($o['size'], $modules));

        return (string) (new Library($options))->render($data);
    }

    /**
     * Test seam: point the availability check at another class name (or
     * null to restore) to simulate the library being missing.
     *
     * @internal
     */
    public static function overrideLibraryClass(?string $class): void
    {
        self::$libraryClass = $class;
    }

    /**
     * Runs before anything touches a library symbol, so a missing library
     * gives this message and not an autoload fatal.
     */
    private static function requireLibrary(): void
    {
        if (!self::available()) {
            throw new \RuntimeException(
                'QR codes need the chillerlan/php-qrcode library. Install it with: composer require chillerlan/php-qrcode',
            );
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array{size: int, margin: int, ecc: string, color: string, background: string}
     */
    private static function normalise(array $options): array
    {
        $ecc = strtoupper((string) ($options['ecc'] ?? 'M'));
        if (!in_array($ecc, self::ECC_LEVELS, true)) {
            throw new \InvalidArgumentException("QR error correction must be one of L, M, Q, H; got '{$ecc}'.");
        }

        return [
            // Below 21px even the smallest QR symbol cannot be drawn.
            'size' => max(21, (int) ($options['size'] ?? 200)),
            'margin' => max(0, (int) ($options['margin'] ?? 2)),
            'ecc' => $ecc,
            'color' => (string) ($options['color'] ?? '#000'),
            'background' => (string) ($options['background'] ?? '#fff'),
        ];
    }

    /**
     * @param array{size: int, margin: int, ecc: string, color: string, background: string} $o
     */
    private static function options(array $o, string $outputType): QROptions
    {
        $colors = $outputType === QROutputInterface::MARKUP_SVG
            ? [$o['color'], $o['background']]
            : [self::rgb($o['color']), self::rgb($o['background'])];

        return new QROptions([
            'outputType' => $outputType,
            'eccLevel' => constant(EccLevel::class . '::' . $o['ecc']),
            'quietzoneSize' => $o['margin'],
            'outputBase64' => false,
            'svgAddXmlHeader' => false,
            'bgColor' => $colors[1],
            'moduleValues' => [
                QRMatrix::M_DATA_DARK => $colors[0],
                QRMatrix::M_FINDER_DARK => $colors[0],
                QRMatrix::M_ALIGNMENT_DARK => $colors[0],
                QRMatrix::M_TIMING_DARK => $colors[0],
                QRMatrix::M_FORMAT_DARK => $colors[0],
                QRMatrix::M_VERSION_DARK => $colors[0],
                QRMatrix::M_DARKMODULE => $colors[0],
                QRMatrix::M_FINDER_DOT => $colors[0],
                QRMatrix::M_QUIETZONE => $colors[1],
            ],
        ]);
    }

    /**
     * @return array{int, int, int}
     */
    private static function rgb(string $css): array
    {
        $hex = ltrim(trim($css), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            throw new \InvalidArgumentException("PNG QR colours must be hex like #000 or #1a2b3c; got '{$css}'.");
        }

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}
