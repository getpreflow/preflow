<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\PageCache;

require_once __DIR__ . '/Fixtures/PurposeRoutes.php';

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Purpose;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\SystemClock;
use Preflow\Core\PageCache\DataTags;
use Preflow\Core\PageCache\DependencyRecorder;
use Preflow\Core\PageCache\LiveParts;
use Preflow\Core\PageCache\PageCache;
use Preflow\Core\PageCache\PageCacheConfig;
use Preflow\Core\PageCache\PageCacheMiddleware;
use Preflow\Core\PageCache\PageKey;
use Preflow\Core\PageCache\PageStore;
use Preflow\Core\PageCache\Refresher;
use Preflow\Core\PageCache\TagIndex;
use Preflow\Core\Tests\PageCache\Fixtures\PurposeRoutes;
use Psr\Http\Message\ResponseInterface;

/**
 * A request asked for inside a visitor's page (Application::handle() in a route) is part of that
 * page, as before 0.81: what it reads tags the page, what it refuses refuses the page, and its
 * live parts leave placeholders the page fills per visitor. A render that reads as another
 * context than the public default for a stretch (Contexts::within()), in its own frame or in a
 * request inside it, is never stored. A stored page is served to an internal render only under
 * the public default context.
 */
final class NestedRenderCacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        Contexts::reset();
        PurposeRoutes::$routes = [];
        PurposeRoutes::$seen = [];
        $this->dir = sys_get_temp_dir() . '/pcnested_' . bin2hex(random_bytes(4));
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

    #[RunInSeparateProcess]
    public function test_what_a_sub_request_reads_tags_the_page_around_it(): void
    {
        $app = $this->site();
        PurposeRoutes::$routes['/inner'] = static function (mixed $r, Container $c): ResponseInterface {
            $c->get(DependencyRecorder::class)->record('menu');

            return new Response(200, ['Content-Type' => 'text/html'], 'inner');
        };
        PurposeRoutes::$routes['/outer'] = static fn (mixed $r, Container $c): ResponseInterface => new Response(
            200,
            ['Content-Type' => 'text/html'],
            '<p>' . (string) $app->handle(new ServerRequest('GET', 'https://example.com/inner'))->getBody() . '</p>',
        );

        $this->assertSame('miss', $app->handle(new ServerRequest('GET', 'https://example.com/outer'))->getHeaderLine('X-Page-Cache'));
        $outer = $this->key('/outer')->id();
        $this->assertArrayHasKey($outer, $this->index()->pagesFor(['menu']), 'the outer page is tagged with what the sub-request read');
        $this->assertArrayHasKey($this->key('/inner')->id(), $this->index()->pagesFor(['menu']), 'and so is the sub-request\'s own page');
        $this->assertSame('hit', $app->handle(new ServerRequest('GET', 'https://example.com/outer'))->getHeaderLine('X-Page-Cache'));

        // A write to what the sub-request read forgets the outer page too.
        (new Refresher($this->store(), $this->config()))->invalidate(DataTags::changes('menu'));
        $this->assertNull($this->store()->get($this->key('/outer')));
        $this->assertSame('miss', $app->handle(new ServerRequest('GET', 'https://example.com/outer'))->getHeaderLine('X-Page-Cache'));
    }

    #[RunInSeparateProcess]
    public function test_what_a_sub_request_refuses_refuses_the_page_around_it(): void
    {
        $app = $this->site();
        PurposeRoutes::$routes['/refuser'] = static function (mixed $r, Container $c): ResponseInterface {
            $c->get(PageCache::class)->refuse('inner-refused');

            return new Response(200, ['Content-Type' => 'text/html'], 'refuser');
        };
        PurposeRoutes::$routes['/outer'] = static fn (): ResponseInterface => new Response(
            200,
            ['Content-Type' => 'text/html'],
            '<p>' . (string) $app->handle(self::bypassed('/refuser'))->getBody() . '</p>',
        );

        $response = $app->handle(new ServerRequest('GET', 'https://example.com/outer'));

        $this->assertSame('miss', $response->getHeaderLine('X-Page-Cache'));
        $this->assertSame('refused:inner-refused', $response->getHeaderLine('X-Page-Cache-Skip'));
        $this->assertSame(0, $this->index()->count());
    }

    #[RunInSeparateProcess]
    public function test_a_live_part_in_a_bypassed_sub_request_leaves_its_placeholder(): void
    {
        $app = $this->site();
        $live = $app->container()->get(LiveParts::class);
        $live->register('probe', static fn (array $recipe): string => '<b>live ' . $recipe['n'] . '</b>');
        PurposeRoutes::$routes['/part'] = static fn (): ResponseInterface => new Response(
            200,
            ['Content-Type' => 'text/html'],
            $live->placeholder('probe', ['n' => 1]) ?? 'rendered in place',
        );
        PurposeRoutes::$routes['/outer'] = static fn (): ResponseInterface => new Response(
            200,
            ['Content-Type' => 'text/html'],
            '<p>' . (string) $app->handle(self::bypassed('/part'))->getBody() . '</p>',
        );

        $response = $app->handle(new ServerRequest('GET', 'https://example.com/outer'));

        $this->assertSame('', $response->getHeaderLine('X-Page-Cache-Skip'));
        $this->assertSame('<p><b>live 1</b></p>', (string) $response->getBody(), 'filled for this visitor');
        $stored = (string) $this->store()->get($this->key('/outer'))?->body;
        $this->assertStringNotContainsString('rendered in place', $stored);
        $this->assertStringNotContainsString('<b>live 1</b>', $stored, 'the stored page holds the placeholder');
        $this->assertTrue($live->in($stored));
    }

    #[RunInSeparateProcess]
    public function test_a_render_that_reads_as_another_context_for_a_stretch_is_not_stored(): void
    {
        $app = $this->site();
        // A component that shows a draft teaser in a public page.
        PurposeRoutes::$routes['/teaser'] = static function (): ResponseInterface {
            $draft = Contexts::within(Contexts::current()->workingCopies(), static fn (): string => Contexts::current()->reads->key());

            return new Response(200, ['Content-Type' => 'text/html'], '<p>' . $draft . '</p>');
        };
        PurposeRoutes::$routes['/outer'] = static fn (): ResponseInterface => new Response(
            200,
            ['Content-Type' => 'text/html'],
            '<p>' . (string) $app->handle(self::bypassed('/teaser'))->getBody() . '</p>',
        );

        $direct = $app->handle(new ServerRequest('GET', 'https://example.com/teaser'));
        $this->assertSame('miss', $direct->getHeaderLine('X-Page-Cache'));
        $this->assertSame('context', $direct->getHeaderLine('X-Page-Cache-Skip'));

        $outer = $app->handle(new ServerRequest('GET', 'https://example.com/outer'));
        $this->assertSame('miss', $outer->getHeaderLine('X-Page-Cache'));
        $this->assertSame('refused:context', $outer->getHeaderLine('X-Page-Cache-Skip'), 'the page around a sub-request that did');
        $this->assertSame(0, $this->index()->count());

        // A within() that stays the public default (another locale) changes nothing.
        PurposeRoutes::$routes['/fine'] = static fn (): ResponseInterface => new Response(200, ['Content-Type' => 'text/html'], '<p>' . Contexts::within(Contexts::current()->in('de'), static fn (): string => 'de') . '</p>');
        $this->assertSame('', $app->handle(new ServerRequest('GET', 'https://example.com/fine'))->getHeaderLine('X-Page-Cache-Skip'));
        $this->assertSame(1, $this->index()->count());
    }

    #[RunInSeparateProcess]
    public function test_a_stored_page_is_served_to_an_internal_render_only_under_the_public_default(): void
    {
        $app = $this->site();
        $this->assertSame('miss', $app->handle(new ServerRequest('GET', 'https://example.com/about'))->getHeaderLine('X-Page-Cache'));
        $request = (new ServerRequest('GET', 'https://example.com/about'))->withAttribute(PageCacheMiddleware::SERVE_STORED, true);
        $geo = RenderContext::internal(Purpose::Geo, $app->site(), new SystemClock(), '/about');

        $this->assertSame('hit', $app->render($geo, $request)->response->getHeaderLine('X-Page-Cache'), 'GEO\'s llms-full.txt takes the stored page');
        $drafts = $app->render($geo->workingCopies(), $request)->response;
        $this->assertSame('', $drafts->getHeaderLine('X-Page-Cache'), 'under working copies it renders, never from the store');
        $this->assertSame(1, $this->index()->count());
    }

    #[RunInSeparateProcess]
    public function test_nothing_rendered_inside_a_capturing_render_is_stored(): void
    {
        $app = $this->site();
        PurposeRoutes::$routes['/outer'] = static fn (): ResponseInterface => new Response(
            200,
            ['Content-Type' => 'text/html'],
            '<p>' . $app->handle(new ServerRequest('GET', 'https://example.com/inner'))->getHeaderLine('X-Page-Cache') . '</p>',
        );
        $request = new ServerRequest('GET', 'https://example.com/outer');
        $ctx = RenderContext::fromRequest($request, $app->site(), new SystemClock())->capturing();

        $response = $app->render($ctx, $request)->response;

        $this->assertSame('', $response->getHeaderLine('X-Page-Cache'), 'a capturing visit is not what a visitor gets');
        $this->assertSame('<p></p>', (string) $response->getBody(), 'nor is the request inside it');
        $this->assertSame(0, $this->index()->count());
    }

    /** A sub-request the page cache passes by (a host it does not cache), as a POST or an admin path would. */
    private static function bypassed(string $path): ServerRequest
    {
        return new ServerRequest('GET', 'https://example.com' . $path, ['X-Forwarded-Host' => 'elsewhere.test']);
    }

    private function site(): Application
    {
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name' => 't', 'debug' => 0, 'engine' => 'twig', 'key' => 'page-cache-nested-key-0123456789ab', 'url' => 'https://example.com', 'locale' => 'en'];\n");
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

    private function index(): TagIndex
    {
        return TagIndex::forStore($this->store(), $this->config());
    }
}
