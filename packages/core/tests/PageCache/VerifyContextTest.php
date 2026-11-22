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
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\Site;
use Preflow\Core\Context\SystemClock;
use Preflow\Core\PageCache\DependencyRecorder;
use Preflow\Core\PageCache\EnabledMarker;
use Preflow\Core\PageCache\PageCache;
use Preflow\Core\PageCache\PageCacheConfig;
use Preflow\Core\PageCache\PageCacheMiddleware;
use Preflow\Core\PageCache\PageCacheSource;
use Preflow\Core\PageCache\PageKey;
use Preflow\Core\PageCache\PageStore;
use Preflow\Core\PageCache\PerRequestValues;
use Preflow\Core\Tests\PageCache\Fixtures\PurposeRoutes;
use Preflow\Notify\Email;
use Preflow\Notify\Notifier;
use Preflow\Notify\SentMessage;
use Preflow\Notify\TransportFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Preflow\Testing\ChecksFrameBalance;

/**
 * Verify's second render (the page rendered once more as another anonymous visitor before it is
 * stored) runs in a frame of its own under the visitor's context, discarding what it would send
 * and skipping what it would write, for the purpose Verify. What the two renders set as page meta
 * (the state the cache carries, SEO's) is compared frame by frame, not put back.
 */
final class VerifyContextTest extends TestCase
{
    use ChecksFrameBalance;

    private string $dir;

    protected function setUp(): void
    {
        Contexts::reset();
        PurposeRoutes::$routes = [];
        PurposeRoutes::$seen = [];
        $this->dir = sys_get_temp_dir() . '/pcverifyctx_' . bin2hex(random_bytes(4));
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
    public function test_verify_render_sends_no_mail_and_writes_nothing(): void
    {
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name' => 't', 'debug' => 0, 'engine' => 'twig', 'key' => 'verify-context-test-key-0123456789ab', 'url' => 'https://example.com'];\n");
        file_put_contents($this->dir . '/config/page_cache.php', "<?php\nreturn ['enabled' => true, 'explain' => true, 'verify' => 'always'];\n");
        file_put_contents($this->dir . '/config/notify.php', "<?php\nreturn ['transports' => ['mail' => ['driver' => 'memory']], 'channels' => ['email' => ['transport' => 'mail', 'from' => 'site@example.com']]];\n");
        file_put_contents($this->dir . '/config/middleware.php', "<?php\nreturn [" . var_export(PurposeRoutes::class, true) . "];\n");
        $counter = $this->dir . '/views.txt';
        // A page that counts its views and mails on every GET.
        PurposeRoutes::$routes['/counter'] = static function (ServerRequestInterface $r, Container $c) use ($counter): ResponseInterface {
            PurposeRoutes::$seen[] = PurposeRoutes::context('/counter');
            $c->get(Notifier::class)->sendLater(new Email('page@example.com', 'Viewed', 'The page was viewed.'));

            return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], '<p>views: ' . PurposeRoutes::count($counter) . '</p>');
        };
        $app = Application::create($this->dir);
        $app->setRunningInConsole(false);
        $app->boot();

        $response = $app->handle(new ServerRequest('GET', 'https://example.com/counter', [], null, '1.1', ['REMOTE_ADDR' => '203.0.113.9']));

        $this->assertSame('miss', $response->getHeaderLine('X-Page-Cache'));
        $this->assertSame('', $response->getHeaderLine('X-Page-Cache-Skip'), 'both renders show one view: stored. ' . (string) @file_get_contents($this->dir . '/php.log'));
        $this->assertSame('<p>views: 1</p>', (string) $response->getBody());
        $this->assertSame('1', file_get_contents($counter), 'the verify render wrote nothing');
        $sent = array_map(static fn (SentMessage $s): string => $s->message->to[0], $app->container()->get(TransportFactory::class)->get('mail')->sent());
        $this->assertSame(['page@example.com'], $sent, 'the visitor\'s render mails; the verify render does not');

        $this->assertCount(2, PurposeRoutes::$seen);
        [$visit, $verify] = PurposeRoutes::$seen;
        $this->assertSame(['visit', 'Allow', 'Deliver', true, 1, '203.0.113.9'], [$visit['purpose'], $visit['writes'], $visit['outbox'], $visit['anonymous'], $visit['depth'], $visit['remote']]);
        $this->assertSame(['verify', 'Skip', 'Discard', true, 2], [$verify['purpose'], $verify['writes'], $verify['outbox'], $verify['anonymous'], $verify['depth']], 'its own frame, under the visitor\'s context');
        $this->assertNotSame('203.0.113.9', $verify['remote'], 'as another visitor');

