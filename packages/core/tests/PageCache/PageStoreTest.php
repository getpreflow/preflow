<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\PageCache;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Preflow\Core\PageCache\PageCacheConfig;
use Preflow\Core\PageCache\PageCacheSource;
use Preflow\Core\PageCache\PageKey;
use Preflow\Core\PageCache\PageStore;
use Preflow\Core\PageCache\StoredPage;
use Preflow\Core\PageCache\TagIndex;

final class PageStoreTest extends TestCase
{
    private string $root;

    private float $now;

    protected function setUp(): void
    {
        $this->now = (float) time(); // file mtimes are real
        $this->root = sys_get_temp_dir() . '/pagestore_' . bin2hex(random_bytes(4)) . '/storage/cache/pages';
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg(dirname($this->root, 3)));
    }

    private function store(string $release = 'rel1'): PageStore
    {
        return new PageStore($this->root, $release, fn (): float => $this->now);
    }

    private function key(string $url): PageKey
    {
        return PageKey::fromRequest(new ServerRequest('GET', $url), new PageCacheConfig());
    }

    public function test_put_and_get_round_trip_with_headers(): void
    {
        $store = $this->store();
        $key = $this->key('https://example.com/de/x');
        $body = "<html>\nÄ ö 🙂\0 bytes\n</html>";
        $store->put($key, new StoredPage($body, ['Content-Type' => ['text/html; charset=utf-8'], 'Vary' => ['HX-Request', 'X-Gallery-Load']], 1234.5, 99999.0));

        $page = $this->store()->get($key);

        $this->assertNotNull($page);
        $this->assertSame($body, $page->body);
        $this->assertSame(['Content-Type' => ['text/html; charset=utf-8'], 'Vary' => ['HX-Request', 'X-Gallery-Load']], $page->headers);
        $this->assertSame(1234.5, $page->createdAt);
        $this->assertSame(99999.0, $page->expiresAt);
        $this->assertFalse($page->stale);
        $id = $key->id();
        $this->assertFileExists($this->root . '/rel1/' . substr($id, 0, 2) . '/' . $id . '.page');
        $this->assertSame([], glob($this->root . '/rel1/*/*.tmp'));
    }

    public function test_a_missing_page_is_null(): void
    {
        $this->assertNull($this->store()->get($this->key('https://example.com/none')));
    }

    public function test_a_stale_mark_survives_a_reread(): void
    {
        $key = $this->key('https://example.com/news');
        $this->store()->put($key, new StoredPage('body', [], 1.0));

        $this->store()->markStale($key->id());

        $page = $this->store()->get($key);
        $this->assertTrue($page->stale);
        $this->assertSame('body', $page->body);
        // Marking a page that is not there does nothing.
        $this->store()->markStale(str_repeat('a', 40));
        $this->assertFileDoesNotExist($this->root . '/rel1/aa/' . str_repeat('a', 40) . '.page');
    }

    public function test_a_corrupt_file_is_no_page(): void
    {
        $key = $this->key('https://example.com/x');
        $store = $this->store();
        $store->put($key, new StoredPage('a body long enough', ['X' => ['1']], 1.0));
        $file = $this->root . '/rel1/' . substr($key->id(), 0, 2) . '/' . $key->id() . '.page';
        $full = file_get_contents($file);

        foreach ([substr($full, 0, -3), 'garbage', '', "PFPC1\n12\n{not json", "PFPC1\nxx\n{}", $full . 'extra'] as $broken) {
            file_put_contents($file, $broken);
            $this->assertNull($store->get($key), 'for: ' . substr($broken, 0, 20));
        }
    }

    public function test_another_release_does_not_see_the_page(): void
    {
        $key = $this->key('https://example.com/x');
        $this->store('rel1')->put($key, new StoredPage('one', [], 1.0));

        $this->assertNull($this->store('rel2')->get($key));
        $this->assertNotNull($this->store('rel1')->get($key));
    }

    public function test_forget_removes_a_page(): void
    {
        $key = $this->key('https://example.com/x');
        $this->store()->put($key, new StoredPage('one', [], 1.0));

        $this->store()->forget($key->id());

        $this->assertNull($this->store()->get($key));
    }

    public function test_clear_counts_the_pages_of_its_release(): void
    {
        $store = $this->store();
        foreach (['a', 'b', 'c'] as $path) {
            $store->put($this->key('https://example.com/' . $path), new StoredPage($path, [], 1.0));
        }
        $this->store('rel2')->put($this->key('https://example.com/a'), new StoredPage('a', [], 1.0));

        $this->assertSame(3, $store->clear());
        $this->assertSame(0, $store->clear());
        $this->assertNull($store->get($this->key('https://example.com/a')));
        $this->assertNotNull($this->store('rel2')->get($this->key('https://example.com/a')));
    }

    public function test_the_first_write_prunes_other_releases_older_than_a_day(): void
    {
        $this->store('old')->put($this->key('https://example.com/a'), new StoredPage('a', [], 1.0));
        $this->store('recent')->put($this->key('https://example.com/a'), new StoredPage('a', [], 1.0));
        touch($this->root . '/old', (int) $this->now - 90000);
        foreach (glob($this->root . '/old/*') as $shard) {
            touch($shard, (int) $this->now - 90000);
        }
        touch($this->root . '/recent', (int) $this->now - 3600);

        $this->store('current')->put($this->key('https://example.com/b'), new StoredPage('b', [], 1.0));

        $this->assertDirectoryDoesNotExist($this->root . '/old');
        $this->assertDirectoryExists($this->root . '/recent');
        $this->assertDirectoryExists($this->root . '/current');
    }

    public function test_a_recently_used_index_keeps_an_old_release_folder(): void
    {
        $this->store('old')->put($this->key('https://example.com/a'), new StoredPage('a', [], 1.0));
        file_put_contents($this->root . '/old/' . TagIndex::FILE, 'x');
        touch($this->root . '/old/' . TagIndex::FILE, (int) $this->now - 60);
        touch($this->root . '/old', (int) $this->now - 90000);

        $this->store('current')->put($this->key('https://example.com/b'), new StoredPage('b', [], 1.0));

        $this->assertDirectoryExists($this->root . '/old');
    }

    public function test_the_release_key_follows_the_folder_the_manifest_and_composer(): void
    {
        $base = dirname($this->root, 3);
        mkdir($base . '/vendor/composer', 0777, true);
        file_put_contents($base . '/vendor/composer/installed.json', '{}');
        touch($base . '/vendor/composer/installed.json', 1_700_000_000);
        $first = PageStore::releaseKey($base);

        $this->assertSame($first, PageStore::releaseKey($base . '/'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $first);

        touch($base . '/vendor/composer/installed.json', 1_700_000_100);
        $second = PageStore::releaseKey($base);
        $this->assertNotSame($first, $second);

        mkdir($base . '/bootstrap/cache', 0777, true);
        file_put_contents($base . '/bootstrap/cache/boot.php', '<?php return [];');
        $this->assertNotSame($second, PageStore::releaseKey($base));

        $this->assertNotSame(PageStore::releaseKey($base), PageStore::releaseKey($base . '/storage'));
    }

    public function test_the_cache_source_lists_and_clears_every_release(): void
    {
        $this->store('rel1')->put($this->key('https://example.com/a'), new StoredPage('aaaa', [], 1.0));
        $this->store('rel1')->put($this->key('https://example.com/b'), new StoredPage('bbbb', [], 1.0));
        $this->store('rel0')->put($this->key('https://example.com/a'), new StoredPage('old', [], 1.0));
        $index = TagIndex::in($this->root . '/rel1');
        $index->record($this->key('https://example.com/a')->id(), 'https://example.com/a', false, false, ['news' => [\Preflow\Core\PageCache\Freshness::Fresh, null]]);
        $source = new PageCacheSource($this->root, static fn (): string => 'rel1');

        $this->assertSame('pages', $source->key());
        $this->assertSame(3, $source->stats()->entries);
        $this->assertGreaterThan(0, $source->stats()->bytes);

        $cleared = $source->clear();

        $this->assertSame(3, $cleared->entries);
        $this->assertGreaterThan(0, $cleared->bytes);
        $this->assertSame(0, $source->stats()->entries);
        $this->assertDirectoryDoesNotExist($this->root . '/rel0');
        // The current index is emptied, not removed: an open connection keeps working.
        $this->assertSame([], $index->pagesFor(['news']));
        $this->assertFileExists($this->root . '/rel1/' . TagIndex::FILE);
    }

    public function test_the_cache_source_of_a_missing_folder_is_empty(): void
    {
        $source = new PageCacheSource($this->root . '/nothing', static fn (): string => 'x');

        $this->assertSame(0, $source->stats()->entries);
        $this->assertSame(0, $source->clear()->entries);
    }
}
