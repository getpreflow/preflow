<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\View\ImageUrlTransformer;

final class CdnTransformer implements ImageUrlTransformer
{
    public function transform(string $path, int $width, string $format, int $quality): string
    {
        return "https://cdn.test/{$width}/{$path}";
    }
}

final class CdnImageProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $container->instance(ImageUrlTransformer::class, new CdnTransformer());
    }
}

final class ImageTransformerProviderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/img_provider_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/config', 0777, true);
        @mkdir($this->dir . '/app/pages', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'k'];\n");
        file_put_contents($this->dir . '/config/providers.php', "<?php\nreturn [\\" . CdnImageProvider::class . "::class];\n");
        file_put_contents($this->dir . '/app/pages/index.twig', '<html><head></head><body>{{ responsive_image("a.jpg", {widths: [400]}) }}</body></html>');
    }

    protected function tearDown(): void
    {
        foreach (['config/app.php', 'config/providers.php', 'app/pages/index.twig'] as $f) {
            @unlink($this->dir . '/' . $f);
        }
        foreach (['app/pages', 'app', 'config', ''] as $d) {
            @rmdir($this->dir . '/' . $d);
        }
    }

    #[RunInSeparateProcess]
    public function test_provider_bound_transformer_is_used(): void
    {
        $app = Application::create($this->dir);
        $app->boot();
        $body = (string) $app->handle((new Psr17Factory())->createServerRequest('GET', '/'))->getBody();

        $this->assertStringContainsString('https://cdn.test/400/a.jpg', $body);
    }
}
