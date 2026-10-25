<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Console;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\TestCase;
use Preflow\Media\Asset;
use Preflow\Media\Console\PreviewsCommand;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Preview\PdfPreviewRenderer;
use Preflow\Media\Tests\Support\MediaApp;

/** media:previews [--id=<id>] [--all]: previews for documents uploaded before previews existed. */
#[RequiresFunction('imagewebp')]
final class PreviewsCommandTest extends TestCase
{
    use MediaApp;

    private const string FIXTURES = __DIR__ . '/../Fixtures/pdf';

    protected function setUp(): void
    {
        $this->scaffoldMediaApp();
    }

    protected function tearDown(): void
    {
        $this->removeMediaApp();
    }

    private function requireRenderer(): void
    {
        if ((new PdfPreviewRenderer())->engine() === null) {
            $this->markTestSkipped('No PDF renderer (Ghostscript or pdftocairo) on this machine.');
        }
    }

    private function pdf(string $fixture, string $name, bool $private = false): Asset
    {
        $bytes = (string) file_get_contents(self::FIXTURES . '/' . $fixture);
        $f = new Psr17Factory();

        return $this->media()->upload($f->createUploadedFile($f->createStream($bytes), strlen($bytes), UPLOAD_ERR_OK, $name), null, $private);
    }

    /** As a document uploaded before previews existed: no preview, no variants, none of their files. */
    private function withoutPreview(Asset $asset): Asset
    {
        $base = $asset->private ? $this->dir . '/storage/private-media' : $this->dir . '/public/media';
        foreach ([$asset->preview?->path, ...array_map(static fn ($v) => $v->path, $asset->variants)] as $path) {
            if ($path !== null) {
                unlink($base . '/' . $path);
            }
        }
        (new \ReflectionMethod(MediaLibrary::class, 'save'))->invoke($this->media(), [...$asset->toData(), 'preview' => [], 'variants' => []]);

        return $this->media()->find($asset->id);
    }

    /**
     * @param list<string> $args
     * @return array{0: int, 1: string}
     */
    private function runCommand(array $args): array
    {
        ob_start();
        $code = (new PreviewsCommand(fn () => $this->mediaApp()))->execute($args);

        return [$code, (string) ob_get_clean()];
    }

    public function test_the_package_lists_the_command(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);

        $this->assertContains(PreviewsCommand::class, $composer['extra']['preflow']['commands'] ?? []);
        $this->assertSame('media:previews', (new PreviewsCommand())->getName());
    }

    public function test_documents_without_a_preview_get_one(): void
    {
        $this->requireRenderer();
        $public = $this->withoutPreview($this->pdf('rgb.pdf', 'public.pdf'));
        $private = $this->withoutPreview($this->pdf('rgb.pdf', 'private.pdf', true));
        $done = $this->pdf('rgb.pdf', 'done.pdf');
        $this->assertNull($public->preview);
        $this->assertNull($private->preview);
        $this->assertNotNull($done->preview);

        [$code, $out] = $this->runCommand([]);

        $this->assertSame(0, $code);
        $this->assertSame("Made previews of 2 documents.\n", $out);
        $public = $this->media()->find($public->id);
        $this->assertSame([1600, 1067], [$public->preview?->width, $public->preview?->height]);
        $this->assertFileExists($this->dir . '/public/media/' . $public->preview->path);
        $this->assertNotSame([], $public->variants);
        foreach ($public->variants as $v) {
            $this->assertFileExists($this->dir . '/public/media/' . $v->path);
        }
        // Private: in the private dir, no variants, nothing public.
        $private = $this->media()->find($private->id);
        $this->assertFileExists($this->dir . '/storage/private-media/' . $private->preview?->path);
        $this->assertSame([], $private->variants);
        $this->assertSame([], glob($this->dir . '/public/media/*/*/' . $private->id . '*') ?: []);
        // One that had a preview is left alone.
        $this->assertEquals($done, $this->media()->find($done->id));
    }

    public function test_id_and_all_make_previews_again(): void
    {
        $this->requireRenderer();
        $a = $this->pdf('rgb.pdf', 'a.pdf');
        $this->pdf('rgb.pdf', 'b.pdf', true);
        $stale = $this->dir . '/public/media/' . substr($a->path, 0, -4) . '-preview-640.webp'; // made on demand
        touch($stale);

        [, $one] = $this->runCommand(['--id=' . $a->id]);
        [, $all] = $this->runCommand(['--all']);

        $this->assertSame("Made previews of 1 document.\n", $one);
        $this->assertSame("Made previews of 2 documents.\n", $all);
        $this->assertFileDoesNotExist($stale);
        $this->assertFileExists($this->dir . '/public/media/' . $this->media()->find($a->id)?->preview?->path);
    }

    public function test_an_unreadable_document_is_reported(): void
    {
        $this->requireRenderer();
        $this->pdf('broken.pdf', 'broken.pdf');

        [$code, $out] = $this->runCommand([]);

        $this->assertSame(0, $code);
        $this->assertSame("Made previews of 0 documents.\n1 document could not be rendered (unreadable, encrypted or over the time limit).\n", $out);
    }

    public function test_without_a_renderer_nothing_is_made(): void
    {
        $this->writeFile('config/media.php', "<?php\nreturn ['ghostscript' => '/no/such/gs', 'pdftocairo' => '/no/such/pdftocairo'];\n");
        $asset = $this->pdf('rgb.pdf', 'a.pdf');

        [$code, $out] = $this->runCommand([]);

        $this->assertSame(0, $code);
        $this->assertSame("Made previews of 0 documents.\n1 document has no preview renderer (PDFs need Ghostscript or pdftocairo).\n", $out);
        $this->assertNull($this->media()->find($asset->id)?->preview);
    }
}
