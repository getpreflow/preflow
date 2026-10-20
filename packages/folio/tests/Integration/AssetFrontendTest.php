<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Assets\AssetLibrary;
use Preflow\Folio\Tests\Support\PublishingScaffold;

#[RequiresFunction('imagewebp')]
final class AssetFrontendTest extends TestCase
{
    use PublishingScaffold;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->write('resources/folio/frontend/post.twig', '<html><head><title>t</title></head><body>{{ folio_image(record.cover) }}</body></html>');
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_folio_image_focal_css_reaches_the_page_head(): void
    {
        $library = $this->app()->container()->get(AssetLibrary::class);
        $image = imagecreatetruecolor(600, 300);
        ob_start();
        imagepng($image);
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $bytes = (string) ob_get_clean();
        $asset = $library->upload($f->createUploadedFile($f->createStream($bytes), strlen($bytes), UPLOAD_ERR_OK, 'a.png'), null);
        $library->update($asset->id, ['focal_x' => 30, 'focal_y' => 70]);
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'T'], 'slug' => ['en' => 'hello'], 'cover' => $asset->id]), null);

        $body = (string) $this->get('/hello')->getBody();

        $this->assertStringContainsString('folio-fx-30', $body);
        $head = substr($body, 0, (int) strpos($body, '</head>'));
        $this->assertMatchesRegularExpression('/<style nonce="[^"]+">[^<]*\.folio-fx-30\{--folio-fx:30%\}/', $head);
        $this->assertFileExists($this->dir . '/public/media/' . $asset->path);
    }

    public function test_step_widths_are_made_on_first_request_and_pages_still_resolve(): void
    {
        $this->write('config/image.php', "<?php\nreturn ['presets' => ['card' => ['widths' => [480, 960], 'step' => 160]]];\n");
        $library = $this->app()->container()->get(AssetLibrary::class);
        $image = imagecreatetruecolor(1000, 600);
        ob_start();
        imagepng($image);
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $bytes = (string) ob_get_clean();
        $asset = $library->upload($f->createUploadedFile($f->createStream($bytes), strlen($bytes), UPLOAD_ERR_OK, 'a.png'), null);
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'T'], 'slug' => ['en' => 'hello'], 'cover' => $asset->id]), null);
        $variant = substr($asset->path, 0, (int) strrpos($asset->path, '.')) . '-640.webp';

        $res = $this->get('/media/' . $variant);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('image/webp', $res->getHeaderLine('Content-Type'));
        $this->assertFileExists($this->dir . '/public/media/' . $variant);
        $this->assertSame(200, $this->get('/hello')->getStatusCode());      // the frontend catch-all still serves pages
        $this->assertSame(404, $this->get('/media/notes.txt')->getStatusCode());
        $this->assertSame(404, $this->get('/media/' . substr($variant, 0, -9) . '-700.webp')->getStatusCode());
    }
}
