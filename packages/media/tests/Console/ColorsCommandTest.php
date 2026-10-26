<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Console;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\TestCase;
use Preflow\Media\Asset;
use Preflow\Media\Console\ColorsCommand;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Tests\Support\MediaApp;

/** media:colors [--all] [--id=<id>]: colours for assets uploaded before colours existed. */
#[RequiresFunction('imagewebp')]
final class ColorsCommandTest extends TestCase
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

    private function image(array $rgb, string $name): Asset
    {
        $image = imagecreatetruecolor(300, 200);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, ...$rgb));
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        $f = new Psr17Factory();

        return $this->media()->upload($f->createUploadedFile($f->createStream($bytes), strlen($bytes), UPLOAD_ERR_OK, $name), null);
    }

    /** Stored as given (an asset from before colours existed: none). */
    private function withColors(Asset $asset, string $color = '', array $palette = [], string $text = ''): Asset
    {
        (new \ReflectionMethod(MediaLibrary::class, 'save'))->invoke($this->media(), [...$asset->toData(), 'color' => $color, 'palette' => $palette, 'text_color' => $text]);

        return $this->media()->find($asset->id);
    }

    /** @return array{0: int, 1: string, 2: string} exit code, stdout, stderr */
    private function runCommand(array $args): array
    {
        $stderr = fopen('php://memory', 'w+');
        ob_start();
        $code = (new ColorsCommand(fn () => $this->mediaApp(), $stderr))->execute($args);
        $out = (string) ob_get_clean();
        rewind($stderr);

        return [$code, $out, (string) stream_get_contents($stderr)];
    }

    public function test_the_package_lists_the_command(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);

        $this->assertContains(ColorsCommand::class, $composer['extra']['preflow']['commands'] ?? []);
        $this->assertSame('media:colors', (new ColorsCommand())->getName());
    }

    public function test_all_fills_assets_without_colours_and_leaves_the_others(): void
    {
        $blue = $this->withColors($this->image([25, 118, 210], 'blue.png'));
        $amber = $this->withColors($this->image([255, 193, 7], 'amber.png'));
        $done = $this->withColors($this->image([25, 118, 210], 'done.png'), '#123456', ['#123456', '#000000', '#ffffff', '#ff0000', '#00ff00'], '#ffffff');
        $this->media()->upload((new Psr17Factory())->createUploadedFile((new Psr17Factory())->createStream('plain text'), 10, UPLOAD_ERR_OK, 'a.txt'), null);
        $this->assertNull($blue->color);

        [$code, $out] = $this->runCommand(['--all']);

        $this->assertSame(0, $code);
        $this->assertSame("Extracted the colours of 2 assets.\n", $out);
        $this->assertSame('#1976d2', $this->media()->find($blue->id)?->color);
        $this->assertSame('#ffffff', $this->media()->find($blue->id)?->textColor);
        $this->assertSame('#ffc107', $this->media()->find($amber->id)?->color);
        $this->assertSame('#000000', $this->media()->find($amber->id)?->textColor);
        $this->assertSame(['#ffc107'], $this->media()->find($amber->id)?->palette);
        $this->assertEquals($done, $this->media()->find($done->id));
    }

    public function test_id_extracts_that_assets_colours_again(): void
    {
        $done = $this->withColors($this->image([25, 118, 210], 'done.png'), '#123456', ['#123456', '#000000', '#ffffff', '#ff0000', '#00ff00'], '#ffffff');
        $other = $this->withColors($this->image([255, 193, 7], 'other.png'));

        [$code, $out] = $this->runCommand(['--id=' . $done->id]);

        $this->assertSame(0, $code);
        $this->assertSame("Extracted the colours of 1 asset.\n", $out);
        $this->assertSame('#1976d2', $this->media()->find($done->id)?->color);
        $this->assertNull($this->media()->find($other->id)?->color);
    }

    public function test_id_of_an_unknown_asset_or_one_without_an_image_fails(): void
    {
        $text = $this->media()->upload((new Psr17Factory())->createUploadedFile((new Psr17Factory())->createStream('plain text'), 10, UPLOAD_ERR_OK, 'a.txt'), null);
        $unknown = str_repeat('0', 32);

        [$code, $out, $err] = $this->runCommand(['--id=' . $unknown]);
        $this->assertSame([1, '', "Asset {$unknown} not found.\n"], [$code, $out, $err]);

        [$code, $out, $err] = $this->runCommand(['--id=' . $text->id]);
        $this->assertSame([1, '', "Asset {$text->id} has no image to read colours from.\n"], [$code, $out, $err]);
    }

    public function test_without_options_it_says_how_to_use_it(): void
    {
        [$code, $out] = $this->runCommand([]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('--all', $out);
    }

    public function test_an_unreadable_image_is_reported(): void
    {
        $asset = $this->withColors($this->image([25, 118, 210], 'a.png'));
        file_put_contents($this->dir . '/public/media/' . $asset->path, 'broken');
        $log = $this->dir . '/error.log';
        $previous = ini_set('error_log', $log);
        try {
            [$code, $out] = $this->runCommand(['--all']);
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $this->assertStringContainsString("preflow/media: no colours of asset {$asset->id}", (string) @file_get_contents($log));
        $this->assertSame(0, $code);
        $this->assertSame("Extracted the colours of 0 assets.\n1 asset could not be read (unreadable, or it needs Imagick for its colour profile).\n", $out);
    }

    public function test_limit_processes_a_chunk_and_a_second_run_resumes(): void
    {
        $assets = [];
        foreach ([[25, 118, 210], [255, 193, 7], [200, 30, 30]] as $i => $rgb) {
            $assets[] = $this->withColors($this->image($rgb, "{$i}.png"));
        }

        [$code, $out] = $this->runCommand(['--all', '--limit=2']);
        $this->assertSame([0, "Extracted the colours of 2 assets.\n1 asset left: run again with --all --limit=2.\n"], [$code, $out]);

        [$code, $out] = $this->runCommand(['--all', '--limit=2']);
        $this->assertSame([0, "Extracted the colours of 1 asset.\n"], [$code, $out]);
        foreach ($assets as $asset) {
            $this->assertNotNull($this->media()->find($asset->id)?->color);
        }
    }

    public function test_a_limit_that_is_not_a_positive_number_fails(): void
    {
        foreach (['--limit=0', '--limit=x', '--limit='] as $limit) {
            [$code, $out, $err] = $this->runCommand(['--all', $limit]);
            $this->assertSame([1, '', "--limit needs a positive number.\n"], [$code, $out, $err], $limit);
        }
    }

    public function test_the_next_chunk_skips_assets_that_could_not_be_read(): void
    {
        $this->withColors($this->image([25, 118, 210], 'a.png'));
        $this->withColors($this->image([255, 193, 7], 'b.png'));
        [$first, $second] = $this->media()->all(); // the order the command goes in
        file_put_contents($this->dir . '/public/media/' . $first->path, 'broken');
        $log = ini_set('error_log', $this->dir . '/error.log');
        try {
            [, $out] = $this->runCommand(['--all', '--limit=1']);
        } finally {
            ini_set('error_log', (string) $log);
        }
        $this->assertSame("Extracted the colours of 0 assets.\n1 asset could not be read (unreadable, or it needs Imagick for its colour profile).\n1 asset left: run again with --all --limit=1 --offset=1.\n", $out);

        [, $out] = $this->runCommand(['--all', '--limit=1', '--offset=1']);
        $this->assertSame("Extracted the colours of 1 asset.\n", $out);
        $this->assertNotNull($this->media()->find($second->id)?->color);
    }
}
