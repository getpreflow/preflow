<?php

declare(strict_types=1);

namespace Preflow\Media\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Core\Http\InternalRender;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Locales;
use Preflow\Media\Asset;
use Preflow\Media\CropRect;
use Preflow\Media\Http\VariantController;
use Preflow\Media\Image\Formats;
use Preflow\Media\Image\GdBackend;
use Preflow\Media\Image\ImageWidths;
use Preflow\Media\Image\VariantGenerator;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Tests\Support\MediaFixtures;
use Preflow\View\VariantPending;
use Psr\Http\Message\ResponseInterface;

/** The built-in "share" box as a JPEG for og:image cards. */
#[RequiresPhpExtension('gd')]
#[RequiresPhpExtension('fileinfo')]
#[RequiresFunction('imagewebp')]
final class ShareImageTest extends TestCase
{
    use MediaFixtures;

    protected function setUp(): void
    {
        $this->makeAssetRoot();
    }

    protected function tearDown(): void
    {
        $this->removeAssetRoot();
    }

    private function lib(): MediaLibrary
    {
        $dm = new DataManager(drivers: ['json' => new JsonFileDriver($this->assetRoot . '/store')], defaultDriver: 'json');

        return new MediaLibrary(
            $dm,
            new Locales(['en', 'de'], 'en'),
            $this->assetRoot . '/media',
            '/media',
            ['versions_dir' => $this->assetRoot . '/versions', 'private_dir' => $this->assetRoot . '/private'],
            new VariantGenerator(new GdBackend(), [480], 75),
            formats: Formats::fromConfig([]),
        );
    }

    private function serve(MediaLibrary $lib, string $url, bool $internal = false): ResponseInterface
    {
        $path = substr($url, strlen('/media/'));
        $request = (new Psr17Factory())->createServerRequest('GET', $url)->withAttribute('path', $path);
        if ($internal) {
            $request = InternalRender::mark($request);
        }

        return (new VariantController($lib, ImageWidths::fromConfig([], [480]), Formats::fromConfig([])))->serve($request);
    }

    /** 2000x1500 JPEG: red down to y=1000, blue below. */
    private function jpeg(): string
    {
        $im = imagecreatetruecolor(2000, 1500);
        imagefilledrectangle($im, 0, 0, 1999, 999, (int) imagecolorallocate($im, 255, 0, 0));
        imagefilledrectangle($im, 0, 1000, 1999, 1499, (int) imagecolorallocate($im, 0, 0, 255));
        ob_start();
        imagejpeg($im, null, 95);

        return (string) ob_get_clean();
    }

    private function red(string $file, int $x, int $y): bool
    {
        $rgb = imagecolorat(imagecreatefromjpeg($file), $x, $y);

        return (($rgb >> 16) & 255) > 150 && ($rgb & 255) < 100;
    }

    private function stored(MediaLibrary $lib): Asset
    {
        return $lib->upload($this->upload($this->jpeg(), 'photo.jpg'), null);
    }

    public function test_share_image_of_an_image_asset(): void
    {
        $lib = $this->lib();
        $asset = $this->stored($lib);
        $lib->update($asset->id, ['alt' => ['en' => 'A red field']]);

        $share = $lib->shareImage($asset->id);

        $this->assertMatchesRegularExpression('#^/media/.+-share-[0-9a-f]{8}-1200\.jpg$#', $share['url']);
        $this->assertSame([1200, 630, 'A red field'], [$share['width'], $share['height'], $share['alt']]);
        $this->assertSame('', $lib->shareImage($this->stored($lib))['alt']);
    }

