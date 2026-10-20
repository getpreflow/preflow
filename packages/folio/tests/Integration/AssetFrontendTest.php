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
}
