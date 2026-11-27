<?php

declare(strict_types=1);

namespace Preflow\BlockLibrary\Tests;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Preflow\BlockLibrary\BlockLibraryServiceProvider;
use Preflow\BlockLibrary\Columns\Columns;
use Preflow\BlockLibrary\Tests\Fixtures\Note\Note;
use Preflow\Blocks\BlocksServiceProvider;
use Preflow\Core\Application;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\SystemClock;
use Preflow\View\TemplateEngineInterface;

/**
 * A temp app without Folio that lists BlocksServiceProvider and BlockLibraryServiceProvider, as a
 * site does: content locales en and de, the blocks "columns" and "note" (Fixtures\Note) in
 * config/blocks.php (the test app has no Composer metadata, so extra.preflow.blocks is not read).
 */
abstract class BlockLibraryAppTestCase extends TestCase
{
    protected string $dir;
    private ?Application $app = null;

    /** Twig or Blade. */
    protected string $engine = 'twig';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/block_library_app_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/app', 0777, true);
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Block library', 'debug' => 1, 'engine' => '" . $this->engine . "', 'key' => 'block-library-test-key-0123456789abcdef'];\n");
        $this->providers([BlocksServiceProvider::class, BlockLibraryServiceProvider::class]);
        $this->write('config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en', 'de'], 'fallback' => 'en', 'url_strategy' => 'prefix'];\n");
        $this->blocksConfig();
    }

    protected function tearDown(): void
    {
        Contexts::reset();
        $this->remove($this->dir);
    }

    /** @param list<class-string> $providers */
    protected function providers(array $providers): void
    {
        $this->write('config/providers.php', "<?php\nreturn [" . implode(', ', array_map(static fn (string $class): string => '\\' . $class . '::class', $providers)) . "];\n");
    }

    /** config/blocks.php with the columns and note blocks and $more keys (column_layouts, column_blocks). */
    protected function blocksConfig(string $more = ''): void
    {
        $this->write('config/blocks.php', "<?php\nreturn ['classes' => [\\" . Columns::class . '::class, \\' . Note::class . "::class]" . ($more !== '' ? ', ' . $more : '') . "];\n");
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

    /**
     * $template (a file name in the app's directory, written with $source) rendered as a visitor's
     * page in $locale.
     *
     * @param array<string, mixed> $context
     */
    protected function render(string $template, string $source, array $context = [], string $locale = 'en'): string
    {
        $this->write($template, $source);

        return Contexts::run(
            RenderContext::fromRequest(new ServerRequest('GET', '/'), $this->app()->site(), new SystemClock())->in($locale),
            fn (): string => $this->app()->container()->get(TemplateEngineInterface::class)->render($template, $context),
        );
    }

    /**
     * A columns block with $layout and $areas (area name => note titles), uids from the titles.
     *
     * @param array<string, list<string>> $areas
     * @return array<string, mixed>
     */
    protected static function columns(string $uid, ?string $layout, array $areas): array
    {
        $map = [];
        foreach ($areas as $name => $titles) {
            $map[$name] = array_map(static fn (string $title): array => ['_uid' => $title, '_block' => 'note', 'title' => ['en' => $title, 'de' => $title . '-de']], $titles);
        }

        return ['_uid' => $uid, '_block' => 'columns', 'columns' => ['layout' => $layout, 'areas' => $map]];
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
