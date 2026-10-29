<?php

declare(strict_types=1);

namespace Preflow\View\Qr;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
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

        return self::render($data, $o, (string) $o['size']);
    }

    /**
     * The SVG with a CSS length of its own (e.g. "25mm") instead of a pixel
     * size; pdf_qr prints it this way.
     *
     * @internal
     * @param array{margin?: int, ecc?: string, color?: string, background?: string} $options
     */
    public static function sizedSvg(string $data, array $options, string $cssLength): string
    {
        self::requireLibrary();

        return self::render($data, self::normalise($options), $cssLength);
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
     * @param array{size: int, margin: int, ecc: string, color: string, background: string} $o
     */
    private static function render(string $data, array $o, string $length): string
    {
        $svg = (string) (new Library(self::options($o, QROutputInterface::MARKUP_SVG)))->render($data);

        // The library sizes the SVG by viewBox only; pin the size on its root element.
        $length = htmlspecialchars($length, ENT_QUOTES);
        $sized = preg_replace('/<svg\b/', "<svg width=\"{$length}\" height=\"{$length}\"", $svg, 1, $count);
        if ($count !== 1) {
            throw new \LogicException('The QR library returned no <svg> element to size.');
        }

        return (string) $sized;
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
        if ($outputType === QROutputInterface::MARKUP_SVG) {
            $dark = self::svgColour($o['color']);
            $light = self::svgColour($o['background']);
        } else {
            $dark = self::rgb($o['color']);
            $light = self::rgb($o['background']);
        }

        // Every module type gets a colour: the library paints the light ones
        // (data, finder, timing, format, …) #fff by default, which would break a
        // non-white background and make inverted colours unreadable.
        $moduleValues = [];
        foreach (QROutputInterface::DEFAULT_MODULE_VALUES as $type => $isDark) {
            $moduleValues[$type] = $isDark ? $dark : $light;
        }

        return new QROptions([
            'outputType' => $outputType,
            'eccLevel' => constant(EccLevel::class . '::' . $o['ecc']),
            'quietzoneSize' => $o['margin'],
            'outputBase64' => false,
            'svgAddXmlHeader' => false,
            'bgColor' => $light,
            'moduleValues' => $moduleValues,
        ]);
    }

    /**
     * The library silently falls back to black and white for a colour it
     * does not accept; refuse it instead, as the PNG path does.
     */
    private static function svgColour(string $css): string
    {
        if (!QRMarkupSVG::moduleValueIsValid($css)) {
            throw new \InvalidArgumentException("SVG QR colours must be CSS colours like #000, rgb(0,0,0) or black; got '{$css}'.");
        }

        return $css;
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
