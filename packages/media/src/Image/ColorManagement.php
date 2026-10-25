<?php

declare(strict_types=1);

namespace Preflow\Media\Image;

/**
 * Print-true variants: a CMYK source, or one with an embedded profile that is not
 * sRGB (Adobe RGB, Display P3, a grey profile), is converted to sRGB with lcms
 * through Imagick, relative colorimetric with black-point compensation, and written
 * without a profile (untagged is sRGB in browsers). GD cannot do this: it drops
 * profiles. sRGB and untagged sources are left to the usual backend.
 */
final class ColorManagement
{
    /** FOGRA39L coated (colord, CC0): assigned to a CMYK source without a profile. */
    public const string DEFAULT_CMYK_PROFILE = __DIR__ . '/../../resources/icc/FOGRA39L_coated.icc';

    public const string SRGB_PROFILE = __DIR__ . '/../../resources/icc/sRGB.icc';

    /** Imagick takes black-point compensation from the wand when it reads an image, never later. */
    private const string BPC = 'black-point-compensation';

    /** sRGB's colorants, adapted to D50 as an ICC profile stores them (rXYZ, gXYZ, bXYZ). */
    private const array SRGB_COLORANTS = [
        'rXYZ' => [0.4361, 0.2225, 0.0139],
        'gXYZ' => [0.3851, 0.7169, 0.0971],
        'bXYZ' => [0.1431, 0.0606, 0.7141],
    ];

    private readonly string $cmykProfile;

    /** @var array<string, string> profile bytes by path */
    private array $profiles = [];

    /** @param ?bool $available null: detect (Imagick with lcms); a flag overrides it (tests) */
    public function __construct(?string $cmykProfile = null, private ?bool $available = null)
    {
        $this->cmykProfile = $cmykProfile ?? self::DEFAULT_CMYK_PROFILE;
    }

    /** Whether Imagick with lcms is present, so sources can be converted. */
    public function available(): bool
    {
        if ($this->available === null) {
            $this->available = class_exists(\Imagick::class)
                && str_contains(strtolower(implode(' ', \Imagick::getConfigureOptions('DELEGATES'))), 'lcms');
        }

        return $this->available;
    }

