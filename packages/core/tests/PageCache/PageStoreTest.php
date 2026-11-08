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

    private function key(string $url, string $method = 'GET'): PageKey
    {
        return PageKey::fromRequest(new ServerRequest($method, $url), new PageCacheConfig(hosts: ['example.com']));
    }

    private function file(PageKey $key, string $release = 'rel1', string $ext = 'page'): string
    {
        return $this->root . '/' . $release . '/' . substr($key->id(), 0, 2) . '/' . $key->id() . '.' . $ext;
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
        $this->assertFileExists($this->file($key, ext: 'stale'));
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

        $noCreated = '{"h":[],"b":0}';
        $variants = [substr($full, 0, -3), 'garbage', '', "PFPC1\n12\n{not json", "PFPC1\nxx\n{}", $full . 'extra',
            "PFPC1\n" . strlen($noCreated) . "\n" . $noCreated];
        foreach ($variants as $broken) {
            file_put_contents($file, $broken);
            $this->assertNull($store->get($key), 'for: ' . substr($broken, 0, 20));
        }
    }

    public function test_another_release_does_not_see_the_page(): void
    {
        $key = $this->key('https://example.com/x');
        $one = $this->store('rel1');
        $one->put($key, new StoredPage('one', [], 1.0));

        $this->assertNull($this->store('rel2')->get($key));
        // The process of release 1 that is already running still sees its page.
        $this->assertNotNull($one->get($key));
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
        // This release's own folder is old too, and stays.
        mkdir($this->root . '/current');
        touch($this->root . '/current', (int) $this->now - 90000);
        file_put_contents($this->root . '/' . PageStore::ACTIVE, 'current');

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
        $base = dirname($this->root, 3) . '/releases/1';
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

        // Reached through a deploy's `current` link: the release folder it points at counts.
        $current = dirname($base, 2) . '/current';
        symlink($base, $current);
        $this->assertSame(PageStore::releaseKey($base), PageStore::releaseKey($current));
        mkdir(dirname($base) . '/2/vendor/composer', 0777, true);
        copy($base . '/vendor/composer/installed.json', dirname($base) . '/2/vendor/composer/installed.json');
        touch(dirname($base) . '/2/vendor/composer/installed.json', 1_700_000_100);
        mkdir(dirname($base) . '/2/bootstrap/cache', 0777, true);
        copy($base . '/bootstrap/cache/boot.php', dirname($base) . '/2/bootstrap/cache/boot.php');
        touch(dirname($base) . '/2/bootstrap/cache/boot.php', (int) filemtime($base . '/bootstrap/cache/boot.php'));
        $this->assertNotSame(PageStore::releaseKey($base), PageStore::releaseKey(dirname($base) . '/2'));
    }

    public function test_the_cache_source_lists_and_clears_every_release(): void
    {
        $this->store('rel1')->put($this->key('https://example.com/a'), new StoredPage('aaaa', [], 1.0));
        $this->store('rel1')->put($this->key('https://example.com/b'), new StoredPage('bbbb', [], 1.0));
        $this->store('rel0')->put($this->key('https://example.com/a'), new StoredPage('old', [], 1.0));
        $index = TagIndex::in($this->root . '/rel1');
        $index->available(); // the index exists before the pages (a new index empties its release)
        $this->store('rel1')->put($this->key('https://example.com/a'), new StoredPage('aaaa', [], 1.0));
        $this->store('rel1')->put($this->key('https://example.com/b'), new StoredPage('bbbb', [], 1.0));
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
        // A render that started before the clear must not store its page after it.
        $this->assertTrue($index->invalidatedSince(['news'], microtime(true) - 5));
    }

    public function test_the_cache_source_of_a_missing_folder_is_empty(): void
    {
        $source = new PageCacheSource($this->root . '/nothing', static fn (): string => 'x');

        $this->assertSame(0, $source->stats()->entries);
        $this->assertSame(0, $source->clear()->entries);
    }

    public function test_a_rollback_to_an_older_release_starts_empty(): void
    {
        $key = $this->key('https://example.com/news');
        $this->store('rel5')->put($key, new StoredPage('release 5 news', [], 1.0));
        $this->assertSame('rel5', file_get_contents($this->root . '/' . PageStore::ACTIVE));

        // Release 6 goes live and is used; the news change is recorded only there.
        $this->assertNull($this->store('rel6')->get($key));
        $this->assertSame('rel6', file_get_contents($this->root . '/' . PageStore::ACTIVE));

        // Rolled back: release 5 must not serve its page from before release 6.
        $this->assertNull($this->store('rel5')->get($key));
        $this->assertSame('rel5', file_get_contents($this->root . '/' . PageStore::ACTIVE));
        $this->assertFileDoesNotExist($this->file($key, 'rel5'));
    }

    public function test_a_rollback_empties_the_release_index_too(): void
    {
        $key = $this->key('https://example.com/news');
        $index = TagIndex::in($this->root . '/rel5');
        $index->record($key->id(), $key->url(), false, false, ['news' => [\Preflow\Core\PageCache\Freshness::Fresh, null]]);
        $this->store('rel5')->put($key, new StoredPage('5', [], 1.0));
        $this->store('rel6')->get($key);

        $this->store('rel5')->get($key);

        $this->assertSame([], $index->pagesFor(['news']));
    }

    public function test_a_rollback_empties_the_rows_before_the_files(): void
    {
        $key = $this->key('https://example.com/news');
        TagIndex::in($this->root . '/rel5')->record($key->id(), $key->url(), false, false, ['news' => [\Preflow\Core\PageCache\Freshness::Fresh, null]]);
        $this->store('rel5')->put($key, new StoredPage('5', [], 1.0));
        $this->store('rel6')->get($key);

        // Between the two steps: no rows left, the file still there (a page with rows but no file
        // is a miss; one with a file but no rows could never be refreshed).
        $seen = null;
        $five = $this->store('rel5');
        $five->beforeActivationFiles(function () use ($key, &$seen): void {
            $seen = [TagIndex::in($this->root . '/rel5')->page($key->id()), is_file($this->file($key, 'rel5'))];
        });
        $five->get($key);

        $this->assertSame([null, true], $seen);
        $this->assertFileDoesNotExist($this->file($key, 'rel5'));
    }

    public function test_a_rollback_drops_the_release_queue_and_pending_invalidations(): void
    {
        $config = new PageCacheConfig(hosts: ['example.com']);
        $five = $this->store('rel5');
        $index = TagIndex::forStore($five, $config);
        (new \Preflow\Core\PageCache\RefreshQueue($index->pdo() ?? throw new \RuntimeException('no index')))->push(['p' => ['url' => 'https://example.com/p']]);
        $index->close();
        (new \Preflow\Core\PageCache\PendingInvalidations($five->releaseDir()))->add(['news'], 1.0);
        $this->store('rel6')->get($this->key('https://example.com/news'));

        // Rolled back: what was queued and pending concerned pages that are gone.
        $again = $this->store('rel5');
        $again->get($this->key('https://example.com/news'));

        $pdo = TagIndex::in($this->root . '/rel5')->pdo() ?? throw new \RuntimeException('no index');
        $this->assertSame(0, (new \Preflow\Core\PageCache\RefreshQueue($pdo))->count());
        $this->assertFalse(\Preflow\Core\PageCache\PendingInvalidations::exist($again->releaseDir()));
    }

    public function test_the_same_release_keeps_its_pages_across_processes(): void
    {
        $key = $this->key('https://example.com/news');
        $this->store('rel5')->put($key, new StoredPage('kept', [], 1.0));

        $this->assertSame('kept', $this->store('rel5')->get($key)->body);
    }

    public function test_pruning_never_removes_the_active_release(): void
    {
        $this->store('other')->put($this->key('https://example.com/a'), new StoredPage('a', [], 1.0));
        touch($this->root . '/other', (int) $this->now - 90000);
        foreach (glob($this->root . '/other/*') as $shard) {
            touch($shard, (int) $this->now - 90000);
        }
        $store = $this->store('current');
        $store->get($this->key('https://example.com/b')); // activated
        file_put_contents($this->root . '/' . PageStore::ACTIVE, 'other'); // another process since

        $store->put($this->key('https://example.com/b'), new StoredPage('b', [], 1.0));

        $this->assertDirectoryExists($this->root . '/other');
    }

    public function test_mark_stale_never_puts_back_an_old_page(): void
    {
        $key = $this->key('https://example.com/p');
        $store = $this->store();
        $store->put($key, new StoredPage('old', [], 1.0));
        $before = file_get_contents($this->file($key));

        $store->markStale($key->id());

        // The page file itself is untouched; the mark is a marker file.
        $this->assertSame($before, file_get_contents($this->file($key)));
        // A page stored after the mark is fresh, with its own body.
        $store->put($key, new StoredPage('new', [], 2.0));
        $page = $this->store()->get($key);
        $this->assertSame('new', $page->body);
        $this->assertFalse($page->stale);
        $this->assertFileDoesNotExist($this->file($key, ext: 'stale'));
        // A forgotten page is not brought back by a late mark.
        $store->markStale($key->id());
        $store->forget($key->id());
        $store->markStale($key->id());
        $this->assertNull($this->store()->get($key));
        $this->assertFileDoesNotExist($this->file($key, ext: 'stale'));
    }

    public function test_a_put_page_is_never_stale(): void
    {
        $key = $this->key('https://example.com/p');
        $this->store()->put($key, new StoredPage('x', [], 1.0, stale: true));

        $this->assertFalse($this->store()->get($key)->stale);
    }

    public function test_a_head_response_is_never_stored(): void
    {
        $head = $this->key('https://example.com/p', 'HEAD');

        $this->assertFalse($this->store()->put($head, new StoredPage('', [], 1.0)));
        $this->assertNull($this->store()->get($this->key('https://example.com/p')));
        $this->assertTrue($this->store()->put($this->key('https://example.com/p'), new StoredPage('x', [], 1.0)));
        // HEAD is served from GET's page.
        $this->assertSame('x', $this->store()->get($head)->body);
    }

    public function test_a_clear_racing_a_put_does_not_fail_the_put(): void
    {
        $key = $this->key('https://example.com/p');
        $shard = $this->root . '/rel1/' . substr($key->id(), 0, 2);
        // The clock is asked on the first write, between creating the shard folder and writing
        // the page: a cache:clear removes the folder right then.
        $store = new PageStore($this->root, 'rel1', function () use ($shard): float {
            @rmdir($shard);

            return $this->now;
        });

        $store->put($key, new StoredPage('x', [], 1.0));

        $this->assertSame('x', $store->get($key)->body);
    }

    public function test_after_a_rollback_the_index_is_emptied_before_it_records(): void
    {
        $key = $this->key('https://example.com/news');
        $config = new PageCacheConfig(hosts: ['example.com']);
        $five = $this->store('rel5');
        TagIndex::forStore($five, $config)->record($key->id(), $key->url(), false, false, ['old' => [\Preflow\Core\PageCache\Freshness::Fresh, null]]);
        $five->put($key, new StoredPage('5', [], 1.0));
        $this->store('rel6')->get($key);

        // A rebuild in release 5 after the rollback: index first, then the page.
        $store = $this->store('rel5');
        $index = TagIndex::forStore($store, $config);
        $this->assertSame([], $index->pagesFor(['old']));
        $index->record($key->id(), $key->url(), false, false, ['news' => [\Preflow\Core\PageCache\Freshness::Fresh, null]]);
        $store->put($key, new StoredPage('5 again', [], 2.0));

        $this->assertSame([$key->id() => \Preflow\Core\PageCache\Freshness::Fresh], $index->pagesFor(['news']));
        $this->assertSame('5 again', $store->get($key)->body);
    }

    public function test_concurrent_activations_after_a_rollback_clear_only_once(): void
    {
        $key = $this->key('https://example.com/news');
        $config = new PageCacheConfig(hosts: ['example.com']);
        $this->store('rel5')->put($key, new StoredPage('before release 6', [], 1.0));
        $this->store('rel6')->get($key); // release 6 is active

        // Rolled back. Request B reads `active` (rel6) ... meanwhile request A activates, records
        // and stores P ... then B carries on.
        $b = $this->store('rel5');
        $b->beforeActivationLock(function () use ($key, $config): void {
            $a = $this->store('rel5');
            TagIndex::forStore($a, $config)->record($key->id(), $key->url(), false, false, ['news' => [\Preflow\Core\PageCache\Freshness::Fresh, null]]);
            $a->put($key, new StoredPage('after the rollback', [], 2.0));
        });
        $b->get($this->key('https://example.com/other'));

        // B found release 5 claimed once it held the lock and cleared nothing: P has its file and its rows.
        $this->assertSame('after the rollback', $this->store('rel5')->get($key)->body);
        $this->assertSame([$key->id() => \Preflow\Core\PageCache\Freshness::Fresh], TagIndex::in($this->root . '/rel5')->pagesFor(['news']));
    }
}
