<?php

declare(strict_types=1);

namespace Preflow\Media\Tests;

use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Locales;
use Preflow\Media\AssetUploadException;
use Preflow\Media\Image\GdBackend;
use Preflow\Media\Image\HeicConverter;
use Preflow\Media\Image\HeicTooLarge;
use Preflow\Media\Image\ImagickHeicConverter;
use Preflow\Media\Image\Orientation;
use Preflow\Media\Image\VariantGenerator;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Tests\Support\MediaFixtures;

/** HEIC and HEIF photos become JPEG assets; without a converter they are refused and nothing is written. */
#[RequiresPhpExtension('fileinfo')]
final class HeicTest extends TestCase
{
    use MediaFixtures;

    private const string FIXTURE = __DIR__ . '/Fixtures/heic/portrait.heic';

    private const string UNSUPPORTED = 'IMG_0001.HEIC: HEIC/HEIF images need Imagick with HEIC support on the server, which is missing.';

    protected function setUp(): void
    {
        $this->makeAssetRoot();
    }

    protected function tearDown(): void
    {
        $this->removeAssetRoot();
    }

    /** @param array<string, mixed> $config */
    private function lib(?HeicConverter $heic = null, array $config = []): MediaLibrary
    {
        $dm = new DataManager(drivers: ['json' => new JsonFileDriver($this->assetRoot . '/store')], defaultDriver: 'json');

        return new MediaLibrary(
            $dm,
            new Locales(['en', 'de'], 'en'),
            $this->assetRoot . '/media',
            '/media',
            $config + ['versions_dir' => $this->assetRoot . '/versions'],
            new VariantGenerator(new GdBackend(), [480], 75),
            heic: $heic,
        );
    }

    /** A converter that is missing (available() false) or fails to decode (toJpeg() throws). */
    private static function fake(bool $available, bool $decodes = true): HeicConverter
    {
        return new class ($available, $decodes) implements HeicConverter {
            public function __construct(private readonly bool $available, private readonly bool $decodes) {}

            public function available(): bool
            {
                return $this->available;
            }

            public function toJpeg(string $bytes): string
            {
                if (!$this->decodes) {
                    throw new \RuntimeException('no decoder');
                }
                throw new \LogicException('a test fake converts nothing');
            }
        };
    }

    private function requireHeic(): void
    {
        if (!is_file(self::FIXTURE)) {
            $this->markTestSkipped('The HEIC fixture is missing.');
        }
        if (!(new ImagickHeicConverter())->available()) {
            $this->markTestSkipped('Imagick without HEIC');
        }
    }

    /** @return list<string> */
    private function filesUnder(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            $out[] = substr($f->getPathname(), strlen($dir) + 1);
        }
        sort($out);

