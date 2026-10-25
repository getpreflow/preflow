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

    /** @return array{0: int, 1: string} */
    private function runCommand(array $args): array
    {
        ob_start();
        $code = (new ColorsCommand(fn () => $this->mediaApp()))->execute($args);

        return [$code, (string) ob_get_clean()];
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
        $this->assertGreaterThanOrEqual(5, count($this->media()->find($amber->id)?->palette ?? []));
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

        [$code, $out] = $this->runCommand(['--all']);

        $this->assertSame(0, $code);
        $this->assertSame("Extracted the colours of 0 assets.\n1 asset could not be read (unreadable, or it needs Imagick for its colour profile).\n", $out);
    }
}
