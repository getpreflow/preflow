<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Pdf;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Media\CropRect;
use Preflow\Media\Image\GdBackend;
use Preflow\Media\Image\VariantGenerator;
use Preflow\Media\Pdf\MediaPdfImages;
use Preflow\Media\Tests\Support\MediaFixtures;

/** pdf_image() by asset id: what the media library tells view's PdfImageSource. */
#[RequiresPhpExtension('gd')]
#[RequiresPhpExtension('fileinfo')]
final class MediaPdfImagesTest extends TestCase
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

    public function test_an_image_asset_gives_its_delivery_file_and_url(): void
    {
        $library = $this->library(['private_dir' => $this->assetRoot . '/private']);
        $public = $library->upload($this->upload($this->png(), 'a.png'), null);
        $private = $library->upload($this->upload($this->png(300, 200), 'b.png'), null, private: true);
        $images = new MediaPdfImages($library);

        $found = $images->find($public->id);
        $this->assertNotNull($found);
        $this->assertSame($library->file($public), $found->file);
        $this->assertFileExists($found->file);
        $this->assertSame($library->url($public), $found->url);
        $this->assertFalse($found->private);
        $this->assertTrue($found->isImage);

        $this->assertTrue($images->find($private->id)?->private);
    }

    public function test_an_edited_image_gives_its_rendered_master(): void
    {
        $library = $this->library([], new VariantGenerator(new GdBackend(), [480], 75));
        $asset = $library->upload($this->upload($this->png(), 'a.png'), null);
        $edited = $library->setCrop($asset->id, CropRect::of(50, 0, 50, 100));
        $this->assertNotNull($edited->master);

        $found = (new MediaPdfImages($library))->find($asset->id);

        $this->assertNotNull($found);
        $this->assertSame($library->file($library->deliveryAsset($edited)), $found->file);
        $this->assertStringContainsString('-edit-', $found->file);
        $this->assertNotSame($library->file($asset), $found->file);
        $this->assertFileExists($found->file);
    }

    public function test_an_unknown_id_is_null(): void
    {
        $this->assertNull((new MediaPdfImages($this->library()))->find('no-such-asset'));
    }

    public function test_a_pdf_asset_is_not_an_image(): void
    {
        $library = $this->library();
        $pdf = $library->upload($this->upload((string) file_get_contents(__DIR__ . '/../Fixtures/pdf/rgb.pdf'), 'a.pdf'), null);

        $found = (new MediaPdfImages($library))->find($pdf->id);

        $this->assertNotNull($found);
        $this->assertFalse($found->isImage);
    }
}
