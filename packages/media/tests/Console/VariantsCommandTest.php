<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Console;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\TestCase;
use Preflow\Media\Console\VariantsCommand;
use Preflow\Media\Tests\Support\MediaApp;

/** media:variants [--id=<id>] [--all] (moved from Folio's AssetCommandsTest). */
#[RequiresFunction('imagewebp')]
final class VariantsCommandTest extends TestCase
{
    use MediaApp;

    protected function setUp(): void
    {
        $this->scaffoldMediaApp();
    }

    protected function tearDown(): void
    {
        $this->removeMediaApp();
    }

    public function test_the_package_lists_the_command(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);

        $this->assertSame([VariantsCommand::class], $composer['extra']['preflow']['commands'] ?? null);
        $this->assertSame('media:variants', (new VariantsCommand())->getName());
    }

    public function test_variants_command_regenerates(): void
    {
        $asset = $this->media()->upload($this->pngUpload(), null);
        $this->assertNotSame([], $asset->variants);
        foreach ($asset->variants as $v) {
            unlink($this->dir . '/public/media/' . $v->path);
        }

        [$code, $out] = $this->runCommand([]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Regenerated variants of 1 image', $out);
        foreach ($this->media()->find($asset->id)->variants as $v) {
            $this->assertFileExists($this->dir . '/public/media/' . $v->path);
        }
    }

    public function test_variants_all_also_makes_step_widths(): void
    {
        $this->writeFile('config/image.php', "<?php\nreturn ['presets' => ['card' => ['widths' => [480, 960], 'step' => 160]]];\n");
        $asset = $this->media()->upload($this->pngUpload(), null);
        $base = $this->dir . '/public/media/' . substr($asset->path, 0, (int) strrpos($asset->path, '.'));
        $this->assertFileDoesNotExist($base . '-640.webp');

        [$code, $out] = $this->runCommand(['--all']);

        $this->assertSame(0, $code);
        $this->assertFileExists($base . '-640.webp');
        $this->assertFileExists($base . '-800.webp');
        $this->assertStringContainsString('step-width', $out);
    }

    private function pngUpload(): \Psr\Http\Message\UploadedFileInterface
    {
        $image = imagecreatetruecolor(900, 600);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        $f = new Psr17Factory();

        return $f->createUploadedFile($f->createStream($png), strlen($png), UPLOAD_ERR_OK, 'a.png');
    }

    /**
     * @param list<string> $args
     * @return array{0: int, 1: string}
     */
    private function runCommand(array $args): array
    {
        ob_start();
        $code = (new VariantsCommand(fn () => $this->mediaApp()))->execute($args);

        return [$code, (string) ob_get_clean()];
    }
}
