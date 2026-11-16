<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests;

require_once __DIR__ . '/Redirect/Fixtures/InMemoryRedirectStore.php';

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\InternalRender;
use Preflow\Core\Http\LocaleUrls;
use Preflow\Core\Http\NonPublicPaths;
use Preflow\Routing\CanonicalPathMiddleware;
use Preflow\Routing\Redirect\AreaMode;
use Preflow\Routing\Redirect\Redirect;
use Preflow\Routing\Redirect\RedirectResolver;
use Preflow\Routing\Tests\Redirect\Fixtures\InMemoryRedirectStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CanonicalPathMiddlewareTest extends TestCase
{
    private int $handled = 0;

    private function send(string $method, string $uri, ?NonPublicPaths $nonPublic = null): ResponseInterface
    {
        $this->handled = 0;
        $handler = new class ($this) implements RequestHandlerInterface {
            public function __construct(private readonly CanonicalPathMiddlewareTest $t) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->t->markHandled();

                return new Response(200, [], 'ok');
            }
        };

        return (new CanonicalPathMiddleware($nonPublic))->process($this->request($method, $uri), $handler);
    }

    /** The path is kept exactly as sent ('//x' stays a path; Nyholm's Uri would fold it into an authority). */
    private function request(string $method, string $target): ServerRequestInterface
    {
        [$path, $query] = array_pad(explode('?', $target, 2), 2, '');

        return $this->stubbed($path, $query)->withMethod($method);
    }

    private function stubbed(string $path, string $query): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn($path);
        $uri->method('getQuery')->willReturn($query);

        return (new ServerRequest('GET', '/'))->withUri($uri);
    }

    public function markHandled(): void
    {
        $this->handled++;
    }

    public function test_trailing_slash_redirects_301(): void
    {
        $r = $this->send('GET', '/faq/');
        $this->assertSame(301, $r->getStatusCode());
        $this->assertSame('/faq', $r->getHeaderLine('Location'));
        $this->assertSame(0, $this->handled);
    }

    public function test_root_is_left_alone(): void
    {
        $this->assertSame(200, $this->send('GET', '/')->getStatusCode());
    }

    public function test_repeated_slashes_collapse(): void
    {
        $this->assertSame('/en/faq', $this->send('GET', '//en//faq')->getHeaderLine('Location'));
    }

    public function test_only_slashes_become_root(): void
    {
        $r = $this->send('GET', '///');
        $this->assertSame(301, $r->getStatusCode());
        $this->assertSame('/', $r->getHeaderLine('Location'));
    }

    public function test_query_string_is_kept(): void
    {
        $this->assertSame('/faq?a=1&b=x//y/', $this->send('GET', '/faq/?a=1&b=x//y/')->getHeaderLine('Location'));
    }

    public function test_post_is_never_redirected(): void
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $m) {
            $this->assertSame(200, $this->send($m, '/faq/')->getStatusCode(), $m);
        }
    }

    public function test_head_is_redirected(): void
    {
        $this->assertSame(301, $this->send('HEAD', '/faq/')->getStatusCode());
    }

    public function test_leading_double_slash_never_becomes_protocol_relative(): void
    {
        $r = $this->send('GET', '//evil.example/x/');
        $this->assertSame('/evil.example/x', $r->getHeaderLine('Location'));
        $r = $this->send('GET', '////evil.example');
        $this->assertSame('/evil.example', $r->getHeaderLine('Location'));
    }

    public function test_non_public_paths_are_left_alone(): void
    {
        $np = new NonPublicPaths();
        $np->add('/folio');
        $this->assertSame(200, $this->send('GET', '/folio/pages/', $np)->getStatusCode());
        $this->assertSame(200, $this->send('GET', '//folio/pages/', $np)->getStatusCode());
        $this->assertSame(200, $this->send('GET', '/--component/x/', $np)->getStatusCode());
    }

    public function test_double_dash_first_segment_is_left_alone_without_non_public_paths(): void
    {
        foreach (['/--locale/de/', '/--anything//x/', '//--locale/de/', '/--/', '/de/--component/x/', '//de-ch//--component/x/'] as $path) {
            $r = $this->send('GET', $path);
            $this->assertSame(200, $r->getStatusCode(), $path);
            $this->assertSame(1, $this->handled, $path);
        }
    }

    public function test_internal_render_is_left_alone(): void
    {
        $request = InternalRender::mark($this->request('GET', '/faq/'));
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
        $r = (new CanonicalPathMiddleware())->process($request, $handler);
        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('', $r->getHeaderLine('Location'));
    }

    public function test_root_with_query_only_is_not_rewritten(): void
    {
        $r = $this->send('GET', '/?x');
        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('', $r->getHeaderLine('Location'));
        $this->assertSame(1, $this->handled);
    }

    public function test_clean_path_passes_through(): void
    {
        $this->assertSame(200, $this->send('GET', '/a/b?x=1')->getStatusCode());
        $this->assertSame(1, $this->handled);
    }

    /** @return array<string, array{string}> */
    public static function unsafePaths(): array
    {
        return [
            'dot segment' => ['/a/../b/'],
            'single dot' => ['/a/./b//'],
            'encoded dot segment' => ['//a/%2e%2E/b'],
            'backslash' => ['/\\evil.example/'],
            'backslash after slashes' => ['//\\evil.example'],
            'encoded backslash' => ['//a%5Cb'],
            'encoded slash' => ['//a%2Fb'],
            'control byte' => ["/a\x01b/"],
        ];
    }

    #[DataProvider('unsafePaths')]
    public function test_ambiguous_paths_are_left_alone(string $path): void
    {
        $request = $this->stubbed($path, '');
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
        $r = (new CanonicalPathMiddleware())->process($request, $handler);
        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('', $r->getHeaderLine('Location'));
    }

    public function test_control_bytes_in_query_are_left_alone(): void
    {
        $request = $this->stubbed('/faq/', "a=1\r\nX: y");
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
        $this->assertSame(200, (new CanonicalPathMiddleware())->process($request, $handler)->getStatusCode());
    }

    public function test_redirect_is_not_vary_dependent_and_cacheable_by_url(): void
    {
        $r = $this->send('GET', '/faq/');
        $this->assertSame('', $r->getHeaderLine('Set-Cookie'));
        $this->assertSame('', $r->getHeaderLine('Vary'));
    }

    public function test_canonical(): void
    {
        $this->assertSame('/', CanonicalPathMiddleware::canonical('/'));
        $this->assertSame('/', CanonicalPathMiddleware::canonical(''));
        $this->assertSame('/a/b', CanonicalPathMiddleware::canonical('//a///b//'));
        $this->assertSame('/a%2F/b', CanonicalPathMiddleware::canonical('/a%2F//b'));
    }

    public function test_enabled_reads_the_routing_config(): void
    {
        $this->assertFalse(CanonicalPathMiddleware::enabled(null));
        $this->assertFalse(CanonicalPathMiddleware::enabled([]));
        $this->assertFalse(CanonicalPathMiddleware::enabled(['canonical_paths' => false]));
        $this->assertTrue(CanonicalPathMiddleware::enabled(['canonical_paths' => true]));
    }

    public function test_enabled_rejects_non_bool(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('canonical_paths');
        CanonicalPathMiddleware::enabled(['canonical_paths' => 'yes']);
    }

    public function test_enabled_rejects_non_array_file(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CanonicalPathMiddleware::enabled('nope');
    }

    // ---- one hop with a redirect entry, and the language home's slash (0.78.2) ----

    private function urls(bool $homeSlash = false): LocaleUrls
    {
        return new LocaleUrls(['en', 'de'], 'en', true, false, false, $homeSlash);
    }

    private function entry(string $path, string $locale, string $url, bool $area = false, int $status = 301, AreaMode $mode = AreaMode::One): Redirect
    {
        return new Redirect(Redirect::id($area ? 'area' : 'exact', $locale, $path), $path, $locale, $area, $mode, null, null, $url, $status);
    }

    private function sendWith(string $uri, ?InMemoryRedirectStore $store, ?LocaleUrls $urls, ?ServerRequestInterface $base = null): ResponseInterface
    {
        $this->handled = 0;
        $handler = new class ($this) implements RequestHandlerInterface {
            public function __construct(private readonly CanonicalPathMiddlewareTest $t) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->t->markHandled();

                return new Response(200, [], 'ok');
            }
        };
        $middleware = new CanonicalPathMiddleware(
            null,
            // a closure: the store may be bound after boot
            $store === null ? null : static fn (): RedirectResolver => new RedirectResolver($store),
            $urls,
            $store === null ? null : static fn (Redirect $entry) => $store->hit($entry, new \DateTimeImmutable()),
        );
        $request = $base ?? $this->request('GET', $uri);

        return $middleware->process($request, $handler);
    }

    public function test_an_untidy_address_with_an_entry_answers_in_one_hop(): void
    {
        $store = new InMemoryRedirectStore([$this->entry('/old-help', 'de', '/de/help')]);

        $r = $this->sendWith('/de/old-help/?x=1', $store, $this->urls());

        $this->assertSame(301, $r->getStatusCode());
        $this->assertSame('/de/help?x=1', $r->getHeaderLine('Location'));
        $this->assertSame('public, max-age=86400', $r->getHeaderLine('Cache-Control'));
        $this->assertSame(0, $this->handled);
        $this->assertCount(1, $store->hits);
    }

    public function test_the_entry_keeps_its_status(): void
    {
        $store = new InMemoryRedirectStore([$this->entry('/old-help', 'de', '/de/help', status: 302)]);

        $this->assertSame(302, $this->sendWith('/de/old-help/', $store, $this->urls())->getStatusCode());
    }

    public function test_an_area_entry_answers_in_one_hop(): void
    {
        $store = new InMemoryRedirectStore([$this->entry('/old-help', 'de', '/de/help', area: true, mode: AreaMode::Prefix)]);

        $r = $this->sendWith('/de/old-help/page/', $store, $this->urls());

        $this->assertSame(301, $r->getStatusCode());
        $this->assertSame('/de/help/page', $r->getHeaderLine('Location'));
        $this->assertCount(1, $store->hits);
    }

    public function test_an_unprefixed_address_uses_the_default_locale(): void
    {
        $store = new InMemoryRedirectStore([$this->entry('/old-help', 'en', '/help')]);

        $this->assertSame('/help', $this->sendWith('/old-help/', $store, $this->urls())->getHeaderLine('Location'));
    }

    public function test_the_entry_is_looked_up_in_the_locale_the_address_names(): void
    {
        $store = new InMemoryRedirectStore([$this->entry('/old-help', 'en', '/help')]);

        $r = $this->sendWith('/de/old-help/', $store, $this->urls());

        $this->assertSame('/de/old-help', $r->getHeaderLine('Location'), 'no German entry: the clean 301');
        $this->assertSame([], $store->hits);
    }

    public function test_without_an_entry_it_is_the_clean_301(): void
    {
        $store = new InMemoryRedirectStore([]);

        $r = $this->sendWith('/de/faq/', $store, $this->urls());

        $this->assertSame(301, $r->getStatusCode());
        $this->assertSame('/de/faq', $r->getHeaderLine('Location'));
        $this->assertFalse($r->hasHeader('Cache-Control'));
    }

    public function test_a_clean_address_with_an_entry_passes_on(): void
    {
        $store = new InMemoryRedirectStore([$this->entry('/old-help', 'de', '/de/help')]);

        $this->assertSame(200, $this->sendWith('/de/old-help', $store, $this->urls())->getStatusCode());
        $this->assertSame([], $store->hits);
    }

    public function test_an_internal_render_counts_no_hit(): void
    {
        $store = new InMemoryRedirectStore([$this->entry('/old-help', 'de', '/de/help')]);
        $base = InternalRender::mark($this->request('GET', '/de/old-help/'));

        $r = $this->sendWith('/de/old-help/', $store, $this->urls(), $base);

        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame([], $store->hits);
    }

    public function test_an_entry_pointing_at_the_clean_address_keeps_the_clean_301(): void
    {
        $store = new InMemoryRedirectStore([$this->entry('/old-help', 'de', '/de/old-help')]);

        $r = $this->sendWith('/de/old-help/', $store, $this->urls());

        $this->assertSame('/de/old-help', $r->getHeaderLine('Location'));
        $this->assertSame([], $store->hits);
    }

    public function test_a_protocol_relative_target_is_never_used(): void
    {
        $store = new InMemoryRedirectStore([$this->entry('/old-help', 'de', '//evil.example/x')]);

        $r = $this->sendWith('/de/old-help/', $store, $this->urls());

        $this->assertSame('/de/old-help', $r->getHeaderLine('Location'));
    }

    public function test_a_failing_store_falls_back_to_the_clean_301(): void
    {
        $store = new InMemoryRedirectStore([$this->entry('/old-help', 'de', '/de/help')]);
        $store->fail = true;

        $this->assertSame('/de/old-help', $this->sendWith('/de/old-help/', $store, $this->urls())->getHeaderLine('Location'));
    }

    public function test_a_resolver_given_directly_works_too(): void
    {
        $store = new InMemoryRedirectStore([$this->entry('/old-help', 'en', '/help')]);
        $middleware = new CanonicalPathMiddleware(null, new RedirectResolver($store), $this->urls());

        $r = $middleware->process($this->request('GET', '/old-help/'), new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        });

        $this->assertSame('/help', $r->getHeaderLine('Location'));
    }

    public function test_home_slash_keeps_the_slash_of_a_locale_root(): void
    {
        $urls = $this->urls(homeSlash: true);

        $this->assertSame('/de/', $this->sendWith('/de', null, $urls)->getHeaderLine('Location'));
        $this->assertSame(301, $this->sendWith('/de', null, $urls)->getStatusCode());
        $this->assertSame('/de/', $this->sendWith('/de//', null, $urls)->getHeaderLine('Location'));
        $this->assertSame(200, $this->sendWith('/de/', null, $urls)->getStatusCode());
        $this->assertSame(200, $this->sendWith('/', null, $urls)->getStatusCode());
        $faq = $this->sendWith('/de/faq/', null, $urls);
        $this->assertSame(301, $faq->getStatusCode());
        $this->assertSame('/de/faq', $faq->getHeaderLine('Location'));
    }

    public function test_without_home_slash_the_locale_root_has_no_slash(): void
    {
        $urls = $this->urls();

        $this->assertSame('/de', $this->sendWith('/de/', null, $urls)->getHeaderLine('Location'));
        $this->assertSame(200, $this->sendWith('/de', null, $urls)->getStatusCode());
    }

    public function test_the_same_requests_answer_alike_without_the_new_options(): void
    {
        $plain = new CanonicalPathMiddleware();
        $wired = new CanonicalPathMiddleware(null, static fn (): RedirectResolver => new RedirectResolver(new InMemoryRedirectStore([])), $this->urls(), static function (): void {});
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, ['X-A' => 'b'], 'ok');
            }
        };

        foreach (['/', '/faq', '/faq/', '//en//faq', '/de/', '/de', '/de/faq/?x=1', '/--locale/de/', '/a/./b/', '/x%2Fy/'] as $uri) {
            $a = $plain->process($this->request('GET', $uri), $handler);
            $b = $wired->process($this->request('GET', $uri), $handler);
            $this->assertSame($a->getStatusCode(), $b->getStatusCode(), $uri);
            $this->assertSame($a->getHeaders(), $b->getHeaders(), $uri);
            $this->assertSame((string) $a->getBody(), (string) $b->getBody(), $uri);
        }
    }
}
