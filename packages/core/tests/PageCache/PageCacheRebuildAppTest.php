<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\PageCache;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\PageCache\ChangeLog;
use Preflow\Core\PageCache\DataTags;
use Preflow\Core\PageCache\Freshness;
use Preflow\Core\PageCache\PageCache;
use Preflow\Core\PageCache\PageCacheConfig;
use Preflow\Core\PageCache\PageKey;
use Preflow\Core\PageCache\PageStore;
use Preflow\Core\PageCache\PendingInvalidations;
use Preflow\Core\PageCache\RefreshQueue;
use Preflow\Core\PageCache\Refresher;
use Preflow\Core\PageCache\StoredPage;
use Preflow\Core\PageCache\TagIndex;
use Preflow\Data\DataManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Rebuilds through whole apps: after the response, from the console, under the visitors' keys. */
final class PageCacheRebuildAppTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pcrebuild_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        unset($_SESSION);
        // What the page cache logs (no site logger here) goes to a file, not the test's output.
        ini_set('error_log', $this->dir . '/php.log');
    }

    private function logged(): string
    {
        return (string) @file_get_contents($this->dir . '/php.log');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** @param array<string, mixed> $cache config/page_cache.php */
    private function site(array $cache = [], ?array $i18n = null): void
    {
        $this->write('config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'page-cache-test-key-0123456789ab','url'=>'https://example.com','locale'=>'en'];\n");
        $this->write('config/page_cache.php', '<?php return ' . var_export($cache + ['enabled' => true, 'explain' => true, 'verify' => 'off'], true) . ';');
        $this->write('config/data.php', "<?php\nreturn ['default'=>'json','drivers'=>['json'=>['path'=>'{$this->dir}/storage/data']]];\n");
        if ($i18n !== null) {
            $this->write('config/i18n.php', '<?php return ' . var_export($i18n, true) . ';');
            foreach (['en' => 'Hello', 'de' => 'Hallo'] as $locale => $hi) {
                $this->write("lang/{$locale}/site.php", "<?php return ['hi' => '{$hi}'];");
            }
        }
    }

    private function webApp(): Application
    {
        $app = Application::create($this->dir);
        $app->setRunningInConsole(false);
        $app->runAfterResponseInHandle();
        $app->boot();

        return $app;
    }

    /** @param array<string, string> $headers */
    private function web(string $path, array $headers = [], string $method = 'GET', ?Application $app = null): ResponseInterface
    {
        $request = new ServerRequest($method, 'https://example.com' . $path, $headers);

        return ($app ?? $this->webApp())->handle($request);
    }

    private function store(): PageStore
    {
        return new PageStore($this->dir . '/storage/cache/pages', PageStore::releaseKey($this->dir));
    }

    private function config(): PageCacheConfig
    {
        return $this->webApp()->container()->get(PageCacheConfig::class);
    }

    /** @param array<string, string> $parts */
    private function stored(string $path, array $parts = [], string $origin = 'https://example.com'): ?StoredPage
    {
        $key = PageKey::fromRequest(new ServerRequest('GET', $origin . $path), $this->config(), $parts);

        return $this->store()->get($key);
    }

    private function queue(): RefreshQueue
    {
        return new RefreshQueue(TagIndex::forStore($this->store(), $this->config())->pdo() ?? throw new \RuntimeException('no index'));
    }

    #[RunInSeparateProcess]
    public function test_after_response_rebuilds_within_budget_and_leaves_the_rest_queued(): void
    {
        // A budget far below one render: the work after a response takes one page, then stops.
        $this->site(['rebuild_budget' => 0.000001]);
        foreach (['a', 'b', 'c'] as $name) {
            $this->write("app/pages/{$name}.twig", "<p>{$name} {{ 'now'|date('U.u') }}</p>");
            $this->assertSame('miss', $this->web("/{$name}")->getHeaderLine('X-Page-Cache'));
        }

        // A request writes something every page uses: all three are forgotten and queued; its
        // response goes out first, then one is rebuilt.
        $app = $this->webApp();
        $app->container()->get(PageCache::class)->changed('*');
        $before = $this->queue()->count();
        $this->web('/nowhere', app: $app);

        $this->assertSame(0, $before, 'nothing queued before the request ends');
        $built = array_filter(['/a', '/b', '/c'], fn (string $path): bool => $this->stored($path) !== null);
        $this->assertCount(1, $built, 'one rebuilt behind the response');
        $this->assertSame(2, $this->queue()->count(), 'the rest left for the cron');
        $this->assertSame('hit', $this->web((string) reset($built))->getHeaderLine('X-Page-Cache'));

        // The cron (or the next requests' work) takes the rest.
        $this->assertSame(2, $this->webApp()->container()->get(Refresher::class)->work(50.0));
        foreach (['/a', '/b', '/c'] as $path) {
            $this->assertSame('hit', $this->web($path)->getHeaderLine('X-Page-Cache'), $path);
        }
    }

    #[RunInSeparateProcess]
    public function test_a_stale_page_is_rebuilt_after_its_response_and_head_queues_nothing(): void
    {
        $this->site();
        $this->write('app/pages/page.twig', "<p>page {{ 'now'|date('U.u') }}</p>");
        $this->web('/page');
        $first = (string) $this->stored('/page')?->body;
        $this->store()->markStale(PageKey::fromRequest(new ServerRequest('GET', 'https://example.com/page'), $this->config())->id());

        // An uptime monitor's HEAD: served stale, changes nothing.
        $this->assertSame('stale', $this->web('/page', method: 'HEAD')->getHeaderLine('X-Page-Cache'));
        $this->assertSame(0, $this->queue()->count());
        $this->assertTrue($this->stored('/page')?->stale);

        // A visitor gets the old page at once; it is rebuilt behind the response.
        $stale = $this->web('/page');
        $this->assertSame('stale', $stale->getHeaderLine('X-Page-Cache'));
        $this->assertSame($first, (string) $stale->getBody());
        $rebuilt = $this->stored('/page');
        $this->assertFalse($rebuilt?->stale, 'fresh again');
        $this->assertNotSame($first, $rebuilt?->body, 'rendered anew');
        $this->assertSame(0, $this->queue()->count());
        $this->assertSame('hit', $this->web('/page')->getHeaderLine('X-Page-Cache'));
    }

    #[RunInSeparateProcess]
    public function test_a_rebuild_stores_under_the_key_visitors_read(): void
    {
        $this->site(i18n: ['default' => 'en', 'available' => ['en', 'de'], 'url_strategy' => 'none', 'cookie' => false]);
        $this->write('app/pages/x.twig', "<p>{{ t('site.hi') }} {{ 'now'|date('U.u') }}</p>");

        // A German visitor behind a TLS proxy (the app sees http), and an English one.
        $de = ['Accept-Language' => 'de', 'X-Forwarded-Proto' => 'https'];
        $this->assertStringContainsString('Hallo', (string) $this->web('/x', $de)->getBody());
        $this->web('/x');
        $german = ['locale' => 'de', 'proto' => 'https'];
        $this->assertNotNull($this->stored('/x', $german));
        $this->assertSame(['locale' => 'de', 'proto' => 'https'], TagIndex::forStore($this->store(), $this->config())
            ->page(PageKey::fromRequest(new ServerRequest('GET', 'https://example.com/x'), $this->config(), $german)->id())['parts'] ?? null);
        $old = (string) $this->stored('/x', $german)?->body;

        // Refreshed: both are rebuilt under the same keys, each in its language.
        $refresher = $this->webApp()->container()->get(Refresher::class);
        $this->assertSame(2, $refresher->refreshAll());
        $this->assertSame(2, $refresher->work(50.0));

        $this->assertStringContainsString('Hallo', (string) $this->stored('/x', $german)?->body);
        $this->assertNotSame($old, (string) $this->stored('/x', $german)?->body);
        $this->assertStringContainsString('Hello', (string) $this->stored('/x', ['locale' => 'en'])?->body);
        $this->assertSame('hit', $this->web('/x', $de)->getHeaderLine('X-Page-Cache'));
        $this->assertStringContainsString('Hallo', (string) $this->web('/x', $de)->getBody());
        $this->assertSame(2, TagIndex::forStore($this->store(), $this->config())->count(), 'no page under another key');
    }

    #[RunInSeparateProcess]
    public function test_rebuilds_in_one_process_do_not_share_request_state(): void
    {
        $this->site();
        $this->write('config/seo.php', "<?php\nreturn ['enabled' => true, 'name' => 'Club'];\n");
        $this->write('app/pages/a.twig', '{{ seo({title: "Only on A"}) }}<html><head><title>A</title></head><body>A</body></html>');
        $this->write('app/pages/b.twig', '<html><head><title>B</title></head><body>B</body></html>');
        $this->web('/a');
        $this->web('/b');
        $refresher = $this->webApp()->container()->get(Refresher::class);
        $refresher->refreshAll();
        $this->assertSame(2, $refresher->work(50.0));

        $this->assertSame('Only on A', $this->stored('/a')?->state['seo']['meta']['title'] ?? null);
        $this->assertNull($this->stored('/b')?->state['seo']['meta'] ?? null, 'B carries none of A\'s page meta');
        $this->assertStringNotContainsString('Only on A', (string) $this->web('/b')->getBody());
        $this->assertStringContainsString('Only on A', (string) $this->web('/a')->getBody());
    }

    #[RunInSeparateProcess]
    public function test_a_bulk_import_queues_each_page_once(): void
    {
        $this->site();
        $this->write('app/pages/jobs.twig', '<p>jobs</p>');
        $this->web('/jobs');
        // Three job pages and the listing, as their renders recorded them.
        $config = $this->config();
        $index = TagIndex::forStore($this->store(), $config);
        foreach ([1, 2, 3] as $id) {
            $key = PageKey::fromRequest(new ServerRequest('GET', "https://example.com/jobs/{$id}"), $config);
            $index->record($key->id(), $key->url(), false, false, [DataTags::record('default', 'job', (string) $id) => [Freshness::Fresh, null], DataTags::anyRecord('default', 'job') => [Freshness::Fresh, null]], $key->parts());
            $this->store()->put($key, new StoredPage('job', [], microtime(true)));
        }
        $listing = PageKey::fromRequest(new ServerRequest('GET', 'https://example.com/jobs'), $config);
        $index->record($listing->id(), $listing->url(), false, false, [DataTags::listing('default', 'job') => [Freshness::Fresh, null]], $listing->parts());

        // An import command saves 300 jobs, flushing early once and again at its end.
        $console = Application::create($this->dir);
        $console->boot();
        $dm = $console->container()->get(DataManager::class);
        for ($id = 1; $id <= 300; $id++) {
            $dm->reportWrite('json', 'job', (string) $id);
            if ($id === 150) {
                $console->container()->get(ChangeLog::class)->flush();
            }
        }
        $console->container()->get(ChangeLog::class)->flush();

        $queued = $this->queue()->all();
        $this->assertCount(4, $queued, 'one entry per page');
        $this->assertSame(4, count(array_unique(array_column($queued, 'id'))));
        $this->assertSame([RefreshQueue::PRIORITY_RECORD, RefreshQueue::PRIORITY_RECORD, RefreshQueue::PRIORITY_RECORD, RefreshQueue::PRIORITY_PAGE], array_column($queued, 'priority'));
        $this->assertSame('https://example.com/jobs', end($queued)['url']);

        // The cron, a console app: rebuilds go through the whole pipeline and are stored.
        $cron = Application::create($this->dir);
        $cron->boot();
        $this->assertSame(4, $cron->container()->get(Refresher::class)->work(50.0));
        $this->assertSame('<p>jobs</p>', $this->stored('/jobs')?->body);
        $this->assertSame(0, $this->queue()->count());
        $this->assertNull($this->stored('/jobs/1'), 'a 404 is rebuilt but never stored');
    }

    #[RunInSeparateProcess]
    public function test_a_pending_invalidation_is_applied_by_the_next_hit(): void
    {
        $this->site();
        $this->write('app/pages/page.twig', '<p>page</p>');
        $this->write('app/pages/other.twig', '<p>other</p>');
        $this->web('/page');
        $this->web('/other');
        $this->assertSame('hit', $this->web('/page')->getHeaderLine('X-Page-Cache'));

        // A write whose invalidation could not reach the index (SQLite was busy).
        (new PendingInvalidations($this->store()->releaseDir()))->add([TagIndex::EVERYTHING], microtime(true));

        $response = $this->web('/page');
        $this->assertSame('miss', $response->getHeaderLine('X-Page-Cache'), 'applied first: everything was forgotten');
        $this->assertFalse(PendingInvalidations::exist($this->store()->releaseDir()));
        $this->assertSame('hit', $this->web('/page')->getHeaderLine('X-Page-Cache'));
        $this->assertStringContainsString('pending invalidations were applied', $this->logged());
    }

    private function write(string $rel, string $content): void
    {
        $path = $this->dir . '/' . $rel;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }
}