    public function test_variant_controller_serves_the_jpeg_box(): void
    {
        $lib = $this->lib();
        $asset = $this->stored($lib);

        $res = $this->serve($lib, $lib->shareImage($asset)['url']);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('image/jpeg', $res->getHeaderLine('Content-Type'));
        $file = $lib->absolute(substr($lib->shareImage($asset)['url'], strlen('/media/')));
        $this->assertSame([1200, 630], array_slice(getimagesize($file), 0, 2));
        $this->assertSame(IMAGETYPE_JPEG, getimagesize($file)[2]);
        $this->assertFalse($this->red($file, 600, 500)); // uncropped: the centre band, blue at the bottom

        $lib->setFormatCrop($asset->id, 'share', CropRect::of(0, 0, 100, 70));
        $cropped = $lib->shareImage($asset->id)['url'];
        $this->assertSame(200, $this->serve($lib, $cropped)->getStatusCode());
        $this->assertTrue($this->red($lib->absolute(substr($cropped, strlen('/media/'))), 600, 500)); // the top of the picture
    }

    public function test_an_internal_render_gets_a_pending_404_for_an_unmade_share_file(): void
    {
        $lib = $this->lib();
        $url = $lib->shareImage($this->stored($lib))['url'];
        $file = $lib->absolute(substr($url, strlen('/media/')));

        $pending = $this->serve($lib, $url, internal: true);

        $this->assertSame(404, $pending->getStatusCode());
        $this->assertSame('pending', $pending->getHeaderLine(VariantPending::HEADER));
        $this->assertFileDoesNotExist($file);
        $this->assertSame([], glob($file . '.*') ?: []); // no lock file either
    }

    public function test_a_plain_width_jpg_is_not_found(): void
    {
        $lib = $this->lib();
        $asset = $this->stored($lib);
        $stem = substr($asset->path, 0, (int) strrpos($asset->path, '.'));

        $this->expectException(NotFoundHttpException::class);
        $this->serve($lib, '/media/' . $stem . '-480.jpg');
    }

    public function test_no_share_image_for_unusable_assets(): void
    {
        $lib = $this->lib();
        $pdf = $lib->upload($this->upload("%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF", 'doc.pdf', 'application/pdf'), null);

        $this->assertNull($lib->shareImage('0123456789abcdef0123456789abcdef'));
        $this->assertNull($lib->shareImage($pdf));
        $private = $lib->upload($this->upload($this->jpeg(), 'secret.jpg'), null, private: true);
        $this->assertNull($lib->shareImage($private));
    }

    public function test_alt_text_in_the_asked_locale_else_the_default(): void
    {
        $lib = $this->lib();
        $asset = $this->stored($lib);
        $lib->update($asset->id, ['alt' => ['en' => 'A red field', 'de' => 'Ein rotes Feld']]);
        $only = $this->stored($lib);
        $lib->update($only->id, ['alt' => ['en' => 'Only English']]);

        $this->assertSame('Ein rotes Feld', $lib->shareImage($asset->id, 'de')['alt']);
        $this->assertSame('A red field', $lib->shareImage($asset->id)['alt']);
        $this->assertSame('Only English', $lib->shareImage($only->id, 'de')['alt']);
    }

    public function test_warm_makes_no_share_files(): void
    {
        $lib = $this->lib();
        $asset = $this->stored($lib);

        $lib->warm($asset, [480]);

        $shares = array_filter(glob($this->assetRoot . '/media/*/*/*') ?: [], static fn (string $f): bool => str_contains($f, '-share-'));
        $this->assertSame([], array_values($shares));
    }

    public function test_a_transparent_png_becomes_a_jpeg_on_white(): void
    {
        $im = imagecreatetruecolor(300, 200);
        imagesavealpha($im, true);
        imagealphablending($im, false);
        imagefill($im, 0, 0, (int) imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagepng($im, $this->assetRoot . '/t.png');
        $dest = $this->assetRoot . '/t.jpg';

        $ok = (new GdBackend())->writeVariants($this->assetRoot . '/t.png', [['dest' => $dest, 'width' => 120, 'format' => 'jpeg']], 80);

        $this->assertSame([true], $ok);
        $this->assertSame(IMAGETYPE_JPEG, getimagesize($dest)[2]);
        $this->assertSame(0xFFFFFF, imagecolorat(imagecreatefromjpeg($dest), 2, 2) & 0xFFFFFF);
    }
}
