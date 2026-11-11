<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\PageCache;

require_once __DIR__ . '/../Fixtures/Pluggable/SignalDriver.php';

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Preflow\Core\PageCache\PageCacheConfig;
use Preflow\Core\PageCache\PageKey;

final class PageKeyTest extends TestCase
{
    private function key(string $url, array $headers = [], string $method = 'GET', array $config = []): ?PageKey
    {
        $config += ['hosts' => ['example.com', 'other.example']];

        return PageKey::fromRequest(new ServerRequest($method, $url, $headers), PageCacheConfig::fromArray($config), [], self::htmx());
    }

    public function test_the_query_is_sorted(): void
    {
        $a = $this->key('https://example.com/list?b=2&a=1');
        $b = $this->key('https://example.com/list?a=1&b=2');

        $this->assertSame($a->id(), $b->id());
        $this->assertSame('https://example.com/list?a=1&b=2', $a->url());
        $this->assertTrue($a->hasQuery());
    }

    public function test_repeated_names_keep_their_order(): void
    {
        $this->assertNotSame(
            $this->key('https://example.com/list?a[]=2&a[]=1')->id(),
            $this->key('https://example.com/list?a[]=1&a[]=2')->id(),
        );
    }

    public function test_indexed_array_parameters_keep_their_order(): void
    {
        $this->assertNotSame(
            $this->key('https://example.com/list?a[1]=x&a[0]=y')->id(),
            $this->key('https://example.com/list?a[0]=y&a[1]=x')->id(),
        );
        $this->assertNotSame(
            $this->key('https://example.com/list?a[]=1&a[x]=2&a[]=3')->id(),
            $this->key('https://example.com/list?a[]=1&a[]=3&a[x]=2')->id(),
        );
        // Different base names are still sorted.
        $this->assertSame(
            $this->key('https://example.com/list?b=1&a[0]=2&a[1]=3')->id(),
            $this->key('https://example.com/list?a[0]=2&a[1]=3&b=1')->id(),
        );
    }

    public function test_only_configured_hosts_are_cacheable(): void
    {
        $this->assertNull($this->key('https://alias.example/x'));
        $this->assertNull($this->key('https://evil.test/x', config: ['hosts' => []]));

        $config = PageCacheConfig::fromArray([], 'https://Site.example/base');
        $this->assertNotNull(PageKey::fromRequest(new ServerRequest('GET', 'https://site.example/x'), $config));
        $this->assertNotNull(PageKey::fromRequest(new ServerRequest('GET', 'http://site.example:8080/x'), $config));
        $this->assertNull(PageKey::fromRequest(new ServerRequest('GET', 'https://www.site.example/x'), $config));
        // No app.url and no hosts: nothing is cached.
        $this->assertNull(PageKey::fromRequest(new ServerRequest('GET', 'https://site.example/x'), new PageCacheConfig()));
    }

    public function test_ignored_parameters_are_dropped_with_globbing(): void
    {
        $plain = $this->key('https://example.com/news');
        $tracked = $this->key('https://example.com/news?utm_source=mail&utm_campaign=x&fbclid=abc&gclid=1&mc_cid=2&mc_eid=3');

        $this->assertSame($plain->id(), $tracked->id());
        $this->assertSame('https://example.com/news', $tracked->url());
        $this->assertFalse($tracked->hasQuery());

        $mixed = $this->key('https://example.com/news?page=2&UTM_Medium=x');
        $this->assertSame($this->key('https://example.com/news?page=2')->id(), $mixed->id());
    }

    public function test_ignore_query_is_configurable(): void
    {
        $key = $this->key('https://example.com/news?ref=a&page=2', config: ['ignore_query' => ['ref']]);

        $this->assertSame('https://example.com/news?page=2', $key->url());
    }

    public function test_equivalent_encodings_share_a_key(): void
    {
        $this->assertSame(
            $this->key('https://example.com/s?q=a+b')->id(),
            $this->key('https://example.com/s?q=a%20b')->id(),
        );
    }

    public function test_without_cache_query_a_url_with_a_query_is_not_cacheable(): void
    {
        $this->assertNull($this->key('https://example.com/search?q=wood', config: ['cache_query' => false]));
        // Only tracking parameters: no query left, so it is cacheable.
        $this->assertNotNull($this->key('https://example.com/search?utm_source=x', config: ['cache_query' => false]));
        $this->assertNotNull($this->key('https://example.com/search', config: ['cache_query' => false]));
    }

    public function test_htmx_request_and_target_change_the_id(): void
    {
        $page = $this->key('https://example.com/gallery');
        $fragment = $this->key('https://example.com/gallery', ['HX-Request' => 'true', 'HX-Target' => 'items']);
        $other = $this->key('https://example.com/gallery', ['HX-Request' => 'true', 'HX-Target' => 'detail']);

        $this->assertNotSame($page->id(), $fragment->id());
        $this->assertNotSame($fragment->id(), $other->id());
        $this->assertTrue($fragment->isFragment());
        $this->assertFalse($page->isFragment());
    }

