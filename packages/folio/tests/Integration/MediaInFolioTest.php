<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Routing\RouterInterface;
use Preflow\Folio\FolioServiceProvider;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Media\Asset;
use Preflow\Media\MediaLibrary;
use Preflow\Media\MediaServiceProvider;

/**
 * A site with Folio and the media core, whichever order config/providers.php lists them in:
 * media is registered once, its routes are added once and come before Folio's frontend
 * catch-all; media_image() and folio_image() are the same image tag.
 */
#[RequiresFunction('imagewebp')]
final class MediaInFolioTest extends TestCase
{
    use PublishingScaffold;

    private const string VARIANTS = 'Preflow\\Media\\Http\\VariantController@serve';
    private const string PRIVATE = 'Preflow\\Media\\Http\\PrivateFileController@serve';
    private const string FRONTEND = 'Preflow\\Folio\\Http\\FrontendController@show';

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->write('config/image.php', "<?php\nreturn ['presets' => ['hero' => ['widths' => [480, 960], 'step' => 160, 'sizes' => '100vw']]];\n");
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    /** @return array<string, array{list<class-string>}> */
    public static function orders(): array
    {
        return [
            'media, folio' => [[MediaServiceProvider::class, FolioServiceProvider::class]],
            'folio, media' => [[FolioServiceProvider::class, MediaServiceProvider::class]],
            'folio only' => [[FolioServiceProvider::class]],
        ];
    }

    /** @param list<class-string> $providers */
    private function listProviders(array $providers): void
    {
        $this->write('config/providers.php', "<?php\nreturn [" . implode(', ', array_map(static fn (string $c): string => '\\' . $c . '::class', $providers)) . "];\n");
    }

    private function image(Application $app, int $width = 1000): Asset
    {
        $image = imagecreatetruecolor($width, 600);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        $f = new Psr17Factory();

        return $app->container()->get(MediaLibrary::class)->upload($f->createUploadedFile($f->createStream($png), strlen($png), UPLOAD_ERR_OK, 'a.png'), null);
    }

    private function handlerFor(Application $app, string $uri): string
    {
        return $app->container()->get(RouterInterface::class)->match((new Psr17Factory())->createServerRequest('GET', $uri))->handler;
    }

    /** @param list<class-string> $providers */
    #[DataProvider('orders')]
    public function test_media_routes_once_and_before_the_frontend(array $providers): void
    {
        $this->listProviders($providers);
        $app = $this->app();
        $handlers = array_map(static fn ($e) => $e->handler, $app->container()->get(RouterInterface::class)->getCollection()->all());

        $this->assertSame(1, count(array_keys($handlers, self::VARIANTS, true)));
        $this->assertSame(1, count(array_keys($handlers, self::PRIVATE, true)));
        $this->assertLessThan(array_search(self::FRONTEND, $handlers, true), array_search(self::VARIANTS, $handlers, true));
        $this->assertLessThan(array_search(self::FRONTEND, $handlers, true), array_search(self::PRIVATE, $handlers, true));

        $id = str_repeat('a', 32);
        $this->assertSame(self::VARIANTS, $this->handlerFor($app, '/media/2026/10/' . $id . '-480.webp'));
        $this->assertSame(self::PRIVATE, $this->handlerFor($app, '/files/' . $id));
        $this->assertSame(self::FRONTEND, $this->handlerFor($app, '/hello'));

        // Through the app: a step width is made, a private file is refused (no auth), a page renders.
        $asset = $this->image($app);
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'Hello page'], 'slug' => ['en' => 'hello'], 'cover' => $asset->id]), null);
        $variant = $this->get('/media/' . substr($asset->path, 0, (int) strrpos($asset->path, '.')) . '-640.webp');
        $this->assertSame(200, $variant->getStatusCode());
        $this->assertSame('image/webp', $variant->getHeaderLine('Content-Type'));
        $this->assertSame(403, $this->get('/files/' . $id)->getStatusCode());
        $page = $this->get('/hello');
        $this->assertSame(200, $page->getStatusCode());
        $this->assertStringContainsString('Hello page', (string) $page->getBody());
    }

    public function test_media_image_equals_folio_image_and_adds_the_head_once(): void
    {
        $this->write('resources/folio/frontend/post.twig', "<html><head><title>t</title></head><body>"
            . "<i>{{ folio_image(record.cover, 'hero', {priority: true}) }}</i>"
            . "<b>{{ media_image(record.cover, 'hero', {priority: true}) }}</b>"
            . "<u>{{ folio_asset_url(record.cover) }}|{{ media_url(record.cover) }}</u></body></html>");
        $app = $this->app();
        $asset = $this->image($app, 1200);
        $app->container()->get(MediaLibrary::class)->update($asset->id, ['focal_x' => 30, 'focal_y' => 70]);
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'T'], 'slug' => ['en' => 'hello'], 'cover' => $asset->id]), null);

        $body = (string) $this->get('/hello')->getBody();

        preg_match('#<i>(.*)</i><b>(.*)</b><u>(.*)\|(.*)</u>#s', $body, $m);
        $this->assertCount(5, $m, $body);
        $this->assertStringContainsString('folio-fx-30', $m[1]);
        $this->assertSame($m[1], $m[2]);
        $this->assertSame('/media/' . $asset->path, $m[3]);
        $this->assertSame($m[3], $m[4]);
        $head = substr($body, 0, (int) strpos($body, '</head>'));
        $this->assertSame(1, substr_count($head, 'rel="preload"'));
        $this->assertSame(1, substr_count($head, '.folio-fx-30{'));
    }
}
