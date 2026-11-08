<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\PageCache;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\PageCache\ChangeLog;
use Preflow\Core\PageCache\DataLayerObserver;
use Preflow\Core\PageCache\DependencyRecorder;
use Preflow\Core\PageCache\Freshness;
use Preflow\Core\PageCache\PageCache;
use Preflow\Core\PageCache\PageCacheConfig;
use Preflow\Core\PageCache\PageKey;
use Preflow\Core\PageCache\PageStore;
use Preflow\Core\PageCache\StoredPage;
use Preflow\Core\PageCache\TagIndex;
use Preflow\Data\DataManager;

/** The Application connects the data layer to the page cache only when the cache is on. */
final class PageCacheWiringTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pcwire_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'k','url'=>'https://example.com'];\n");
        file_put_contents($this->dir . '/config/data.php', "<?php\nreturn ['default'=>'json','drivers'=>['json'=>['path'=>'{$this->dir}/storage/data']]];\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function app(bool $enabled): Application
    {
        file_put_contents($this->dir . '/config/page_cache.php', '<?php return ' . var_export(['enabled' => $enabled], true) . ';');
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    #[RunInSeparateProcess]
    public function test_off_nothing_observes_and_nothing_is_written(): void
    {
        $app = $this->app(false);
        $dm = $app->container()->get(DataManager::class);

        $this->assertNull($dm->observer());
        $this->assertFalse($app->container()->bound(ChangeLog::class));
        $this->assertFalse($app->container()->get(PageCache::class)->enabled());
        $app->container()->get(PageCache::class)->changed('json:post');
        $dm->reportWrite('json', 'post', 'p1');
        $app->handle(new ServerRequest('GET', 'https://example.com/nowhere'));

        $this->assertDirectoryDoesNotExist($this->dir . '/storage/cache/pages');
    }

    #[RunInSeparateProcess]
    public function test_on_a_write_invalidates_its_pages_before_the_request_ends(): void
    {
        $app = $this->app(true);
        $container = $app->container();
        $dm = $container->get(DataManager::class);
        $this->assertInstanceOf(DataLayerObserver::class, $dm->observer());

        // A page that read post p1 while it rendered (storage json is the default driver: "default").
        $recorder = $container->get(DependencyRecorder::class);
        $recorder->start();
        $dm->reportRead('json', 'post', 'p1');
        $tags = $recorder->stop();
        $this->assertSame(['default:post#p1' => [Freshness::Fresh, null], 'default:post#' => [Freshness::Fresh, null]], $tags);

        $config = $container->get(PageCacheConfig::class);
        $store = $container->get(PageStore::class);
        $key = PageKey::fromRequest(new ServerRequest('GET', 'https://example.com/post/p1'), $config);
        $index = TagIndex::forStore($store, $config);
        $index->record($key->id(), $key->url(), false, false, $tags);
        $store->put($key, new StoredPage('old', [], microtime(true)));

        // A request writes the post: the page is gone once handle() returns.
        $dm->reportWrite('json', 'post', 'p1');
        $this->assertSame(['default:post#p1', 'default:post', 'default:*'], $container->get(ChangeLog::class)->pending());
        $this->assertNotNull($store->get($key), 'not before the end of the request');
        $app->handle(new ServerRequest('GET', 'https://example.com/nowhere'));

        $this->assertNull($store->get($key));
        $this->assertSame([], $container->get(ChangeLog::class)->pending());
        $this->assertSame([], $index->pagesFor(['default:post#p1']));
    }
}
