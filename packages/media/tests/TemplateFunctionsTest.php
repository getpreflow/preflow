<?php

declare(strict_types=1);

namespace Preflow\Media\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\TestCase;
use Preflow\Media\ImageTag;
use Preflow\Media\Tests\Support\MediaApp;
use Preflow\View\AssetCollector;
use Preflow\View\TemplateEngineInterface;

/** media_image() and media_url() on a site without Folio: the ImageTag service's output. */
#[RequiresFunction('imagewebp')]
final class TemplateFunctionsTest extends TestCase
{
    use MediaApp;

    protected function setUp(): void
    {
        $this->scaffoldMediaApp();
        $this->writeFile('config/image.php', "<?php\nreturn ['presets' => ['hero' => ['widths' => [480, 960], 'sizes' => '100vw']]];\n");
        $this->writeFile('page.twig', "{{ media_image(id, 'hero', {priority: true}) }}|{{ media_url(id) }}|{{ media_url(null) }}");
    }

    protected function tearDown(): void
    {
        $this->removeMediaApp();
    }

    public function test_media_image_and_media_url_render_through_the_image_tag(): void
    {
        $image = imagecreatetruecolor(1200, 600);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        $f = new Psr17Factory();
        $asset = $this->media()->upload($f->createUploadedFile($f->createStream($png), strlen($png), UPLOAD_ERR_OK, 'a.png'), null);
        $this->media()->update($asset->id, ['focal_x' => 30, 'focal_y' => 70]);
        $container = $this->mediaApp()->container();

        $out = $container->get(TemplateEngineInterface::class)->render('page.twig', ['id' => $asset->id]);

        [$html, $url, $none] = explode('|', $out);
        $expected = (new ImageTag($this->media(), $container->get(\Preflow\Media\LocaleProvider::class), ['hero' => ['widths' => [480, 960], 'sizes' => '100vw']], new AssetCollector(new \Preflow\View\NonceGenerator()), '', $container->get(\Preflow\Media\Image\ImageWidths::class)))
            ->render($asset->id, 'hero', ['priority' => true]);
        $this->assertSame($expected, $html);
        $this->assertStringContainsString('folio-fx-30', $html);
        $this->assertSame('/media/' . $asset->path, $url);
        $this->assertSame('', $none);
        $head = $container->get(AssetCollector::class)->renderHead();
        $this->assertSame(1, substr_count($head, 'rel="preload"'));
    }
}
