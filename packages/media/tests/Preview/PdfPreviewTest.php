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
        if ((new PdfPreviewRenderer())->engine() === null) {
            $this->markTestSkipped('No PDF renderer (Ghostscript or pdftocairo) on this machine.');
        }
    }

    /** An executable shell script standing in for gs: --version prints $version, anything else runs $body. */
    private function stubGs(string $version, string $body): string
    {
        $stub = $this->assetRoot . '/gs-' . bin2hex(random_bytes(3));
        file_put_contents($stub, "#!/bin/sh\nif [ \"\$1\" = \"--version\" ]; then echo x >> {$stub}.version-calls; echo {$version}; exit 0; fi\n{$body}\n");
        chmod($stub, 0755);

        return $stub;
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

    /** @return iterable<string, array{string}> */
    public static function printPdfs(): iterable
    {
        yield 'FOGRA39 intent (the default profile)' => ['cmyk-print.pdf'];
        yield 'newsprint intent (not the default)' => ['cmyk-newsprint.pdf'];
    }

    #[DataProvider('printPdfs')]
    #[RequiresPhpExtension('imagick')]
    public function test_a_cmyk_print_pdf_matches_its_reference_patches(string $name): void
    {
        if ((new PdfPreviewRenderer())->engine() !== PdfPreviewRenderer::GHOSTSCRIPT) {
            $this->markTestSkipped('Needs Ghostscript ' . PdfPreviewRenderer::GHOSTSCRIPT_FLOOR . ' or later (pdftocairo ignores the output intent).');
        }
        $asset = $this->lib()->upload($this->upload($this->pdf($name), 'print.pdf'), null);
        $reference = json_decode($this->pdf('references.json'), true, flags: JSON_THROW_ON_ERROR);
        $expected = $reference['files'][$name]['srgb'];

        $this->assertNotNull($asset->preview);
        $this->assertSame([1600, 800], [$asset->preview->width, $asset->preview->height]);
        $image = new \Imagick($this->assetRoot . '/media/' . $asset->preview->path);
        foreach ($reference['centres'] as $i => [$x, $y]) {
            $pixel = $image->getImagePixelColor((int) ($x * 1600), (int) ($y * 800))->getColor();
            $actual = [$pixel['r'], $pixel['g'], $pixel['b']];
            $this->assertLessThan(3.0, self::deltaE2000($expected[$i], $actual), sprintf('patch %d: expected rgb(%s), got rgb(%s)', $i, implode(',', $expected[$i]), implode(',', $actual)));
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
        // A (safe) Ghostscript that never finishes.
        $stub = $this->stubGs('10.08.0', 'exec sleep 20');
        $library = $this->lib($this->renderers(new PdfPreviewRenderer(new ColorManagement(), $stub, timeLimit: 1)));
        $started = microtime(true);

        $asset = $library->upload($this->upload($this->pdf('rgb.pdf'), 'slow.pdf'), null);

        $this->assertLessThan(5, microtime(true) - $started);
        $this->assertNull($asset->preview);
        $this->assertSame([$asset->path], $this->filesUnder($this->assetRoot . '/media'));
    }

    public function test_a_renderer_that_throws_leaves_the_upload_alone(): void
    {
        $log = $this->assetRoot . '/error.log';
        $previous = ini_set('error_log', $log);
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

        try {
            $asset = $library->upload($this->upload($this->pdf('rgb.pdf'), 'a.pdf'), null);
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $this->assertNull($asset->preview);
        $this->assertFileExists($library->file($asset));
        $this->assertStringContainsString("preflow/media: no preview of asset {$asset->id}: RuntimeException: boom", (string) file_get_contents($log));
    }

    /** A one-page PDF of $w × $h units (times /UserUnit, turned by /Rotate), filled blue. */
    private static function pagePdf(int $w, int $h, ?int $userUnit = null, int $rotate = 0): string
    {
        $content = "0 0 1 rg 0 0 {$w} {$h} re f";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$w} {$h}] " . ($userUnit !== null ? "/UserUnit {$userUnit} " : '') . "/Rotate {$rotate} /Contents 4 0 R >>",
            '<< /Length ' . strlen($content) . " >>\nstream\n{$content}\nendstream",
        ];
        $out = "%PDF-1.6\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($out);
            $out .= ($i + 1) . " 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 5\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $out .= sprintf("%010d 00000 n \n", $o);
        }

        return $out . "trailer << /Size 5 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    public function test_a_huge_page_is_measured_small(): void
    {
        $renderer = new PdfPreviewRenderer(new ColorManagement());
        if ($renderer->engine() !== PdfPreviewRenderer::GHOSTSCRIPT) {
            $this->markTestSkipped('The measuring pass is Ghostscript\'s.');
        }
        $cases = [[100000, 50000, null, 0, [1600, 800]], [14400, 14400, 10, 0, [1600, 1600]], [100000, 50000, null, 90, [800, 1600]], [600, 400, null, 0, [1600, 1067]]];
        foreach ($cases as [$w, $h, $unit, $rotate, $expected]) {
            $file = $this->assetRoot . '/huge.pdf';
            file_put_contents($file, self::pagePdf($w, $h, $unit, $rotate));
            $started = microtime(true);

            $png = $renderer->render($file, 1600);

            // Unbounded, the measuring pass alone drew 25000 × 25000 (36000 × 36000) pixels for 10-20 s.
            $this->assertLessThan(5, microtime(true) - $started);
            $this->assertNotNull($png);
            $this->assertSame($expected, array_slice((array) getimagesize($png), 0, 2));
            $image = imagecreatefrompng($png);
            foreach ([[2, 2], [$expected[0] - 3, $expected[1] - 3], [intdiv($expected[0], 2), intdiv($expected[1], 2)]] as [$x, $y]) {
                $rgb = imagecolorsforindex($image, imagecolorat($image, $x, $y)); // the page fills the bitmap: no white margin
                $this->assertLessThan(60, $rgb['red'] + $rgb['green'], "{$w}x{$h} at {$x},{$y}");
                $this->assertGreaterThan(150, $rgb['blue']);
            }
            unlink($png);
        }
    }

    public function test_without_ghostscript_or_pdftocairo_there_is_no_renderer(): void
    {
        $renderer = new PdfPreviewRenderer(new ColorManagement(), $this->assetRoot . '/no-such-gs', pdftocairo: $this->assetRoot . '/no-such-pdftocairo');
        $this->assertFalse($renderer->supports('application/pdf'));
        $this->assertNull($renderer->engine());
        $asset = $this->lib($this->renderers($renderer))->upload($this->upload($this->pdf('rgb.pdf'), 'a.pdf'), null);

        $this->assertNull($asset->preview);
    }

    public function test_a_ghostscript_below_the_floor_is_never_run_and_pdftocairo_renders(): void
    {
        $cairo = PdfPreviewRenderer::findBinary('pdftocairo');
        if ($cairo === null) {
            $this->markTestSkipped('No pdftocairo on this machine.');
        }
        $marker = $this->assetRoot . '/old-gs-ran';
        $old = $this->stubGs('9.27', "touch {$marker}; exit 1");
        $renderer = new PdfPreviewRenderer(new ColorManagement(), $old);
        $this->assertSame('9.27', PdfPreviewRenderer::ghostscriptVersion($old));
        $this->assertSame(PdfPreviewRenderer::PDFTOCAIRO, $renderer->engine());

        $asset = $this->lib($this->renderers($renderer))->upload($this->upload($this->pdf('rgb.pdf'), 'a.pdf'), null);

        $this->assertSame([1600, 1067], [$asset->preview?->width, $asset->preview?->height]);
        $this->assertFileDoesNotExist($marker);
        if (class_exists(\Imagick::class)) {
            $this->assertSame([], (new \Imagick($this->assetRoot . '/media/' . $asset->preview->path))->getImageProfiles('icc'));
        }
        // The version is read once per process.
        PdfPreviewRenderer::ghostscriptVersion($old);
        (new PdfPreviewRenderer(new ColorManagement(), $old))->engine();
        $this->assertSame(1, count(file($old . '.version-calls') ?: []));
    }

    public function test_a_ghostscript_below_the_floor_without_pdftocairo_makes_no_preview(): void
    {
        $marker = $this->assetRoot . '/old-gs-ran';
        $renderer = new PdfPreviewRenderer(new ColorManagement(), $this->stubGs('9.27', "touch {$marker}; exit 1"), pdftocairo: $this->assetRoot . '/no-such-pdftocairo');

        $asset = $this->lib($this->renderers($renderer))->upload($this->upload($this->pdf('rgb.pdf'), 'a.pdf'), null);

        $this->assertNull($renderer->engine());
        $this->assertNull($asset->preview);
        $this->assertFileDoesNotExist($marker);
    }

    public function test_a_ghostscript_from_the_floor_on_is_used(): void
    {
        $gs = PdfPreviewRenderer::findBinary('gs');
        if ($gs === null || version_compare((string) PdfPreviewRenderer::ghostscriptVersion($gs), PdfPreviewRenderer::GHOSTSCRIPT_FLOOR, '<')) {
            $this->markTestSkipped('Needs Ghostscript ' . PdfPreviewRenderer::GHOSTSCRIPT_FLOOR . ' or later.');
        }
        $marker = $this->assetRoot . '/gs-ran';
        $stub = $this->stubGs('10.08.0', "touch {$marker}; exec " . escapeshellarg($gs) . ' "$@"');
        $renderer = new PdfPreviewRenderer(new ColorManagement(), $stub, pdftocairo: $this->assetRoot . '/no-such-pdftocairo');
        $this->assertSame(PdfPreviewRenderer::GHOSTSCRIPT, $renderer->engine());

        $asset = $this->lib($this->renderers($renderer))->upload($this->upload($this->pdf('rgb.pdf'), 'a.pdf'), null);

        $this->assertFileExists($marker);
        $this->assertSame([1600, 1067], [$asset->preview?->width, $asset->preview?->height]);
        $this->assertSame('10.03.1', PdfPreviewRenderer::GHOSTSCRIPT_FLOOR);
    }

    public function test_the_registry_asks_site_renderers_before_the_fallback(): void
    {
        $pdf = new PdfPreviewRenderer(new ColorManagement(), $this->assetRoot . '/no-such-gs', pdftocairo: $this->assetRoot . '/no-such-pdftocairo');
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

    /** A renderer that draws a PNG of one colour with GD, or fails when $fail says so. */
    private function gdRenderer(array $rgb, bool &$fail): PreviewRenderer
    {
        return new class ($rgb, $fail) implements PreviewRenderer {
            public function __construct(private array $rgb, private bool &$fail) {}

            public function supports(string $mime): bool
            {
                return $mime === 'application/pdf';
            }

            public function render(string $file, int $longSide): ?string
            {
                if ($this->fail) {
                    throw new \RuntimeException('killed');
                }
                $image = imagecreatetruecolor(1000, 500);
                imagefill($image, 0, 0, (int) imagecolorallocate($image, ...$this->rgb));
                $tmp = tempnam(sys_get_temp_dir(), 'preview');
                imagepng($image, $tmp);

                return $tmp;
            }
        };
    }

    public function test_a_failed_remake_keeps_the_working_preview(): void
    {
        $fail = false;
        $library = $this->lib($this->renderers($this->gdRenderer([200, 30, 30], $fail)));
        $asset = $library->upload($this->upload($this->pdf('rgb.pdf'), 'a.pdf'), null);
        $this->assertNotNull($asset->preview);
        $this->assertNotSame([], $asset->variants);
        $files = [$this->assetRoot . '/media/' . $asset->preview->path, ...array_map(fn ($v) => $this->assetRoot . '/media/' . $v->path, $asset->variants)];
        $bytes = array_map('file_get_contents', $files);

        $fail = true;
        $log = ini_set('error_log', $this->assetRoot . '/error.log');
        try {
            $this->assertNull($library->remakePreview($asset));
        } finally {
            ini_set('error_log', (string) $log);
        }

        $this->assertEquals($asset, $library->find($asset->id));
        $this->assertSame($bytes, array_map('file_get_contents', $files));
    }

    public function test_backfills_save_nothing_for_an_asset_deleted_since_the_list_was_read(): void
    {
        $fail = false;
        $dm = new \Preflow\Data\DataManager(drivers: ['json' => new \Preflow\Data\Driver\JsonFileDriver($this->assetRoot . '/store')], defaultDriver: 'json');
        $library = new MediaLibrary(
            $dm,
            new \Preflow\Data\Locales(['en', 'de'], 'en'),
            $this->assetRoot . '/media',
            '/media',
            ['versions_dir' => $this->assetRoot . '/versions'],
            new VariantGenerator(new GdBackend(), [480], 75),
            previews: $this->renderers($this->gdRenderer([200, 30, 30], $fail)),
            colors: new \Preflow\Media\Color\ColorExtractor(new ColorManagement()),
        );
        $pdf = $library->upload($this->upload($this->pdf('rgb.pdf'), 'a.pdf'), null);
        $image = $library->upload($this->upload($this->png(), 'a.png'), null);
        $library->delete($pdf->id); // deleted while media:variants/previews/colors work through their list
        $library->delete($image->id);

        $this->assertSame($image, $library->regenerate($image));
        $this->assertNull($library->remakePreview($pdf));
        $this->assertNull($library->extractColors($image));
        $this->assertNull($library->extractColors($pdf));

        $this->assertSame([], $library->all());
    }

    public function test_a_remake_swaps_in_the_new_preview(): void
    {
        $fail = false;
        $library = $this->lib($this->renderers($this->gdRenderer([200, 30, 30], $fail)));
        $asset = $library->upload($this->upload($this->pdf('rgb.pdf'), 'a.pdf'), null);
        $onDemand = $library->variantFile($library->previewAsset($asset), 640, 'webp', [480, 640, 960]);
        $this->assertNotNull($onDemand);
        $old = (string) file_get_contents($this->assetRoot . '/media/' . $asset->preview->path);

        $new = $this->lib($this->renderers($this->gdRenderer([30, 30, 200], $fail)))->remakePreview($asset);

        $this->assertNotNull($new);
        $this->assertEquals($new, $library->find($asset->id));
        $this->assertSame($asset->preview->path, $new->preview?->path);
        $this->assertNotSame($old, file_get_contents($this->assetRoot . '/media/' . $new->preview->path));
        $this->assertFileDoesNotExist($onDemand);
        $this->assertNotSame([], $new->variants);
        foreach ($new->variants as $v) {
            $this->assertFileExists($this->assetRoot . '/media/' . $v->path);
        }
    }

    public function test_regenerate_leaves_a_document_alone(): void
    {
        $this->requireGhostscript();
        $library = $this->lib();
        $asset = $library->upload($this->upload($this->pdf('rgb.pdf'), 'a.pdf'), null);
        $this->assertNotSame([], $asset->variants);

        $this->assertEquals($asset, $library->regenerate($asset));
        $this->assertEquals($asset, $library->find($asset->id));
        foreach ($asset->variants as $variant) {
            $this->assertFileExists($this->assetRoot . '/media/' . $variant->path);
        }
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
