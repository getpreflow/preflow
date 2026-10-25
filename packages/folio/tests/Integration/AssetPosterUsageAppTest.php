<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Media\MediaLibrary;
use Psr\Http\Message\UploadedFileInterface;

/** The core's poster usage on Folio's library screen: the same row and refusal as before the move. */
#[RequiresPhpExtension('gd')]
final class AssetPosterUsageAppTest extends TestCase
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

    public function test_poster_usage_row_and_delete_refusal(): void
    {
        $lib = $this->app()->container()->get(MediaLibrary::class);
        $poster = $lib->upload($this->file($this->png(), 'poster.png'), null);
        $video = $lib->upload($this->file("notes\n", 'clip.txt'), null);
        $lib->update($video->id, ['poster' => $poster->id]);
        $row = '<li><a href="/folio/assets/' . $video->id . '">clip.txt</a> (as poster)</li>';

        $panel = (string) $this->get('/folio/assets/' . $poster->id)->getBody();
        $this->assertStringContainsString('<p>Used in 1 record:</p>', $panel);
        $this->assertStringContainsString('<ul>' . $row . '</ul>', $panel);

        $res = $this->post('/folio/assets/' . $poster->id . '/delete');
        $this->assertSame(409, $res->getStatusCode());
        $body = (string) $res->getBody();
        $this->assertStringContainsString('Used in 1 record — remove it there first.', $body);
        $this->assertStringContainsString($row, $body);
        $this->assertNotNull($this->app()->container()->get(MediaLibrary::class)->find($poster->id));
    }

    private function file(string $bytes, string $name): UploadedFileInterface
    {
        $f = new Psr17Factory();

        return $f->createUploadedFile($f->createStream($bytes), strlen($bytes), UPLOAD_ERR_OK, $name);
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(20, 10);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
