<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\PageCache;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Preflow\Core\PageCache\DataTags;
use Preflow\Core\PageCache\DependencyRecorder;
use Preflow\Core\PageCache\Freshness;
use Preflow\Core\PageCache\PageCacheConfig;
use Preflow\Core\PageCache\PageKey;
use Preflow\Core\PageCache\PageStore;
use Preflow\Core\PageCache\Refresher;
use Preflow\Core\PageCache\StoredPage;
use Preflow\Core\PageCache\TagIndex;

final class RefresherTest extends TestCase
{
    private string $dir;

    private float $now = 1_800_000_000.0;

    private PageCacheConfig $config;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/refresher_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->config = new PageCacheConfig(enabled: true, hosts: ['example.com']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function store(): PageStore
    {
        return new PageStore($this->dir . '/pages', 'rel1', fn (): float => $this->now);
    }

    /**
     * Stores a page at $path as a render that read $reads would (tags through the recorder).
     *
     * @param list<string> $reads
     */
    private function cache(PageStore $store, string $path, array $reads, Freshness $freshness = Freshness::Fresh): PageKey
    {
        $key = PageKey::fromRequest(new ServerRequest('GET', 'https://example.com' . $path), $this->config);
        $recorder = new DependencyRecorder();
        $recorder->start();
        $recorder->within($freshness, $freshness === Freshness::Timed ? 600 : null, fn () => $recorder->record(...$reads));
        $index = TagIndex::forStore($store, $this->config, fn (): float => $this->now);
        $index->record($key->id(), $key->url(), false, false, $recorder->stop());
        $index->close();
        $store->put($key, new StoredPage('<p>' . $path . '</p>', [], $this->now));

        return $key;
    }

    public function test_a_record_write_forgets_the_record_and_listing_pages_only(): void
    {
        $store = $this->store();
        $detail = $this->cache($store, '/job/5', ['default:job#5']);
        $other = $this->cache($store, '/job/6', ['default:job#6']);
        $list = $this->cache($store, '/jobs', ['default:job']);
        $raw = $this->cache($store, '/stats', ['default:*']);
        $news = $this->cache($store, '/news', ['default:news']);

        (new Refresher($store, $this->config, fn (): float => $this->now))->invalidate(DataTags::recordChanged('default', 'job', '5'));

        $this->assertNull($store->get($detail));
        $this->assertNull($store->get($list));
        $this->assertNull($store->get($raw), 'a page with an unsure raw read depends on the whole storage');
        $this->assertNotNull($store->get($other));
        $this->assertNotNull($store->get($news));
        $index = TagIndex::forStore($store, $this->config);
        $this->assertSame([], $index->pagesFor(['default:job#5', 'default:job', 'default:*']));
        $this->assertCount(2, $index->pagesFor(['default:job#6', 'default:news']));
    }

    public function test_a_bulk_write_forgets_every_record_page_of_the_table(): void
    {
        $store = $this->store();
        $detail = $this->cache($store, '/job/5', ['default:job#5']);
        $other = $this->cache($store, '/job/6', ['default:job#6']);
        $published = $this->cache($store, '/p/1', ['default:job__published#1']);

        (new Refresher($store, $this->config))->invalidate(DataTags::tableChanged('default', 'job'));

        $this->assertNull($store->get($detail));
        $this->assertNull($store->get($other));
        $this->assertNotNull($store->get($published), 'another table');
    }

    public function test_the_invalidation_is_recorded_for_renders_in_flight(): void
    {
        $store = $this->store();
        $refresher = new Refresher($store, $this->config, fn (): float => $this->now);
        $renderStart = $this->now - 1;

        $refresher->invalidate(DataTags::tableChanged('default', 'job'));

        $index = TagIndex::forStore($store, $this->config, fn (): float => $this->now);
        $this->assertTrue($index->invalidatedSince(['default:job#9', 'default:job#'], $renderStart), 'a record page read during a bulk write');
        $this->assertFalse($index->invalidatedSince(['default:news'], $renderStart));
        $this->assertFalse($index->invalidatedSince(['default:job'], $this->now), 'only after the render started');
    }

    public function test_everything_forgets_every_page_and_counts_for_every_tag(): void
    {
        $store = $this->store();
        $a = $this->cache($store, '/a', ['default:job#5']);
        $b = $this->cache($store, '/b', []);

        (new Refresher($store, $this->config, fn (): float => $this->now))->invalidate([TagIndex::EVERYTHING]);

        $this->assertNull($store->get($a));
        $this->assertNull($store->get($b));
        $index = TagIndex::forStore($store, $this->config, fn (): float => $this->now);
        $this->assertSame(0, $index->count());
        $this->assertTrue($index->invalidatedSince(['menu'], $this->now - 1));
    }

    public function test_freshness_does_not_yet_spare_a_page(): void
    {
        // Task 5 adds next visit (mark stale) and timed (ignore); until then every match goes.
        $store = $this->store();
        $timed = $this->cache($store, '/t', ['default:company'], Freshness::Timed);

        (new Refresher($store, $this->config))->invalidate(DataTags::tableChanged('default', 'company'));

        $this->assertNull($store->get($timed));
    }

    public function test_an_unavailable_index_does_nothing_and_touches_no_file(): void
    {
        (new Refresher(new PageStore($this->dir . '/none', 'rel1'), $this->config, sqlite: false))->invalidate(['default:job']);
        (new Refresher(new PageStore($this->dir . '/none', 'rel1'), $this->config))->invalidate([]);

        $this->assertDirectoryDoesNotExist($this->dir . '/none');
    }

    public function test_a_command_from_the_release_before_a_deploy_also_reaches_the_active_release(): void
    {
        $old = new PageStore($this->dir . '/pages', str_repeat('a', 16), fn (): float => $this->now);
        $import = new Refresher($old, $this->config, fn (): float => $this->now);
        $import->invalidate(['default:job#1']); // its first flush: the old release claims active

        // The new release serves a request: it reclaims active and caches a page.
        $new = new PageStore($this->dir . '/pages', str_repeat('b', 16), fn (): float => $this->now);
        $page = $this->cache($new, '/job/5', ['default:job#5']);
        $this->assertSame(str_repeat('b', 16), $new->activeRelease());

        $import->invalidate(DataTags::recordChanged('default', 'job', '5'));
        $this->assertNull($new->get($page), 'the active release\'s page is forgotten too');

        // A new refresher of the old release (a second command) reaches it as well.
        $page = $this->cache(new PageStore($this->dir . '/pages', str_repeat('b', 16), fn (): float => $this->now), '/job/5', ['default:job#5']);
        (new Refresher(new PageStore($this->dir . '/pages', str_repeat('a', 16)), $this->config))->invalidate(DataTags::recordChanged('default', 'job', '5'));
        $this->assertNull($new->get($page));
    }
}
