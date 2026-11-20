<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\PageCache;

require_once __DIR__ . '/Fixtures/PurposeRoutes.php';

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Purpose;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\RoleView;
use Preflow\Core\PageCache\PageCacheConfig;
use Preflow\Core\PageCache\PageKey;
use Preflow\Core\PageCache\PageStore;
use Preflow\Core\PageCache\Rebuild;
use Preflow\Core\PageCache\TagIndex;
use Preflow\Core\Tests\PageCache\Fixtures\PurposeRoutes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Only a visitor's render and a rebuild are ever stored, and only when the context is what an
 * anonymous visitor gets (RenderContext::isStorable()): the purpose and the context decide, not
 * the request. A request carrying a preview token is never served from the cache or stored.
 */
final class StorageByPurposeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        Contexts::reset();
        PurposeRoutes::$routes = [];
        PurposeRoutes::$seen = [];
        $this->dir = sys_get_temp_dir() . '/pcpurpose_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/config', 0777, true);
        @mkdir($this->dir . '/app/pages', 0777, true);
        unset($_SESSION);
        ini_set('error_log', $this->dir . '/php.log');
    }

    protected function tearDown(): void
    {
        Contexts::reset();
        PurposeRoutes::$routes = [];
        PurposeRoutes::$seen = [];
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** @return iterable<string, array{Purpose}> */
    public static function purposes(): iterable
    {
        foreach (Purpose::cases() as $purpose) {
            yield $purpose->value => [$purpose];
        }
    }

    #[DataProvider('purposes')]
    #[RunInSeparateProcess]
    public function test_only_visit_and_rebuild_renders_are_stored(Purpose $purpose): void
    {
        $app = $this->site();
        // A rebuild renders the request the Refresher builds; every other purpose a plain GET.
        $request = $purpose === Purpose::Rebuild
            ? Rebuild::request('https://example.com/about')
            : new ServerRequest('GET', 'https://example.com/about');
        $ctx = RenderContext::fromRequest($request, $app->site(), new \Preflow\Core\Context\SystemClock())->because($purpose);
        $this->assertTrue($ctx->isPublicDefault(), 'the public default context');

        $response = $app->render($ctx, $request)->response;

        $this->assertSame(200, $response->getStatusCode());
        $stored = in_array($purpose, [Purpose::Visit, Purpose::Rebuild], true);
        $this->assertSame($stored ? 1 : 0, $this->pages(), $purpose->value . ($stored ? ' is stored' : ' is never stored'));
        $this->assertSame($stored, $this->store()->get($this->key('/about')) !== null);
        $this->assertSame([$purpose->value], array_column(PurposeRoutes::$seen, 'purpose'), 'rendered once, under its own purpose');
    }

    #[RunInSeparateProcess]
    public function test_audit_and_geo_renders_leave_store_and_tag_index_untouched(): void
    {
        $app = $this->site();
        $this->assertSame('miss', $app->handle(new ServerRequest('GET', 'https://example.com/about'))->getHeaderLine('X-Page-Cache'));
        $file = $this->pageFile('/about');
        $before = [file_get_contents($file), filemtime($file), $this->rows(), $this->tags()];
        clearstatcache();

        foreach ([Purpose::Audit, Purpose::Geo] as $purpose) {
            foreach (['/about', '/other'] as $path) {
                $ctx = RenderContext::internal($purpose, $app->site(), new \Preflow\Core\Context\SystemClock(), $path);
                $response = $app->render($ctx, new ServerRequest('GET', 'https://example.com' . $path))->response;
                $this->assertSame(200, $response->getStatusCode());
                $this->assertSame('', $response->getHeaderLine('X-Page-Cache'), $purpose->value . ' ' . $path . ' passes the cache by');
            }
        }

        clearstatcache();
        $this->assertSame($before, [file_get_contents($file), filemtime($file), $this->rows(), $this->tags()], 'the stored page, its rows and its tags as they were');
        $this->assertSame(1, $this->pages());
        $this->assertNull($this->store()->get($this->key('/other')));
    }

    #[RunInSeparateProcess]
    public function test_a_visit_with_a_non_default_context_is_not_stored(): void
    {
        $app = $this->site();
        $request = new ServerRequest('GET', 'https://example.com/about');
        $visit = RenderContext::fromRequest($request, $app->site(), new \Preflow\Core\Context\SystemClock());

        foreach ([
            'working copies' => $visit->workingCopies(),
            'a synthetic viewer' => $visit->asRole(new RoleView('members', ['member'])),
            'frozen time' => $visit->at(new \DateTimeImmutable('2026-01-01 12:00:00')),
        ] as $what => $ctx) {
            $this->assertSame(Purpose::Visit, $ctx->purpose);
            $response = $app->render($ctx, $request)->response;
            $this->assertSame(200, $response->getStatusCode(), $what);
            $this->assertSame('', $response->getHeaderLine('X-Page-Cache'), $what . ': passes the cache by');
            $this->assertSame(0, $this->pages(), $what . ': not stored');
        }

        // A context narrowed during the render (a middleware that switches to working copies) is not stored either.
        PurposeRoutes::$routes['/narrowed'] = static function (): ResponseInterface {
            Contexts::narrow(static fn (RenderContext $c): RenderContext => $c->workingCopies());

            return new Response(200, ['Content-Type' => 'text/html'], '<p>narrowed</p>');
        };
        $narrowed = $app->handle(new ServerRequest('GET', 'https://example.com/narrowed'));
        $this->assertSame('miss', $narrowed->getHeaderLine('X-Page-Cache'));
        $this->assertSame('context', $narrowed->getHeaderLine('X-Page-Cache-Skip'));
        $this->assertSame(0, $this->pages());

        // The same visit as the public default is stored.
        $this->assertSame('miss', $app->render($visit, $request)->response->getHeaderLine('X-Page-Cache'));
        $this->assertSame(1, $this->pages());
    }

    #[RunInSeparateProcess]
    public function test_a_request_with_a_preview_token_is_never_served_from_or_stored_in_the_cache(): void
    {
        $app = $this->site();
        $this->assertSame('miss', $app->handle(new ServerRequest('GET', 'https://example.com/'))->getHeaderLine('X-Page-Cache'));
        $this->assertSame('hit', $app->handle(new ServerRequest('GET', 'https://example.com/'))->getHeaderLine('X-Page-Cache'));
        $count = $this->pages();
        $rendered = count(PurposeRoutes::$seen);

        foreach ([
            new ServerRequest('GET', 'https://example.com/?_preview=x'),
            new ServerRequest('GET', 'https://example.com/', ['X-Preflow-Preview' => 'x']),
            new ServerRequest('GET', 'https://example.com/about?_preview=x'),
        ] as $request) {
            $response = $app->handle($request);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('', $response->getHeaderLine('X-Page-Cache'), (string) $request->getUri() . ': no hit, no miss');
        }

        $this->assertSame($count, $this->pages(), 'nothing stored');
        $this->assertSame($rendered + 3, count(PurposeRoutes::$seen), 'each rendered, none served from the store');
        $this->assertSame('hit', $app->handle(new ServerRequest('GET', 'https://example.com/'))->getHeaderLine('X-Page-Cache'), 'the visitor\'s page stays');
    }

    /**
     * A request asked for inside an internal render (a sub-request of an audited, verified,
     * rebuilt or previewed page) keeps the purpose of the render around it and is never stored.
     */
    #[RunInSeparateProcess]
    public function test_a_request_inside_an_internal_render_is_never_stored(): void
    {
        $app = $this->site();
        PurposeRoutes::$routes['/outer'] = static function (ServerRequestInterface $r, Container $c) use ($app): ResponseInterface {
            $inner = $app->handle(new ServerRequest('GET', 'https://example.com/inner'));

            return new Response(200, ['Content-Type' => 'text/html'], '<p>' . $inner->getHeaderLine('X-Page-Cache') . '</p>');
        };

        foreach ([Purpose::Audit, Purpose::Geo, Purpose::Verify, Purpose::Preview] as $purpose) {
            PurposeRoutes::$seen = [];
            $ctx = RenderContext::internal($purpose, $app->site(), new \Preflow\Core\Context\SystemClock(), '/outer');
            $body = (string) $app->render($ctx, new ServerRequest('GET', 'https://example.com/outer'))->response->getBody();

            $this->assertSame('<p></p>', $body, $purpose->value . ': the inner request passes the cache by');
            $this->assertSame([[$purpose->value, 2]], array_map(static fn (array $s): array => [$s['purpose'], $s['depth']], PurposeRoutes::$seen), $purpose->value . ': the inner request keeps the outer purpose');
        }
        // A rebuild's own request is stored; a request inside it is not.
        PurposeRoutes::$seen = [];
        $rebuild = RenderContext::internal(Purpose::Rebuild, $app->site(), new \Preflow\Core\Context\SystemClock(), '/outer');
        $this->assertSame('miss', $app->render($rebuild, Rebuild::request('https://example.com/outer'))->response->getHeaderLine('X-Page-Cache'));
        $this->assertSame([['rebuild', 2]], array_map(static fn (array $s): array => [$s['purpose'], $s['depth']], PurposeRoutes::$seen));
        $this->assertNotNull($this->store()->get($this->key('/outer')), 'the rebuilt page');
        $this->assertNull($this->store()->get($this->key('/inner')), 'never the request inside it');
        $this->assertSame(1, $this->pages());

        // Inside a visitor's request a request is a visit of its own, as before 0.81.
        PurposeRoutes::$seen = [];
        $app->handle(new ServerRequest('GET', 'https://example.com/outer?visit=1'));
        $this->assertSame([['visit', 2]], array_map(static fn (array $s): array => [$s['purpose'], $s['depth']], PurposeRoutes::$seen));
        $this->assertNotNull($this->store()->get($this->key('/inner')));
    }

    /**
     * A hypermedia fragment of a public page (an htmx GET) is stored under its fragment key, as
     * since 0.60: its purpose is Fragment, its context the public default. Under any other
     * context, or for a full page's key, a Fragment render is not stored.
     */
    #[RunInSeparateProcess]
    public function test_a_public_hypermedia_fragment_is_stored_under_its_fragment_key(): void
    {
        $app = $this->site();
        $fragment = static fn (): ServerRequest => new ServerRequest('GET', 'https://example.com/about', ['HX-Request' => 'true', 'HX-Target' => 'main']);

        $this->assertSame('miss', $app->handle($fragment())->getHeaderLine('X-Page-Cache'));
        $this->assertSame('hit', $app->handle($fragment())->getHeaderLine('X-Page-Cache'));
        $this->assertSame(['fragment'], array_column(PurposeRoutes::$seen, 'purpose'));
        $config = $app->container()->get(PageCacheConfig::class);
        $key = PageKey::fromRequest($fragment(), $config, [], $app->container()->get(\Preflow\Core\Http\Hypermedia\HypermediaProtocols::class));
        $this->assertNotNull($key);
        $this->assertTrue($key->isFragment());
        $this->assertNotNull($this->store()->get($key), 'stored under the key it had in 0.80');
        $this->assertSame(1, $this->pages());

        // A fragment under working copies is not stored, nor a Fragment render of a full page's key.
        $other = new ServerRequest('GET', 'https://example.com/other', ['HX-Request' => 'true']);
        $ctx = RenderContext::fromRequest($other, $app->site(), new \Preflow\Core\Context\SystemClock())->because(Purpose::Fragment);
        $this->assertSame('', $app->render($ctx->workingCopies(), $other)->response->getHeaderLine('X-Page-Cache'));
        $plain = new ServerRequest('GET', 'https://example.com/other');
        $this->assertSame('', $app->render($ctx->for($plain), $plain)->response->getHeaderLine('X-Page-Cache'));
        $this->assertSame(1, $this->pages());
    }

    private function site(): Application
    {
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name' => 't', 'debug' => 0, 'engine' => 'twig', 'key' => 'page-cache-purpose-key-0123456789ab', 'url' => 'https://example.com', 'locale' => 'en'];\n");
        file_put_contents($this->dir . '/config/page_cache.php', "<?php\nreturn ['enabled' => true, 'explain' => true, 'verify' => 'off'];\n");
        file_put_contents($this->dir . '/config/middleware.php', "<?php\nreturn [" . var_export(PurposeRoutes::class, true) . "];\n");
        $app = Application::create($this->dir);
        $app->setRunningInConsole(false);
        $app->boot();

        return $app;
    }

    private function store(): PageStore
    {
        return new PageStore($this->dir . '/storage/cache/pages', PageStore::releaseKey($this->dir));
    }

    private function config(): PageCacheConfig
    {
        return PageCacheConfig::fromArray(['enabled' => true, 'explain' => true, 'verify' => 'off'], 'https://example.com');
    }

    private function key(string $path): PageKey
    {
        return PageKey::fromRequest(new ServerRequest('GET', 'https://example.com' . $path), $this->config(), []) ?? throw new \RuntimeException('no key');
    }

    private function pageFile(string $path): string
    {
        $id = $this->key($path)->id();

        return $this->dir . '/storage/cache/pages/' . PageStore::releaseKey($this->dir) . '/' . substr($id, 0, 2) . '/' . $id . '.page';
    }

    private function index(): TagIndex
    {
        return TagIndex::forStore($this->store(), $this->config());
    }

    private function pages(): int
    {
        return $this->index()->count();
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return $this->index()->pdo()?->query('SELECT id, url, fragment, has_query, created_at FROM pages ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC) ?? [];
    }

    /** @return list<array<string, mixed>> */
    private function tags(): array
    {
        return $this->index()->pdo()?->query('SELECT * FROM tags ORDER BY 1, 2')->fetchAll(\PDO::FETCH_ASSOC) ?? [];
    }
}
