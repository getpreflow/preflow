<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Color;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Locales;
use Preflow\Media\Color\ColorExtractor;
use Preflow\Media\Image\ColorManagement;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Preview\PdfPreviewRenderer;
use Preflow\Media\Preview\PreviewRenderers;
use Preflow\Media\Tests\Support\DeltaE;
use Preflow\Media\Tests\Support\MediaFixtures;

/**
 * The dominant colour, a palette of 5-8 colours (most significant first) and the text
 * colour with the higher WCAG contrast on the dominant one, from Imagick's quantisation
 * or GD's histogram; extracted on upload, replace and restore.
 */
#[RequiresPhpExtension('gd')]
final class ColorExtractorTest extends TestCase
{
    use MediaFixtures;
    use DeltaE;

    private const array BLUE = [0x19, 0x76, 0xd2];
    private const array AMBER = [0xff, 0xc1, 0x07];

    protected function setUp(): void
    {
        $this->makeAssetRoot();
    }

    protected function tearDown(): void
    {
        $this->removeAssetRoot();
    }

    /** Two colour blocks side by side: $share of the width in $first, the rest in $second. */
    private function blocks(array $first, array $second, float $share = 0.7, string $format = 'png'): string
    {
        $image = imagecreatetruecolor(1000, 600);
        imagefilledrectangle($image, 0, 0, (int) (1000 * $share) - 1, 599, (int) imagecolorallocate($image, ...$first));
        imagefilledrectangle($image, (int) (1000 * $share), 0, 999, 599, (int) imagecolorallocate($image, ...$second));
        ob_start();
        $format === 'jpeg' ? imagejpeg($image, null, 95) : imagepng($image);

        return (string) ob_get_clean();
    }

    private function file(string $bytes, string $ext = 'png'): string
    {
        $path = $this->assetRoot . '/' . bin2hex(random_bytes(4)) . '.' . $ext;
        file_put_contents($path, $bytes);

        return $path;
    }

    private static function rgb(string $hex): array
    {
        return array_map('hexdec', str_split(ltrim($hex, '#'), 2));
    }

