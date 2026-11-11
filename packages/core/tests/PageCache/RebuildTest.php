<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\PageCache;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\InternalRender;
use Preflow\Core\PageCache\DataTags;
use Preflow\Core\PageCache\Freshness;
use Preflow\Core\PageCache\PageCacheConfig;
use Preflow\Core\PageCache\PageCacheMiddleware;
use Preflow\Core\PageCache\PageKey;
use Preflow\Core\PageCache\PageStore;
use Preflow\Core\PageCache\PendingInvalidations;
use Preflow\Core\PageCache\Rebuild;
use Preflow\Core\PageCache\RefreshQueue;
use Preflow\Core\PageCache\Refresher;
use Preflow\Core\PageCache\StoredPage;
use Preflow\Core\PageCache\TagIndex;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** The refresh queue and Refresher::work(), with a stand-in for the app's render. */
final class RebuildTest extends TestCase
{
    private string $dir;

    private float $now = 1_800_000_000.0;

    private PageCacheConfig $config;

    /** @var list<string> urls rendered, in order */
    private array $rendered = [];

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rebuild_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->config = new PageCacheConfig(enabled: true, hosts: ['example.com']);
    }

    protected function tearDown(): void
    {
        @chmod($this->dir . '/pages/' . self::RELEASE, 0775);
        exec('chmod -R u+w ' . escapeshellarg($this->dir) . '; rm -rf ' . escapeshellarg($this->dir));
    }

    private const string RELEASE = 'aaaaaaaaaaaaaaaa';

    private function store(string $release = self::RELEASE): PageStore
    {
        return new PageStore($this->dir . '/pages', $release, fn (): float => $this->now);
    }

    /**
     * A refresher whose rebuilds "render" by storing the page under the key the request gives
     * (as the middleware does), taking $seconds of the clock each.
     *
     * @param (\Closure(ServerRequestInterface): void)|null $during runs inside each render
     */
    /** How often onQueue was called. */
    private int $onQueue = 0;

    /** @param (\Closure(ServerRequestInterface): ?ResponseInterface)|null $refuse a response instead of storing (a refused rebuild) */
    private function refresher(PageStore $store, float $seconds = 0.1, ?\Closure $during = null, ?\Closure $refuse = null): Refresher
    {
        return new Refresher(
            $store,
            $this->config,
            fn (): float => $this->now,
            onQueue: function (): void {
                $this->onQueue++;
            },
            rebuild: function (ServerRequestInterface $request) use ($store, $seconds, $during, $refuse): ResponseInterface {
                $this->assertTrue(InternalRender::is($request));
                $this->assertTrue($request->getAttribute(PageCacheMiddleware::REBUILD));
                $this->rendered[] = (string) $request->getUri();
                $this->now += 0.001;
                $started = $this->now;
                if ($during !== null) {
                    $during($request);
                }
                $this->now += $seconds;
                $refused = $refuse !== null ? $refuse($request) : null;
                if ($refused !== null) {
                    return $refused;
                }
                $key = PageKey::fromRequest($request, $this->config, array_filter(['locale' => $request->getHeaderLine('Accept-Language')]), self::htmx());
                $index = TagIndex::forStore($store, $this->config, fn (): float => $this->now);
                $index->record($key->id(), $key->url(), $key->isFragment(), $key->hasQuery(), ['default:job' => [Freshness::Fresh, null]], $key->parts());
                $store->put($key, new StoredPage('<p>new ' . $key->url() . '</p>', [], $started));
                // As the middleware does: a write during the render refuses the store.
                if ($index->invalidatedSince(['default:job'], $started)) {
                    $index->forget($key->id());
                    $store->forget($key->id());
                    $index->close();

                    return new Response(200, [PageCacheMiddleware::SKIP_HEADER => 'invalidated']);
                }
                $index->close();

                return new Response(200);
            },
            log: function (string $message): void {
                $this->logged[] = $message;
            },
            origin: 'https://example.com',
        );
    }

    /**
     * Stores a page as a render that read $reads would.
     *
     * @param list<string> $reads
     * @param array<string, string> $headers
     */
    private function cache(PageStore $store, string $path, array $reads, array $headers = [], Freshness $freshness = Freshness::Fresh): PageKey
    {
        $request = new ServerRequest('GET', 'https://example.com' . $path, $headers);
        $key = PageKey::fromRequest($request, $this->config, [], self::htmx());
        $tags = [];
        foreach ($reads as $read) {
            foreach (DataTags::dependencies($read) as $tag) {
                $tags[$tag] = [$freshness, null];
            }
        }
        $index = TagIndex::forStore($store, $this->config, fn (): float => $this->now);
        $index->record($key->id(), $key->url(), $key->isFragment(), $key->hasQuery(), $tags, $key->parts());
        $index->close();
        $store->put($key, new StoredPage('<p>old ' . $path . '</p>', [], $this->now));

        return $key;
    }

    private function queue(PageStore $store): RefreshQueue
    {
        $index = TagIndex::forStore($store, $this->config);

        return new RefreshQueue($index->pdo() ?? throw new \RuntimeException('no index'));
    }

    /** @return list<string> */
    private function queuedUrls(PageStore $store): array
    {
        return array_column($this->queue($store)->all(), 'url');
    }

    public function test_work_stops_once_its_budget_is_spent_and_leaves_the_rest_queued(): void
    {
        $store = $this->store();
        foreach (['/a', '/b', '/c', '/d', '/e'] as $path) {
            $this->cache($store, $path, ['default:job']);
        }
        $refresher = $this->refresher($store, seconds: 0.8);
        $refresher->invalidate(DataTags::tableChanged('default', 'job'));
        $this->assertSame(5, $refresher->queued());

        // 2 s: renders at 0, 0.8 and 1.6 s; the budget is spent at 2.4 s.
        $this->assertSame(3, $refresher->work(2.0));
        $this->assertSame(2, $refresher->queued(), 'the rest waits for the cron');
        $this->assertCount(3, $this->rendered);

        $this->assertSame(2, $refresher->work(50.0));
        $this->assertSame(0, $refresher->queued());
        foreach (['/a', '/b', '/c', '/d', '/e'] as $path) {
            $this->assertStringContainsString('new', $store->get(PageKey::fromRequest(new ServerRequest('GET', 'https://example.com' . $path), $this->config))?->body ?? '', $path);
        }
        $this->assertSame(0, $refresher->work(0.0), 'no budget, no work');
    }

    public function test_a_record_page_is_rebuilt_before_the_listings(): void
    {
        $store = $this->store();
        $this->cache($store, '/jobs', ['default:job']);
        $this->cache($store, '/jobs/7', ['default:job#7']);
        $this->cache($store, '/jobs/8', ['default:job#8']);

        $refresher = $this->refresher($store);
        $refresher->invalidate(DataTags::recordChanged('default', 'job', '7'));

        $this->assertSame(['https://example.com/jobs/7', 'https://example.com/jobs'], $this->queuedUrls($store));
        $this->assertNotNull($store->get(PageKey::fromRequest(new ServerRequest('GET', 'https://example.com/jobs/8'), $this->config)), 'another job\'s page stays');
        $refresher->work(50.0);
        $this->assertSame(['https://example.com/jobs/7', 'https://example.com/jobs'], $this->rendered);
    }

    public function test_cron_work_and_after_response_never_build_one_url_twice(): void
    {
        $store = $this->store();
        foreach (['/a', '/b', '/c'] as $path) {
            $this->cache($store, $path, ['default:job']);
        }
        // The cron's process: its own refresher, its own connection to the index.
        $cron = $this->refresher($this->store());
        $inside = 0;
        // The after-response work: while it builds its first page, the cron runs.
        $after = $this->refresher($store, during: function () use ($cron, &$inside): void {
            if ($inside++ === 0) {
                $this->assertSame(2, $cron->work(50.0), 'the cron builds the two that are free');
            }
        });
        $after->invalidate(DataTags::tableChanged('default', 'job'));

        $after->work(50.0);

        sort($this->rendered);
        $this->assertSame(['https://example.com/a', 'https://example.com/b', 'https://example.com/c'], $this->rendered, 'each once');
        $this->assertSame(0, $after->queued());
    }

    public function test_a_page_changed_again_while_it_is_rebuilt_is_rebuilt_once_more(): void
    {
        $store = $this->store();
        $this->cache($store, '/a', ['default:job']);
        $writer = $this->refresher($this->store());
        $changed = false;
        $refresher = $this->refresher($store, during: function () use ($writer, &$changed): void {
            if (!$changed) {
                $changed = true;
                // A write while the page renders: its render started before, it must go again.
                $writer->invalidate(['default:job']);
            }
        });
        $refresher->invalidate(['default:job']);

        $refresher->work(50.0);
        $this->assertSame(['https://example.com/a'], $this->rendered, 'once per pass');
        $this->assertSame(1, $refresher->queued(), 'left for the next pass');

        $refresher->work(50.0);
        $this->assertSame(['https://example.com/a', 'https://example.com/a'], $this->rendered);
        $this->assertSame(0, $refresher->queued());
    }

    public function test_a_stale_page_whose_rebuild_is_refused_is_never_served_again(): void
    {
        foreach ([404 => '', 200 => 'set-cookie', 409 => ''] as $status => $skip) {
            $store = $this->store();
            $key = $this->cache($store, '/gone', ['default:job']);
            $store->markStale($key->id());
            $refresher = $this->refresher($store, refuse: static fn (): ResponseInterface => new Response($status, $skip === '' ? [] : [PageCacheMiddleware::SKIP_HEADER => $skip]));
            $refresher->stale($key);
            $this->assertTrue($store->get($key)?->stale, 'served stale while it is rebuilt');

            $this->assertSame(1, $refresher->work(50.0));

            $this->assertNull($store->get($key), "{$status} {$skip}: forgotten, its next visitor renders it");
            $this->assertNull(TagIndex::forStore($store, $this->config)->page($key->id()));
            $this->assertSame(0, $refresher->queued());
        }

        // A rebuild that throws forgets it too (and is tried again, at most MAX_ATTEMPTS times).
        $store = $this->store();
        $key = $this->cache($store, '/broken', ['default:job']);
        $store->markStale($key->id());
        $refresher = $this->refresher($store, refuse: static fn (): ResponseInterface => throw new \RuntimeException('boom'));
        $refresher->stale($key);
        $refresher->work(50.0);
        $this->assertNull($store->get($key));
    }

    public function test_a_render_that_writes_what_it_read_is_built_once_and_not_queued_again(): void
    {
        $store = $this->store();
        $this->cache($store, '/object/7', ['default:object#7']);
        $refresher = null;
        // The render bumps the object's view count: its own flush invalidates the page it read.
        $refresher = $this->refresher($store, seconds: 0.01, during: function () use (&$refresher): void {
            $refresher->invalidate(DataTags::recordChanged('default', 'object', '7'));
        });
        $refresher->invalidate(DataTags::recordChanged('default', 'object', '7'));
        $this->onQueue = 0;

        $this->assertSame(1, $refresher->work(2.0), 'one render, not one per 10 ms of the budget');
        $this->assertSame(0, $refresher->queued(), 'not queued again by its own write');
        $this->assertSame(0, $this->onQueue, 'the work never schedules more work');
    }

    public function test_three_refused_rebuilds_in_a_row_drop_the_page(): void
    {
        $store = $this->store();
        $key = $this->cache($store, '/busy', ['default:job']);
        $refresher = $this->refresher($store, refuse: static fn (): ResponseInterface => new Response(200, [PageCacheMiddleware::SKIP_HEADER => 'invalidated']));
        $refresher->invalidate(['default:job']);

        for ($pass = 1; $pass <= RefreshQueue::MAX_ATTEMPTS; $pass++) {
            $this->assertSame(1, $refresher->work(50.0), "pass {$pass}");
            // Queued again by a write in between: the count of failures in a row is kept.
            $refresher->stale($key);
        }
        $this->assertSame(1, $refresher->queued(), 'the last stale() queued it anew');
        $this->assertSame(0, $this->queue($store)->all()[0]['attempts'] ?? null, 'a fresh row after the drop');
        $this->assertNull($store->get($key));
    }

    public function test_a_throw_while_forgetting_a_failed_rebuild_still_records_the_failure(): void
    {
        $store = $this->store();
        $key = $this->cache($store, '/broken', ['default:job']);
        $refresher = $this->refresher($store, refuse: function () use ($store): never {
            // The index can't forget the page now (its tags table is gone), the queue still works.
            $pdo = TagIndex::forStore($store, $this->config)->pdo() ?? throw new \RuntimeException('no index');
            $pdo->exec('DROP TABLE tags');

            throw new \RuntimeException('render failed');
        });
        $refresher->invalidate(['default:job']);

        $refresher->work(50.0);

        $rows = $this->queue($store)->all();
        $this->assertCount(1, $rows, 'still queued');
        $this->assertSame(1, $rows[0]['attempts'], 'the failure is recorded');
        $this->assertSame('https://example.com/broken', $this->queue($store)->claim(300.0)['url'] ?? null, 'and its lease released');
        $this->assertNull($store->get($key), 'the stale file is gone');
        $this->assertStringContainsString('render failed', implode("\n", $this->logged));
    }

    public function test_an_expired_lease_is_taken_again_and_attempts_end_a_row(): void
    {
        $store = $this->store();
        $queue = new RefreshQueue(TagIndex::forStore($store, $this->config)->pdo() ?? throw new \RuntimeException(), fn (): float => $this->now);
        $queue->push(['p' => ['url' => 'https://example.com/p']]);

        $claimed = $queue->claim(300.0);
        $this->assertSame('p', $claimed['id'] ?? null);
        $this->assertNull($queue->claim(300.0), 'leased');
        $this->now += 299;
        $this->assertNull($queue->claim(300.0), 'still leased');
        $this->now += 2;
        $this->assertSame('p', $queue->claim(300.0)['id'] ?? null, 'a dead process\'s row is taken again');
        $this->assertNull($queue->claim(300.0, ['p']) , 'excluded');

        for ($i = 1; $i < RefreshQueue::MAX_ATTEMPTS; $i++) {
            $queue->fail(['id' => 'p']);
            $this->assertSame(1, $queue->count(), "attempt {$i}");
        }
        $queue->push(['p' => ['url' => 'https://example.com/p']]);
        $this->assertSame(RefreshQueue::MAX_ATTEMPTS - 1, $queue->all()[0]['attempts'], 'queued again, the count stays');
        $queue->fail(['id' => 'p']);
        $this->assertSame(0, $queue->count(), 'dropped');
    }

    public function test_a_refresh_by_hand_keeps_a_render_in_flight_from_storing(): void
    {
        $store = $this->store();
        $this->cache($store, '/about', ['menu']);
        $before = $this->now - 0.5; // a visitor's render started before the click
        $this->refresher($store)->refresh('/about');

        $this->assertTrue(TagIndex::forStore($store, $this->config)->invalidatedSince(['menu'], $before));
        $this->assertFalse(TagIndex::forStore($store, $this->config)->invalidatedSince(['other'], $before));
    }

    public function test_a_page_fresh_again_is_not_rebuilt(): void
    {
        $store = $this->store();
        $this->cache($store, '/a', ['default:job']);
        $refresher = $this->refresher($store);
        $refresher->invalidate(['default:job']);
        // A visitor renders it before the work runs.
        $this->cache($store, '/a', ['default:job']);

        $this->assertSame(0, $refresher->work(50.0));
        $this->assertSame([], $this->rendered);
        $this->assertSame(0, $refresher->queued());
    }

    public function test_query_and_fragment_pages_are_forgotten_not_queued(): void
    {
        $store = $this->store();
        $full = $this->cache($store, '/jobs', ['default:job']);
        $search = $this->cache($store, '/jobs?q=wood', ['default:job']);
        $fragment = $this->cache($store, '/jobs', ['default:job'], ['HX-Request' => 'true', 'HX-Target' => 'list']);
        $refresher = $this->refresher($store);

        $refresher->invalidate(DataTags::tableChanged('default', 'job'));

        $this->assertSame(['https://example.com/jobs'], $this->queuedUrls($store));
        $this->assertSame($full->id(), $this->queue($store)->all()[0]['id']);
        foreach ([$full, $search, $fragment] as $key) {
            $this->assertNull($store->get($key), $key->url() . ' forgotten');
        }

        // Stale ones too: a stale fragment or search page is forgotten once served, never queued.
        $this->cache($store, '/jobs?q=wood', ['default:job']);
        $refresher->stale($search);
        $this->assertNull($store->get($search));
        $this->assertSame(['https://example.com/jobs'], $this->queuedUrls($store));

        // So is everything: only the full page is queued.
        $this->cache($store, '/jobs?q=wood', ['default:job']);
        $this->cache($store, '/jobs', ['default:job'], ['HX-Request' => 'true', 'HX-Target' => 'list']);
        $this->assertSame(1, $refresher->refreshAll());
    }

    public function test_a_refresh_by_url_forgets_every_variant_and_queues_the_full_page_first(): void
    {
        $store = $this->store();
        $this->cache($store, '/jobs', ['default:job']);
        $this->cache($store, '/other', ['default:job']);
        $fragment = $this->cache($store, '/about', ['menu'], ['HX-Request' => 'true']);
        $about = $this->cache($store, '/about', ['menu']);
        $refresher = $this->refresher($store);
        $refresher->invalidate(['default:job']);

        $this->assertSame(2, $refresher->refresh('/about'));
        $this->assertNull($store->get($about));
        $this->assertNull($store->get($fragment));
        $urls = $this->queuedUrls($store);
        $this->assertSame('https://example.com/about', array_shift($urls), 'first in line');
        sort($urls);
        $this->assertSame(['https://example.com/jobs', 'https://example.com/other'], $urls);

        // A URL the index doesn't know is queued as it is.
        $this->assertSame(0, $refresher->refresh('https://example.com/new'));
        $this->assertContains('https://example.com/new', $this->queuedUrls($store));
        $refresher->work(50.0);
        $this->assertNotNull($store->get(PageKey::fromRequest(new ServerRequest('GET', 'https://example.com/new'), $this->config)));

        $this->expectException(\InvalidArgumentException::class);
        $refresher->refresh('https://elsewhere.test/about');
    }

    public function test_a_bulk_change_queues_each_page_once(): void
    {
        $store = $this->store();
        $this->cache($store, '/jobs', ['default:job']);
        foreach ([1, 2, 3] as $id) {
            $this->cache($store, "/jobs/{$id}", ["default:job#{$id}"]);
        }
        $refresher = $this->refresher($store);

        // An import flushes early, then again at its end (ChangeLog): the same tags twice.
        $tags = [];
        for ($id = 1; $id <= 300; $id++) {
            array_push($tags, ...DataTags::recordChanged('default', 'job', (string) $id));
        }
        $refresher->invalidate($tags);
        $refresher->stale(PageKey::fromRequest(new ServerRequest('GET', 'https://example.com/jobs/1'), $this->config));
        $refresher->invalidate($tags);

        $queued = $this->queue($store)->all();
        $this->assertCount(4, $queued);
        $this->assertSame(4, count(array_unique(array_column($queued, 'id'))));
        $this->assertSame([0, 0, 0, 1], array_column($queued, 'priority'), 'the records first, kept first when queued again');
    }

    public function test_a_new_release_starts_empty_and_warms_from_the_previous_index(): void
    {
        $this->now = microtime(true); // the old release's folder is pruned by its age on disk
        $old = $this->store('1111111111111111');
        $this->cache($old, '/a', ['default:job']);
        $this->cache($old, '/de/b', ['default:job'], ['Accept-Language' => 'de']);
        $this->cache($old, '/jobs?q=x', ['default:job']);
        $this->cache($old, '/a', ['default:job'], ['HX-Request' => 'true']);

        // The deploy: the new release's first request finds another one active.
        $new = $this->store('2222222222222222');
        $key = PageKey::fromRequest(new ServerRequest('GET', 'https://example.com/a'), $this->config);
        $this->assertNull($new->get($key), 'it starts empty');
        $this->assertSame('1111111111111111', trim((string) file_get_contents($new->releaseDir() . '/' . PageStore::WARM_FROM)));

        // The cron's first run opens the new index: the old release's full pages are queued.
        $refresher = $this->refresher($new);
        $this->assertSame(2, $refresher->queued());
        $queued = $this->queue($new)->all();
        $urls = array_column($queued, 'url');
        sort($urls);
        $this->assertSame(['https://example.com/a', 'https://example.com/de/b'], $urls);
        $this->assertSame([false, false], array_column($queued, 'verified'), 'verified again: the templates changed');
        $this->assertFileDoesNotExist($new->releaseDir() . '/' . PageStore::WARM_FROM, 'once');

        $this->assertSame(2, $refresher->work(50.0));
        $this->assertSame('<p>new https://example.com/a</p>', $new->get($key)?->body);
        $this->assertSame('<p>old /a</p>', $old->get($key)?->body, 'the old release is left alone');
        $this->assertSame(0, $this->refresher($new)->queued(), 'not warmed twice');
    }

    public function test_a_failed_invalidation_is_kept_and_applied_when_the_index_is_back(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('root writes read-only files');
        }
        $store = $this->store();
        $key = $this->cache($store, '/jobs', ['default:job']);
        $other = $this->cache($store, '/about', ['menu']);
        $dir = $store->releaseDir();

        // The index can't be written (busy or broken): the invalidation is kept, not dropped.
        foreach (glob($dir . '/' . TagIndex::FILE . '*') ?: [] as $file) {
            chmod($file, 0444);
        }
        try {
            $this->refresher($store)->invalidate(['default:job']);
        } finally {
            foreach (glob($dir . '/' . TagIndex::FILE . '*') ?: [] as $file) {
                chmod($file, 0664);
            }
        }
        $this->assertTrue(PendingInvalidations::exist($dir));
        $this->assertNotNull($store->get($key), 'its page is still there');
        $this->assertStringContainsString('an invalidation is pending', implode("\n", $this->logged));

        // The next request or command that reaches the index applies it.
        $this->assertTrue($this->refresher($store)->applyPending());
        $this->assertFalse(PendingInvalidations::exist($dir));
        $this->assertNull($store->get($key), 'forgotten');
        $this->assertNotNull($store->get($other), 'only what changed');
        $this->assertSame(['https://example.com/jobs'], $this->queuedUrls($store), 'and queued as it would have been');
    }

    public function test_pending_invalidations_are_applied_by_the_next_invalidation_and_by_work(): void
    {
        $store = $this->store();
        $jobs = $this->cache($store, '/jobs', ['default:job']);
        $about = $this->cache($store, '/about', ['menu']);
        (new PendingInvalidations($store->releaseDir()))->add(['default:job'], $this->now - 1);

        $refresher = $this->refresher($store);
        $refresher->invalidate(['menu']);
        $this->assertNull($store->get($jobs));
        $this->assertNull($store->get($about));
        $this->assertFalse(PendingInvalidations::exist($store->releaseDir()));

        $this->cache($store, '/jobs', ['default:job']);
        (new PendingInvalidations($store->releaseDir()))->add(['default:job'], $this->now);
        $this->refresher($store)->work(50.0);
        $this->assertFalse(PendingInvalidations::exist($store->releaseDir()));
        $this->assertStringContainsString('new', $store->get($jobs)?->body ?? '', 'forgotten, then rebuilt');
    }

    public function test_too_many_pending_invalidations_fold_into_everything(): void
    {
        $pending = new PendingInvalidations($this->dir . '/rel');
        for ($i = 0; $i < 2000; $i++) {
            $this->assertTrue($pending->add(["default:job#{$i}", 'default:job'], $this->now + $i));
        }
        $this->assertLessThanOrEqual(PendingInvalidations::MAX_BYTES, filesize($pending->file()));

        $seen = null;
        $this->assertTrue($pending->apply(function (array $tags, float $at) use (&$seen): void {
            $seen = [$tags, $at];
        }));
        $this->assertContains(TagIndex::EVERYTHING, $seen[0]);
        $this->assertSame($this->now, $seen[1], 'from the earliest');
        $this->assertFalse(PendingInvalidations::exist($this->dir . '/rel'));
    }

    public function test_a_rebuild_request_carries_the_key_parts(): void
    {
        $request = Rebuild::request('http://example.com/x', ['locale' => 'de', 'proto' => 'https', 'host' => 'example.com', 'vary' => ['hx-target' => 'list']], 'abc', true);

        $this->assertTrue(InternalRender::is($request));
        $this->assertTrue($request->getAttribute(PageCacheMiddleware::REBUILD));
        $this->assertSame('abc', $request->getAttribute(Rebuild::EXPECTED_ID));
        $this->assertTrue($request->getAttribute(Rebuild::VERIFIED));
        $this->assertSame('de', $request->getHeaderLine('Accept-Language'));
        $this->assertSame('https', $request->getHeaderLine('X-Forwarded-Proto'));
        $this->assertSame('example.com', $request->getHeaderLine('X-Forwarded-Host'));
        $this->assertSame('list', $request->getHeaderLine('HX-Target'));
        $this->assertSame([], $request->getCookieParams());
        $this->assertSame('', $request->getHeaderLine('Cookie'));

        // The same key as the visitor's request it was stored from.
        $visitor = (new ServerRequest('GET', 'http://example.com/x'))->withHeader('X-Forwarded-Proto', 'https')->withHeader('HX-Target', 'list');
        $parts = ['locale' => 'de', 'proto' => 'https'];
        $this->assertSame(
            PageKey::fromRequest($visitor, $this->config, $parts, self::htmx())->id(),
            PageKey::fromRequest($request, $this->config, $parts, self::htmx())->id(),
        );
        $this->assertSame(['locale' => 'de', 'proto' => 'https', 'vary' => ['hx-target' => 'list']], PageKey::fromRequest($visitor, $this->config, $parts, self::htmx())->parts());
    }

    /** htmx as the app's hypermedia client (what core binds by default). */
    private static function htmx(): \Preflow\Core\Http\Hypermedia\HypermediaProtocols
    {
        $protocols = new \Preflow\Core\Http\Hypermedia\HypermediaProtocols();
        $protocols->add(new \Preflow\Htmx\HtmxDriver(new \Preflow\Htmx\ResponseHeaders()));

        return $protocols;
    }
}
