<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Pdf;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Blade\BladeEngine;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Tests\Support\MediaFixtures;
use Preflow\Twig\TwigEngine;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;
use Preflow\View\Pdf\MpdfEngine;
use Preflow\View\Pdf\PageSettings;
use Preflow\View\Pdf\PdfConfig;
use Preflow\View\Pdf\PdfEngine;
use Preflow\View\Pdf\PdfRenderer;
use Preflow\View\Qr\QrCode;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\Tests\Pdf\Fixtures\BlocksDocument;
use Preflow\View\ViewExtensionProvider;
use Psr\Container\ContainerInterface;
use Smalot\PdfParser\Parser;

require_once __DIR__ . '/Fixtures/BlocksDocument.php';

/**
 * The PDF building blocks (pdf_* template functions) in both template
 * engines, rendered through mPDF and as the browser preview.
 */
#[RequiresPhpExtension('gd')]
final class BlocksTest extends TestCase
{
    use MediaFixtures;

    private string $dir;

    private ?MediaLibrary $library = null;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/preflow_pdf_blocks_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/blade', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
        if (isset($this->assetRoot)) {
            $this->removeAssetRoot();
        }
    }

    /** @return array<string, array{string}> */
    public static function engines(): array
    {
        return ['twig' => ['twig'], 'blade' => ['blade.php']];
    }

    #[DataProvider('engines')]
    public function test_page_x_of_y_in_footer(string $ext): void
    {
        $body = $this->template($ext, 'body', '<p>One</p>{{ pdf_page_break() }}<p>Two</p>{{ pdf_page_break() }}<p>Three</p>', '<p>One</p>@pdf_page_break()<p>Two</p>@pdf_page_break<p>Three</p>');
        $footer = $this->template($ext, 'footer', 'Page {{ pdf_page_number() }} of {{ pdf_page_count() }}', 'Page @pdf_page_number() of @pdf_page_count()');
        $doc = new BlocksDocument($body, [], new PageSettings(footerTemplate: $footer));

        $pages = (new Parser())->parseContent($this->renderer($ext)->render($doc))->getPages();

        $this->assertCount(3, $pages);
        foreach ($pages as $i => $page) {
            $this->assertStringContainsString('Page ' . ($i + 1) . ' of 3', $page->getText());
        }
    }

    #[DataProvider('engines')]
    public function test_table_header_repeats(string $ext): void
    {
        $body = $this->template(
            $ext,
            'table',
            '{{ pdf_table(vars.rows, {player: "Player", score: "Score"}, {class: "scores"}) }}',
            "@pdf_table(\$vars['rows'], ['player' => 'Player', 'score' => 'Score'], ['class' => 'scores'])",
        );
        $rows = [['player' => 'Ada <b>& co</b>', 'score' => 1]];
        for ($i = 2; $i <= 150; $i++) {
            $rows[] = ['player' => "Player {$i}", 'score' => $i * 10];
        }
        $doc = new BlocksDocument($body, ['rows' => $rows]);

        $pages = (new Parser())->parseContent($this->renderer($ext)->render($doc))->getPages();

        $this->assertGreaterThanOrEqual(3, count($pages));
        foreach ($pages as $page) {
            $this->assertStringContainsString('Player', $page->getText());
            $this->assertStringContainsString('Score', $page->getText());
            $this->assertMatchesRegularExpression('/Player\s+Score/', $page->getText());
        }
        $text = implode("\n", array_map(static fn ($p): string => $p->getText(), $pages));
        $this->assertStringContainsString('Ada <b>& co</b>', $text);
        $this->assertStringContainsString('Player 150', $text);

        $html = $this->previewRenderer($ext)->html($doc);
        $this->assertStringContainsString('<table class="pdf-table scores">', $html);
        $this->assertStringContainsString('<thead><tr><th>Player</th><th>Score</th></tr></thead>', $html);
        $this->assertStringContainsString('<td>Ada &lt;b&gt;&amp; co&lt;/b&gt;</td><td>1</td>', $html);
    }

    #[DataProvider('engines')]
    public function test_image_is_scaled_copy_and_temp_is_cleaned(string $ext): void
    {
        $big = $this->image($this->dir . '/big.png', 3000, 2000);
        $small = $this->image($this->dir . '/small.png', 400, 300);
        $body = $this->template(
            $ext,
            'image',
            '{{ pdf_image(vars.big, {width_mm: 80}) }}{{ pdf_image(vars.small) }}',
            "@pdf_image(\$vars['big'], ['width_mm' => 80])@pdf_image(\$vars['small'])",
        );
        $doc = new BlocksDocument($body, ['big' => $big, 'small' => $small]);
        $engine = new CapturingEngine(new MpdfEngine($this->config()), $this->config()->tempDir);

        $pdf = (new Parser())->parseContent($this->renderer($ext, $engine)->render($doc));

        // While mPDF ran, the big image was a 1200 px JPEG copy in the temp dir; the small one went in as it is.
        $this->assertCount(1, $engine->scaledCopies);
        [$copy] = $engine->scaledCopies;
        $this->assertSame([1200, 800, IMAGETYPE_JPEG], array_slice((array) getimagesize($copy['file']) ?: [], 0, 3));
        $this->assertStringContainsString('src="' . $copy['path'] . '"', $engine->html);
        $this->assertStringContainsString('style="width: 80mm;"', $engine->html);
        $this->assertStringContainsString('src="' . $small . '"', $engine->html);
        $this->assertStringNotContainsString('src="' . $big . '"', $engine->html);
        $widths = array_map(static fn ($o): int => (int) $o->getDetails()['Width'], array_filter(
            $pdf->getObjects(),
            static fn ($o): bool => ($o->getDetails()['Subtype'] ?? null) === 'Image',
        ));
        sort($widths);
        $this->assertSame([400, 1200], array_values($widths));
        $this->assertSame([], $this->tempImages(), 'the scaled copy is deleted after the render');

        // Also when rendering fails.
        $failing = new CapturingEngine(new MpdfEngine($this->config()), $this->config()->tempDir, fail: true);
        try {
            $this->renderer($ext, $failing)->render($doc);
            $this->fail('The engine should have thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('engine failed', $e->getMessage());
        }
        $this->assertCount(1, $failing->scaledCopies);
        $this->assertSame([], $this->tempImages());
    }

    public function test_private_asset_needs_opt_in(): void
    {
        $library = $this->mediaLibrary();
        $public = $library->upload($this->upload($this->png(2000, 1000), 'public.png'), null);
        $private = $library->upload($this->upload($this->png(300, 200), 'private.png'), null, private: true);
        $body = $this->template('twig', 'asset', '{{ pdf_image(vars.id, vars.options) }}', '');
        $renderer = $this->renderer('twig');

        // A public asset by id: its file, scaled.
        $engine = new CapturingEngine(new MpdfEngine($this->config()), $this->config()->tempDir);
        $this->renderer('twig', $engine)->render(new BlocksDocument($body, ['id' => $public->id, 'options' => []]));
        $this->assertCount(1, $engine->scaledCopies);

        try {
            $renderer->render(new BlocksDocument($body, ['id' => $private->id, 'options' => []]));
            $this->fail('A private asset should need allow_private');
        } catch (\Throwable $e) {
            $cause = $this->cause($e, \InvalidArgumentException::class);
            $this->assertStringContainsString('private', $cause->getMessage());
            $this->assertStringContainsString('allow_private', $cause->getMessage());
        }

        $engine = new CapturingEngine(new MpdfEngine($this->config()), $this->config()->tempDir);
        $bytes = $this->renderer('twig', $engine)->render(new BlocksDocument($body, ['id' => $private->id, 'options' => ['allow_private' => true]]));
        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertStringContainsString('src="' . $library->file($private) . '"', $engine->html);

        try {
            $renderer->render(new BlocksDocument($body, ['id' => 'no-such-asset', 'options' => []]));
            $this->fail('An unknown asset id should throw');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('no-such-asset', $this->cause($e, \InvalidArgumentException::class)->getMessage());
        }
    }

    #[DataProvider('engines')]
    public function test_qr_block_is_scannable(string $ext): void
    {
        $url = 'https://bg-genius.example/certificate/7F3K-92QD';
        $body = $this->template($ext, 'qr', '<p>Check it</p>{{ pdf_qr(vars.url, {size_mm: 25}) }}', "<p>Check it</p>@pdf_qr(\$vars['url'], ['size_mm' => 25])");
        $engine = new CapturingEngine(new MpdfEngine($this->config()), $this->config()->tempDir);

        $pdf = (new Parser())->parseContent($this->renderer($ext, $engine)->render(new BlocksDocument($body, ['url' => $url])));

        // The SVG Task 3 makes (which decodes back to the data) reached mPDF, sized in mm...
        $svg = QrCode::svg($url);
        $this->assertStringContainsString('<svg width="25mm" height="25mm"', $engine->html);
        $this->assertStringContainsString(substr($svg, strpos($svg, '<path'), 300), $engine->html);
        // ...and mPDF drew it as vectors: one square subpath per module, none lost.
        preg_match_all('/<path\b[^>]*\bd="([^"]+)"/', $svg, $paths);
        $modules = array_sum(array_map(static fn (string $d): int => substr_count($d, 'M'), $paths[1]));
        $forms = array_filter($pdf->getObjects(), static fn ($o): bool => ($o->getDetails()['Subtype'] ?? null) === 'Form');
        $this->assertCount(1, $forms);
        $this->assertSame($modules, preg_match_all('/ m\b/', reset($forms)->getContent()));
    }

    public function test_custom_font_by_name(): void
    {
        $font = $this->dir . '/fonts/CertificateScript.ttf';
        mkdir(dirname($font));
        copy($this->mpdfFonts() . '/DejaVuSansMono.ttf', $font);
        $body = $this->template('twig', 'fonts', '<p>Plain text</p><p style="font-family: serif">Serif text</p>', '');
        $config = new PdfConfig($this->dir . '/pdf', ['Certificate Script' => $font], defaultFont: 'Certificate Script');

        $pdf = (new Parser())->parseContent((new PdfRenderer($this->templates('twig'), null, $config))->render(new BlocksDocument($body)));

        // The default font, given by the name the config registered, is the configured file.
        $this->assertStringContainsString('Plain text', $pdf->getText());
        $fonts = array_map(static fn ($f): string => (string) $f->getDetails()['BaseFont'], $pdf->getFonts());
        $this->assertNotEmpty(array_filter($fonts, static fn (string $name): bool => str_contains($name, 'DejaVuSansMono')));
        $this->assertNotEmpty(array_filter($fonts, static fn (string $name): bool => str_contains($name, 'DejaVuSerif')));

        // A name mPDF already uses for a bundled family (or translates to one) would shadow its bold and italic.
        foreach (['DejaVu Sans', 'Times'] as $name) {
            try {
                (new MpdfEngine(new PdfConfig($this->dir . '/pdf', [$name => $font])))->render('<p>x</p>', new PageSettings(), '', '');
                $this->fail("The font name '{$name}' should be refused");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString("'{$name}'", $e->getMessage());
                $this->assertStringContainsString('bundled', $e->getMessage());
            }
        }
    }

    #[DataProvider('engines')]
    public function test_preview_renders_blocks_approximately(string $ext): void
    {
        $big = $this->image($this->dir . '/big.png', 3000, 2000);
        $body = $this->template(
            $ext,
            'preview',
            '<p>One</p>{{ pdf_page_break() }}{{ pdf_table(vars.rows, ["Name"]) }}{{ pdf_image(vars.big, {width_mm: 50}) }}{{ pdf_qr("https://example.com", {size_mm: 20}) }}',
            "<p>One</p>@pdf_page_break()@pdf_table(\$vars['rows'], ['Name'])@pdf_image(\$vars['big'], ['width_mm' => 50])@pdf_qr('https://example.com', ['size_mm' => 20])",
        );
        $footer = $this->template($ext, 'footer', 'Page {{ pdf_page_number() }} of {{ pdf_page_count() }}', 'Page @pdf_page_number() of @pdf_page_count()');
        $doc = new BlocksDocument($body, ['rows' => [['Ada'], ['Grace']], 'big' => $big], new PageSettings(footerTemplate: $footer));

        $html = $this->previewRenderer($ext)->html($doc);

        $this->assertStringContainsString('<footer>Page  of </footer>', $html);
        $this->assertStringNotContainsString('{PAGENO}', $html);
        $this->assertStringNotContainsString('{nbpg}', $html);
        $this->assertStringNotContainsString('<pagebreak', $html);
        $this->assertStringContainsString('<div class="pdf-page-break" style="border-top: 1px dashed', $html);
        $this->assertStringContainsString('<thead><tr><th>Name</th></tr></thead><tbody><tr><td>Ada</td></tr><tr><td>Grace</td></tr></tbody>', $html);
        $this->assertStringContainsString('<svg width="20mm" height="20mm"', $html);
        // The browser cannot read the file path: the scaled copy is inlined, and nothing is left on disk.
        $this->assertMatchesRegularExpression('/<img src="data:image\/jpeg;base64,[^"]+" alt="" style="width: 50mm;" \/>/', $html);
        preg_match('/data:image\/jpeg;base64,([^"]+)/', $html, $m);
        $this->assertSame([1200, 800], array_slice((array) getimagesizefromstring((string) base64_decode($m[1])), 0, 2));
        $this->assertFalse(is_dir($this->dir . '/pdf'), 'the preview writes no temp files');
    }

    private function config(): PdfConfig
    {
        return new PdfConfig($this->dir . '/pdf');
    }

    private function renderer(string $ext, ?PdfEngine $engine = null): PdfRenderer
    {
        return new PdfRenderer($this->templates($ext), $engine ?? new MpdfEngine($this->config()), $this->config());
    }

    private function previewRenderer(string $ext): PdfRenderer
    {
        return new PdfRenderer($this->templates($ext), null, $this->config());
    }

    private function templates(string $ext): TemplateEngineInterface
    {
        $assets = new AssetCollector(new NonceGenerator());
        $engine = $ext === 'twig'
            ? new TwigEngine([$this->dir], $assets)
            : new BladeEngine(templateDirs: [$this->dir], assetCollector: $assets, cachePath: $this->dir . '/blade');

        $library = $this->library;
        $container = new class ($library) implements ContainerInterface {
            public function __construct(private readonly ?MediaLibrary $library)
            {
            }

            public function get(string $id): mixed
            {
                return $id === MediaLibrary::class && $this->library !== null ? $this->library : throw new \LogicException("No {$id}");
            }

            public function has(string $id): bool
            {
                return $id === MediaLibrary::class && $this->library !== null;
            }
        };
        foreach ((new ViewExtensionProvider($container))->getTemplateFunctions() as $function) {
            $engine->addFunction($function);
        }

        return $engine;
    }

    private function mediaLibrary(): MediaLibrary
    {
        $this->makeAssetRoot();

        return $this->library = $this->library(['private_dir' => $this->assetRoot . '/private']);
    }

    private function template(string $ext, string $name, string $twig, string $blade): string
    {
        $file = "{$this->dir}/{$name}.{$ext}";
        file_put_contents($file, $ext === 'twig' ? $twig : $blade);

        return $file;
    }

    private function image(string $file, int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 60, 90));
        imagepng($image, $file);

        return $file;
    }

    /** @return list<string> images left in the temp dir */
    private function tempImages(): array
    {
        return glob($this->dir . '/pdf/*.jpg') ?: [];
    }

    private function mpdfFonts(): string
    {
        return dirname((string) (new \ReflectionClass(\Mpdf\Mpdf::class))->getFileName(), 2) . '/ttfonts';
    }

    /**
     * @template T of \Throwable
     * @param class-string<T> $class
     * @return T
     */
    private function cause(\Throwable $e, string $class): \Throwable
    {
        for ($t = $e; $t !== null; $t = $t->getPrevious()) {
            if ($t instanceof $class) {
                return $t;
            }
        }
        $this->fail("Expected a {$class}, got " . $e::class . ': ' . $e->getMessage());
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}

/**
 * Hands the HTML on to mPDF and notes what it was given and which scaled
 * image copies were in the temp dir at that moment.
 */
final class CapturingEngine implements PdfEngine
{
    public string $html = '';

    /** @var list<array{path: string, file: string}> copies, with a copy of each kept for inspection */
    public array $scaledCopies = [];

    public function __construct(private readonly PdfEngine $inner, private readonly string $tempDir, private readonly bool $fail = false)
    {
    }

    public function render(string $html, PageSettings $page, string $headerHtml, string $footerHtml): string
    {
        $this->html = $html;
        foreach (glob($this->tempDir . '/*.jpg') ?: [] as $path) {
            $keep = $path . '.kept';
            copy($path, $keep);
            $this->scaledCopies[] = ['path' => $path, 'file' => $keep];
        }
        if ($this->fail) {
            throw new \RuntimeException('engine failed');
        }

        return $this->inner->render($html, $page, $headerHtml, $footerHtml);
    }
}
