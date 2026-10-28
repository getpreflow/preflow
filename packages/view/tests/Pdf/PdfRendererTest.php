<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Pdf;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Blade\BladeEngine;
use Preflow\Twig\TwigEngine;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;
use Preflow\View\Pdf\MpdfEngine;
use Preflow\View\Pdf\PageSettings;
use Preflow\View\Pdf\PdfConfig;
use Preflow\View\Pdf\PdfDocument;
use Preflow\View\Pdf\PdfRenderer;
use Preflow\View\Pdf\PdfResponse;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\Tests\Pdf\Fixtures\CertificateDocument;
use Smalot\PdfParser\Parser;

require_once __DIR__ . '/Fixtures/CertificateDocument.php';

final class PdfRendererTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/preflow_pdf_test_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/blade', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
    }

    /** @return array<string, array{string}> */
    public static function engines(): array
    {
        return ['twig' => ['twig'], 'blade' => ['blade.php']];
    }

    #[DataProvider('engines')]
    public function test_renders_a_template_to_pdf_bytes(string $extension): void
    {
        $bytes = $this->renderer($extension)->render(new CertificateDocument('Ada', $extension));

        $this->assertStringStartsWith('%PDF', $bytes);
    }

    #[DataProvider('engines')]
    public function test_text_is_extractable(string $extension): void
    {
        $bytes = $this->renderer($extension)->render(new CertificateDocument('Ada Lovelace', $extension));

        $text = (new Parser())->parseContent($bytes)->getText();

        $this->assertStringContainsString('Awarded to Ada Lovelace', $text);
        // resolveState() ran before the template saw the properties.
        $this->assertStringContainsString('Brass: Birmingham', $text);
    }

    public function test_page_settings_apply(): void
    {
        $a5 = new CertificateDocument('Ada', 'twig', new PageSettings(format: 'A5', orientation: 'L'));
        [$width, $height] = $this->pageSize($this->renderer('twig')->render($a5));

        // A5 is 148 x 210 mm; landscape puts the long side across.
        $this->assertEqualsWithDelta(210 / 25.4 * 72, $width, 0.5);
        $this->assertEqualsWithDelta(148 / 25.4 * 72, $height, 0.5);

        $custom = new CertificateDocument('Ada', 'twig', new PageSettings(width: 100, height: 50));
        [$width, $height] = $this->pageSize($this->renderer('twig')->render($custom));

        $this->assertEqualsWithDelta(100 / 25.4 * 72, $width, 0.5);
        $this->assertEqualsWithDelta(50 / 25.4 * 72, $height, 0.5);
    }

    #[DataProvider('engines')]
    public function test_header_and_footer_templates(string $extension): void
    {
        $probe = new CertificateDocument('Ada', $extension);
        $doc = new CertificateDocument('Ada', $extension, new PageSettings(
            headerTemplate: $probe->fixture('header'),
            footerTemplate: $probe->fixture('footer'),
        ));

        $text = (new Parser())->parseContent($this->renderer($extension)->render($doc))->getText();

        $this->assertStringContainsString('Header for Ada', $text);
        $this->assertStringContainsString('Footer page 1 of 1', $text);
    }

    #[DataProvider('engines')]
    public function test_html_preview_renders_without_engine(string $extension): void
    {
        $probe = new CertificateDocument('Ada', $extension);
        $doc = new CertificateDocument('Ada', $extension, new PageSettings(
            headerTemplate: $probe->fixture('header'),
        ));
        $renderer = new PdfRenderer($this->templates($extension), null, new PdfConfig($this->dir . '/pdf'));

        $html = $renderer->html($doc);

        $this->assertStringStartsWith('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('<meta charset="utf-8">', $html);
        $this->assertStringContainsString('h1 { color: #b0306a;', $html);
        $this->assertStringContainsString('Awarded to Ada', $html);
        $this->assertStringContainsString('Brass: Birmingham', $html);
        $this->assertStringContainsString('Header for Ada', $html);
        $this->assertStringEndsWith("</body>\n</html>\n", $html);
        $this->assertDirectoryDoesNotExist($this->dir . '/pdf');
    }

    #[RunInSeparateProcess]
    public function test_missing_mpdf_names_the_package(): void
    {
        // Hide the Mpdf namespace from every autoloader, as if mPDF were not installed.
        $loaders = spl_autoload_functions();
        foreach ($loaders as $loader) {
            spl_autoload_unregister($loader);
        }
        spl_autoload_register(static function (string $class) use ($loaders): void {
            if (str_starts_with($class, 'Mpdf\\')) {
                return;
            }
            foreach ($loaders as $loader) {
                $loader($class);
                if (class_exists($class, false) || interface_exists($class, false) || enum_exists($class, false)) {
                    return;
                }
            }
        });

        $this->assertFalse(MpdfEngine::available());
        $renderer = new PdfRenderer($this->templates('twig'), null, new PdfConfig($this->dir . '/pdf'));
        $doc = new CertificateDocument('Ada');

        // The preview needs no PDF library.
        $this->assertStringContainsString('Awarded to Ada', $renderer->html($doc));

        foreach ([fn () => $renderer->render($doc), fn () => new MpdfEngine(new PdfConfig($this->dir . '/pdf'))] as $attempt) {
            try {
                $attempt();
                $this->fail('Rendering without mPDF should have thrown');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('composer require mpdf/mpdf', $e->getMessage());
            }
        }
        $this->assertFalse(class_exists(\Mpdf\Mpdf::class, false));
    }

    public function test_temp_files_go_to_temp_dir(): void
    {
        $tempDir = $this->dir . '/storage/cache/pdf';
        $this->assertDirectoryDoesNotExist($tempDir);

        $this->renderer('twig', $tempDir)->render(new CertificateDocument('Ada'));

        $this->assertDirectoryExists($tempDir);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tempDir, \FilesystemIterator::SKIP_DOTS));
        $this->assertNotSame(0, iterator_count($files), 'mPDF wrote its font cache into the temp dir');
    }

    public function test_configured_fonts_are_embedded_by_name(): void
    {
        $font = $this->dir . '/fonts/PartyFont.ttf';
        mkdir(dirname($font));
        copy(dirname((new \ReflectionClass(\Mpdf\Mpdf::class))->getFileName(), 2) . '/ttfonts/DejaVuSerif.ttf', $font);
        file_put_contents($this->dir . '/fonts.twig', '<p style="font-family: \'Party Font\'">Hello {{ name }}</p>');
        $doc = new class ($this->dir . '/fonts.twig') extends PdfDocument {
            public string $name = 'Ada';

            public function __construct(private readonly string $file)
            {
            }

            public function template(): string
            {
                return $this->file;
            }
        };
        $config = new PdfConfig($this->dir . '/pdf', ['Party Font' => $font]);

        $pdf = (new Parser())->parseContent((new PdfRenderer($this->templates('twig'), null, $config))->render($doc));

        $this->assertStringContainsString('Hello Ada', $pdf->getText());
        $fonts = array_map(static fn ($f): string => (string) $f->getDetails()['BaseFont'], $pdf->getFonts());
        $this->assertNotEmpty(array_filter($fonts, static fn (string $name): bool => str_contains($name, 'DejaVuSerif')));
    }

    public function test_page_settings_refuse_unknown_orientation_and_half_sizes(): void
    {
        foreach ([fn () => new PageSettings(orientation: 'X'), fn () => new PageSettings(width: 100)] as $attempt) {
            try {
                $attempt();
                $this->fail('Invalid page settings should have thrown');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_responses_send_the_bytes_inline_or_as_download(): void
    {
        $inline = PdfResponse::inline('%PDF-1.4 x', 'certificate.pdf');
        $this->assertSame(200, $inline->getStatusCode());
        $this->assertSame('application/pdf', $inline->getHeaderLine('Content-Type'));
        $this->assertSame('10', $inline->getHeaderLine('Content-Length'));
        $this->assertStringStartsWith('inline; filename="certificate.pdf"', $inline->getHeaderLine('Content-Disposition'));
        $this->assertSame('%PDF-1.4 x', (string) $inline->getBody());

        $download = PdfResponse::download('%PDF', "Zertifikat \"Ä\"\r\n.pdf");
        $disposition = $download->getHeaderLine('Content-Disposition');
        $this->assertStringStartsWith('attachment; filename="Zertifikat ___.pdf"', $disposition);
        $this->assertStringContainsString("filename*=UTF-8''Zertifikat%20%22%C3%84%22.pdf", $disposition);
    }

    private function renderer(string $extension, ?string $tempDir = null): PdfRenderer
    {
        $config = new PdfConfig($tempDir ?? $this->dir . '/pdf');

        return new PdfRenderer($this->templates($extension), new MpdfEngine($config), $config);
    }

    private function templates(string $extension): TemplateEngineInterface
    {
        $assets = new AssetCollector(new NonceGenerator());

        return $extension === 'twig'
            ? new TwigEngine([$this->dir], $assets)
            : new BladeEngine(templateDirs: [$this->dir], assetCollector: $assets, cachePath: $this->dir . '/blade');
    }

    /** @return array{float, float} the first page's width and height in points */
    private function pageSize(string $bytes): array
    {
        $box = (new Parser())->parseContent($bytes)->getPages()[0]->getDetails()['MediaBox'];

        return [(float) $box[2] - (float) $box[0], (float) $box[3] - (float) $box[1]];
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
