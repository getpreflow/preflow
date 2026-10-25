<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Preview;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Media\Asset;
use Preflow\Media\Http\VariantController;
use Preflow\Media\Image\ColorManagement;
use Preflow\Media\Image\GdBackend;
use Preflow\Media\Image\ImageWidths;
use Preflow\Media\Image\VariantGenerator;
use Preflow\Media\ImageTag;
use Preflow\Media\MediaLibrary;
use Preflow\Media\MediaServiceProvider;
use Preflow\Media\Preview\PdfPreviewRenderer;
use Preflow\Media\Preview\PreviewRenderer;
use Preflow\Media\Preview\PreviewRenderers;
use Preflow\Media\Tests\Support\AllowPrivateFiles;
use Preflow\Media\Tests\Support\DeltaE;
use Preflow\Media\Tests\Support\FixedLocale;
use Preflow\Media\Tests\Support\MediaApp;
use Preflow\Media\Tests\Support\MediaFixtures;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;
use Preflow\View\TemplateEngineInterface;

/** PDF previews: the first page as an sRGB PNG, made on upload and replace, private with the PDF. */
#[RequiresPhpExtension('gd')]
#[RequiresPhpExtension('fileinfo')]
#[RequiresFunction('imagewebp')]
final class PdfPreviewTest extends TestCase
{
    use MediaFixtures;
    use MediaApp;
    use DeltaE;

    private const string FIXTURES = __DIR__ . '/../Fixtures/pdf';

    protected function setUp(): void
    {
        $this->makeAssetRoot();
        $this->dir = $this->assetRoot . '/app';
    }

    protected function tearDown(): void
    {
        $this->removeMediaApp();
        $this->removeAssetRoot();
    }

    private function requireGhostscript(): void
    {
        if (PdfPreviewRenderer::findGhostscript() === null) {
            $this->markTestSkipped('No Ghostscript (gs) on this machine.');
        }
    }

    private function renderers(?PreviewRenderer $renderer = null): PreviewRenderers
    {
        $renderers = new PreviewRenderers();
        $renderers->register($renderer ?? new PdfPreviewRenderer(new ColorManagement()));

        return $renderers;
    }

    private function lib(?PreviewRenderers $renderers = null, array $config = []): MediaLibrary
    {
        $dm = new \Preflow\Data\DataManager(drivers: ['json' => new \Preflow\Data\Driver\JsonFileDriver($this->assetRoot . '/store')], defaultDriver: 'json');

        return new MediaLibrary(
            $dm,
            new \Preflow\Data\Locales(['en', 'de'], 'en'),
            $this->assetRoot . '/media',
            '/media',
            $config + ['versions_dir' => $this->assetRoot . '/versions', 'private_dir' => $this->assetRoot . '/private'],
            new VariantGenerator(new GdBackend(), [480, 960], 75),
            previews: $renderers ?? $this->renderers(),
        );
    }

