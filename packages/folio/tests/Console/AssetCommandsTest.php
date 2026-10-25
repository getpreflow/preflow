<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Console;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\TestCase;
use Preflow\Media\MediaLibrary;
use Preflow\Folio\Console\AssetsImportCommand;
use Preflow\Folio\Console\AssetsVariantsCommand;
use Preflow\Folio\Console\FolioCommands;
use Preflow\Folio\Tests\Support\PublishingScaffold;

#[RequiresFunction('imagewebp')]
final class AssetCommandsTest extends TestCase
{
    use PublishingScaffold;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_folio_lists_the_asset_commands(): void
    {
        $this->assertContains(AssetsVariantsCommand::class, FolioCommands::all());
        $this->assertContains(AssetsImportCommand::class, FolioCommands::all());
    }

    public function test_variants_command_regenerates(): void
    {
        $f = new Psr17Factory();
        $png = $this->png();
        $asset = $this->lib()->upload($f->createUploadedFile($f->createStream($png), strlen($png), UPLOAD_ERR_OK, 'a.png'), null);
        $this->assertNotSame([], $asset->variants);
        foreach ($asset->variants as $v) {
            unlink($this->dir . '/public/media/' . $v->path);
        }

        [$code, $out] = $this->runCommand(new AssetsVariantsCommand(fn () => $this->app()));

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Regenerated variants of 1 image', $out);
        foreach ($this->lib()->find($asset->id)->variants as $v) {
            $this->assertFileExists($this->dir . '/public/media/' . $v->path);
        }
    }

    public function test_variants_all_also_makes_step_widths(): void
    {
        $this->write('config/image.php', "<?php\nreturn ['presets' => ['card' => ['widths' => [480, 960], 'step' => 160]]];\n");
        $f = new Psr17Factory();
        $png = $this->png();
        $asset = $this->lib()->upload($f->createUploadedFile($f->createStream($png), strlen($png), UPLOAD_ERR_OK, 'a.png'), null);
        $base = $this->dir . '/public/media/' . substr($asset->path, 0, (int) strrpos($asset->path, '.'));
        $this->assertFileDoesNotExist($base . '-640.webp');

        [$code, $out] = $this->runCommand(new AssetsVariantsCommand(fn () => $this->app()), ['--all']);

        $this->assertSame(0, $code);
        $this->assertFileExists($base . '-640.webp');
        $this->assertFileExists($base . '-800.webp');
        $this->assertStringContainsString('step-width', $out);
    }

    public function test_import_rewrites_working_snapshot_and_revision(): void
    {
        $legacy = $this->legacyFile();
        $this->publisher()->publish($this->working('post', 'p1', [
            'title' => ['en' => 'T'], 'slug' => ['en' => 'hello'], 'cover' => $legacy,
            'sections' => [['_uid' => 'aaaaaaaaaaaaaaaa', '_block' => 'hero', 'headline' => ['en' => 'H'], 'tradeoff' => [
                ['_uid' => 'bbbbbbbbbbbbbbbb', '_block' => 'tradeoff_note', 'chose' => ['en' => 'x'], 'cover' => $legacy],
            ]]],
        ]), null);

        [$code, $out] = $this->runCommand(new AssetsImportCommand(fn () => $this->app()));

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Imported 1 file, rewrote 3 records, 0 missing', $out);
        $assets = $this->lib()->all();
        $this->assertCount(1, $assets);
        $id = $assets[0]->id;
        $working = $this->stored('post', 'p1');
        $this->assertSame($id, $working->get('cover'));
        $this->assertSame($id, $working->get('sections')[0]['tradeoff'][0]['cover']);
        $this->assertSame($id, $this->publisher()->snapshot('post', 'p1')?->get('cover'));
        $this->assertSame($id, $this->publisher()->revisions('post', 'p1')[0]->data['cover']);
        $this->assertSame(\Preflow\Folio\Publishing\PublishState::Published, $this->publisher()->state($working));
    }

    public function test_import_is_idempotent_and_reports_missing(): void
    {
        $missing = '2026/10/' . str_repeat('9', 32) . '.png';
        $this->working('post', 'p1', ['title' => ['en' => 'A'], 'slug' => ['en' => 'a'], 'cover' => $this->legacyFile()]);
        $this->working('post', 'p2', ['title' => ['en' => 'B'], 'slug' => ['en' => 'b'], 'cover' => $missing]);

        $this->runCommand(new AssetsImportCommand(fn () => $this->app()));
        [$code, $out] = $this->runCommand(new AssetsImportCommand(fn () => $this->app()));

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Imported 0 files, rewrote 0 records, 1 missing', $out);
        $this->assertStringContainsString('Missing: ' . $missing, $out);
        $this->assertSame($missing, $this->stored('post', 'p2')->get('cover'));
        $this->assertCount(1, $this->lib()->all());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $legacy = $this->legacyFile();
        $this->working('post', 'p1', ['title' => ['en' => 'A'], 'slug' => ['en' => 'a'], 'cover' => $legacy]);

        [$code, $out] = $this->runCommand(new AssetsImportCommand(fn () => $this->app()), ['--dry-run']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Would import 1 file, rewrite 1 record, 0 missing', $out);
        $this->assertSame([], $this->lib()->all());
        $this->assertSame($legacy, $this->stored('post', 'p1')->get('cover'));
    }

    private function legacyFile(): string
    {
        $path = '2026/10/' . str_repeat('e', 32) . '.png';
        @mkdir($this->dir . '/storage/uploads/2026/10', 0777, true);
        file_put_contents($this->dir . '/storage/uploads/' . $path, $this->png());

        return $path;
    }

    private function lib(): MediaLibrary
    {
        return $this->app()->container()->get(MediaLibrary::class);
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(900, 600);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * @param list<string> $args
     * @return array{0: int, 1: string}
     */
    private function runCommand(\Preflow\DevTools\Command\CommandInterface $command, array $args = []): array
    {
        ob_start();
        $code = $command->execute($args);

        return [$code, (string) ob_get_clean()];
    }
}
