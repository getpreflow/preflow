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
        // Another asset uses it: the library's tile and label ("Assets"), the note after the name.
        $row = self::row('/folio/assets/' . $video->id, 'asset', 'clip.txt', '(as poster)', 'Assets');

        $panel = (string) $this->get('/folio/assets/' . $poster->id)->getBody();
        $this->assertStringContainsString('<h2 class="folio-asset-block-title">Used in · 1</h2>', $panel);
        $this->assertMatchesRegularExpression('#<ul class="folio-asset-usage-list">\s*' . $row . '\s*</ul>#s', $panel);

        $res = $this->post('/folio/assets/' . $poster->id . '/delete');
        $this->assertSame(409, $res->getStatusCode());
        $body = (string) $res->getBody();
        $this->assertStringContainsString('Used in 1 record — remove it there first.', $body);
        $this->assertMatchesRegularExpression('#' . $row . '#s', $body);
        $this->assertNotNull($this->app()->container()->get(MediaLibrary::class)->find($poster->id));
    }

    public function test_record_rows_list_before_poster_rows(): void
    {
        $lib = $this->app()->container()->get(MediaLibrary::class);
        $poster = $lib->upload($this->file($this->png(), 'poster.png'), null);
        $video = $lib->upload($this->file("notes\n", 'clip.txt'), null);
        $lib->update($video->id, ['poster' => $poster->id]);
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello'], 'cover' => $poster->id]);

        $panel = (string) $this->get('/folio/assets/' . $poster->id)->getBody();

        $this->assertStringContainsString('<h2 class="folio-asset-block-title">Used in · 2</h2>', $panel);
        $this->assertMatchesRegularExpression('#<ul class="folio-asset-usage-list">\s*' . self::row('/folio/post/p1/edit', 'page', 'Hello', null, 'Posts')
            . '\s*' . self::row('/folio/assets/' . $video->id, 'asset', 'clip.txt', '(as poster)', 'Assets') . '\s*</ul>#s', $panel);
    }

    /** A "Used in" row as a regex: the link, the type's tile (its family), the name with its note, the type's label. */
    private static function row(string $href, string $family, string $label, ?string $note, string $type): string
    {
        return '<li class="folio-asset-use is-blocking"><a class="folio-asset-use-row" href="' . preg_quote($href, '#') . '">'
            . '<span class="folio-type-icon" data-family="' . $family . '" aria-hidden="true">.*?</span>\s*'
            . '<span class="folio-asset-use-label">' . preg_quote($label, '#') . ($note !== null ? ' <span class="folio-asset-use-note">' . preg_quote($note, '#') . '</span>' : '') . '</span>\s*'
            . '<span class="folio-asset-use-type">' . preg_quote($type, '#') . '</span></a>\s*</li>';
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
