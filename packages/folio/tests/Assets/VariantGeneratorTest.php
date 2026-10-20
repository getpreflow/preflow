<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Assets;

use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Assets\Image\GdBackend;
use Preflow\Folio\Assets\Image\ImageBackends;
use Preflow\Folio\Assets\Variant;
use Preflow\Folio\Assets\VariantGenerator;
use Preflow\Folio\Tests\Support\AssetFixtures;

#[RequiresPhpExtension('gd')]
#[RequiresFunction('imagewebp')]
final class VariantGeneratorTest extends TestCase
{
    use AssetFixtures;

    protected function setUp(): void
    {
        $this->makeAssetRoot();
    }

    protected function tearDown(): void
    {
        $this->removeAssetRoot();
    }

    public function test_generates_webp_widths_below_original(): void
    {
        $asset = $this->library([], new VariantGenerator(new GdBackend(), [480, 768, 1600], 75))
            ->upload($this->upload($this->png(1200, 800), 'a.png'), null);

        $webp = array_values(array_filter($asset->variants, static fn (Variant $v) => $v->format === 'webp'));
        $this->assertSame([480, 768, 1200], array_map(static fn (Variant $v) => $v->width, $webp));
        $this->assertSame(dirname($asset->path) . '/' . $asset->id . '-480.webp', $webp[0]->path);
        $size = getimagesize($this->assetRoot . '/media/' . $webp[0]->path);
        $this->assertSame(480, $size[0]);
        $this->assertSame(320, $size[1]);
        $this->assertSame('image/webp', $size['mime']);
    }

    #[RequiresFunction('imageavif')]
    public function test_avif_when_supported(): void
    {
        $asset = $this->library([], new VariantGenerator(new GdBackend(), [480], 60))
            ->upload($this->upload($this->png(800, 400), 'a.png'), null);

        $avif = array_values(array_filter($asset->variants, static fn (Variant $v) => $v->format === 'avif'));
        $this->assertSame([480, 800], array_map(static fn (Variant $v) => $v->width, $avif));
        $this->assertFileExists($this->assetRoot . '/media/' . $avif[0]->path);
    }

    public function test_no_backend_means_no_variants(): void
    {
        $asset = $this->library([], new VariantGenerator(null, [480], 75))->upload($this->upload($this->png(800, 400), 'a.png'), null);

        $this->assertSame([], $asset->variants);
        $this->assertFileExists($this->assetRoot . '/media/' . $asset->path);
    }

    public function test_gif_and_documents_get_no_variants(): void
    {
        $library = $this->library([], new VariantGenerator(new GdBackend(), [480], 75));
        $image = imagecreatetruecolor(800, 400);
        ob_start();
        imagegif($image);
        $gif = (string) ob_get_clean();

        $this->assertSame([], $library->upload($this->upload($gif, 'a.gif'), null)->variants);
        $this->assertSame([], $library->upload($this->upload("notes\n", 'a.txt'), null)->variants);
    }

    public function test_regenerate_replaces_variants(): void
    {
        $asset = $this->library([], new VariantGenerator(new GdBackend(), [480], 75))->upload($this->upload($this->png(1200, 800), 'a.png'), null);
        $old = $this->assetRoot . '/media/' . dirname($asset->path) . '/' . $asset->id . '-480.webp';
        $this->assertFileExists($old);

        $fresh = $this->library([], new VariantGenerator(new GdBackend(), [600], 75))->regenerate($asset);

        $this->assertFileDoesNotExist($old);
        $this->assertContains(600, array_map(static fn (Variant $v) => $v->width, $fresh->variants));
        $this->assertFileExists($this->assetRoot . '/media/' . dirname($asset->path) . '/' . $asset->id . '-600.webp');
        $this->assertEquals($fresh, $this->library()->find($asset->id));
    }

    public function test_detect_prefers_gd(): void
    {
        $this->assertInstanceOf(GdBackend::class, ImageBackends::detect());
    }
}
