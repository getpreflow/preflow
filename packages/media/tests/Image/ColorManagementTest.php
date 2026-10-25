<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Image;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Media\Image\ColorManagement;
use Preflow\Media\Image\GdBackend;
use Preflow\Media\Image\ImageWidths;
use Preflow\Media\Image\VariantGenerator;
use Preflow\Media\ImageTag;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Tests\Support\FixedLocale;
use Preflow\Media\Tests\Support\MediaFixtures;
use Preflow\Media\Variant;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;

/**
 * Variants of CMYK and wide-gamut sources are converted to sRGB (relative colorimetric,
 * black-point compensation, no embedded profile); sRGB and untagged sources are made
 * exactly as before. The fixtures and references.json come from Fixtures/color/make-references.php.
 */
#[RequiresPhpExtension('gd')]
#[RequiresFunction('imagewebp')]
final class ColorManagementTest extends TestCase
{
    use MediaFixtures;

    private const string FIXTURES = __DIR__ . '/../Fixtures/color';

    /**
     * srgb.jpg's variants as GD wrote them before colour management existed (widths 64
     * and 128, quality 75), on GD 2.3.3 with the owner's libwebp/libavif. The encoders'
     * output depends on their versions, so elsewhere the test is skipped; the equivalence
     * test below holds everywhere.
     */
    private const array SRGB_HASHES = [
        'gd' => '2.3.3',
        '64.webp' => '991bb87b64a5f31ebee555e608e6ca8af3d2394c44a5b5e196189e665d8de846',
        '128.webp' => 'dee986c621ca3235197c8a24377ac50fe795ab25de207c7025e13a2560fd2ff9',
        '64.avif' => '10cc8f876c1433a5824e247a5d1066a3308d57aa8ba30c5382f70003ea8c6133',
        '128.avif' => '5b6864feb235aa15589fb7b5c34ceaaa556d86aaf33fade266fa7e23df5a1807',
    ];

    protected function setUp(): void
    {
        $this->makeAssetRoot();
    }

    protected function tearDown(): void
    {
        $this->removeAssetRoot();
    }

