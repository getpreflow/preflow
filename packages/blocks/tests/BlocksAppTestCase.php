<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\View\TemplateEngineInterface;

/**
 * A temp app with preflow/blocks and no Folio: Twig, content locales en and de, the
 * fixture app's component blocks (app/Components) and data-only blocks (app/Blocks).
 */
abstract class BlocksAppTestCase extends TestCase
{
    protected string $dir;
    private ?Application $app = null;

    public static function setUpBeforeClass(): void
    {
        // The fixture app's App\Components\… and App\Blocks\… classes (a site's composer autoload).
        spl_autoload_register(static function (string $class): void {
            foreach (['App\\Components\\' => 'app/Components/', 'App\\Blocks\\' => 'app/Blocks/'] as $prefix => $dir) {
                $file = __DIR__ . '/Fixtures/app/' . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (str_starts_with($class, $prefix) && is_file($file)) {
                    require $file;
                }
            }
        });
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/blocks_app_' . bin2hex(random_bytes(4));
        $this->copy(__DIR__ . '/Fixtures/app/app', $this->dir . '/app');
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Blocks', 'debug' => 1, 'engine' => 'twig', 'key' => 'blocks-app-test-key'];\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Blocks\\BlocksServiceProvider::class];\n");
        $this->write('config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en', 'de'], 'fallback' => 'en', 'url_strategy' => 'prefix'];\n");
    }

    protected function tearDown(): void
    {
        $this->remove($this->dir);
    }

    protected function write(string $rel, string $content): void
    {
        $path = $this->dir . '/' . $rel;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }

    protected function app(): Application
    {
        if ($this->app === null) {
            $this->app = Application::create($this->dir);
            $this->app->boot();
        }

        return $this->app;
    }

    /** @param array<string, mixed> $context */
    protected function render(string $template, array $context = []): string
    {
        $this->write('page.twig', $template);

        return $this->app()->container()->get(TemplateEngineInterface::class)->render('page.twig', $context);
    }

    private function copy(string $from, string $to): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $target = $to . substr($file->getPathname(), strlen($from));
            @mkdir(dirname($target), 0777, true);
            copy($file->getPathname(), $target);
        }
    }

    private function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