        return $out;
    }

    /** A small HEIC made at test time: fixtures can be missing when only the fakes are tested. */
    private function heicBytes(): string
    {
        return is_file(self::FIXTURE)
            ? (string) file_get_contents(self::FIXTURE)
            // ftyp box with brand "heic": what finfo sniffs as image/heic.
            : "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic" . str_repeat("\x00", 64);
    }

    private function heicFile(): string
    {
        $file = $this->assetRoot . '/in.heic';
        file_put_contents($file, $this->heicBytes());

        return $file;
    }

    private function assertJpegAsset(MediaLibrary $lib, \Preflow\Media\Asset $asset): void
    {
        $this->assertSame('image/jpeg', $asset->mime);
        $this->assertStringEndsWith('.jpg', $asset->path);
        $this->assertSame('IMG_0001.jpg', $asset->originalName);
        $this->assertSame([20, 40], [$asset->width, $asset->height]);
        $file = $lib->absolute($asset->path);
        $this->assertSame('image/jpeg', (new \finfo(FILEINFO_MIME_TYPE))->file($file));
        $this->assertSame(filesize($file), $asset->size);
        $this->assertSame(1, Orientation::of($file));
        $this->assertNotEmpty($asset->variants);
    }

    #[RequiresPhpExtension('gd')]
    #[RequiresFunction('imagewebp')]
    public function test_heic_import_becomes_jpeg(): void
    {
        $this->requireHeic();
        $lib = $this->lib();

        $this->assertJpegAsset($lib, $lib->importFile(self::FIXTURE, 'IMG_0001.HEIC', null));
    }

    #[RequiresPhpExtension('gd')]
    #[RequiresFunction('imagewebp')]
    public function test_heic_upload_becomes_jpeg(): void
    {
        $this->requireHeic();
        $lib = $this->lib();

        $this->assertJpegAsset($lib, $lib->upload($this->upload((string) file_get_contents(self::FIXTURE), 'IMG_0001.HEIC', 'image/heic'), null));
    }

    #[RequiresPhpExtension('gd')]
    #[RequiresFunction('imagewebp')]
    public function test_heic_asset_is_editable(): void
    {
        $this->requireHeic();
        $lib = $this->lib();
        $asset = $lib->importFile(self::FIXTURE, 'IMG_0001.HEIC', null);

        $this->assertTrue($lib->isEditable($asset));
        $edited = $lib->rotate($asset->id, 1);

        $this->assertMatchesRegularExpression('#-edit-[^/]+\.jpg$#', (string) $edited->master?->path);
        $this->assertSame([40, 20], [$edited->master?->width, $edited->master?->height]);
        $this->assertSame([40, 20], array_slice((array) getimagesize($lib->absolute((string) $edited->master?->path)), 0, 2));
    }

    #[RequiresPhpExtension('gd')]
    #[RequiresFunction('imagewebp')]
    public function test_heic_replace_converts(): void
    {
        $this->requireHeic();
        $lib = $this->lib();
        $asset = $lib->upload($this->upload($this->png(10, 10), 'a.png', 'image/png'), null);

        $replaced = $lib->replace($asset->id, $this->upload((string) file_get_contents(self::FIXTURE), 'IMG_0001.HEIC', 'image/heic'), null);

        $this->assertSame(2, $replaced->version);
        $this->assertSame('image/jpeg', $replaced->mime);
        $this->assertStringEndsWith('-v2.jpg', $replaced->path);
        $this->assertSame('IMG_0001.jpg', $replaced->originalName);
        $this->assertSame('image/jpeg', (new \finfo(FILEINFO_MIME_TYPE))->file($lib->absolute($replaced->path)));
        $this->assertSame([20, 40], [$replaced->width, $replaced->height]);
        $this->assertSame('a.png', $replaced->versions[0]->meta['original_name']);
    }

    #[RequiresPhpExtension('gd')]
    #[RequiresFunction('imagewebp')]
    public function test_portrait_heic_is_upright_once(): void
    {
        if (!(new ImagickHeicConverter())->available()) {
            $this->markTestSkipped('Imagick without HEIC');
        }
        // A phone photo: a landscape 40×20 frame with a blue band on its left, stored with "turn 90° clockwise"
        // twice over, as phones do: in the HEIC container (irot) and in its EXIF (orientation 6).
        $gd = imagecreatetruecolor(40, 20);
        imagefill($gd, 0, 0, (int) imagecolorallocate($gd, 255, 0, 0));
        imagefilledrectangle($gd, 0, 0, 9, 19, (int) imagecolorallocate($gd, 0, 0, 255));
        ob_start();
        imagejpeg($gd, null, 95);
        $jpeg = (string) ob_get_clean();
        $tiff = "MM\x00\x2A\x00\x00\x00\x08" . "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01" . pack('n', 6) . "\x00\x00" . "\x00\x00\x00\x00";
        $exif = "Exif\x00\x00" . $tiff;
        $frame = new \Imagick();
        $frame->readImageBlob(substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2));
        $frame->setImageOrientation(\Imagick::ORIENTATION_RIGHTTOP);
        $frame->setImageFormat('heic');
        try {
            $bytes = $frame->getImageBlob();
        } catch (\ImagickException) {
            $this->markTestSkipped('Imagick cannot write HEIC');
        }
        $file = $this->assetRoot . '/rotated.heic';
        file_put_contents($file, $bytes);
        $decoded = new \Imagick($file);
        if ([$decoded->getImageWidth(), $decoded->getImageHeight()] !== [20, 40]) {
            $this->markTestSkipped('Imagick wrote no rotation into the HEIC');
        }
        $this->assertSame('6', $decoded->getImageProperty('exif:Orientation')); // upright pixels, but the EXIF still says "turn"

        $lib = $this->lib();
        $asset = $lib->importFile($file, 'IMG_0002.HEIC', null);

        // Upright once: portrait, orientation 1, the band at the top — not turned a second time.
        $stored = $lib->absolute($asset->path);
        $this->assertSame([$decoded->getImageWidth(), $decoded->getImageHeight()], [$asset->width, $asset->height]);
        $this->assertSame([20, 40], array_slice((array) getimagesize($stored), 0, 2));
        $this->assertSame(1, Orientation::of($stored));
        $image = imagecreatefromjpeg($stored);
        $top = imagecolorsforindex($image, (int) imagecolorat($image, 10, 2));
        $bottom = imagecolorsforindex($image, (int) imagecolorat($image, 10, 37));
        $this->assertGreaterThan(200, $top['blue']);
        $this->assertLessThan(60, $top['red']);
        $this->assertGreaterThan(200, $bottom['red']);
        $this->assertLessThan(60, $bottom['blue']);
    }

    public function test_heic_refused_without_support(): void
    {
        $lib = $this->lib(self::fake(false));

        try {
            $lib->importFile($this->heicFile(), 'IMG_0001.HEIC', null);
            $this->fail('expected AssetUploadException');
        } catch (AssetUploadException $e) {
            $this->assertSame(self::UNSUPPORTED, $e->getMessage());
        }
        try {
            $lib->upload($this->upload($this->heicBytes(), 'IMG_0001.HEIC', 'image/heic'), null);
            $this->fail('expected AssetUploadException');
        } catch (AssetUploadException $e) {
            $this->assertSame(self::UNSUPPORTED, $e->getMessage());
        }

        $this->assertSame([], $this->filesUnder($this->assetRoot . '/media'));
        $this->assertSame([], $lib->all());
    }

    public function test_heic_replace_refused_without_support_changes_nothing(): void
    {
        $lib = $this->lib(self::fake(false));
        $asset = $lib->upload($this->upload("notes\n", 'notes.txt', 'text/plain'), null);
        $files = $this->filesUnder($this->assetRoot . '/media');

        try {
            $lib->replace($asset->id, $this->upload($this->heicBytes(), 'IMG_0001.HEIC', 'image/heic'), null);
            $this->fail('expected AssetUploadException');
        } catch (AssetUploadException $e) {
            $this->assertSame(self::UNSUPPORTED, $e->getMessage());
        }

        $after = $lib->find($asset->id);
        $this->assertSame(1, $after?->version);
        $this->assertSame($asset->path, $after->path);
        $this->assertSame([], $after->versions);
        $this->assertSame($files, $this->filesUnder($this->assetRoot . '/media'));
        $this->assertSame([], $this->filesUnder($this->assetRoot . '/versions/' . $asset->id));
    }

    public function test_undecodable_heic_refused(): void
    {
        $lib = $this->lib(self::fake(true, decodes: false));

        try {
            $lib->importFile($this->heicFile(), 'IMG_0001.HEIC', null);
            $this->fail('expected AssetUploadException');
        } catch (AssetUploadException $e) {
            $this->assertSame('IMG_0001.HEIC: the HEIC/HEIF image could not be converted.', $e->getMessage());
        }

        $this->assertSame([], $this->filesUnder($this->assetRoot . '/media'));
        $this->assertSame([], $lib->all());
    }

    public function test_a_broken_heic_is_refused_by_imagick(): void
    {
        if (!(new ImagickHeicConverter())->available()) {
            $this->markTestSkipped('Imagick without HEIC');
        }
        $lib = $this->lib();
        $file = $this->assetRoot . '/broken.heic';
        file_put_contents($file, "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic" . str_repeat("\x00", 64));

        try {
            $lib->importFile($file, 'IMG_0003.HEIC', null);
            $this->fail('expected AssetUploadException');
        } catch (AssetUploadException $e) {
            $this->assertSame('IMG_0003.HEIC: the HEIC/HEIF image could not be converted.', $e->getMessage());
        }
        $this->assertSame([], $this->filesUnder($this->assetRoot . '/media'));
    }

    /** The fixture with its image data zeroed: the header still pings as 20×40, but decoding it fails. */
    private function undecodableFixture(): string
    {
        $bytes = (string) file_get_contents(self::FIXTURE);
        $box = strpos($bytes, 'mdat') - 4;
        $header = unpack('N', substr($bytes, $box, 4))[1] === 1 ? 16 : 8; // size 1: a 64-bit size follows

        return substr($bytes, 0, $box + $header) . str_repeat("\x00", strlen($bytes) - $box - $header);
    }

    public function test_converter_refuses_too_large_before_decoding(): void
    {
        $this->requireHeic();
        $broken = $this->undecodableFixture();
        try {
            (new ImagickHeicConverter())->toJpeg($broken);
            $this->fail('expected the zeroed image data not to decode');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(HeicTooLarge::class, $e);
        }

        // 800 pixels over a limit of 100: refused from the header, so the broken data is never decoded.
        $this->expectException(HeicTooLarge::class);
        (new ImagickHeicConverter(maxPixels: 100))->toJpeg($broken);
    }

    public function test_too_large_heic_refused_with_nothing_stored(): void
    {
        $this->requireHeic();
        $lib = $this->lib(config: ['heic_max_pixels' => 100]);

        try {
            $lib->importFile(self::FIXTURE, 'IMG_0001.HEIC', null);
            $this->fail('expected AssetUploadException');
        } catch (AssetUploadException $e) {
            $this->assertSame('IMG_0001.HEIC: the HEIC/HEIF image is too large to convert.', $e->getMessage());
        }

        $this->assertSame([], $this->filesUnder($this->assetRoot . '/media'));
        $this->assertSame([], $lib->all());
        $this->assertSame(800, (new \Imagick(self::FIXTURE))->getImageWidth() * (new \Imagick(self::FIXTURE))->getImageHeight());
    }

    public function test_replace_by_another_kind_names_the_uploaded_file(): void
    {
        $this->requireHeic();
        $lib = $this->lib();
        $asset = $lib->upload($this->upload("notes\n", 'notes.txt', 'text/plain'), null);

        try {
            $lib->replace($asset->id, $this->upload((string) file_get_contents(self::FIXTURE), 'IMG_0001.HEIC', 'image/heic'), null);
            $this->fail('expected AssetUploadException');
        } catch (AssetUploadException $e) {
            $this->assertSame('IMG_0001.HEIC: a document can only be replaced by another document.', $e->getMessage());
        }
        $this->assertSame(1, $lib->find($asset->id)?->version);
    }
}