    public function test_configured_vary_headers_change_the_id(): void
    {
        $config = ['vary_headers' => ['HX-Request', 'HX-Target', 'X-Gallery-Load']];
        $a = $this->key('https://example.com/g', ['HX-Request' => 'true', 'X-Gallery-Load' => 'more'], config: $config);
        $b = $this->key('https://example.com/g', ['HX-Request' => 'true'], config: $config);
        // Not configured: ignored.
        $c = $this->key('https://example.com/g', ['HX-Request' => 'true', 'X-Gallery-Load' => 'more']);
        $d = $this->key('https://example.com/g', ['HX-Request' => 'true']);

        $this->assertNotSame($a->id(), $b->id());
        $this->assertSame($c->id(), $d->id());
    }

    public function test_a_history_restore_is_the_full_page(): void
    {
        $restore = $this->key('https://example.com/gallery', [
            'HX-Request' => 'true',
            'HX-History-Restore-Request' => 'true',
            'HX-Target' => 'main',
        ]);

        $this->assertFalse($restore->isFragment());
        $this->assertSame($this->key('https://example.com/gallery')->id(), $restore->id());
    }

    public function test_a_history_restore_still_varies_on_non_htmx_headers(): void
    {
        $config = ['vary_headers' => ['HX-Request', 'HX-Target', 'Accept-Language']];
        $headers = ['HX-Request' => 'true', 'HX-History-Restore-Request' => 'true'];
        $de = $this->key('https://example.com/g', $headers + ['Accept-Language' => 'de'], config: $config);
        $fr = $this->key('https://example.com/g', $headers + ['Accept-Language' => 'fr'], config: $config);
        $page = $this->key('https://example.com/g', ['Accept-Language' => 'de'], config: $config);

        $this->assertNotSame($de->id(), $fr->id());
        $this->assertSame($page->id(), $de->id());
    }

    public function test_only_get_and_head_are_cacheable(): void
    {
        $this->assertNull($this->key('https://example.com/contact', method: 'POST'));
        $this->assertNull($this->key('https://example.com/contact', method: 'DELETE'));
        $this->assertSame(
            $this->key('https://example.com/contact')->id(),
            $this->key('https://example.com/contact', method: 'HEAD')->id(),
        );
        $this->assertTrue($this->key('https://example.com/contact', method: 'HEAD')->isHead());
        $this->assertFalse($this->key('https://example.com/contact')->isHead());
    }

    public function test_locales_and_hosts_are_different_pages(): void
    {
        $this->assertNotSame($this->key('https://example.com/de/x')->id(), $this->key('https://example.com/fr/x')->id());
        $this->assertNotSame($this->key('https://example.com/x')->id(), $this->key('https://other.example/x')->id());
        $this->assertSame($this->key('https://Example.com/x')->id(), $this->key('https://example.com/x')->id());
    }

    public function test_a_request_without_host_is_not_cacheable(): void
    {
        $this->assertNull($this->key('/x'));
    }

    public function test_the_id_is_a_sha1(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $this->key('https://example.com/')->id());
    }

    public function test_the_clients_headers_decide_fragments_and_vary(): void
    {
        $clients = new \Preflow\Core\Http\Hypermedia\HypermediaProtocols();
        $clients->add(new \Preflow\Core\Tests\Fixtures\Pluggable\SignalDriver(new \Preflow\View\AssetCollector(new \Preflow\View\NonceGenerator())));
        $key = static fn (array $headers): PageKey => PageKey::fromRequest(new ServerRequest('GET', 'https://example.com/g', $headers), PageCacheConfig::fromArray(['hosts' => ['example.com']]), [], $clients);

        $page = $key([]);
        $fragment = $key(['Signal-Request' => 'true', 'Signal-Target' => 'list']);
        $this->assertFalse($page->isFragment());
        $this->assertTrue($fragment->isFragment());
        $this->assertNotSame($fragment->id(), $key(['Signal-Request' => 'true', 'Signal-Target' => 'detail'])->id(), 'keyed on the client\'s headers');
        $htmx = $key(['HX-Request' => 'true', 'HX-Target' => 'list']);
        $this->assertFalse($htmx->isFragment(), 'htmx headers mean nothing to this app');
        $this->assertSame($page->id(), $htmx->id());
    }

    /** htmx as the app's hypermedia client (what core binds by default). */
    private static function htmx(): \Preflow\Core\Http\Hypermedia\HypermediaProtocols
    {
        $protocols = new \Preflow\Core\Http\Hypermedia\HypermediaProtocols();
        $protocols->add(new \Preflow\Htmx\HtmxDriver(new \Preflow\Htmx\ResponseHeaders()));

        return $protocols;
    }
}
