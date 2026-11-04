<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests\Redirect;

require_once __DIR__ . '/Fixtures/InMemoryRedirectStore.php';
require_once __DIR__ . '/Fixtures/InMemoryRedirectTargets.php';
require_once __DIR__ . '/Fixtures/InMemoryNotFoundLog.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Core\Http\NonPublicPaths;
use Preflow\Routing\Redirect\AreaMode;
use Preflow\Routing\Redirect\NotFoundFilter;
use Preflow\Routing\Redirect\Redirect;
use Preflow\Routing\Redirect\RedirectMiddleware;
use Preflow\Routing\Redirect\RedirectTargets;
use Preflow\Routing\Tests\Redirect\Fixtures\InMemoryNotFoundLog;
use Preflow\Routing\Tests\Redirect\Fixtures\InMemoryRedirectStore;
use Preflow\Routing\Tests\Redirect\Fixtures\InMemoryRedirectTargets;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RedirectMiddlewareTest extends TestCase
{
    private InMemoryNotFoundLog $log;

    /** @var list<string> */
    private array $warnings = [];

    protected function setUp(): void
    {
        $this->log = new InMemoryNotFoundLog();
    }

    public function test_exact_entry_redirects_with_its_status_and_cache_header(): void
    {
        $store = new InMemoryRedirectStore([
            $this->exact('/old', 'en', url: '/new'),
            $this->exact('/temp', 'en', url: 'https://other.example/x', status: 302),
        ]);

        $response = $this->answer($store, $this->get('/old'));

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/new', $response->getHeaderLine('Location'));
        $this->assertSame('public, max-age=86400', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('', (string) $response->getBody());
        $this->assertSame([Redirect::id('exact', 'en', '/old')], $store->hits);

        $temp = $this->answer($store, $this->get('/temp'));
        $this->assertSame(302, $temp->getStatusCode());
        $this->assertSame('https://other.example/x', $temp->getHeaderLine('Location'));
        $this->assertSame([], $this->log->recorded);
    }

    public function test_any_language_entry_matches_every_locale(): void
    {
        $store = new InMemoryRedirectStore([$this->exact('/old', '', url: '/new')]);

        $this->assertSame('/new', $this->answer($store, $this->get('/old'))->getHeaderLine('Location'));
        $this->assertSame('/new', $this->answer($store, $this->get('/old', 'de'))->getHeaderLine('Location'));
        $this->assertSame('/new', $this->answer($store, $this->get('/old', 'fr'))->getHeaderLine('Location'));
    }

    public function test_longest_area_wins(): void
    {
        $store = new InMemoryRedirectStore([
            $this->area('/blog', 'en', url: '/news'),
            $this->area('/blog/2020', 'en', url: '/archive'),
            $this->area('/blo', 'en', url: '/wrong'),
        ]);

        $this->assertSame('/archive', $this->answer($store, $this->get('/blog/2020/post'))->getHeaderLine('Location'));
        $this->assertSame('/news', $this->answer($store, $this->get('/blog/2021/post'))->getHeaderLine('Location'));
        $this->assertSame('/news', $this->answer($store, $this->get('/blog'))->getHeaderLine('Location'));
    }

    public function test_area_one_target_and_prefix_mode(): void
    {
        $store = new InMemoryRedirectStore([
            $this->area('/shop', 'en', url: '/store'),
            $this->area('/team', 'en', url: '/people/', mode: AreaMode::Prefix),
            $this->area('/home', 'en', url: '/', mode: AreaMode::Prefix),
        ]);

        $this->assertSame('/store', $this->answer($store, $this->get('/shop/a/b'))->getHeaderLine('Location'));
        $this->assertSame('/people/anna/cv', $this->answer($store, $this->get('/team/anna/cv/'))->getHeaderLine('Location'));
        $this->assertSame('/people', $this->answer($store, $this->get('/team'))->getHeaderLine('Location'));
        $this->assertSame('/', $this->answer($store, $this->get('/home'))->getHeaderLine('Location'));
        $this->assertSame('/x', $this->answer($store, $this->get('/home/x'))->getHeaderLine('Location'));
    }

    public function test_prefix_area_never_yields_a_protocol_relative_location(): void
    {
        $store = new InMemoryRedirectStore([$this->area('/old', 'en', url: '/', mode: AreaMode::Prefix)]);

        $response = $this->answer($store, $this->get('/old//evil.example/x'));

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/evil.example/x', $response->getHeaderLine('Location'));

        // A URL target that is itself protocol-relative (or backslash-relative) is never sent.
        $bad = new InMemoryRedirectStore([
            $this->exact('/a', 'en', url: '//evil.example/x'),
            $this->exact('/b', 'en', url: '/\\evil.example/x'),
        ]);
        $this->assertNotFound($bad, $this->get('/a'));
        $this->assertNotFound($bad, $this->get('/b'));
    }

    public function test_query_string_is_kept(): void
    {
        $store = new InMemoryRedirectStore([
            $this->exact('/old', 'en', url: '/new'),
            $this->exact('/search', 'en', url: '/find?scope=all'),
            $this->exact('/anchor', 'en', url: '/page#top'),
        ]);

        $this->assertSame('/new?a=1&b=2', $this->answer($store, $this->get('/old?a=1&b=2'))->getHeaderLine('Location'));
        $this->assertSame('/find?scope=all&q=x', $this->answer($store, $this->get('/search?q=x'))->getHeaderLine('Location'));
        $this->assertSame('/page?q=x#top', $this->answer($store, $this->get('/anchor?q=x'))->getHeaderLine('Location'));
        $this->assertSame('/new', $this->answer($store, $this->get('/old'))->getHeaderLine('Location'));
    }

    public function test_head_is_redirected_post_is_not(): void
    {
        $store = new InMemoryRedirectStore([$this->exact('/old', 'en', url: '/new')]);

        $this->assertSame(301, $this->answer($store, $this->request('HEAD', '/old'))->getStatusCode());
        $this->assertNotFound($store, $this->request('POST', '/old'));
        $this->assertNotFound($store, $this->request('PUT', '/missing'));
        $this->assertSame([], $this->log->recorded);
    }

    public function test_record_target_uses_the_request_locale(): void
    {
        $store = new InMemoryRedirectStore([
            $this->exact('/old', '', type: 'page', id: '7'),
            $this->exact('/alt', 'fr', type: 'page', id: '7'),
        ]);
        $targets = new InMemoryRedirectTargets([
            'page:7:de' => '/de/neu',
            'page:7:en' => '/new',
            'page:7:fr' => '/fr/nouveau',
        ]);

        $this->assertSame('/de/neu', $this->answer($store, $this->get('/old', 'de'), $targets)->getHeaderLine('Location'));
        $this->assertSame('/new', $this->answer($store, $this->get('/old'), $targets)->getHeaderLine('Location'));
        // An entry of its own language asks for that language.
        $this->assertSame('/fr/nouveau', $this->answer($store, $this->get('/alt', 'fr'), $targets)->getHeaderLine('Location'));
        $this->assertSame(['page:7:de', 'page:7:en', 'page:7:fr'], $targets->asked);
    }

    public function test_target_without_url_keeps_the_404_and_counts_a_hit(): void
    {
        $store = new InMemoryRedirectStore([
            $this->exact('/gone', 'en', type: 'page', id: '9'),
            $this->exact('/untargeted', 'en'),
        ]);

        $this->assertNotFound($store, $this->get('/gone'), new InMemoryRedirectTargets());
        $this->assertNotFound($store, $this->get('/untargeted'), new InMemoryRedirectTargets());
        // Without a targets service a record target has no URL either.
        $this->assertNotFound($store, $this->get('/gone'));

        $this->assertSame([
            Redirect::id('exact', 'en', '/gone'),
            Redirect::id('exact', 'en', '/untargeted'),
            Redirect::id('exact', 'en', '/gone'),
        ], $store->hits);
        $this->assertSame([], $this->log->recorded);
    }

    public function test_target_equal_to_the_request_keeps_the_404(): void
    {
        $store = new InMemoryRedirectStore([
            $this->exact('/same', 'en', url: '/same/'),
            $this->exact('/abs', 'en', url: 'https://example.com/abs'),
            $this->exact('/%C3%BCber', 'en', url: '/über'),
            $this->exact('/x', 'de', url: '/de/x'),
            $this->exact('/y', 'de', url: '/y'),
        ]);

        $this->assertNotFound($store, $this->get('/same'));
        $this->assertNotFound($store, $this->get('/abs'));
        $this->assertNotFound($store, $this->get('/%C3%BCber'));
        // The visitor asked for /de/x: a target of /de/x is the same page.
        $this->assertNotFound($store, $this->get('/x', 'de'), urlPrefix: '/de');
        $this->assertCount(4, $store->hits);
        $this->assertSame([], $this->log->recorded);

        // The visitor asked for /de/y: the default-language /y is another address.
        $this->assertSame('/y', $this->answer($store, $this->get('/y', 'de'), urlPrefix: '/de')->getHeaderLine('Location'));
        // Same path on another host is another address.
        $other = new InMemoryRedirectStore([$this->exact('/abs', 'en', url: 'https://other.example/abs')]);
        $this->assertSame('https://other.example/abs', $this->answer($other, $this->get('/abs'))->getHeaderLine('Location'));
    }

    public function test_non_public_paths_are_neither_redirected_nor_recorded(): void
    {
        $store = new InMemoryRedirectStore([$this->exact('/folio/old', 'en', url: '/new')]);

        $this->assertNotFound($store, $this->get('/folio/old'));
        $this->assertNotFound($store, $this->get('//folio/old/'));
        $this->assertNotFound($store, $this->get('/--component/x'));
        $this->assertSame([], $store->hits);
        $this->assertSame([], $this->log->recorded);
    }

    public function test_a_404_response_is_left_alone(): void
    {
        $store = new InMemoryRedirectStore([$this->exact('/old', 'en', url: '/new')]);
        $notFound = (new Psr17Factory())->createResponse(404);

        $response = $this->middleware($store)->process($this->get('/old'), $this->handler(static fn () => $notFound));

        $this->assertSame($notFound, $response);
        $this->assertSame([], $store->hits);
        $this->assertSame([], $this->log->recorded);

        $other = new \RuntimeException('boom');
        try {
            $this->middleware($store)->process($this->get('/old'), $this->handler(static fn () => throw $other));
            $this->fail('expected the exception');
        } catch (\RuntimeException $e) {
            $this->assertSame($other, $e);
        }
        $this->assertSame([], $this->log->recorded);
    }

    public function test_not_found_is_recorded_without_visitor_data(): void
    {
        $store = new InMemoryRedirectStore();
        $request = (new Psr17Factory())
            ->createServerRequest('GET', 'https://example.com/missing/page/?utm=x', ['REMOTE_ADDR' => '203.0.113.9'])
            ->withAttribute('locale', 'de')
            ->withHeader('User-Agent', 'Mozilla')
            ->withHeader('Referer', 'https://ref.example/');

        $this->assertNotFound($store, $request);
        $this->assertNotFound($store, $this->get('/m%c3%bcde'));

        $this->assertSame([['/missing/page', 'de'], ['/m%C3%BCde', 'en']], $this->log->recorded);
    }

    public function test_noise_is_not_recorded(): void
    {
        $store = new InMemoryRedirectStore();

        $this->assertNotFound($store, $this->get('/wp-login.php'));
        $this->assertNotFound($store, $this->get('/.env'));
        $this->assertNotFound($store, $this->get('/backup.zip/'));
        $this->assertNotFound($store, $this->get('/a/../b'));
        $this->assertSame([], $this->log->recorded);

        // Without a log nothing is recorded and the 404 stays.
        $this->assertNotFound($store, $this->get('/missing'), log: false);
        $this->assertSame([], $this->log->recorded);
    }

    public function test_store_failure_keeps_the_404(): void
    {
        $store = new InMemoryRedirectStore([$this->exact('/old', 'en', url: '/new')]);
        $store->fail = true;

        $this->assertNotFound($store, $this->get('/old'));
        $this->assertNotEmpty($this->warnings);

        // A failing hit counter does not stop the redirect.
        $store->fail = false;
        $store->failHit = true;
        $this->assertSame(301, $this->answer($store, $this->get('/old'))->getStatusCode());

        // A failing targets service keeps the 404.
        $broken = new class implements RedirectTargets {
            public function url(string $type, string $id, string $locale): ?string
            {
                throw new \RuntimeException('db down');
            }
        };
        $record = new InMemoryRedirectStore([$this->exact('/rec', 'en', type: 'page', id: '1')]);
        $this->assertNotFound($record, $this->get('/rec'), $broken);
    }

    public function test_log_failure_keeps_the_404(): void
    {
        $this->log->fail = true;

        $this->assertNotFound(new InMemoryRedirectStore(), $this->get('/missing'));
        $this->assertNotEmpty($this->warnings);
    }

    private function answer(
        InMemoryRedirectStore $store,
        ServerRequestInterface $request,
        ?RedirectTargets $targets = null,
        string $urlPrefix = '',
    ): ResponseInterface {
        return $this->middleware($store, $targets, true, $urlPrefix)->process($request, $this->notFound());
    }

    private function assertNotFound(
        InMemoryRedirectStore $store,
        ServerRequestInterface $request,
        ?RedirectTargets $targets = null,
        bool $log = true,
        string $urlPrefix = '',
    ): void {
        $thrown = new NotFoundHttpException();

        try {
            $this->middleware($store, $targets, $log, $urlPrefix)
                ->process($request, $this->handler(static fn () => throw $thrown));
        } catch (NotFoundHttpException $e) {
            $this->assertSame($thrown, $e, 'the original 404 is rethrown');
            return;
        }

        $this->fail('expected the 404 to be rethrown for ' . $request->getUri());
    }

    private function middleware(
        InMemoryRedirectStore $store,
        ?RedirectTargets $targets = null,
        bool $log = true,
        string $urlPrefix = '',
    ): RedirectMiddleware {
        $nonPublic = new NonPublicPaths();
        $nonPublic->add('/folio');

        return new RedirectMiddleware(
            $store,
            $targets,
            $log ? $this->log : null,
            $nonPublic,
            new NotFoundFilter(),
            'en',
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-04 12:00:00'),
            function (string $message): void {
                $this->warnings[] = $message;
            },
            static fn (): string => $urlPrefix,
        );
    }

    private function notFound(): RequestHandlerInterface
    {
        return $this->handler(static fn () => throw new NotFoundHttpException());
    }

    /** @param \Closure(ServerRequestInterface): ResponseInterface $handle */
    private function handler(\Closure $handle): RequestHandlerInterface
    {
        return new class ($handle) implements RequestHandlerInterface {
            public function __construct(private readonly \Closure $handle) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->handle)($request);
            }
        };
    }

    private function get(string $target, ?string $locale = null): ServerRequestInterface
    {
        return $this->request('GET', $target, $locale);
    }

    private function request(string $method, string $target, ?string $locale = null): ServerRequestInterface
    {
        $request = (new Psr17Factory())->createServerRequest($method, 'https://example.com' . $target);

        return $locale === null ? $request : $request->withAttribute('locale', $locale);
    }

    private function exact(
        string $path,
        string $locale,
        ?string $url = null,
        ?string $type = null,
        ?string $id = null,
        int $status = 301,
    ): Redirect {
        return new Redirect(Redirect::id('exact', $locale, $path), $path, $locale, false, AreaMode::One, $type, $id, $url, $status);
    }

    private function area(string $path, string $locale, ?string $url = null, AreaMode $mode = AreaMode::One): Redirect
    {
        return new Redirect(Redirect::id('area', $locale, $path), $path, $locale, true, $mode, null, null, $url, 301);
    }
}
