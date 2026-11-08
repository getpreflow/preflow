<?php

declare(strict_types=1);

namespace Preflow\DevTools\Tests\Command;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\PageCache\PageCacheConfig;
use Preflow\Core\PageCache\PageKey;
use Preflow\Core\PageCache\PageStore;
use Preflow\Core\PageCache\PendingInvalidations;
use Preflow\Core\PageCache\RefreshQueue;
use Preflow\Core\PageCache\TagIndex;
use Preflow\DevTools\Command\CacheClearCommand;
use Preflow\DevTools\Command\PagesRefreshCommand;
use Preflow\DevTools\Command\PagesWorkCommand;

/** pages:work and pages:refresh, and cache:clear pages, on a site with the page cache on. */
final class PagesCommandsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pages_cmd_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        mkdir($this->dir . '/app/pages', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'pages-command-test-key-0123456789','url'=>'https://example.com'];\n");
        file_put_contents($this->dir . '/config/page_cache.php', "<?php\nreturn ['enabled' => true, 'verify' => 'off'];\n");
        foreach (['a', 'b', 'c'] as $name) {
            file_put_contents($this->dir . "/app/pages/{$name}.twig", "<p>{$name} {{ 'now'|date('U.u') }}</p>");
        }
        ini_set('error_log', $this->dir . '/php.log');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function console(): \Closure
    {
        $dir = $this->dir;

        return static function () use ($dir): Application {
            $app = Application::create($dir);
            $app->boot();

            return $app;
        };
    }

    private function visit(string $path): string
    {
        $app = Application::create($this->dir);
        $app->setRunningInConsole(false);
        $app->boot();

        return $app->handle(new ServerRequest('GET', 'https://example.com' . $path))->getHeaderLine('X-Page-Cache');
    }

    private function store(): PageStore
    {
        return new PageStore($this->dir . '/storage/cache/pages', PageStore::releaseKey($this->dir));
    }

    private function config(): PageCacheConfig
    {
        return ($this->console())()->container()->get(PageCacheConfig::class);
    }

    private function body(string $path): ?string
    {
        return $this->store()->get(PageKey::fromRequest(new ServerRequest('GET', 'https://example.com' . $path), $this->config()))?->body;
    }

    private function queue(): RefreshQueue
    {
        return new RefreshQueue(TagIndex::forStore($this->store(), $this->config())->pdo() ?? throw new \RuntimeException('no index'));
    }

    /** @param list<string> $args */
    private function exec(object $command, array $args): array
    {
        ob_start();
        try {
            $code = $command->execute($args);
        } finally {
            $out = (string) ob_get_clean();
        }

        return [$code, $out];
    }

    #[RunInSeparateProcess]
    public function test_refresh_one_url_and_everything_and_work_the_queue(): void
    {
        foreach (['/a', '/b', '/c'] as $path) {
            $this->assertSame('miss', $this->visit($path));
        }
        $a = $this->body('/a');

        // One URL, as a path: forgotten and rebuilt at once.
        [$code, $out] = $this->exec(new PagesRefreshCommand($this->console()), ['/a']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Forgot 1 cached copy of /a.', $out);
        $this->assertStringContainsString('Rebuilt 1 page(s); 0 queued.', $out);
        $this->assertNotNull($this->body('/a'));
        $this->assertNotSame($a, $this->body('/a'));
        $this->assertSame('hit', $this->visit('/a'));

        // Everything, with a budget that leaves the rest to pages:work (at least one is built).
        [$code, $out] = $this->exec(new PagesRefreshCommand($this->console()), ['--all', '--budget=0.000001']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Forgot every page; 3 queued for rebuilding.', $out);
        $this->assertStringContainsString('Rebuilt 1 page(s); 2 queued.', $out);

        [$code, $out] = $this->exec(new PagesWorkCommand($this->console()), []);
        $this->assertSame(0, $code, $out);
        $this->assertMatchesRegularExpression('/^Rebuilt 2 page\(s\) in [\d.]+ s; 0 queued\.$/m', $out);
        foreach (['/a', '/b', '/c'] as $path) {
            $this->assertSame('hit', $this->visit($path), $path);
        }

        [, $out] = $this->exec(new PagesWorkCommand($this->console()), ['--budget=5']);
        $this->assertStringContainsString('Rebuilt 0 page(s)', $out, 'nothing left');
    }

    #[RunInSeparateProcess]
    public function test_wrong_arguments_exit_with_1(): void
    {
        foreach ([[], ['/a', '--all'], ['/a', '/b'], ['/a', '--fast']] as $args) {
            [$code] = $this->exec(new PagesRefreshCommand($this->console()), $args);
            $this->assertSame(1, $code, implode(' ', $args));
        }
        [$code] = $this->exec(new PagesRefreshCommand($this->console()), ['https://elsewhere.test/a']);
        $this->assertSame(1, $code, 'a host the cache does not cache');
        foreach ([['--budget=0'], ['--budget=x'], ['--all']] as $args) {
            [$code] = $this->exec(new PagesWorkCommand($this->console()), $args);
            $this->assertSame(1, $code, implode(' ', $args));
        }
    }

    #[RunInSeparateProcess]
    public function test_with_the_cache_off_nothing_is_done(): void
    {
        file_put_contents($this->dir . '/config/page_cache.php', "<?php\nreturn ['enabled' => false];\n");

        [$code, $out] = $this->exec(new PagesWorkCommand($this->console()), []);
        $this->assertSame([0, "The page cache is off: nothing to rebuild.\n"], [$code, $out]);
        [$code, $out] = $this->exec(new PagesRefreshCommand($this->console()), ['--all']);
        $this->assertSame([0, "The page cache is off: nothing to refresh.\n"], [$code, $out]);
        $this->assertDirectoryDoesNotExist($this->dir . '/storage/cache/pages');
    }

    #[RunInSeparateProcess]
    public function test_cache_clear_pages_clears(): void
    {
        foreach (['/a', '/b'] as $path) {
            $this->visit($path);
        }
        $this->exec(new PagesRefreshCommand($this->console()), ['--all', '--budget=0.000001']);
        (new PendingInvalidations($this->store()->releaseDir()))->add(['menu'], microtime(true));
        $this->assertSame(1, $this->queue()->count());

        [$code, $out] = $this->exec(new CacheClearCommand($this->console()), ['pages']);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Cleared pages: 1 file(s)', $out);
        $this->assertNull($this->body('/a'));
        $this->assertNull($this->body('/b'));
        $this->assertSame(0, TagIndex::forStore($this->store(), $this->config())->count());
        $this->assertSame(0, $this->queue()->count(), 'a clear is not a refresh');
        $this->assertFalse(PendingInvalidations::exist($this->store()->releaseDir()));
        $this->assertSame('miss', $this->visit('/a'));
        $this->assertSame('hit', $this->visit('/a'));
    }
}