    /**
     * True for a CMYK source, or one whose embedded profile is not sRGB. Read from
     * the file's own headers (JPEG, PNG, WebP; AVIF through Imagick when present),
     * so it also answers without Imagick. Unreadable or untagged: false.
     */
    public function needsConversion(string $file): bool
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            $head = (string) fread($handle, 12);
            [$cmyk, $profile] = match (true) {
                str_starts_with($head, "\xFF\xD8") => self::jpeg($handle),
                str_starts_with($head, "\x89PNG\r\n\x1A\n") => [false, self::png($handle)],
                str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => [false, self::webp($handle)],
                substr($head, 4, 4) === 'ftyp' => [false, self::viaImagick($file)],
                default => [false, null],
            };
        } catch (\Throwable) {
            return false;
        } finally {
            fclose($handle);
        }

        return $cmyk || ($profile !== null && $profile !== '' && !self::isSrgb($profile));
    }

    /**
     * Convert the image to sRGB in place and strip it: relative colorimetric with
     * black-point compensation, from its own profile, or from the default CMYK
     * profile for CMYK without one. Best read with open(); an image read otherwise
     * is read again so the compensation applies.
     */
    public function toSrgb(\Imagick $image): void
    {
        if ($image->getOption(self::BPC) !== 'true') {
            $image->setImageFormat('miff');
            $blob = $image->getImagesBlob();
            $image->clear();
            $image->setOption(self::BPC, 'true');
            $image->readImageBlob($blob);
        }
        $profile = $image->getImageProfiles('icc', true)['icc'] ?? null;
        if (($profile === null || $profile === '') && $image->getImageColorspace() === \Imagick::COLORSPACE_CMYK) {
            $profile = $this->profile($this->cmykProfile);
            $image->profileImage('icc', $profile); // assigned: there is nothing to convert from yet
        }
        if ($profile !== null && $profile !== '' && !self::isSrgb($profile)) {
            $image->setImageRenderingIntent(\Imagick::RENDERINGINTENT_RELATIVE);
            $image->profileImage('icc', $this->profile(self::SRGB_PROFILE));
            $image->clampImage(); // out of gamut: clipped now, so scaling does not blend values outside 0-255
        }
        if ($image->getImageColorspace() !== \Imagick::COLORSPACE_SRGB) {
            $image->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
        }
        $image->stripImage();
    }

    /** The file read by Imagick, ready for toSrgb(). */
    public function open(string $file): \Imagick
    {
        $image = new \Imagick();
        $image->setOption(self::BPC, 'true');
        $image->readImage($file);

        return $image;
    }

    private function profile(string $path): string
    {
        if (!isset($this->profiles[$path])) {
            $bytes = @file_get_contents($path);
            if ($bytes === false || $bytes === '') {
                throw new \RuntimeException("The colour profile {$path} cannot be read.");
            }
            $this->profiles[$path] = $bytes;
        }

        return $this->profiles[$path];
    }

    /**
     * An RGB profile with sRGB's colorants (and not a linear variant of them). A
     * profile without colorants (a table-based one) counts by its description.
     */
    private static function isSrgb(string $profile): bool
    {
        if (strlen($profile) < 132 || substr($profile, 16, 4) !== 'RGB ') {
            return false;
        }
        $tags = [];
        $count = min(unpack('N', $profile, 128)[1], 200);
        for ($i = 0; $i < $count && 144 + $i * 12 <= strlen($profile); $i++) {
            ['sig' => $sig, 'offset' => $offset, 'size' => $size] = unpack('a4sig/Noffset/Nsize', $profile, 132 + $i * 12);
            $tags[$sig] = substr($profile, $offset, $size);
        }
        $description = self::description($tags['desc'] ?? '');
        if (str_contains(strtolower($description), 'linear')) {
            return false;
        }
        foreach (self::SRGB_COLORANTS as $sig => $expected) {
            $tag = $tags[$sig] ?? '';
            if (strlen($tag) < 20 || !str_starts_with($tag, 'XYZ ')) {
                return str_contains($description, 'sRGB');
            }
            foreach (array_values(unpack('N3', $tag, 8)) as $i => $raw) {
                $value = ($raw >= 0x80000000 ? $raw - 0x100000000 : $raw) / 65536;
                if (abs($value - $expected[$i]) > 0.01) {
                    return false;
                }
            }
        }

        return true;
    }

    /** The text of a 'desc' (ICC v2) or 'mluc' (v4, first record) tag. */
    private static function description(string $tag): string
    {
        if (str_starts_with($tag, 'desc') && strlen($tag) >= 12) {
            return rtrim(substr($tag, 12, unpack('N', $tag, 8)[1]), "\0");
        }
        if (str_starts_with($tag, 'mluc') && strlen($tag) >= 28) {
            ['length' => $length, 'offset' => $offset] = unpack('Nlength/Noffset', $tag, 20);
            $text = substr($tag, $offset, $length);

            return function_exists('mb_convert_encoding') ? (string) mb_convert_encoding($text, 'UTF-8', 'UTF-16BE') : str_replace("\0", '', $text);
        }

        return '';
    }

    /**
     * Up to the image data: whether the frame has four components (CMYK or YCCK),
     * and the ICC profile from its APP2 chunks.
     *
     * @param resource $handle positioned after the first 12 bytes
     * @return array{bool, ?string}
     */
    private static function jpeg($handle): array
    {
        fseek($handle, 2);
        $chunks = [];
        $cmyk = false;
        while (!feof($handle)) {
            $marker = fread($handle, 2);
            if ($marker === false || strlen($marker) < 2 || $marker[0] !== "\xFF") {
                break;
            }
            $type = ord($marker[1]);
            if ($type === 0xD9 || $type === 0xDA) { // end of image, start of scan
                break;
            }
            if ($type === 0x01 || ($type >= 0xD0 && $type <= 0xD7) || $type === 0xFF) {
                continue;
            }
            $bytes = (string) fread($handle, 2);
            $length = strlen($bytes) === 2 ? unpack('n', $bytes)[1] : 0;
            if ($length < 2) {
                break;
            }
            if ($type === 0xE2) {
                $data = (string) fread($handle, $length - 2);
                if (str_starts_with($data, "ICC_PROFILE\0") && strlen($data) > 14) {
                    $chunks[ord($data[12])] = substr($data, 14);
                }
            } elseif ($type >= 0xC0 && $type <= 0xCF && !in_array($type, [0xC4, 0xC8, 0xCC], true)) {
                $data = (string) fread($handle, $length - 2);
                $cmyk = strlen($data) >= 6 && ord($data[5]) === 4;
            } else {
                fseek($handle, $length - 2, SEEK_CUR);
            }
        }
        ksort($chunks);

        return [$cmyk, $chunks === [] ? null : implode('', $chunks)];
    }

    /**
     * The iCCP chunk's profile; null without one (an sRGB chunk or untagged).
     *
     * @param resource $handle
     */
    private static function png($handle): ?string
    {
        fseek($handle, 8);
        while (!feof($handle)) {
            $header = (string) fread($handle, 8);
            if (strlen($header) < 8) {
                return null;
            }
            ['length' => $length, 'type' => $type] = unpack('Nlength/a4type', $header);
            if ($type === 'IDAT' || $type === 'IEND') {
                return null;
            }
            if ($type === 'iCCP') {
                $data = (string) fread($handle, $length);
                $name = strpos($data, "\0");
                if ($name === false) {
                    return null;
                }
                $profile = @gzuncompress(substr($data, $name + 2));

                return $profile === false ? null : $profile;
            }
            fseek($handle, $length + 4, SEEK_CUR); // data and CRC
        }

        return null;
    }

    /**
     * The ICCP chunk's profile of an extended WebP.
     *
     * @param resource $handle
     */
    private static function webp($handle): ?string
    {
        fseek($handle, 12);
        while (!feof($handle)) {
            $header = (string) fread($handle, 8);
            if (strlen($header) < 8) {
                return null;
            }
            ['type' => $type, 'length' => $length] = unpack('a4type/Vlength', $header);
            if ($type === 'ICCP') {
                return (string) fread($handle, $length);
            }
            if ($type !== 'VP8X') {
                return null; // the profile comes before the image data
            }
            fseek($handle, $length + ($length % 2), SEEK_CUR);
        }

        return null;
    }

    /** An AVIF's ICC profile, read by Imagick when it is there. */
    private static function viaImagick(string $file): ?string
    {
        if (!class_exists(\Imagick::class)) {
            return null;
        }
        $image = new \Imagick();
        $image->pingImage($file);

        return $image->getImageProfiles('icc', true)['icc'] ?? null;
    }
}