        $hit = $app->handle(new ServerRequest('GET', 'https://example.com/counter'));
        $this->assertSame('hit', $hit->getHeaderLine('X-Page-Cache'));
        $this->assertSame('<p>views: 1</p>', (string) $hit->getBody());
    }

    /**
     * Verify's second render runs the rest of the pipeline only, so it never passes the SEO
     * middleware again: the site's defaults it set for the request stay visible there (the SEO
     * render scope resets only the page meta), and a page that prints them is stored.
     */
    #[RunInSeparateProcess]
    public function test_a_page_printing_the_site_seo_defaults_is_verified_and_stored(): void
    {
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name' => 't', 'debug' => 0, 'engine' => 'twig', 'key' => 'verify-context-test-key-0123456789ab', 'url' => 'https://example.com', 'locale' => 'en'];\n");
        file_put_contents($this->dir . '/config/page_cache.php', "<?php\nreturn ['enabled' => true, 'explain' => true, 'verify' => 'first'];\n");
        file_put_contents($this->dir . '/config/seo.php', "<?php\nreturn ['enabled' => true];\n");
        file_put_contents($this->dir . '/app/pages/about.twig', '<html><head><title>About</title></head><body><p>{{ seo_description() }}</p></body></html>');
        $app = Application::create($this->dir);
        $app->setRunningInConsole(false);
        $app->boot();
        $app->container()->get(\Preflow\View\Seo\SeoSite::class)->defaults(static fn (string $locale): \Preflow\View\Seo\PageMeta => new \Preflow\View\Seo\PageMeta(description: 'Site tagline'));

        $miss = $app->handle(new ServerRequest('GET', 'https://example.com/about'));

        $this->assertSame('miss', $miss->getHeaderLine('X-Page-Cache'));
        $this->assertSame('', $miss->getHeaderLine('X-Page-Cache-Skip'), 'verified and stored. ' . (string) @file_get_contents($this->dir . '/php.log'));
        $this->assertStringContainsString('<p>Site tagline</p>', (string) $miss->getBody());
        $hit = $app->handle(new ServerRequest('GET', 'https://example.com/about'));
        $this->assertSame('hit', $hit->getHeaderLine('X-Page-Cache'));
        $this->assertStringContainsString('<p>Site tagline</p>', (string) $hit->getBody());
    }

    public function test_verify_compares_the_two_frames_meta(): void
    {
        $cache = new PageCache(recorder: new DependencyRecorder());
        $restored = 0;
        // The page meta a render sets lives in its frame (as SEO's will): each render has its own.
        $cache->carry(
            'meta',
            static fn (): array => Contexts::frame()->slot(\ArrayObject::class, static fn (): \ArrayObject => new \ArrayObject())->getArrayCopy(),
            static function () use (&$restored): void {
                $restored++;
            },
        );
        $titles = ['Same', 'Same'];
        $handler = new class($titles) implements RequestHandlerInterface {
            public int $calls = 0;

            /** @param list<string> $titles */
            public function __construct(public array $titles) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $meta = Contexts::frame()->slot(\ArrayObject::class, static fn (): \ArrayObject => new \ArrayObject());
                $meta['titles'] = [...($meta['titles'] ?? []), $this->titles[$this->calls++] ?? '?'];

                return new Response(200, ['Content-Type' => 'text/html'], '<p>page</p>');
            }
        };
        [$mw, $store, $logged] = $this->middleware($cache);

        $same = $this->visit($mw, '/same', $handler);
        $this->assertSame('', $same->getHeaderLine('X-Page-Cache-Skip'), 'the same meta in both frames: stored. ' . implode("\n", $logged->getArrayCopy()));
        $this->assertSame(2, $handler->calls);
        $this->assertSame(['meta' => ['titles' => ['Same']]], $store->get($this->key('/same'))?->state, 'the first render\'s meta, from its own frame');
        $this->assertSame(0, $restored, 'nothing is put back after the second render');

        $handler->titles = ['Ours', 'Theirs'];
        $handler->calls = 0;
        $differs = $this->visit($mw, '/differs', $handler);
        $this->assertSame('differs', $differs->getHeaderLine('X-Page-Cache-Skip'));
        $this->assertNull($store->get($this->key('/differs')));
        $this->assertStringContainsString('differs per visitor outside live parts (page meta', implode("\n", $logged->getArrayCopy()));
        $this->assertSame(0, $restored);
    }

    /**
     * A middleware on its own (verify always), with its store and log.
     *
     * @return array{0: PageCacheMiddleware, 1: PageStore, 2: \ArrayObject<int, string>}
     */
    private function middleware(PageCache $cache): array
    {
        $root = $this->dir . '/storage/cache/pages';
        $store = new PageStore($root, 'aaaaaaaaaaaaaaaa');
        $source = new PageCacheSource($root, static fn (): string => 'aaaaaaaaaaaaaaaa');
        $logged = new \ArrayObject();

        return [new PageCacheMiddleware(
            config: $this->config(),
            store: static fn (): PageStore => $store,
            cache: $cache,
            recorder: new DependencyRecorder(),
            values: new PerRequestValues('verify-context-secret-0123456789abcdef0123456789'),
            marker: new EnabledMarker($root),
            clearAll: static function () use ($source): void {
                $source->clear();
            },
            log: static function (string $message) use ($logged): void {
                $logged[] = $message;
            },
            verify: 'always',
        ), $store, $logged];
    }

    private function config(): PageCacheConfig
    {
        return new PageCacheConfig(enabled: true, hosts: ['example.com'], explain: true, verify: 'always');
    }

    private function key(string $path): PageKey
    {
        return PageKey::fromRequest(new ServerRequest('GET', 'https://example.com' . $path), $this->config()) ?? throw new \RuntimeException('no key');
    }

    private function visitor(string $path): RenderContext
    {
        return RenderContext::fromRequest(new ServerRequest('GET', 'https://example.com' . $path), new Site('https://example.com', ['en'], 'en'), new SystemClock());
    }

    /** A visitor's GET of $path, in a frame of its own as the application gives it. */
    private function visit(PageCacheMiddleware $mw, string $path, RequestHandlerInterface $handler): ResponseInterface
    {
        $request = new ServerRequest('GET', 'https://example.com' . $path);

        return Contexts::run($this->visitor($path), static fn (): ResponseInterface => $mw->process($request, $handler));
    }
}
