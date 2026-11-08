<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\PageCache;

require_once __DIR__ . '/Fixtures/NegotiatedLocaleRedirect.php';
require_once __DIR__ . '/Fixtures/BrokenFeed.php';

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Http\Csrf\StatelessCsrfToken;
use Preflow\Core\Http\InternalRender;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Core\PageCache\DataTags;
use Preflow\Core\PageCache\DependencyRecorder;
use Preflow\Core\PageCache\EnabledMarker;
use Preflow\Core\PageCache\PageCache;
use Preflow\Core\PageCache\PageCacheConfig;
use Preflow\Core\PageCache\PageCacheMiddleware;
use Preflow\Core\PageCache\PageCacheSource;
use Preflow\Core\PageCache\PageKey;
use Preflow\Core\PageCache\PageStore;
use Preflow\Core\PageCache\PerRequestValues;
use Preflow\Core\PageCache\Refresher;
use Preflow\Core\PageCache\TagIndex;
use Preflow\Core\Tests\PageCache\Fixtures\NegotiatedLocaleRedirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serving and storing: the middleware on its own (a handler stands in for the pipeline inside
 * it), and whole apps in web mode for what depends on the pipeline around it (locale, session,
 * CSRF, security headers, HEAD).
 */
final class PageCacheMiddlewareTest extends TestCase
{
    private string $dir;

    private string $root;

    private PageCacheConfig $config;

    private PageStore $store;

    private DependencyRecorder $recorder;

    private PageCache $cache;

    private PerRequestValues $values;

    private bool $signedIn = false;

    private bool $sessionStarted = false;

    /** @var list<string> */
    private array $logged = [];