    private function generator(?ColorManagement $color = null): VariantGenerator
    {
        return new VariantGenerator(new GdBackend(), [64, 256], 75, $color ?? new ColorManagement());
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES . '/' . $name);
    }

    /** @return array<string, mixed> */
    private static function references(): array
    {
        return json_decode((string) file_get_contents(self::FIXTURES . '/references.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{string}> */
    public static function fixtures(): iterable
    {
        foreach (['cmyk-profile.jpg', 'cmyk-no-profile.jpg', 'adobe-rgb.jpg', 'srgb.jpg'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('fixtures')]
    #[RequiresPhpExtension('imagick')]
    public function test_variant_patches_match_the_references(string $name): void
    {
        $asset = $this->library([], $this->generator())->upload($this->upload($this->fixture($name), $name), null);
        $reference = self::references();

        $this->assertNotSame([], $asset->variants);
        $this->assertNull($asset->note);
        foreach ($asset->variants as $variant) {
            $image = new \Imagick($this->assetRoot . '/media/' . $variant->path);
            $this->assertSame([], $image->getImageProfiles('icc'), "{$variant->path} carries no profile");
            [$w, $h] = [$image->getImageWidth(), $image->getImageHeight()];
            foreach ($reference['centres'] as $i => [$x, $y]) {
                $pixel = $image->getImagePixelColor((int) ($x * $w), (int) ($y * $h))->getColor();
                $expected = $reference['files'][$name]['srgb'][$i];
                $actual = [$pixel['r'], $pixel['g'], $pixel['b']];
                $this->assertLessThan(3.0, self::deltaE2000($expected, $actual), sprintf('%s patch %d: expected rgb(%s), got rgb(%s)', $variant->path, $i, implode(',', $expected), implode(',', $actual)));
            }
        }
    }

    public function test_srgb_variants_are_the_files_gd_wrote_before(): void
    {
        if ((gd_info()['GD Version'] ?? '') !== self::SRGB_HASHES['gd'] || !function_exists('imageavif')) {
            $this->markTestSkipped('The pinned hashes are from GD ' . self::SRGB_HASHES['gd'] . ' with AVIF.');
        }
        $asset = $this->library([], $this->generator())->upload($this->upload($this->fixture('srgb.jpg'), 'srgb.jpg'), null);

        $hashes = [];
        foreach ($asset->variants as $variant) {
            $hashes[$variant->width . '.' . $variant->format] = hash_file('sha256', $this->assetRoot . '/media/' . $variant->path);
        }
        ksort($hashes);
        $expected = self::SRGB_HASHES;
        unset($expected['gd']);
        ksort($expected);
        $this->assertSame($expected, $hashes);
    }

    public function test_srgb_and_untagged_sources_keep_the_plain_backend(): void
    {
        foreach (['srgb.jpg' => $this->fixture('srgb.jpg'), 'plain.png' => $this->png(300, 200)] as $name => $bytes) {
            mkdir($dir = $this->assetRoot . '/' . bin2hex(random_bytes(4)));
            file_put_contents($dir . '/a.src', $bytes);
            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($dir . '/a.src');
            $width = (int) getimagesize($dir . '/a.src')[0];
            $managed = $this->generator()->generate($dir, 'a.src', $mime, $width);
            $jobs = array_map(static fn (Variant $v) => ['dest' => "{$dir}/plain-{$v->width}.{$v->format}", 'width' => $v->width, 'format' => $v->format], $managed);
            (new GdBackend())->writeVariants($dir . '/a.src', $jobs, 75);

            $this->assertNotSame([], $managed, $name);
            $this->assertSame(
                array_map(static fn (array $j) => hash_file('sha256', $j['dest']), $jobs),
                array_map(static fn (Variant $v) => hash_file('sha256', "{$dir}/{$v->path}"), $managed),
                $name,
            );
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function sources(): iterable
    {
        yield 'CMYK with a profile' => ['cmyk-profile.jpg', true];
        yield 'CMYK without a profile' => ['cmyk-no-profile.jpg', true];
        yield 'Adobe RGB' => ['adobe-rgb.jpg', true];
        yield 'sRGB' => ['srgb.jpg', false];
    }

    #[DataProvider('sources')]
    public function test_needs_conversion(string $name, bool $needs): void
    {
        $this->assertSame($needs, (new ColorManagement())->needsConversion(self::FIXTURES . '/' . $name));
    }

    public function test_untagged_and_unreadable_files_need_nothing(): void
    {
        file_put_contents($png = $this->assetRoot . '/plain.png', $this->png(20, 20));
        file_put_contents($junk = $this->assetRoot . '/junk.jpg', "\xFF\xD8\xFF\xE2\x00");
        $color = new ColorManagement();

        $this->assertFalse($color->needsConversion($png));
        $this->assertFalse($color->needsConversion($junk));
        $this->assertFalse($color->needsConversion($this->assetRoot . '/missing.jpg'));
    }

    /** @return iterable<string, array{string}> */
    public static function profiledFormats(): iterable
    {
        yield 'PNG' => ['png'];
        yield 'WebP' => ['webp'];
    }

    #[DataProvider('profiledFormats')]
    #[RequiresPhpExtension('imagick')]
    public function test_a_wide_gamut_profile_in_png_and_webp_is_found(string $format): void
    {
        $adobe = new \Imagick(self::FIXTURES . '/adobe-rgb.jpg');
        $adobe->setImageFormat($format);
        $adobe->writeImage($wide = $this->assetRoot . '/wide.' . $format);
        $srgb = new \Imagick(self::FIXTURES . '/srgb.jpg');
        $srgb->setImageFormat($format);
        $srgb->writeImage($narrow = $this->assetRoot . '/narrow.' . $format);

        $this->assertTrue((new ColorManagement())->needsConversion($wide));
        $this->assertFalse((new ColorManagement())->needsConversion($narrow));
    }

    #[RequiresPhpExtension('imagick')]
    public function test_to_srgb_converts_and_strips(): void
    {
        $image = new \Imagick(self::FIXTURES . '/cmyk-no-profile.jpg');
        (new ColorManagement())->toSrgb($image);

        $this->assertSame(\Imagick::COLORSPACE_SRGB, $image->getImageColorspace());
        $this->assertSame([], $image->getImageProfiles('*'));
        $black = $image->getImagePixelColor(16, 48)->getColor(); // the rich black patch
        $this->assertLessThan(3.0, self::deltaE2000(self::references()['files']['cmyk-no-profile.jpg']['srgb'][4], [$black['r'], $black['g'], $black['b']]));
    }

    #[RequiresPhpExtension('imagick')]
    public function test_available_means_imagick_with_lcms(): void
    {
        $lcms = str_contains(implode(' ', \Imagick::getConfigureOptions('DELEGATES')), 'lcms');

        $this->assertSame($lcms, (new ColorManagement())->available());
        $this->assertFalse((new ColorManagement(available: false))->available());
    }

    public function test_the_default_cmyk_profile_ships_with_the_package(): void
    {
        $this->assertFileExists(ColorManagement::DEFAULT_CMYK_PROFILE);
        $this->assertFileExists(dirname(ColorManagement::DEFAULT_CMYK_PROFILE) . '/LICENSE');
    }

    public function test_without_imagick_a_cmyk_upload_keeps_only_the_original_with_a_note(): void
    {
        $library = $this->library(['versions_dir' => $this->assetRoot . '/versions'], $this->generator(new ColorManagement(available: false)));

        $asset = $library->upload($this->upload($this->fixture('cmyk-profile.jpg'), 'print.jpg'), null);

        $this->assertSame([], $asset->variants);
        $this->assertSame('needs Imagick for colour-accurate variants', $asset->note);
        $this->assertSame('needs Imagick for colour-accurate variants', $library->find($asset->id)?->note);
        $this->assertSame([], glob($this->assetRoot . '/media/' . dirname($asset->path) . '/*.{webp,avif}', GLOB_BRACE));
        $html = $this->tag($library)->render($asset->id);
        $this->assertStringContainsString('src="/media/' . $asset->path . '"', $html);
        $this->assertStringContainsString('width="128" height="64"', $html);
        $this->assertStringNotContainsString('<source', $html);

        // Nor on first request, and the note follows the file: an sRGB replace clears it.
        $this->assertNull($this->generator(new ColorManagement(available: false))->generateOne($this->assetRoot . '/media', $asset->path, 'image/jpeg', 64, 'webp'));
        $replaced = $library->replace($asset->id, $this->upload($this->fixture('srgb.jpg'), 'screen.jpg'), null);
        $this->assertNotSame([], $replaced->variants);
        $this->assertSame('', (string) $replaced->note);
        $again = $library->replace($asset->id, $this->upload($this->fixture('adobe-rgb.jpg'), 'wide.jpg'), null);
        $this->assertSame([], $again->variants);
        $this->assertSame('needs Imagick for colour-accurate variants', $again->note);
    }

    #[RequiresPhpExtension('imagick')]
    public function test_a_variant_on_first_request_is_converted_too(): void
    {
        $asset = $this->library([], new VariantGenerator(new GdBackend(), [], 75))->upload($this->upload($this->fixture('cmyk-profile.jpg'), 'print.jpg'), null);
        $rel = $this->generator()->generateOne($this->assetRoot . '/media', $asset->path, 'image/jpeg', 64, 'webp');

        $this->assertNotNull($rel);
        $image = new \Imagick($this->assetRoot . '/media/' . $rel);
        $reference = self::references();
        [$x, $y] = $reference['centres'][1];
        $cyan = $image->getImagePixelColor((int) ($x * 64), (int) ($y * 32))->getColor();
        $this->assertLessThan(3.0, self::deltaE2000($reference['files']['cmyk-profile.jpg']['srgb'][1], [$cyan['r'], $cyan['g'], $cyan['b']]));
    }

    private function tag(MediaLibrary $library): ImageTag
    {
        return new ImageTag($library, new FixedLocale('en'), [], new AssetCollector(new NonceGenerator()), '', ImageWidths::fromConfig([], [64, 256]));
    }

    /**
     * CIEDE2000 between two sRGB colours (0-255).
     *
     * @param array<int|float> $a
     * @param array<int|float> $b
     */
    private static function deltaE2000(array $a, array $b): float
    {
        [$l1, $a1, $b1] = self::lab($a);
        [$l2, $a2, $b2] = self::lab($b);
        $c1 = hypot($a1, $b1);
        $c2 = hypot($a2, $b2);
        $cBar = ($c1 + $c2) / 2;
        $g = 0.5 * (1 - sqrt($cBar ** 7 / ($cBar ** 7 + 25 ** 7)));
        $a1p = (1 + $g) * $a1;
        $a2p = (1 + $g) * $a2;
        $c1p = hypot($a1p, $b1);
        $c2p = hypot($a2p, $b2);
        $h1p = $c1p == 0 ? 0.0 : fmod(rad2deg(atan2($b1, $a1p)) + 360, 360);
        $h2p = $c2p == 0 ? 0.0 : fmod(rad2deg(atan2($b2, $a2p)) + 360, 360);
        $dLp = $l2 - $l1;
        $dCp = $c2p - $c1p;
        $dhp = 0.0;
        if ($c1p * $c2p != 0) {
            $dhp = $h2p - $h1p;
            $dhp += $dhp > 180 ? -360 : ($dhp < -180 ? 360 : 0);
        }
        $dHp = 2 * sqrt($c1p * $c2p) * sin(deg2rad($dhp / 2));
        $lBarP = ($l1 + $l2) / 2;
        $cBarP = ($c1p + $c2p) / 2;
        $hBarP = $h1p + $h2p;
        if ($c1p * $c2p != 0) {
            $hBarP = abs($h1p - $h2p) > 180 ? ($h1p + $h2p + ($h1p + $h2p < 360 ? 360 : -360)) / 2 : ($h1p + $h2p) / 2;
        }
        $t = 1 - 0.17 * cos(deg2rad($hBarP - 30)) + 0.24 * cos(deg2rad(2 * $hBarP)) + 0.32 * cos(deg2rad(3 * $hBarP + 6)) - 0.20 * cos(deg2rad(4 * $hBarP - 63));
        $dTheta = 30 * exp(-((($hBarP - 275) / 25) ** 2));
        $rc = 2 * sqrt($cBarP ** 7 / ($cBarP ** 7 + 25 ** 7));
        $sl = 1 + (0.015 * ($lBarP - 50) ** 2) / sqrt(20 + ($lBarP - 50) ** 2);
        $sc = 1 + 0.045 * $cBarP;
        $sh = 1 + 0.015 * $cBarP * $t;
        $rt = -sin(deg2rad(2 * $dTheta)) * $rc;

        return sqrt(($dLp / $sl) ** 2 + ($dCp / $sc) ** 2 + ($dHp / $sh) ** 2 + $rt * ($dCp / $sc) * ($dHp / $sh));
    }

    /**
     * @param array<int|float> $rgb sRGB 0-255
     * @return array{float, float, float} CIELAB (D65)
     */
    private static function lab(array $rgb): array
    {
        $lin = array_map(static function (int|float $v): float {
            $v /= 255;

            return $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, array_values($rgb));
        $x = (0.4124564 * $lin[0] + 0.3575761 * $lin[1] + 0.1804375 * $lin[2]) / 0.95047;
        $y = 0.2126729 * $lin[0] + 0.7151522 * $lin[1] + 0.0721750 * $lin[2];
        $z = (0.0193339 * $lin[0] + 0.1191920 * $lin[1] + 0.9503041 * $lin[2]) / 1.08883;
        $f = static fn (float $t): float => $t > 216 / 24389 ? $t ** (1 / 3) : (24389 / 27 * $t + 16) / 116;

        return [116 * $f($y) - 16, 500 * ($f($x) - $f($y)), 200 * ($f($y) - $f($z))];
    }
}