    private function assertNear(array $expected, string $hex, float $limit): void
    {
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $hex);
        $this->assertLessThan($limit, self::deltaE2000($expected, self::rgb($hex)), "{$hex} is too far from rgb(" . implode(',', $expected) . ')');
    }

    /** 1-8 entries, each one of the two blocks' colours (nothing made up). */
    private function assertOnlyImageColours(array $palette, float $limit): void
    {
        $this->assertGreaterThanOrEqual(1, count($palette));
        $this->assertLessThanOrEqual(8, count($palette));
        foreach ($palette as $hex) {
            $nearest = min(self::deltaE2000(self::BLUE, self::rgb($hex)), self::deltaE2000(self::AMBER, self::rgb($hex)));
            $this->assertLessThan($limit, $nearest, "{$hex} is not in the image");
        }
    }

    /** Photo-like: hues across, light to dark down. */
    private function gradient(): string
    {
        $image = imagecreatetruecolor(600, 400);
        for ($x = 0; $x < 600; $x++) {
            $h = $x / 600 * 6;
            $f = $h - floor($h);
            [$r, $g, $b] = [[1, $f, 0], [1 - $f, 1, 0], [0, 1, $f], [0, 1 - $f, 1], [$f, 0, 1], [1, 0, 1 - $f]][(int) $h];
            for ($y = 0; $y < 400; $y += 4) {
                $l = 1 - $y / 400 * 0.8;
                imagefilledrectangle($image, $x, $y, $x, $y + 3, (int) imagecolorallocate($image, (int) ($r * 255 * $l), (int) ($g * 255 * $l), (int) ($b * 255 * $l)));
            }
        }
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function library(array $config = [], ?ColorExtractor $colors = null, ?PreviewRenderers $previews = null): MediaLibrary
    {
        $dm = new DataManager(drivers: ['json' => new JsonFileDriver($this->assetRoot . '/store')], defaultDriver: 'json');

        return new MediaLibrary($dm, new Locales(['en', 'de'], 'en'), $this->assetRoot . '/media', '/media', $config, null, previews: $previews, colors: $colors ?? new ColorExtractor(new ColorManagement()));
    }

    #[RequiresPhpExtension('imagick')]
    public function test_imagick_finds_the_dominant_colour_and_a_palette(): void
    {
        $colors = (new ColorExtractor(new ColorManagement(), imagick: true))->extract($this->file($this->blocks(self::BLUE, self::AMBER)));

        $this->assertNear(self::BLUE, $colors['color'], 5);
        $this->assertNear(self::BLUE, $colors['palette'][0], 5);
        $this->assertNear(self::AMBER, $colors['palette'][1], 5);
        $this->assertOnlyImageColours($colors['palette'], 5);
        $this->assertSame(count($colors['palette']), count(array_unique($colors['palette'])));
        $this->assertSame('#ffffff', $colors['text']);
    }

    public function test_gd_finds_the_same_colours(): void
    {
        $colors = (new ColorExtractor(new ColorManagement(available: false), imagick: false))->extract($this->file($this->blocks(self::BLUE, self::AMBER)));

        $this->assertNear(self::BLUE, $colors['color'], 8);
        $this->assertNear(self::BLUE, $colors['palette'][0], 8);
        $this->assertNear(self::AMBER, $colors['palette'][1], 8);
        $this->assertOnlyImageColours($colors['palette'], 8);
        $this->assertSame('#ffffff', $colors['text']);
    }

    public function test_the_text_colour_has_the_higher_contrast(): void
    {
        foreach ([true, false] as $imagick) {
            if ($imagick && !extension_loaded('imagick')) {
                continue;
            }
            $extractor = new ColorExtractor(new ColorManagement(), imagick: $imagick);

            $this->assertSame('#ffffff', $extractor->extract($this->file($this->blocks(self::BLUE, self::AMBER)))['text']);
            $amber = $extractor->extract($this->file($this->blocks(self::AMBER, self::BLUE)));
            $this->assertNear(self::AMBER, $amber['color'], 8);
            $this->assertSame('#000000', $amber['text']);
        }
        $this->assertSame('#ffffff', ColorExtractor::textColor('#1976d2'));
        $this->assertSame('#000000', ColorExtractor::textColor('#ffc107'));
        $this->assertSame('#000000', ColorExtractor::textColor('#ffffff'));
        $this->assertSame('#ffffff', ColorExtractor::textColor('#000000'));
    }

    public function test_a_flat_image_has_a_palette_of_its_one_colour(): void
    {
        foreach (extension_loaded('imagick') ? [true, false] : [false] as $imagick) {
            foreach (['#ffffff' => [255, 255, 255], '#000000' => [0, 0, 0], '#1e78c8' => [30, 120, 200]] as $hex => $rgb) {
                $colors = (new ColorExtractor(new ColorManagement(), imagick: $imagick))->extract($this->file($this->blocks($rgb, $rgb)));

                $this->assertSame($hex, $colors['color']);
                $this->assertSame([$hex], $colors['palette']);
            }
        }
    }

    public function test_a_photo_like_image_gets_five_to_eight_colours(): void
    {
        $file = $this->file($this->gradient());

        foreach (extension_loaded('imagick') ? [true, false] : [false] as $imagick) {
            $palette = (new ColorExtractor(new ColorManagement(), imagick: $imagick))->extract($file)['palette'];

            $this->assertGreaterThanOrEqual(5, count($palette), implode(' ', $palette));
            $this->assertLessThanOrEqual(8, count($palette));
            $this->assertSame(count($palette), count(array_unique($palette)));
        }
    }

    public function test_transparent_pixels_do_not_count(): void
    {
        $image = imagecreatetruecolor(400, 400);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 255, 255, 255, 127));
        imagefilledrectangle($image, 150, 150, 249, 249, (int) imagecolorallocate($image, ...self::AMBER));
        ob_start();
        imagepng($image);
        $file = $this->file((string) ob_get_clean());

        foreach (extension_loaded('imagick') ? [true, false] : [false] as $imagick) {
            $this->assertNear(self::AMBER, (new ColorExtractor(new ColorManagement(), imagick: $imagick))->extract($file)['color'], 8);
        }
    }

    public function test_a_fully_transparent_image_has_no_colours(): void
    {
        $image = imagecreatetruecolor(50, 50);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();

        foreach (extension_loaded('imagick') ? [true, false] : [false] as $imagick) {
            $this->assertNull((new ColorExtractor(new ColorManagement(), imagick: $imagick))->fromFile($this->file($bytes)));
        }
        $this->assertNull($this->library()->upload($this->upload($bytes, 'clear.png'), null)->color);
    }

    #[RequiresPhpExtension('imagick')]
    public function test_a_cmyk_source_is_read_in_srgb(): void
    {
        $managed = new ColorManagement();
        if (!$managed->available()) {
            $this->markTestSkipped('Imagick without lcms.');
        }
        $references = json_decode((string) file_get_contents(__DIR__ . '/../Fixtures/color/references.json'), true);
        $expected = $references['files']['cmyk-profile.jpg']['srgb'];

        $colors = (new ColorExtractor($managed))->fromFile(__DIR__ . '/../Fixtures/color/cmyk-profile.jpg');

        $this->assertNotNull($colors);
        // Eight equal patches: every palette colour is one of the converted patch colours.
        foreach (array_slice($colors['palette'], 0, 3) as $hex) {
            $nearest = min(array_map(fn (array $patch) => self::deltaE2000($patch, self::rgb($hex)), $expected));
            $this->assertLessThan(5, $nearest, "{$hex} is not a converted patch colour");
        }
    }

    public function test_without_imagick_a_cmyk_source_gets_no_colours(): void
    {
        $colors = new ColorExtractor(new ColorManagement(available: false), imagick: false);

        $this->assertNull($colors->fromFile(__DIR__ . '/../Fixtures/color/cmyk-profile.jpg'));
        $this->assertNull($colors->fromFile(__DIR__ . '/../Fixtures/color/adobe-rgb.jpg'));
        $this->assertNotNull($colors->fromFile(__DIR__ . '/../Fixtures/color/srgb.jpg'));
    }

    public function test_upload_replace_and_restore_store_the_colours(): void
    {
        $media = $this->library(['versions_dir' => $this->assetRoot . '/versions']);

        $asset = $media->upload($this->upload($this->blocks(self::BLUE, self::AMBER), 'a.png'), null);

        $this->assertNear(self::BLUE, (string) $asset->color, 8);
        $this->assertSame('#ffffff', $asset->textColor);
        $this->assertSame($asset->color, $asset->palette[0]);
        $this->assertEquals($asset, $media->find($asset->id));

        $replaced = $media->replace($asset->id, $this->upload($this->blocks(self::AMBER, self::BLUE, 0.8, 'jpeg'), 'b.jpg'), null);
        $this->assertNear(self::AMBER, (string) $replaced->color, 8);
        $this->assertSame('#000000', $replaced->textColor);

        $restored = $media->restore($asset->id, 1, null);
        $this->assertNear(self::BLUE, (string) $restored->color, 8);
        $this->assertSame('#ffffff', $restored->textColor);
    }

    public function test_private_images_get_colours_too(): void
    {
        $media = $this->library(['private_dir' => $this->assetRoot . '/private']);

        $asset = $media->upload($this->upload($this->blocks(self::BLUE, self::AMBER), 'a.png'), null, true);

        $this->assertNear(self::BLUE, (string) $asset->color, 8);
    }

    public function test_an_undecodable_image_uploads_without_colours(): void
    {
        $bytes = substr($this->blocks(self::BLUE, self::AMBER), 0, 200); // a PNG header, then nothing
        $log = $this->assetRoot . '/error.log';
        $previous = ini_set('error_log', $log);
        try {
            $asset = $this->library()->upload($this->upload($bytes, 'broken.png'), null);
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $this->assertStringContainsString("preflow/media: no colours of asset {$asset->id}: RuntimeException", (string) @file_get_contents($log));

        $this->assertSame('image/png', $asset->mime);
        $this->assertNull($asset->color);
        $this->assertSame([], $asset->palette);
        $this->assertNull($asset->textColor);
    }

    public function test_without_an_extractor_nothing_is_extracted(): void
    {
        $dm = new DataManager(drivers: ['json' => new JsonFileDriver($this->assetRoot . '/store')], defaultDriver: 'json');
        $media = new MediaLibrary($dm, new Locales(['en'], 'en'), $this->assetRoot . '/media', '/media');

        $this->assertNull($media->upload($this->upload($this->blocks(self::BLUE, self::AMBER), 'a.png'), null)->color);
    }

    public function test_a_pdf_gets_the_colours_of_its_preview(): void
    {
        $renderer = new PdfPreviewRenderer();
        if ($renderer->engine() === null) {
            $this->markTestSkipped('No PDF renderer (Ghostscript or pdftocairo) on this machine.');
        }
        $previews = new PreviewRenderers();
        $previews->register($renderer);
        $media = $this->library([], null, $previews);
        $bytes = (string) file_get_contents(__DIR__ . '/../Fixtures/pdf/rgb.pdf');

        $asset = $media->upload($this->upload($bytes, 'a.pdf'), null);

        $this->assertNotNull($asset->preview);
        $this->assertNotNull($asset->color);
        $this->assertEquals((new ColorExtractor(new ColorManagement()))->extract($this->assetRoot . '/media/' . $asset->preview->path)['color'], $asset->color);

        // Made again with the preview.
        (new \ReflectionMethod(MediaLibrary::class, 'save'))->invoke($media, [...$asset->toData(), 'color' => '#123456']);
        $this->assertSame($asset->color, $media->remakePreview($media->find($asset->id))->color);
    }
}
