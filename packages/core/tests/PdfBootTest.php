<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

require_once __DIR__ . '/Fixtures/PdfEngineProvider.php';

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Tests\Fixtures\PdfEngineProvider;
use Preflow\View\Pdf\MpdfEngine;
use Preflow\View\Pdf\PdfConfig;
use Preflow\View\Pdf\PdfDocument;
use Preflow\View\Pdf\PdfEngine;
use Preflow\View\Pdf\PdfRenderer;

/** The Application binds the PDF renderer, mPDF as its engine, and reads view.pdf from config/view.php. */
final class PdfBootTest extends TestCase
{
    #[RunInSeparateProcess]
    public function test_renderer_and_mpdf_engine_are_bound_with_default_config(): void
    {
        $dir = $this->app();
        $app = Application::create($dir);
        $app->boot();
        $container = $app->container();

        $config = $container->get(PdfConfig::class);
        $this->assertSame($dir . '/storage/cache/pdf', $config->tempDir);
        $this->assertSame([], $config->fonts);
        $this->assertSame('dejavusanscondensed', $config->defaultFont);
        $this->assertInstanceOf(MpdfEngine::class, $container->get(PdfEngine::class));

        $bytes = $container->get(PdfRenderer::class)->render($this->document($dir));
        $this->assertStringStartsWith('%PDF', $bytes);
        // The building blocks are registered: pdf_page_break() made a second page.
        $this->assertCount(2, (new \Smalot\PdfParser\Parser())->parseContent($bytes)->getPages());
        $this->assertDirectoryExists($dir . '/storage/cache/pdf');
    }

    #[RunInSeparateProcess]
    public function test_view_config_sets_temp_dir_fonts_and_default_font(): void
    {
        $dir = $this->app(view: "['pdf' => ['temp_dir' => 'storage/tmp/pdf', 'fonts' => ['Party' => 'fonts/party.ttf', 'Abs' => '/srv/abs.ttf'], 'default_font' => 'dejavuserif']]");
        $app = Application::create($dir);
        $app->boot();

        $config = $app->container()->get(PdfConfig::class);
        $this->assertSame($dir . '/storage/tmp/pdf', $config->tempDir);
        $this->assertSame(['Party' => $dir . '/fonts/party.ttf', 'Abs' => '/srv/abs.ttf'], $config->fonts);
        $this->assertSame('dejavuserif', $config->defaultFont);
    }

    #[RunInSeparateProcess]
    public function test_a_provider_can_bind_its_own_engine(): void
    {
        $dir = $this->app(providers: true);
        $app = Application::create($dir);
        $app->boot();

        $bytes = $app->container()->get(PdfRenderer::class)->render($this->document($dir));

        $this->assertStringStartsWith('custom:', $bytes);
        $this->assertStringContainsString('Hello Ada', $bytes);
    }

    private function document(string $dir): PdfDocument
    {
        file_put_contents($dir . '/hello.twig', '<p>Hello {{ name }}</p>{{ pdf_page_break() }}<p>Bye</p>');

        return new class ($dir . '/hello.twig') extends PdfDocument {
            public string $name = 'Ada';

            public function __construct(private readonly string $file)
            {
            }

            public function template(): string
            {
                return $this->file;
            }
        };
    }

    private function app(?string $view = null, bool $providers = false): string
    {
        $dir = sys_get_temp_dir() . '/pdfboot_' . bin2hex(random_bytes(4));
        mkdir($dir . '/config', 0777, true);
        file_put_contents($dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'k'];\n");
        file_put_contents($dir . '/config/data.php', "<?php\nreturn ['default'=>'json','drivers'=>['json'=>['path'=>'{$dir}/storage/data']]];\n");
        if ($view !== null) {
            file_put_contents($dir . '/config/view.php', "<?php\nreturn {$view};\n");
        }
        if ($providers) {
            file_put_contents($dir . '/config/providers.php', "<?php\nreturn [\\" . PdfEngineProvider::class . "::class];\n");
        }

        return $dir;
    }
}