    /** @var list<string> */
    private array $staleKeys = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pcmw_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        $this->root = $this->dir . '/storage/cache/pages';
        $this->config = new PageCacheConfig(enabled: true, hosts: ['example.com'], signedInCookies: ['preflow_auth', 'remember_session'], explain: true, verify: 'off');
        $this->store = new PageStore($this->root, 'aaaaaaaaaaaaaaaa');
        $this->recorder = new DependencyRecorder();
        $this->cache = new PageCache();
        $this->values = new PerRequestValues('test-secret');
        // Earlier tests in this process may leave a $_SESSION; a cacheable request has none (verify checks).
        $this->sessionBefore = $_SESSION ?? null;
        unset($_SESSION);
    }

    /** @var array<string, mixed>|null */
    private ?array $sessionBefore = null;

    protected function tearDown(): void
    {
        if ($this->sessionBefore !== null) {
            $_SESSION = $this->sessionBefore;
        }
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    // -----------------------------------------------------------------------
    // Review Focus 1, 3 and storing rules (the middleware alone)
    // -----------------------------------------------------------------------

    public function test_a_write_during_render_refuses_the_store(): void
    {
        $mw = $this->middleware();
        $tag = DataTags::record('default', 'post', '1');

        // While the page renders with the old post, another request writes it.
        $handler = $this->handler(function () use ($tag): ResponseInterface {
            $this->recorder->record($tag);
            (new Refresher(new PageStore($this->root, 'aaaaaaaaaaaaaaaa'), $this->config))
                ->invalidate(DataTags::recordChanged('default', 'post', '1'));

            return $this->html('old post');
        });

        $response = $mw->process($this->get('/post/1'), $handler);

        $this->assertSame('miss', $response->getHeaderLine('X-Page-Cache'));
        $this->assertSame('invalidated', $response->getHeaderLine('X-Page-Cache-Skip'));
        $this->assertSame('old post', (string) $response->getBody(), 'this visitor still gets the page');
        $this->assertNull($this->store->get($this->key('/post/1')), 'not stored');
        $this->assertSame([], TagIndex::forStore($this->store, $this->config)->pagesFor([$tag]), 'no rows left');

        // Without the write in between, the same page is stored and served.
        $quiet = $this->handler(function () use ($tag): ResponseInterface {
            $this->recorder->record($tag);

            return $this->html('post');
        });
        $this->assertSame('miss', $mw->process($this->get('/post/1'), $quiet)->getHeaderLine('X-Page-Cache'));
        $hit = $mw->process($this->get('/post/1'), $quiet);
        $this->assertSame('hit', $hit->getHeaderLine('X-Page-Cache'));
        $this->assertSame('post', (string) $hit->getBody());
        $this->assertSame(1, $quiet->calls, 'rendered once');
        $this->assertArrayHasKey($this->key('/post/1')->id(), TagIndex::forStore($this->store, $this->config)->pagesFor([$tag]));
    }

    public function test_an_invalidation_between_record_and_put_still_refuses_the_store(): void
    {
        // Another request writes the post right after this one recorded its rows and before its
        // file is written (the store's first write consults its clock: the seam).
        $tag = DataTags::record('default', 'post', '1');
        $armed = false;
        $this->store = new PageStore($this->root, 'aaaaaaaaaaaaaaaa', function () use (&$armed): float {
            if ($armed) {
                $armed = false;
                (new Refresher(new PageStore($this->root, 'aaaaaaaaaaaaaaaa'), $this->config))
                    ->invalidate(DataTags::recordChanged('default', 'post', '1'));
            }

            return microtime(true);
        });
        $handler = $this->handler(function () use ($tag, &$armed): ResponseInterface {
            $this->recorder->record($tag);
            $armed = true;

            return $this->html('old post');
        });

        $response = $this->middleware()->process($this->get('/post/1'), $handler);

        $this->assertFalse($armed, 'the write happened during the store');
        $this->assertSame('invalidated', $response->getHeaderLine('X-Page-Cache-Skip'));
        $this->assertNull($this->store->get($this->key('/post/1')));
        $this->assertSame([], TagIndex::forStore($this->store, $this->config)->pagesFor([$tag]));
    }

    public function test_a_history_restore_that_misses_is_never_stored(): void
    {
        $mw = $this->middleware();
        // An app that answers any htmx request with a fragment, a history restore too.
        $handler = $this->handler(fn (ServerRequestInterface $r): ResponseInterface => $this->html(
            $r->hasHeader('HX-Request') ? '<li>fragment</li>' : '<html><ul><li>fragment</li></ul></html>',
        ));

        $restore = $mw->process($this->get('/gallery')->withHeader('HX-Request', 'true')->withHeader('HX-History-Restore-Request', 'true'), $handler);
        $this->assertSame('miss', $restore->getHeaderLine('X-Page-Cache'));
        $this->assertSame('restore', $restore->getHeaderLine('X-Page-Cache-Skip'));
        $this->assertNull($this->store->get($this->key('/gallery')));

        $visitor = $mw->process($this->get('/gallery'), $handler);
        $this->assertSame('miss', $visitor->getHeaderLine('X-Page-Cache'));
        $this->assertSame('<html><ul><li>fragment</li></ul></html>', (string) $visitor->getBody(), 'the visitor gets the full page');
        $again = $mw->process($this->get('/gallery'), $handler);
        $this->assertSame('hit', $again->getHeaderLine('X-Page-Cache'));
        $this->assertSame('<html><ul><li>fragment</li></ul></html>', (string) $again->getBody());

        // Served from the full page once it is there, and an HX-Request other than "true" stores nothing.
        $this->assertSame('hit', $mw->process($this->get('/gallery')->withHeader('HX-Request', 'true')->withHeader('HX-History-Restore-Request', 'true'), $handler)->getHeaderLine('X-Page-Cache'));
        $this->assertSame('kind', $mw->process($this->get('/other')->withHeader('HX-Request', 'false'), $handler)->getHeaderLine('X-Page-Cache-Skip'));
    }

    public function test_native_cookies_streams_and_a_public_app_key_are_not_stored(): void
    {
        $cookie = $this->middleware(sentHeaders: static fn (): array => ['Content-Type: text/html', 'Set-Cookie: PHPSESSID=abc; path=/']);
        $this->assertSame('set-cookie', $cookie->process($this->get('/a'), $this->handler(fn () => $this->html('x')))->getHeaderLine('X-Page-Cache-Skip'));

        $stream = $this->handler(static function (): ResponseInterface {
            $resource = fopen('php://temp', 'r+');
            fwrite($resource, 'a,b,c');
            rewind($resource);
            $body = new class (\Nyholm\Psr7\Stream::create($resource)) implements \Psr\Http\Message\StreamInterface {
                public function __construct(private readonly \Psr\Http\Message\StreamInterface $inner) {}
                public function isSeekable(): bool { return false; }
                public function __toString(): string { return $this->inner->getContents(); }
                public function close(): void { $this->inner->close(); }
                public function detach() { return $this->inner->detach(); }
                public function getSize(): ?int { return null; }
                public function tell(): int { return $this->inner->tell(); }
                public function eof(): bool { return $this->inner->eof(); }
                public function seek(int $offset, int $whence = SEEK_SET): void { throw new \RuntimeException('not seekable'); }
                public function rewind(): void { throw new \RuntimeException('not seekable'); }
                public function isWritable(): bool { return false; }
                public function write(string $string): int { throw new \RuntimeException('read only'); }
                public function isReadable(): bool { return true; }
                public function read(int $length): string { return $this->inner->read($length); }
                public function getContents(): string { return $this->inner->getContents(); }
                public function getMetadata(?string $key = null): mixed { return $this->inner->getMetadata($key); }
            };

            return new Response(200, ['Content-Type' => 'text/csv'], $body);
        });
        $export = $this->middleware()->process($this->get('/export.csv'), $stream);
        $this->assertSame('stream', $export->getHeaderLine('X-Page-Cache-Skip'));
        $this->assertSame('a,b,c', (string) $export->getBody(), 'the visitor still gets the whole body');

        foreach (['', StatelessCsrfToken::DEFAULT_KEY] as $key) {
            $this->values = new PerRequestValues($key);
            $skip = $this->middleware()->process($this->get('/k' . strlen($key)), $this->handler(fn () => $this->html('x')))->getHeaderLine('X-Page-Cache-Skip');
            $this->assertSame('app-key', $skip, "key '{$key}'");
        }
    }

    public function test_forwarded_hosts_that_are_cached_stay_apart(): void
    {
        $this->config = new PageCacheConfig(enabled: true, hosts: ['example.com', 'www.example.com'], verify: 'off');
        $mw = $this->middleware();
        $handler = $this->handler(fn (ServerRequestInterface $r): ResponseInterface => $this->html('for ' . $r->getHeaderLine('X-Forwarded-Host')));

        $mw->process($this->get('/page')->withHeader('X-Forwarded-Host', 'example.com'), $handler);
        $mw->process($this->get('/page')->withHeader('X-Forwarded-Host', 'www.example.com'), $handler);
        $www = $mw->process($this->get('/page')->withHeader('X-Forwarded-Host', 'www.example.com'), $handler);
        $this->assertSame('hit', $www->getHeaderLine('X-Page-Cache'));
        $this->assertSame('for www.example.com', (string) $www->getBody());
        $this->assertSame('for example.com', (string) $mw->process($this->get('/page')->withHeader('X-Forwarded-Host', 'example.com'), $handler)->getBody());
    }

    public function test_a_csp_the_page_set_itself_is_not_replayed(): void
    {
        $mw = $this->middleware();
        $handler = $this->handler(fn (): ResponseInterface => $this->html('x')
            ->withHeader('Content-Security-Policy', "script-src 'nonce-old'")
            ->withHeader('Content-Security-Policy-Report-Only', "script-src 'nonce-old'")
            ->withHeader('HX-Nonce', 'old'));
        $mw->process($this->get('/csp'), $handler);
        $hit = $mw->process($this->get('/csp'), $handler);
        $this->assertSame('hit', $hit->getHeaderLine('X-Page-Cache'));
        $this->assertFalse($hit->hasHeader('Content-Security-Policy'));
        $this->assertFalse($hit->hasHeader('Content-Security-Policy-Report-Only'));
        $this->assertFalse($hit->hasHeader('HX-Nonce'));
    }

    public function test_changed_while_switched_off_removes_the_enabled_marker(): void
    {
        $marker = new EnabledMarker($this->root);
        $marker->write();
        (new PageCache(null, null, $marker))->changed('default:member');
        $this->assertFalse($marker->exists());
        $marker->write();
        (new PageCache(null, null, $marker))->changed();
        $this->assertTrue($marker->exists(), 'nothing changed');
    }

    public function test_404_and_redirects_are_never_cached(): void
    {
        $mw = $this->middleware();
        $exists = false;
        // A job slug that doesn't exist yet: a 404, then the feed imports it a minute later.
        $job = $this->handler(function () use (&$exists): ResponseInterface {
            return $exists ? $this->html('the new job') : new Response(404, ['Content-Type' => 'text/html'], 'Not Found');
        });

        $first = $mw->process($this->get('/jobs/new-slug'), $job);
        $this->assertSame(404, $first->getStatusCode());
        $this->assertSame('status', $first->getHeaderLine('X-Page-Cache-Skip'));
        $exists = true;
        $now = $mw->process($this->get('/jobs/new-slug'), $job);
        $this->assertSame(200, $now->getStatusCode());
        $this->assertSame('the new job', (string) $now->getBody(), 'appears at once');

        foreach ([301, 302, 307, 308] as $status) {
            $redirect = $this->handler(fn (): ResponseInterface => new Response($status, ['Location' => '/elsewhere']));
            $mw->process($this->get('/moved'), $redirect);
            $again = $mw->process($this->get('/moved'), $redirect);
            $this->assertSame($status, $again->getStatusCode());
            $this->assertSame(2, $redirect->calls, "{$status} is rendered every time");
        }

        // A thrown not-found (the kernel's error page) passes through untouched.
        $throws = $this->handler(fn (): ResponseInterface => throw new \Preflow\Core\Exceptions\NotFoundHttpException());
        try {
            $mw->process($this->get('/missing'), $throws);
            $this->fail('the exception reaches the kernel');
        } catch (\Preflow\Core\Exceptions\NotFoundHttpException) {
        }
        $this->assertNull($this->store->get($this->key('/missing')));
        $this->assertFalse($this->recorder->recording(), 'the recording was closed');
        $this->assertFalse($this->cache->storing());
    }

    public function test_responses_setting_cookies_are_not_stored(): void
    {
        $mw = $this->middleware();
        $cases = [
            'set-cookie' => fn (): ResponseInterface => $this->html('x')->withHeader('Set-Cookie', 'album=1,2; Path=/'),
            'cache-control' => fn (): ResponseInterface => $this->html('x')->withHeader('Cache-Control', 'private, no-store'),
            'vary' => fn (): ResponseInterface => $this->html('x')->withHeader('Vary', 'Cookie'),
            'content-type' => fn (): ResponseInterface => new Response(200, ['Content-Type' => 'image/png'], 'png'),
            'refused:the basket' => function (): ResponseInterface {
                $this->assertTrue($this->cache->storing());
                $this->cache->refuse('the basket');

                return $this->html('x');
            },
        ];
        foreach ($cases as $reason => $render) {
            $handler = $this->handler($render);
            $path = '/' . md5($reason);
            $this->assertSame($reason, $mw->process($this->get($path), $handler)->getHeaderLine('X-Page-Cache-Skip'));
            $this->assertSame('miss', $mw->process($this->get($path), $handler)->getHeaderLine('X-Page-Cache'), $reason);
            $this->assertNull($this->store->get($this->key($path)), $reason);
        }

        // The session was started, or a guard resolved a user, while it rendered.
        $this->sessionStarted = true;
        $this->assertSame('session', $mw->process($this->get('/s'), $this->handler(fn () => $this->html('x')))->getHeaderLine('X-Page-Cache-Skip'));
        $this->sessionStarted = false;
        $this->signedIn = true;
        $this->assertSame('signed-in', $mw->process($this->get('/u'), $this->handler(fn () => $this->html('x')))->getHeaderLine('X-Page-Cache-Skip'));
        $this->signedIn = false;

        // Varying on what the key holds is fine; stored headers leave the per-request ones out.
        $fine = $this->handler(fn (): ResponseInterface => $this->html('x')->withHeader('Vary', 'HX-Request, Accept-Encoding')
            ->withHeader('Content-Security-Policy', "script-src 'nonce-abc'")->withHeader('HX-Trigger', 'loaded')
            ->withHeader('Strict-Transport-Security', 'max-age=1')->withHeader('X-Robots-Tag', 'noindex'));
        $this->assertSame('', $mw->process($this->get('/fine'), $fine)->getHeaderLine('X-Page-Cache-Skip'));
        $page = $this->store->get($this->key('/fine'));
        $this->assertNotNull($page);
        $this->assertSame(['Content-Type', 'Vary', 'HX-Trigger', 'X-Robots-Tag'], array_keys($page->headers));
    }

    public function test_a_registered_site_value_is_swapped(): void
    {
        $token = 'form-token-of-visitor-a';
        $this->values->register('form_token', static function () use (&$token): string {
            return $token;
        });
        $mw = $this->middleware();
        $handler = $this->handler(static function () use (&$token): ResponseInterface {
            return new Response(200, ['Content-Type' => 'text/html'], '<input name="t" value="' . $token . '"><script>var t = "' . $token . '";</script>');
        });

        $miss = $mw->process($this->get('/contact'), $handler);
        $this->assertStringContainsString('form-token-of-visitor-a', (string) $miss->getBody());
        $stored = (string) file_get_contents($this->pageFile('/contact'));
        $this->assertStringNotContainsString('form-token-of-visitor-a', $stored);
        $this->assertStringContainsString('<!--pc:v:form_token:', $stored);

        $token = 'form-token-of-visitor-b';
        $hit = $mw->process($this->get('/contact'), $handler);
        $this->assertSame('hit', $hit->getHeaderLine('X-Page-Cache'));
        $this->assertSame('<input name="t" value="form-token-of-visitor-b"><script>var t = "form-token-of-visitor-b";</script>', (string) $hit->getBody());
        $this->assertSame(1, $handler->calls);
    }

    public function test_per_request_values_handle_json_short_values_and_forged_placeholders(): void
    {
        $values = new PerRequestValues('secret');
        $nonce = 'ab/cd+ef==';
        $values->register('nonce', static function () use (&$nonce): string {
            return $nonce;
        });
        $values->register('short', static fn (): string => '1');
        $body = '<script nonce="ab/cd+ef==">{"n":"ab\/cd+ef=="}</script> 1 <!--pc:v:nonce:0000000000000000-->';

        $stored = $values->toPlaceholders($body);
        $this->assertStringNotContainsString('ab/cd', $stored);
        $this->assertStringNotContainsString('ab\/cd', $stored);
        $this->assertStringContainsString(' 1 ', $stored, 'a value shorter than 8 characters is left alone');

        $nonce = 'zz/yy+xx==';
        $filled = $values->fill($stored);
        $this->assertSame('<script nonce="zz/yy+xx==">{"n":"zz\/yy+xx=="}</script> 1 <!--pc:v:nonce:0000000000000000-->', $filled, 'an unsigned placeholder in the text is not filled');

        // Another app key signs other placeholders.
        $other = new PerRequestValues('another-secret');
        $other->register('nonce', static fn (): string => 'leaked-value');
        $this->assertStringNotContainsString('leaked-value', $other->fill($stored));
    }

    public function test_htmx_fragment_and_full_page_are_separate_entries(): void
    {
        $mw = $this->middleware();
        $handler = $this->handler(fn (ServerRequestInterface $r): ResponseInterface => $this->html(
            $r->getHeaderLine('HX-Request') === 'true' ? '<li>more</li>' : '<html><ul><li>more</li></ul></html>',
        ));
        $fragment = fn (): ServerRequestInterface => $this->get('/gallery?page=2')->withHeader('HX-Request', 'true')->withHeader('HX-Target', 'list');

        $mw->process($this->get('/gallery?page=2'), $handler);
        $mw->process($fragment(), $handler);
        $page = $mw->process($this->get('/gallery?page=2'), $handler);
        $part = $mw->process($fragment(), $handler);
        $restore = $mw->process($this->get('/gallery?page=2')->withHeader('HX-Request', 'true')->withHeader('HX-History-Restore-Request', 'true'), $handler);

        $this->assertSame(['hit', 'hit', 'hit'], [$page->getHeaderLine('X-Page-Cache'), $part->getHeaderLine('X-Page-Cache'), $restore->getHeaderLine('X-Page-Cache')]);
        $this->assertSame('<html><ul><li>more</li></ul></html>', (string) $page->getBody());
        $this->assertSame('<li>more</li>', (string) $part->getBody());
        $this->assertSame('<html><ul><li>more</li></ul></html>', (string) $restore->getBody(), 'a history restore is the full page');
        $this->assertSame(2, $handler->calls);
        $index = TagIndex::forStore($this->store, $this->config);
        $this->assertTrue($index->page($this->key('/gallery?page=2', ['HX-Request' => 'true', 'HX-Target' => 'list'])->id())['fragment'] ?? false);
    }

    public function test_bypasses_and_pass_throughs(): void
    {
        $mw = $this->middleware(nonPublic: static fn (string $path): bool => str_starts_with($path, '/folio'));
        $handler = $this->handler(fn (): ResponseInterface => $this->html('page'));
        $mw->process($this->get('/page'), $handler);

        // Signed-in cookies (the marker, a remember-me cookie) and Authorization: live, never stored.
        foreach ([['preflow_auth' => '1'], ['remember_session' => 'sel:val']] as $cookies) {
            $response = $mw->process($this->get('/page')->withCookieParams($cookies), $handler);
            $this->assertSame('bypass', $response->getHeaderLine('X-Page-Cache'), (string) json_encode($cookies));
        }
        $this->assertSame('bypass', $mw->process($this->get('/page')->withHeader('Authorization', 'Bearer x'), $handler)->getHeaderLine('X-Page-Cache'));
        // A leftover session cookie, or any other cookie, does not.
        $this->assertSame('hit', $mw->process($this->get('/page')->withCookieParams(['preflow_session' => 'old', 'album' => '1,2']), $handler)->getHeaderLine('X-Page-Cache'));

        // Not cached at all: POST, an admin path, another host, an internal render, a forged scheme.
        $this->assertSame('', $mw->process($this->get('/page')->withMethod('POST'), $handler)->getHeaderLine('X-Page-Cache'));
        $this->assertSame('', $mw->process($this->get('/folio/login'), $handler)->getHeaderLine('X-Page-Cache'));
        $this->assertSame('', $mw->process(new ServerRequest('GET', 'https://evil.test/page'), $handler)->getHeaderLine('X-Page-Cache'));
        $this->assertSame('', $mw->process(InternalRender::mark($this->get('/page')), $handler)->getHeaderLine('X-Page-Cache'));
        $this->assertSame('', $mw->process($this->get('/page')->withHeader('X-Forwarded-Proto', 'gopher'), $handler)->getHeaderLine('X-Page-Cache'));
        $this->assertSame('', $mw->process($this->get('/page')->withHeader('X-Forwarded-Host', 'evil.test'), $handler)->getHeaderLine('X-Page-Cache'));

        // http and https never share a copy, proxied or not.
        $this->assertSame('miss', $mw->process($this->get('/page')->withHeader('X-Forwarded-Proto', 'https'), $handler)->getHeaderLine('X-Page-Cache'));
        $this->assertSame('miss', $mw->process(new ServerRequest('GET', 'http://example.com/page'), $handler)->getHeaderLine('X-Page-Cache'));
        $this->assertSame('hit', $mw->process($this->get('/page')->withHeader('X-Forwarded-Proto', 'https'), $handler)->getHeaderLine('X-Page-Cache'));

        // A rebuild renders and stores, never serves.
        $rebuild = $mw->process(InternalRender::mark($this->get('/page'))->withAttribute(PageCacheMiddleware::REBUILD, true), $handler);
        $this->assertSame('miss', $rebuild->getHeaderLine('X-Page-Cache'));
    }

    public function test_stale_pages_are_served_and_reported(): void
    {
        $now = 1_000_000.0;
        $config = new PageCacheConfig(enabled: true, hosts: ['example.com'], maxAge: 3600, verify: 'off');
        $mw = $this->middleware(config: $config, clock: static function () use (&$now): float {
            return $now;
        });
        $handler = $this->handler(fn (): ResponseInterface => $this->html('page'));
        $mw->process($this->get('/page'), $handler);
        $this->assertSame('hit', $mw->process($this->get('/page'), $handler)->getHeaderLine('X-Page-Cache'));

        $now += 3600;
        $stale = $mw->process($this->get('/page'), $handler);
        $this->assertSame('stale', $stale->getHeaderLine('X-Page-Cache'), 'older than max_age');
        $this->assertSame('page', (string) $stale->getBody());
        $this->assertSame(1, $handler->calls);

        $now -= 3600;
        $this->store->markStale($this->key('/page')->id());
        $this->assertSame('stale', $mw->process($this->get('/page'), $handler)->getHeaderLine('X-Page-Cache'), 'marked');
        $this->assertSame([$this->key('/page')->url(), $this->key('/page')->url()], $this->staleKeys);
    }

    public function test_switching_the_cache_on_again_starts_empty(): void
    {
        $marker = new EnabledMarker($this->root);
        $handler = $this->handler(fn (): ResponseInterface => $this->html('page'));
        $this->middleware()->process($this->get('/page'), $handler);
        $this->assertTrue($marker->exists(), 'written on the first request');
        $this->assertSame('hit', $this->middleware()->process($this->get('/page'), $handler)->getHeaderLine('X-Page-Cache'), 'a new process keeps the pages');

        // Switched off, a write removes the marker (DisabledWritesObserver); on again, the old page is gone.
        $marker->remove();
        $this->assertSame('miss', $this->middleware()->process($this->get('/page'), $handler)->getHeaderLine('X-Page-Cache'));
        $this->assertTrue($marker->exists());
    }

    // -----------------------------------------------------------------------
    // Whole apps in web mode
    // -----------------------------------------------------------------------

    #[RunInSeparateProcess]
    public function test_locale_prefixes_are_separate_and_negotiated_redirects_uncached(): void
    {
        $this->site(i18n: ['default' => 'en', 'available' => ['en', 'de', 'fr'], 'url_strategy' => 'prefix']);
        $this->write('config/middleware.php', "<?php\nreturn [\\" . NegotiatedLocaleRedirect::class . "::class];\n");
        $this->write('app/pages/x.twig', '<p>{{ t("site.hi") }}</p>');
        $this->write('app/pages/negotiated.twig', '<p>{{ t("site.hi") }}</p>');

        foreach (['/de/x' => 'Hallo', '/fr/x' => 'Salut', '/x' => 'Hello'] as $path => $text) {
            $this->assertSame('miss', $this->web($path)->getHeaderLine('X-Page-Cache'), $path);
        }
        foreach (['/de/x' => 'Hallo', '/fr/x' => 'Salut', '/x' => 'Hello'] as $path => $text) {
            $response = $this->web($path);
            $this->assertSame('hit', $response->getHeaderLine('X-Page-Cache'), $path);
            $this->assertStringContainsString("<p>{$text}</p>", (string) $response->getBody(), $path);
        }
        $index = TagIndex::forStore($this->appStore(), $this->appConfig());
        $urls = [];
        foreach (['/de/x', '/fr/x', '/x'] as $path) {
            $key = PageKey::fromRequest(new ServerRequest('GET', 'https://example.com' . $path), $this->appConfig(), ['locale' => $path === '/x' ? 'en' : substr($path, 1, 2)]);
            $urls[] = $index->page($key->id())['url'] ?? null;
        }
        $this->assertSame(['https://example.com/de/x', 'https://example.com/fr/x', 'https://example.com/x'], $urls, 'stored under the URL as asked for');

        // The unprefixed page that redirects by Accept-Language: never cached, for either visitor.
        $redirect = $this->web('/negotiated', ['Accept-Language' => 'de-CH,de;q=0.9']);
        $this->assertSame(302, $redirect->getStatusCode());
        $this->assertSame('miss', $this->web('/negotiated')->getHeaderLine('X-Page-Cache'));
        $this->assertSame('vary', $this->web('/negotiated')->getHeaderLine('X-Page-Cache-Skip'));
        $this->assertSame(302, $this->web('/negotiated', ['Accept-Language' => 'de'])->getStatusCode(), 'the German visitor is still sent on');
    }

    #[RunInSeparateProcess]
    public function test_a_locale_from_accept_language_is_part_of_the_key(): void
    {
        $this->site(i18n: ['default' => 'en', 'available' => ['en', 'de'], 'url_strategy' => 'none', 'cookie' => false]);
        $this->write('app/pages/x.twig', '<p>{{ t("site.hi") }}</p>');

        $this->assertStringContainsString('Hello', (string) $this->web('/x')->getBody());
        $this->assertStringContainsString('Hallo', (string) $this->web('/x', ['Accept-Language' => 'de'])->getBody());
        $de = $this->web('/x', ['Accept-Language' => 'de']);
        $en = $this->web('/x');
        $this->assertSame(['hit', 'hit'], [$de->getHeaderLine('X-Page-Cache'), $en->getHeaderLine('X-Page-Cache')]);
        $this->assertStringContainsString('Hallo', (string) $de->getBody());
        $this->assertStringContainsString('Hello', (string) $en->getBody());
    }

    #[RunInSeparateProcess]
    public function test_signed_in_requests_bypass(): void
    {
        $this->site(auth: true);
        $this->write('app/pages/page.twig', '<p>page</p>');

        $config = $this->appConfig();
        $this->assertSame(['preflow_auth', 'keep_me', 'site_cookie'], $config->signedInCookies, 'the marker, every remember cookie, the site\'s own');

        $this->web('/page');
        $this->assertSame('bypass', $this->web('/page', cookies: ['preflow_auth' => '1'])->getHeaderLine('X-Page-Cache'));
        $this->assertSame('bypass', $this->web('/page', cookies: ['keep_me' => 'a:b'])->getHeaderLine('X-Page-Cache'));
        $this->assertSame('bypass', $this->web('/page', cookies: ['site_cookie' => 'x'])->getHeaderLine('X-Page-Cache'));

        // A leftover session cookie alone: a hit, and the session is never opened.
        $app = $this->webApp();
        $response = $app->handle($this->request('/page')->withCookieParams(['pc_session' => 'left-over']));
        $this->assertSame('hit', $response->getHeaderLine('X-Page-Cache'));
        $this->assertFalse($app->container()->get(SessionInterface::class)->isStarted());
        $this->assertSame([], $response->getHeader('Set-Cookie'));
        $this->assertSame(PHP_SESSION_NONE, session_status());

        // Where the session lives, nothing is stored.
        $this->write('app/pages/account.twig', '<p>account</p>');
        $this->web('/account');
        $this->assertSame('session', $this->web('/account')->getHeaderLine('X-Page-Cache-Skip'));
    }

    #[RunInSeparateProcess]
    public function test_nonce_and_csrf_are_fresh_per_hit(): void
    {
        $this->site(auth: true);
        $this->write('app/pages/form.twig', '<script nonce="{{ csp_nonce() }}">go()</script><form method="post">{{ csrf_field() }}</form>');

        $miss = $this->web('/form');
        $first = $this->web('/form'); // each request is a new process, as on the server
        $second = $this->web('/form');
        $this->assertSame(['miss', 'hit', 'hit'], array_map(static fn (ResponseInterface $r): string => $r->getHeaderLine('X-Page-Cache'), [$miss, $first, $second]));

        $nonces = [];
        foreach ([$miss, $first, $second] as $response) {
            preg_match('/<script nonce="([^"]+)">/', (string) $response->getBody(), $body);
            preg_match("/'nonce-([^']+)'/", $response->getHeaderLine('Content-Security-Policy'), $header);
            $this->assertSame($body[1] ?? 'none', $header[1] ?? 'no header', 'the body has the nonce of its own header');
            $nonces[] = $body[1] ?? '';
        }
        $this->assertCount(3, array_unique($nonces), 'a new nonce every time');

        // The stored page holds neither the nonce nor the token, only their placeholders.
        $stored = (string) file_get_contents($this->appPageFile('/form'));
        $this->assertStringNotContainsString($nonces[0], $stored);
        $this->assertStringContainsString('<!--pc:v:nonce:', $stored);
        $this->assertStringContainsString('<!--pc:v:csrf:', $stored);

        // The token from a hit is the current one, and validates on POST.
        $token = (new StatelessCsrfToken('page-cache-test-key-0123456789ab'))->issue();
        $this->assertStringContainsString('value="' . $token . '"', (string) $second->getBody());
        $post = fn (string $value): ResponseInterface => $this->webApp()->handle($this->request('/form', method: 'POST')->withParsedBody(['_csrf_token' => $value]));
        $this->assertNotSame(403, $post($token)->getStatusCode(), 'the token from the hit passes');
        $this->assertSame(403, $post('v1.1.' . str_repeat('A', 43))->getStatusCode(), 'a wrong one does not');
    }

    #[RunInSeparateProcess]
    public function test_seo_head_tags_of_a_hit_are_those_of_the_render(): void
    {
        $this->site();
        $this->write('config/seo.php', "<?php\nreturn ['enabled' => true, 'name' => 'Club'];\n");
        $this->write('app/pages/about.twig', '{{ seo({title: "About the club", description: "Board games on Fridays", modified: "2026-11-01"}) }}<html><head><title>About</title></head><body>About</body></html>');

        $miss = $this->web('/about');
        $hit = $this->web('/about');
        $this->assertSame(['miss', 'hit'], [$miss->getHeaderLine('X-Page-Cache'), $hit->getHeaderLine('X-Page-Cache')]);
        $strip = static fn (ResponseInterface $r): string => (string) preg_replace('/nonce="[^"]+"|Nonce":"[^"]+"/', 'NONCE', (string) $r->getBody());
        $this->assertStringContainsString('<meta property="og:title" content="About the club">', $strip($miss));
        $this->assertStringContainsString('og:updated_time', $strip($miss));
        $this->assertSame($strip($miss), $strip($hit), 'the page meta is carried through the cache');
        $this->assertSame('About the club', $this->appStore()->get(PageKey::fromRequest($this->request('/about'), $this->appConfig()))?->state['seo']['meta']['title'] ?? null);
    }

    #[RunInSeparateProcess]
    public function test_a_component_that_failed_is_never_stored(): void
    {
        putenv('FEED_UP');
        $this->site();
        $this->write('app/pages/news.twig', '<main>{{ component("' . addslashes(\Preflow\Core\Tests\PageCache\Fixtures\BrokenFeed::class) . '") }}</main>');

        $broken = $this->web('/news');
        $this->assertSame(200, $broken->getStatusCode(), 'the boundary keeps the page');
        $this->assertStringNotContainsString('feed items', (string) $broken->getBody());
        $this->assertSame('refused:component-error', $broken->getHeaderLine('X-Page-Cache-Skip'));
        $this->assertSame('miss', $this->web('/news')->getHeaderLine('X-Page-Cache'));

        // The feed is back: the next visitor gets it, and that page is stored.
        putenv('FEED_UP=1');
        $this->assertStringContainsString('feed items', (string) $this->web('/news')->getBody());
        $this->assertSame('hit', $this->web('/news')->getHeaderLine('X-Page-Cache'));
        putenv('FEED_UP');
    }

    #[RunInSeparateProcess]
    public function test_head_is_served_from_cache_without_body(): void
    {
        $this->site();
        $this->write('app/pages/page.twig', '<p>a page of some length</p>');

        $head = $this->web('/page', method: 'HEAD');
        $this->assertSame('miss', $head->getHeaderLine('X-Page-Cache'));
        $this->assertNull($this->appStore()->get(PageKey::fromRequest($this->request('/page'), $this->appConfig())), 'HEAD stores nothing');

        $get = $this->web('/page');
        $head = $this->web('/page', method: 'HEAD');
        $this->assertSame('hit', $head->getHeaderLine('X-Page-Cache'));
        $this->assertSame('', (string) $head->getBody());
        $this->assertSame((string) strlen((string) $get->getBody()), $head->getHeaderLine('Content-Length'));
        $this->assertNotSame('', $head->getHeaderLine('Content-Security-Policy'));
    }

    #[RunInSeparateProcess]
    public function test_disabled_in_debug_and_console(): void
    {
        $this->site();
        $this->write('app/pages/page.twig', '<p>page</p>');

        $console = Application::create($this->dir);
        $console->boot();
        $this->assertSame('', $console->handle($this->request('/page'))->getHeaderLine('X-Page-Cache'), 'console');
        $this->assertFalse($console->container()->bound(PageCacheMiddleware::class));

        $this->write('config/app.php', "<?php\nreturn ['name'=>'t','debug'=>1,'engine'=>'twig','key'=>'page-cache-test-key-0123456789ab','url'=>'https://example.com'];\n");
        $this->assertSame('', $this->web('/page')->getHeaderLine('X-Page-Cache'), 'debug');
        $this->assertSame('', $this->web('/page')->getHeaderLine('X-Page-Cache'));
        $this->assertDirectoryDoesNotExist($this->dir . '/storage/cache/pages', 'nothing stored or cleared');
    }

    #[RunInSeparateProcess]
    public function test_a_write_while_switched_off_removes_the_enabled_marker(): void
    {
        $this->site(data: true);
        $this->write('app/pages/page.twig', '<p>page</p>');
        $this->web('/page');
        $marker = new EnabledMarker($this->dir . '/storage/cache/pages');
        $this->assertTrue($marker->exists());

        $this->write('config/page_cache.php', "<?php\nreturn ['enabled' => false];\n");
        $app = $this->webApp();
        $dm = $app->container()->get(\Preflow\Data\DataManager::class);
        $this->assertInstanceOf(\Preflow\Core\PageCache\DisabledWritesObserver::class, $dm->observer());
        $dm->reportRead('json', 'post', 'p1');
        $this->assertTrue($marker->exists(), 'reads leave it');
        $dm->reportWrite('json', 'post', 'p1');
        $this->assertFalse($marker->exists());
        $this->assertNull($dm->observer(), 'and the observer goes');
        $this->assertNull($this->webApp()->container()->get(\Preflow\Data\DataManager::class)->observer(), 'nothing observes once the marker is gone');

        // On again: the page from before is gone, a fresh one is stored.
        $this->write('config/page_cache.php', "<?php\nreturn ['enabled' => true];\n");
        $this->assertSame('miss', $this->web('/page')->getHeaderLine('X-Page-Cache'));
        $this->assertSame('hit', $this->web('/page')->getHeaderLine('X-Page-Cache'));
    }

    // -----------------------------------------------------------------------
    // Live parts, verify and explain (Task 4)
    // -----------------------------------------------------------------------

    public function test_live_parts_are_filled_for_each_visitor_on_the_miss_and_on_hits(): void
    {
        $live = new \Preflow\Core\PageCache\LiveParts(new \Preflow\Core\PageCache\LivePlaceholder('test-secret'), $this->cache, $this->recorder);
        $visitor = '';
        $live->register('greeting', function (array $recipe) use (&$visitor): string {
            $this->recorder->record('default:visitor#' . $visitor); // not the page's

            return "<b>{$recipe['hi']}, {$visitor}</b>";
        });
        $mw = $this->middleware(live: $live);
        $handler = $this->handler(function () use ($live): ResponseInterface {
            $this->recorder->record(DataTags::record('default', 'post', '1'));

            return $this->html('<p>page</p>' . ($live->placeholder('greeting', ['hi' => 'Hello']) ?? '<b>in place</b>'));
        });

        $visitor = 'Ann';
        $miss = $mw->process($this->get('/page'), $handler);
        $this->assertSame(['miss', '<p>page</p><b>Hello, Ann</b>'], [$miss->getHeaderLine('X-Page-Cache'), (string) $miss->getBody()]);
        $this->assertSame('', $miss->getHeaderLine('X-Page-Cache-Skip'));
        $stored = (string) file_get_contents($this->pageFile('/page'));
        $this->assertStringContainsString('<!--pc:l:', $stored);
        $this->assertStringNotContainsString('Ann', $stored);

        $visitor = 'Bob';
        $hit = $mw->process($this->get('/page'), $handler);
        $this->assertSame(['hit', '<p>page</p><b>Hello, Bob</b>'], [$hit->getHeaderLine('X-Page-Cache'), (string) $hit->getBody()]);
        $this->assertSame(1, $handler->calls);

        $index = TagIndex::forStore($this->store, $this->config, null, null);
        $this->assertSame([$this->key('/page')->id()], array_keys($index->pagesFor([DataTags::record('default', 'post', '1')])));
        $this->assertSame([], $index->pagesFor(['default:visitor#Ann', 'default:visitor#Bob', 'default:visitor']), 'what a live part read is no dependency of the page');

        // Not stored (another status): the visitor still gets their part, never a placeholder.
        $gone = $mw->process($this->get('/gone'), $this->handler(fn (): ResponseInterface => new Response(404, ['Content-Type' => 'text/html'], (string) $live->placeholder('greeting', ['hi' => 'Hi']))));
        $this->assertSame('<b>Hi, Bob</b>', (string) $gone->getBody());
    }

    public function test_verify_refuses_a_page_that_differs_per_visitor(): void
    {
        $values = $this->values;
        $values->register('form_token', static fn (): string => 'token-of-' . 'this-request');
        $mw = $this->middleware(verify: true);
        $personal = $this->handler(fn (ServerRequestInterface $r): ResponseInterface => $this->html(
            '<p>for ' . ($r->getCookieParams()['album'] ?? '-') . ' at ' . ($r->getServerParams()['REMOTE_ADDR'] ?? '-') . '</p>',
        ));

        $miss = $mw->process($this->get('/album')->withCookieParams(['album' => '1_2']), $personal);
        $this->assertSame(['miss', 'differs'], [$miss->getHeaderLine('X-Page-Cache'), $miss->getHeaderLine('X-Page-Cache-Skip')]);
        $this->assertSame('<p>for 1_2 at -</p>', (string) $miss->getBody(), 'the visitor gets their render');
        $this->assertFileDoesNotExist($this->pageFile('/album'));
        $this->assertSame(['page cache: https://example.com/album differs per visitor outside live parts'], $this->logged);
        $this->assertSame(2, $personal->calls);

        // By address alone (a token from the IP that was not registered) it differs too.
        $byIp = $this->handler(fn (ServerRequestInterface $r): ResponseInterface => $this->html('<p>' . ($r->getServerParams()['REMOTE_ADDR'] ?? '-') . '</p>'));
        $request = new ServerRequest('GET', 'https://example.com/ip', [], null, '1.1', ['REMOTE_ADDR' => '203.0.113.9']);
        $this->assertSame('differs', $mw->process($request, $byIp)->getHeaderLine('X-Page-Cache-Skip'));

        // The same for everyone, with a registered per-request value: stored.
        $same = $this->handler(fn (): ResponseInterface => $this->html('<form><input value="token-of-this-request"></form>'));
        $this->assertSame('', $mw->process($this->get('/same')->withCookieParams(['album' => '1']), $same)->getHeaderLine('X-Page-Cache-Skip'));
        $this->assertSame(2, $same->calls);
        $this->assertSame('hit', $mw->process($this->get('/same'), $same)->getHeaderLine('X-Page-Cache'));
        $this->assertSame(2, $same->calls, 'verify never runs on a hit');
    }

    public function test_verify_compares_the_stored_headers(): void
    {
        $mw = $this->middleware(verify: 'always');
        $count = $this->handler(fn (ServerRequestInterface $r): ResponseInterface => $this->html('<p>same body</p>')
            ->withHeader('HX-Trigger', json_encode(['albumCount' => count(array_filter(explode('_', (string) ($r->getCookieParams()['album'] ?? ''))))]) ?: ''));

        $miss = $mw->process($this->get('/fragment')->withCookieParams(['album' => '1_2']), $count);
        $this->assertSame('differs', $miss->getHeaderLine('X-Page-Cache-Skip'));
        $this->assertSame('{"albumCount":2}', $miss->getHeaderLine('HX-Trigger'), 'the visitor gets their own');
        $this->assertFileDoesNotExist($this->pageFile('/fragment'));
        $this->assertSame(['page cache: https://example.com/fragment differs per visitor outside live parts'], $this->logged);

        // Headers the same for everyone (in another case and order): stored.
        $same = $this->handler(fn (): ResponseInterface => $this->html('<p>x</p>')->withHeader('X-Robots-Tag', 'noindex'));
        $this->assertSame('', $mw->process($this->get('/same')->withCookieParams(['album' => '1']), $same)->getHeaderLine('X-Page-Cache-Skip'));
    }

    #[RunInSeparateProcess]
    public function test_verify_sees_a_visitor_read_from_the_superglobals(): void
    {
        $_COOKIE = ['album' => '1_2'];
        $_REQUEST = ['album' => '1_2', 'q' => 'x'];
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['HTTP_COOKIE'] = 'album=1_2';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';
        $seen = [];
        $mw = $this->middleware(verify: 'always');
        $raw = $this->handler(function () use (&$seen): ResponseInterface {
            $seen[] = [$_COOKIE, $_REQUEST, $_SERVER['REMOTE_ADDR'] ?? null, isset($_SERVER['HTTP_COOKIE']), isset($_SERVER['HTTP_X_FORWARDED_FOR'])];

            return $this->html('<p>' . ($_COOKIE['album'] ?? '') . '</p>');
        });

        $miss = $mw->process($this->get('/raw'), $raw);
        $this->assertSame('differs', $miss->getHeaderLine('X-Page-Cache-Skip'));
        $this->assertSame('<p>1_2</p>', (string) $miss->getBody());
        $this->assertFileDoesNotExist($this->pageFile('/raw'));
        $this->assertSame([[], ['q' => 'x'], '192.0.2.1', false, false], $seen[1], 'the second render saw another visitor');

        // Put back afterwards.
        $this->assertSame(['album' => '1_2'], $_COOKIE);
        $this->assertSame(['album' => '1_2', 'q' => 'x'], $_REQUEST);
        $this->assertSame(['203.0.113.9', 'album=1_2', '198.51.100.7'], [$_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_COOKIE'], $_SERVER['HTTP_X_FORWARDED_FOR']]);

        // A leftover \$_SESSION (no session active) is hidden from the second render and put back.
        $_SESSION = ['residue' => 1];
        $sessions = [];
        $same = $this->handler(function () use (&$sessions): ResponseInterface {
            $sessions[] = $_SESSION ?? null;

            return $this->html('<p>same</p>');
        });
        $this->assertSame('', $mw->process($this->get('/residue'), $same)->getHeaderLine('X-Page-Cache-Skip'));
        $this->assertSame([['residue' => 1], null], $sessions);
        $this->assertSame(['residue' => 1], $_SESSION);
        unset($_SESSION);

        // A render with a session active is not verified, so not stored.
        session_id('pcverifytest');
        session_start();
        $this->assertSame('differs', $mw->process($this->get('/session'), $same)->getHeaderLine('X-Page-Cache-Skip'));
        session_write_close();
        $this->assertStringContainsString('could not be verified: a session is open', implode("\n", $this->logged));
        unset($_SESSION);
        $this->assertSame('', $mw->process($this->get('/same'), $same)->getHeaderLine('X-Page-Cache-Skip'));
    }

    public function test_verify_first_runs_once_for_a_key_the_index_does_not_know(): void
    {
        $mw = $this->middleware(config: new PageCacheConfig(enabled: true, hosts: ['example.com'], explain: true));
        $page = $this->handler(fn (): ResponseInterface => $this->html('<p>page</p>'));

        $this->assertSame('', $mw->process($this->get('/page'), $page)->getHeaderLine('X-Page-Cache-Skip'));
        $this->assertSame(2, $page->calls, 'the first store of the key renders twice');

        // A rebuild of the known key is not verified.
        $rebuild = $this->get('/page')->withAttribute(PageCacheMiddleware::REBUILD, true)->withAttribute(InternalRender::ATTRIBUTE, true);
        $mw->process($rebuild, $page);
        $this->assertSame(3, $page->calls);

        // A page that differed is never known: verified again on its next miss.
        $personal = $this->handler(fn (ServerRequestInterface $r): ResponseInterface => $this->html('<p>' . ($r->getCookieParams()['album'] ?? '') . '</p>'));
        $mw->process($this->get('/album')->withCookieParams(['album' => '1']), $personal);
        $this->assertSame('differs', $mw->process($this->get('/album')->withCookieParams(['album' => '2']), $personal)->getHeaderLine('X-Page-Cache-Skip'));
        $this->assertSame(4, $personal->calls);

        $off = $this->middleware(config: new PageCacheConfig(enabled: true, hosts: ['example.com'], verify: 'off'));
        $other = $this->handler(fn (): ResponseInterface => $this->html('<p>other</p>'));
        $off->process($this->get('/other'), $other);
        $this->assertSame(1, $other->calls);
    }

    public function test_live_placeholders_are_filled_into_html_only(): void
    {
        $live = new \Preflow\Core\PageCache\LiveParts(new \Preflow\Core\PageCache\LivePlaceholder('test-secret'), $this->cache, $this->recorder);
        $live->register('greeting', static fn (): string => "<b>\"hi\"\n</b>");
        $mw = $this->middleware(live: $live);
        $json = $this->handler(fn (): ResponseInterface => new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['html' => $live->placeholder('greeting', [])])));

        $response = $mw->process($this->get('/api'), $json);
        $this->assertSame('live-non-html', $response->getHeaderLine('X-Page-Cache-Skip'));
        $this->assertIsArray(json_decode((string) $response->getBody(), true), 'never filled: still valid JSON');
        $this->assertFileDoesNotExist($this->pageFile('/api'));
    }

    public function test_an_unusable_app_key_places_and_fills_nothing(): void
    {
        $live = new \Preflow\Core\PageCache\LiveParts(new \Preflow\Core\PageCache\LivePlaceholder(StatelessCsrfToken::DEFAULT_KEY), $this->cache, $this->recorder, function (string $m): void {
            $this->logged[] = $m;
        });
        $live->register('greeting', static fn (): string => '<b>FILLED</b>');
        $public = (new \Preflow\Core\PageCache\LivePlaceholder(StatelessCsrfToken::DEFAULT_KEY))->make('greeting', []);
        $this->values = new PerRequestValues(StatelessCsrfToken::DEFAULT_KEY);
        $mw = $this->middleware(live: $live);
        $handler = $this->handler(fn (): ResponseInterface => $this->html('<p>' . ($live->placeholder('greeting', []) ?? 'in place') . "</p>{$public}"));

        $response = $mw->process($this->get('/page'), $handler);
        $this->assertSame("<p>in place</p>{$public}", (string) $response->getBody());
        $this->assertSame('app-key', $response->getHeaderLine('X-Page-Cache-Skip'));
        $this->assertStringContainsString('1 live marker(s) left as comments', $this->logged[0] ?? '');
    }

    public function test_without_explain_the_skip_reason_is_not_sent(): void
    {
        $mw = $this->middleware(config: new PageCacheConfig(enabled: true, hosts: ['example.com']));
        $response = $mw->process($this->get('/page'), $this->handler(fn (): ResponseInterface => $this->html('x')->withHeader('Set-Cookie', 'a=b')));

        $this->assertSame('miss', $response->getHeaderLine('X-Page-Cache'));
        $this->assertFalse($response->hasHeader('X-Page-Cache-Skip'));
        $this->assertFileDoesNotExist($this->pageFile('/page'));
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * @param (\Closure(): float)|null $clock
     * @param (\Closure(string): bool)|null $nonPublic
     */
    private function middleware(?PageCacheConfig $config = null, ?\Closure $clock = null, ?\Closure $nonPublic = null, ?\Closure $sentHeaders = null, ?\Preflow\Core\PageCache\LiveParts $live = null, bool|string|null $verify = null): PageCacheMiddleware
    {
        $config ??= $this->config;
        $source = new PageCacheSource($this->root, static fn (): string => 'aaaaaaaaaaaaaaaa');

        return new PageCacheMiddleware(
            config: $config,
            store: fn (): PageStore => $this->store,
            cache: $this->cache,
            recorder: $this->recorder,
            values: $this->values,
            marker: new EnabledMarker($this->root),
            clearAll: static function () use ($source): void {
                $source->clear();
            },
            signedIn: fn (): bool => $this->signedIn,
            sessionStarted: fn (): bool => $this->sessionStarted,
            nonPublic: $nonPublic,
            onStale: function (PageKey $key): void {
                $this->staleKeys[] = $key->url();
            },
            log: function (string $message): void {
                $this->logged[] = $message;
            },
            clock: $clock,
            sentHeaders: $sentHeaders,
            live: $live,
            verify: $verify,
        );
    }

    /** @param \Closure(ServerRequestInterface): ResponseInterface $render */
    private function handler(\Closure $render): RequestHandlerInterface
    {
        return new class($render) implements RequestHandlerInterface {
            public int $calls = 0;

            public function __construct(private readonly \Closure $render) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->calls++;

                return ($this->render)($request);
            }
        };
    }

    private function html(string $body): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $body);
    }

    private function get(string $path): ServerRequestInterface
    {
        return new ServerRequest('GET', 'https://example.com' . $path);
    }

    /** @param array<string, string> $headers */
    private function key(string $path, array $headers = []): PageKey
    {
        $request = $this->get($path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return PageKey::fromRequest($request, $this->config) ?? throw new \RuntimeException('no key');
    }

    private function pageFile(string $path): string
    {
        $id = $this->key($path)->id();

        return $this->root . '/aaaaaaaaaaaaaaaa/' . substr($id, 0, 2) . '/' . $id . '.page';
    }

    /**
     * A site in a temp folder: pages under app/pages, the cache on.
     *
     * @param array<string, mixed>|null $i18n config/i18n.php, with lang/{en,de,fr}/site.php
     */
    private function site(?array $i18n = null, bool $auth = false, bool $data = false): void
    {
        $this->write('config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'page-cache-test-key-0123456789ab','url'=>'https://example.com','locale'=>'en'];\n");
        $this->write('config/page_cache.php', "<?php\nreturn ['enabled' => true, 'signed_in_cookies' => ['site_cookie'], 'explain' => true, 'verify' => 'always'];\n");
        if ($i18n !== null) {
            $this->write('config/i18n.php', '<?php return ' . var_export($i18n, true) . ';');
            foreach (['en' => 'Hello', 'de' => 'Hallo', 'fr' => 'Salut'] as $locale => $hi) {
                $this->write("lang/{$locale}/site.php", "<?php return ['hi' => '{$hi}'];");
            }
        }
        if ($data || $auth) {
            $this->write('config/data.php', "<?php\nreturn ['default'=>'json','drivers'=>['json'=>['path'=>'{$this->dir}/storage/data'], 'sqlite' => ['path' => '{$this->dir}/storage/app.sqlite']], 'aliases' => ['auth' => 'sqlite']];\n");
        }
        if ($auth) {
            $this->write('config/auth.php', "<?php\nreturn ["
                . "'default_guard' => 'session',"
                . "'guards' => ["
                . "'session' => ['class' => \\Preflow\\Auth\\SessionGuard::class, 'provider' => 'config'],"
                . "'member' => ['class' => \\Preflow\\Auth\\SessionGuard::class, 'provider' => 'config', 'remember' => ['cookie' => 'keep_me']],"
                . "],"
                . "'providers' => ['config' => ['class' => \\Preflow\\Auth\\ConfigUserProvider::class, 'users' => []]],"
                . "'session' => ['cookie' => 'pc_session', 'paths' => ['/account']],"
                . "];\n");
        }
    }

    private function webApp(): Application
    {
        $app = Application::create($this->dir);
        $app->setRunningInConsole(false);
        $app->boot();

        return $app;
    }

    /**
     * One request through a newly booted app (a new process on the server).
     *
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     */
    private function web(string $path, array $headers = [], array $cookies = [], string $method = 'GET'): ResponseInterface
    {
        $request = $this->request($path, $method)->withCookieParams($cookies);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->webApp()->handle($request);
    }

    private function request(string $path, string $method = 'GET'): ServerRequestInterface
    {
        return new ServerRequest($method, 'https://example.com' . $path);
    }

    private function appConfig(): PageCacheConfig
    {
        return $this->webApp()->container()->get(PageCacheConfig::class);
    }

    private function appStore(): PageStore
    {
        return new PageStore($this->dir . '/storage/cache/pages', PageStore::releaseKey($this->dir));
    }

    private function appPageFile(string $path): string
    {
        $id = (PageKey::fromRequest($this->request($path), $this->appConfig()) ?? throw new \RuntimeException('no key'))->id();

        return $this->appStore()->releaseDir() . '/' . substr($id, 0, 2) . '/' . $id . '.page';
    }

    private function write(string $rel, string $content): void
    {
        $path = $this->dir . '/' . $rel;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }
}