    private function pdf(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES . '/' . $name);
    }

    private function tag(MediaLibrary $library): ImageTag
    {
        return new ImageTag($library, new FixedLocale('en'), [], new AssetCollector(new NonceGenerator()), '', ImageWidths::fromConfig([], [480, 960]));
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

    private function base(Asset $asset): string
    {
        return substr($asset->path, 0, (int) strrpos($asset->path, '.'));
    }

    public function test_an_rgb_pdf_gets_a_preview_1600_px_on_its_long_side(): void
    {
        $this->requireGhostscript();
        $library = $this->lib();

        $asset = $library->upload($this->upload($this->pdf('rgb.pdf'), 'flyer.pdf'), null);

        $this->assertNotNull($asset->preview);
        $this->assertSame($this->base($asset) . '-preview.png', $asset->preview->path);
        $this->assertSame([1600, 1067], [$asset->preview->width, $asset->preview->height]);
        $file = $this->assetRoot . '/media/' . $asset->preview->path;
        $this->assertFileExists($file);
        $this->assertSame([1600, 1067, IMAGETYPE_PNG], array_slice((array) getimagesize($file), 0, 3));
        if (class_exists(\Imagick::class)) {
            $this->assertSame([], (new \Imagick($file))->getImageProfiles('icc'), 'the preview carries no profile');
        }
        // Its variants are the preview's, named after it.
        $this->assertNotSame([], $asset->variants);
        foreach ($asset->variants as $variant) {
            $this->assertStringStartsWith($this->base($asset) . '-preview-', $variant->path);
            $this->assertFileExists($this->assetRoot . '/media/' . $variant->path);
        }
        $this->assertEquals($asset, $library->find($asset->id));
        $this->assertSame('/media/' . $asset->preview->path, $library->previewUrl($asset));
        $this->assertSame('/media/' . $asset->path, $library->url($asset)); // the PDF stays the PDF
    }

    #[RequiresPhpExtension('imagick')]
    public function test_a_cmyk_print_pdf_matches_its_reference_patches(): void
    {
        $this->requireGhostscript();
        $asset = $this->lib()->upload($this->upload($this->pdf('cmyk-print.pdf'), 'print.pdf'), null);
        $reference = json_decode($this->pdf('references.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertNotNull($asset->preview);
        $this->assertSame([1600, 800], [$asset->preview->width, $asset->preview->height]);
        $image = new \Imagick($this->assetRoot . '/media/' . $asset->preview->path);
        foreach ($reference['centres'] as $i => [$x, $y]) {
            $pixel = $image->getImagePixelColor((int) ($x * 1600), (int) ($y * 800))->getColor();
            $actual = [$pixel['r'], $pixel['g'], $pixel['b']];
            $this->assertLessThan(3.0, self::deltaE2000($reference['srgb'][$i], $actual), sprintf('patch %d: expected rgb(%s), got rgb(%s)', $i, implode(',', $reference['srgb'][$i]), implode(',', $actual)));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unreadable(): iterable
    {
        yield 'broken' => ['broken.pdf'];
        yield 'encrypted' => ['encrypted.pdf'];
    }

    #[DataProvider('unreadable')]
    public function test_a_broken_or_encrypted_pdf_uploads_without_a_preview(string $name): void
    {
        $this->requireGhostscript();
        $library = $this->lib();
        $started = microtime(true);

        $asset = $library->upload($this->upload($this->pdf($name), $name), null);

        $this->assertNull($asset->preview);
        $this->assertSame([], $asset->variants);
        $this->assertFileExists($library->file($asset));
        $this->assertSame([$asset->path], $this->filesUnder($this->assetRoot . '/media'));
        $this->assertLessThan(PdfPreviewRenderer::TIME_LIMIT, microtime(true) - $started);
    }

    public function test_rendering_is_aborted_at_the_time_limit(): void
    {
        $this->assertSame(30, PdfPreviewRenderer::TIME_LIMIT);
        // A Ghostscript that never finishes.
        $stub = $this->assetRoot . '/slow-gs';
        file_put_contents($stub, "#!/bin/sh\nexec sleep 20\n");
        chmod($stub, 0755);
        $library = $this->lib($this->renderers(new PdfPreviewRenderer(new ColorManagement(), $stub, timeLimit: 1)));
        $started = microtime(true);

        $asset = $library->upload($this->upload($this->pdf('rgb.pdf'), 'slow.pdf'), null);

        $this->assertLessThan(5, microtime(true) - $started);
        $this->assertNull($asset->preview);
        $this->assertSame([$asset->path], $this->filesUnder($this->assetRoot . '/media'));
    }

    public function test_a_renderer_that_throws_leaves_the_upload_alone(): void
    {
        $library = $this->lib($this->renderers(new class implements PreviewRenderer {
            public function supports(string $mime): bool
            {
                return true;
            }

            public function render(string $file, int $longSide): ?string
            {
                throw new \RuntimeException('boom');
            }
        }));

        $asset = $library->upload($this->upload($this->pdf('rgb.pdf'), 'a.pdf'), null);

        $this->assertNull($asset->preview);
        $this->assertFileExists($library->file($asset));
    }

    public function test_without_ghostscript_there_is_no_renderer(): void
    {
        $renderer = new PdfPreviewRenderer(new ColorManagement(), $this->assetRoot . '/no-such-gs');
        $this->assertFalse($renderer->supports('application/pdf'));
        $asset = $this->lib($this->renderers($renderer))->upload($this->upload($this->pdf('rgb.pdf'), 'a.pdf'), null);

        $this->assertNull($asset->preview);
    }

    public function test_the_registry_asks_site_renderers_before_the_fallback(): void
    {
        $pdf = new PdfPreviewRenderer(new ColorManagement(), $this->assetRoot . '/no-such-gs');
        $site = new class implements PreviewRenderer {
            public function supports(string $mime): bool
            {
                return $mime === 'application/pdf';
            }

            public function render(string $file, int $longSide): ?string
            {
                return null;
            }
        };
        $renderers = new PreviewRenderers();
        $renderers->register($pdf, fallback: true);
        $renderers->register($site);

        $this->assertSame($site, $renderers->for('application/pdf'));
        $this->assertNull($renderers->for('image/png'));
        $this->assertNotNull((new \ReflectionClass(PreviewRenderers::class))->getAttributes(\Preflow\Core\Container\Attributes\Singleton::class)[0] ?? null);
    }

    public function test_the_long_side_is_capped_by_max_pixels(): void
    {
        $this->requireGhostscript();
        $asset = $this->lib(config: ['max_pixels' => 640_000])->upload($this->upload($this->pdf('rgb.pdf'), 'a.pdf'), null);

        $this->assertSame(800, $asset->preview?->width);
        $this->assertEqualsWithDelta(533, $asset->preview->height, 1); // Ghostscript snaps the page to whole pixels
    }

    public function test_a_private_pdf_has_a_private_preview(): void
    {
        $this->requireGhostscript();
        $this->scaffoldMediaApp();
        $asset = $this->media()->upload($this->upload($this->pdf('rgb.pdf'), 'secret.pdf'), null, true);

        $this->assertNotNull($asset->preview);
        $this->assertFileExists($this->dir . '/storage/private-media/' . $asset->preview->path);
        $this->assertSame([], $this->filesUnder($this->dir . '/public/media'));
        $this->assertSame([], $asset->variants);
        $this->assertSame('/files/' . $asset->id . '/preview', $this->media()->previewUrl($asset));
        $this->assertSame(403, $this->fetch('/files/' . $asset->id . '/preview')->getStatusCode());

        // Deleting removes the preview from the private dir too.
        $this->media()->delete($asset->id);
        $this->assertSame([], $this->filesUnder($this->dir . '/storage/private-media'));
    }

    public function test_the_private_preview_is_served_to_whom_the_gate_allows(): void
    {
        $this->requireGhostscript();
        $this->scaffoldMediaApp([MediaServiceProvider::class, AllowPrivateFiles::class]);
        $private = $this->media()->upload($this->upload($this->pdf('rgb.pdf'), 'secret.pdf'), null, true);
        $public = $this->media()->upload($this->upload($this->pdf('rgb.pdf'), 'open.pdf'), null);

        $res = $this->fetch('/files/' . $private->id . '/preview');

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('image/png', $res->getHeaderLine('Content-Type'));
        $this->assertSame('private, no-store', $res->getHeaderLine('Cache-Control'));
        $this->assertStringStartsWith('inline;', $res->getHeaderLine('Content-Disposition'));
        $this->assertSame((string) file_get_contents($this->dir . '/storage/private-media/' . $private->preview?->path), (string) $res->getBody());
        // A public asset's preview is under the media URL, not here; an asset without one is a 404.
        $this->assertSame(404, $this->fetch('/files/' . $public->id . '/preview')->getStatusCode());
        $this->assertSame(404, $this->fetch('/files/' . str_repeat('a', 32) . '/preview')->getStatusCode());
    }

    public function test_replace_makes_a_new_preview_and_removes_the_old_one(): void
    {
        $this->requireGhostscript();
        $library = $this->lib();
        $asset = $library->upload($this->upload($this->pdf('rgb.pdf'), 'a.pdf'), null);
        // A preview variant made on first request goes too.
        $onDemand = $library->variantFile($library->previewAsset($asset), 640, 'webp', [480, 640, 960]);
        $this->assertNotNull($onDemand);
        $old = [$this->assetRoot . '/media/' . $asset->preview->path, $onDemand, ...array_map(fn ($v) => $this->assetRoot . '/media/' . $v->path, $asset->variants)];

        $new = $library->replace($asset->id, $this->upload($this->pdf('cmyk-print.pdf'), 'b.pdf'), null);

        $this->assertSame($this->base($asset) . '-v2-preview.png', $new->preview?->path);
        $this->assertSame([1600, 800], [$new->preview->width, $new->preview->height]);
        $this->assertFileExists($this->assetRoot . '/media/' . $new->preview->path);
        foreach ($old as $file) {
            $this->assertFileDoesNotExist($file);
        }
        foreach ($new->variants as $variant) {
            $this->assertStringStartsWith($this->base($asset) . '-v2-preview-', $variant->path);
        }

        $library->delete($asset->id);
        $this->assertSame([], $this->filesUnder($this->assetRoot . '/media'));
    }

    public function test_a_private_replace_removes_the_old_private_preview(): void
    {
        $this->requireGhostscript();
        $library = $this->lib();
        $asset = $library->upload($this->upload($this->pdf('rgb.pdf'), 'a.pdf'), null, true);

        $new = $library->replace($asset->id, $this->upload($this->pdf('cmyk-print.pdf'), 'b.pdf'), null);

        $this->assertSame([$new->preview?->path, $new->path], $this->filesUnder($this->assetRoot . '/private'));
        $this->assertSame([], $this->filesUnder($this->assetRoot . '/media'));
    }

    public function test_media_image_renders_the_preview_as_a_picture(): void
    {
        $this->requireGhostscript();
        $library = $this->lib();
        $asset = $library->upload($this->upload($this->pdf('rgb.pdf'), 'flyer.pdf'), null);
        $library->update($asset->id, ['alt' => ['en' => 'The flyer']]);
        $base = '/media/' . $this->base($asset) . '-preview';

        $html = $this->tag($library)->render($asset->id, null);

        $this->assertStringStartsWith('<picture>', $html);
        $this->assertStringContainsString('<source type="image/webp" srcset="' . $base . '-480.webp 480w, ' . $base . '-960.webp 960w"', $html);
        $this->assertStringContainsString('<img src="' . $base . '.png" width="1600" height="1067" alt="The flyer"', $html);
        $this->assertSame($base . '.png', $this->tag($library)->previewUrl($asset->id));
        $this->assertSame('/media/' . $asset->path, $this->tag($library)->url($asset->id));
    }

    public function test_media_image_of_a_private_pdf_is_the_gated_preview(): void
    {
        $this->requireGhostscript();
        $library = $this->lib();
        $asset = $library->upload($this->upload($this->pdf('rgb.pdf'), 'secret.pdf'), null, true);

        $html = $this->tag($library)->render($asset->id, null);

        $this->assertStringStartsWith('<img src="/files/' . $asset->id . '/preview" width="1600" height="1067"', $html);
        $this->assertStringNotContainsString('<source', $html);
    }

    public function test_a_pdf_without_a_preview_stays_a_link(): void
    {
        $library = $this->lib(new PreviewRenderers());
        $asset = $library->upload($this->upload($this->pdf('rgb.pdf'), 'a.pdf'), null);

        $this->assertSame('<a href="/media/' . $asset->path . '">a.pdf</a>', $this->tag($library)->render($asset->id));
        $this->assertSame('', $this->tag($library)->previewUrl($asset->id));
        $this->assertNull($library->previewUrl($asset));
        $this->assertNull($library->thumbnailUrl($asset));
    }

    public function test_a_poster_is_used_instead_of_the_preview(): void
    {
        $this->requireGhostscript();
        $library = $this->lib();
        $poster = $library->upload($this->upload($this->png(1200, 900), 'cover.png'), null);
        $asset = $library->upload($this->upload($this->pdf('rgb.pdf'), 'secret.pdf'), null, true);
        $library->update($asset->id, ['poster' => $poster->id, 'alt' => ['en' => 'Annual report']]);

        $html = $this->tag($library)->render($asset->id, null);

        $this->assertStringStartsWith('<picture>', $html);
        $this->assertStringContainsString('<img src="/media/' . $poster->path . '" width="1200" height="900" alt="Annual report"', $html);
        $this->assertStringNotContainsString('preview', $html);
    }

    public function test_the_thumbnail_of_a_pdf_is_its_preview(): void
    {
        $this->requireGhostscript();
        $library = $this->lib();
        $public = $library->upload($this->upload($this->pdf('rgb.pdf'), 'a.pdf'), null);
        $private = $library->upload($this->upload($this->pdf('rgb.pdf'), 'b.pdf'), null, true);

        $this->assertSame('/media/' . $this->base($public) . '-preview-480.webp', $library->thumbnailUrl($public));
        $this->assertSame('/files/' . $private->id . '/preview', $library->thumbnailUrl($private));
    }

    public function test_preview_variants_are_made_on_request_and_others_are_404(): void
    {
        $this->requireGhostscript();
        $library = $this->lib();
        $widths = ImageWidths::fromConfig(['card' => ['widths' => [480, 960], 'step' => 160]], null);
        $asset = $library->upload($this->upload($this->pdf('rgb.pdf'), 'a.pdf'), null);
        $private = $library->upload($this->upload($this->pdf('rgb.pdf'), 'b.pdf'), null, true);
        $serve = function (string $path) use ($library, $widths) {
            return (new VariantController($library, $widths))->serve((new Psr17Factory())->createServerRequest('GET', '/media/' . $path)->withAttribute('path', $path));
        };
        $base = $this->base($asset);

        $res = $serve($base . '-preview-640.webp');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertFileExists($this->assetRoot . '/media/' . $base . '-preview-640.webp');

        $before = $this->filesUnder($this->assetRoot . '/media');
        foreach ([
            $base . '-v2-preview-640.webp',                              // not the current version
            $base . '-preview-700.webp',                                 // not an allowed width
            $base . '-preview-1920.webp',                                // wider than the preview
            $base . '-preview-preview-640.webp',
            $base . '-640.webp',                                         // the PDF itself has no variants
            dirname($base) . '/' . $private->id . '-preview-640.webp',   // private: never public files
        ] as $path) {
            try {
                $serve($path);
                $this->fail("expected a 404 for {$path}");
            } catch (NotFoundHttpException) {
            }
        }
        $this->assertSame($before, $this->filesUnder($this->assetRoot . '/media'));
    }

    public function test_media_preview_url_in_templates(): void
    {
        $this->requireGhostscript();
        $this->scaffoldMediaApp();
        $this->writeFile('page.twig', "{{ media_preview_url(id) }}|{{ media_preview_url(null) }}");
        $asset = $this->media()->upload($this->upload($this->pdf('rgb.pdf'), 'a.pdf'), null);

        $out = $this->mediaApp()->container()->get(TemplateEngineInterface::class)->render('page.twig', ['id' => $asset->id]);

        $this->assertSame('/media/' . $asset->preview?->path . '|', $out);
    }
}
