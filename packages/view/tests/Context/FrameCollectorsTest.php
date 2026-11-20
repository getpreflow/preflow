<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Context;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Deprecations;
use Preflow\Core\Context\Purpose;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\Site;
use Preflow\Core\Context\SystemClock;
use Preflow\Core\Http\ResponseHeaderHints;
use Preflow\Htmx\HtmxAssets;
use Preflow\Htmx\HtmxDriver;
use Preflow\Htmx\ResponseHeaders;
use Preflow\View\AssetCollector;
use Preflow\View\Geo\GeoCapture;
use Preflow\View\Geo\GeoCollector;
use Preflow\View\Geo\RenderScopes;
use Preflow\View\JsPosition;
use Preflow\View\NonceGenerator;
use Preflow\View\Seo\PageMeta;
use Preflow\View\Seo\SeoContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What a render collects lives in its render frame (0.81): assets, page meta, GEO blocks, the CSP
 * nonce, htmx's hints and the response headers a page adds. A frame starts with none of another
 * render's, within() keeps the frame's, and nothing a nested render collects reaches the frame
 * around it.
 */
final class FrameCollectorsTest extends TestCase
{
    private ?string $dir = null;

    protected function setUp(): void
    {
        Contexts::reset();
    }

    protected function tearDown(): void
    {
        Contexts::reset();
        NonceGenerator::fixedForTests(null);
        if ($this->dir !== null) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    private static function ctx(Purpose $purpose = Purpose::Visit): RenderContext
    {
        $site = new Site('https://example.com', ['en', 'de'], 'en');

        return $purpose === Purpose::Visit
            ? RenderContext::fromRequest(new ServerRequest('GET', 'https://example.com/'), $site, new SystemClock())
            : RenderContext::internal($purpose, $site, new SystemClock(), '/');
    }

    public function test_each_frame_starts_with_empty_assets_and_meta(): void
    {
        $assets = new AssetCollector(new NonceGenerator());
        $assets->addCss('.boot { color: red; }', 'boot');
        $assets->markBooted();
        $seo = new SeoContext();
        $geo = new GeoCollector();

        Contexts::run(self::ctx(), function () use ($assets, $seo, $geo): void {
            $assets->addCss('.outer { color: blue; }', 'outer');
            $assets->addJs('window.outer = 1;', JsPosition::Body, 'outer');
            $seo->set(new PageMeta(title: 'Outer'));
            $geo->capture(true);
            $geo->add('outer block');

            $inner = Contexts::run(self::ctx(Purpose::Audit), function () use ($assets, $seo, $geo): array {
                $seen = [$assets->renderCss(), $assets->renderJsBody(), $seo->get(), $geo->capturing(), $geo->pageBlocks()];
                $assets->addCss('.inner { color: green; }', 'inner');
                $seo->set(new PageMeta(title: 'Inner'));

                return $seen;
            });

            [$css, $js, $meta, $capturing, $blocks] = $inner;
            $this->assertStringContainsString('.boot', $css, 'a render starts from what boot registered');
            $this->assertStringNotContainsString('.outer', $css, 'never from the frame around it');
            $this->assertSame('', $js);
            $this->assertNull($meta, 'a render starts with no page meta');
            $this->assertFalse($capturing, 'and no GEO capture');
            $this->assertSame([], $blocks);

            $this->assertStringContainsString('.outer', $assets->renderCss());
            $this->assertStringNotContainsString('.inner', $assets->renderCss(), 'what the inner render collected stays in its frame');
            $this->assertSame('Outer', $seo->get()?->title);
            $this->assertSame(['outer block'], $geo->pageBlocks());
        });

        // A frame's head prints what it collected, once; the next frame prints again from the start.
        foreach ([1, 2] as $render) {
            $head = Contexts::run(self::ctx(), static fn (): string => $assets->renderHead() . '|' . $assets->renderHead());
            $this->assertSame(1, substr_count($head, '.boot'), "render {$render}");
            $this->assertStringNotContainsString('.outer', $head, "render {$render}");
        }
        $this->assertStringNotContainsString('.outer', $assets->renderCss(), 'outside every render: the own registries');
        $this->assertNull($seo->get());
    }

    public function test_within_keeps_assets_in_the_same_frame(): void
    {
        $assets = new AssetCollector(new NonceGenerator());
        $seo = new SeoContext();

        Contexts::run(self::ctx(), function () use ($assets, $seo): void {
            $before = $assets->getNonce();
            Contexts::within(Contexts::current()->in('de'), function () use ($assets, $seo, $before): void {
                $assets->addCss('.preview { color: blue; }', 'preview');
                $seo->merge(['title' => 'In German']);
                $this->assertSame($before, $assets->getNonce(), 'the same frame, the same nonce');
            });

            $this->assertStringContainsString('.preview', $assets->renderCss(), 'what the stretch collected stays in the frame');
            $this->assertSame('In German', $seo->get()?->title);
        });
    }

    public function test_nested_internal_render_gets_its_own_nonce(): void
    {
        $nonce = new NonceGenerator();
        $assets = new AssetCollector($nonce);

        $outer = Contexts::run(self::ctx(), function () use ($nonce, $assets): string {
            $mine = $nonce->get();
            $inner = Contexts::run(self::ctx(Purpose::Rebuild), static fn (): string => $nonce->get());
            $this->assertNotSame($mine, $inner, 'an internal render has a nonce of its own');
            $this->assertSame($mine, $nonce->get(), 'the outer frame keeps its nonce');
            $this->assertSame($mine, $assets->getNonce());

            return $mine;
        });

        // One nonce per render, not per process: the next request gets another.
        $next = Contexts::run(self::ctx(), static fn (): string => $nonce->get());
        $this->assertNotSame($outer, $next);
        $this->assertNotSame($outer, $nonce->get(), 'outside every render: the generator\'s own');

        // A request a page asks for in-process shares the page's nonce.
        Contexts::run(self::ctx(), function () use ($nonce): void {
            $page = $nonce->get();
            $frame = Contexts::frame();
            $shared = Contexts::run(self::ctx(), static function () use ($nonce, $frame): string {
                $nonce->share($frame);

                return $nonce->get();
            });
            $this->assertSame($page, $shared);
        });

        // The test seam makes every new nonce the same.
        NonceGenerator::fixedForTests('Zml4ZWQtbm9uY2UtZm9yLXRlc3Rz');
        $this->assertSame(
            ['Zml4ZWQtbm9uY2UtZm9yLXRlc3Rz', 'Zml4ZWQtbm9uY2UtZm9yLXRlc3Rz'],
            [Contexts::run(self::ctx(), static fn (): string => $nonce->get()), Contexts::run(self::ctx(Purpose::Audit), static fn (): string => $nonce->get())],
        );
    }

    public function test_fragment_requests_adopt_the_page_nonce(): void
    {
        $nonce = new NonceGenerator();
        $page = 'cGFnZS1ub25jZS0xMjM0NQ==';

        Contexts::run(self::ctx(), function () use ($nonce, $page): void {
            $this->assertTrue($nonce->adoptInFrame($page));
            $this->assertSame($page, $nonce->get());
            $this->assertTrue($nonce->adopted());

            // A render the request starts (after-response work, a rebuild) answers with its own.
            $inner = Contexts::run(self::ctx(Purpose::Rebuild), static fn (): array => [$nonce->get(), $nonce->adopted()]);
            $this->assertNotSame($page, $inner[0]);
            $this->assertFalse($inner[1]);
        });

        // The frame's end is where the adopted nonce ends.
        $after = Contexts::run(self::ctx(), static fn (): array => [$nonce->get(), $nonce->adopted()]);
        $this->assertNotSame($page, $after[0]);
        $this->assertFalse($after[1]);
        $this->assertFalse($nonce->adoptInFrame($page), 'outside every render nothing is adopted');
        $this->assertNotSame($page, $nonce->get());

        // Untrusted input: anything but a base64 nonce is ignored.
        Contexts::run(self::ctx(), function () use ($nonce): void {
            $own = $nonce->get();
            foreach (['', 'short', 'abcdefghijklmnopqrstu', 'x" onload="alert(1)', str_repeat('a', 80), "abc\ndefghijklmnopqrstuvwxyz"] as $bad) {
                $this->assertFalse($nonce->adoptInFrame($bad), $bad);
            }
            $this->assertSame($own, $nonce->get());
            $this->assertFalse($nonce->adopted());
        });
    }

    public function test_push_url_and_htmx_required_do_not_cross_frames(): void
    {
        $assets = new AssetCollector(new NonceGenerator());
        $headers = new ResponseHeaders();
        $driver = new HtmxDriver($headers);
        $htmx = new HtmxAssets($assets, 'on-demand');
        $driver->useAssets($htmx);

        Contexts::run(self::ctx(), function () use ($driver, $headers, $htmx, $assets): void {
            $driver->pushUrl('/outer');
            $driver->actionAttrs('post', '/--component/action', 'target', \Preflow\Htmx\SwapStrategy::OuterHTML);
            $this->assertTrue($htmx->isRequired());

            [$hints, $required, $head] = Contexts::run(self::ctx(Purpose::Rebuild), function () use ($driver, $headers, $htmx, $assets): array {
                $seen = [$headers->all(), $htmx->isRequired(), $assets->renderHead()];
                $driver->pushUrl('/inner');
                $driver->triggerEvent('inner');

                return $seen;
            });
            $this->assertSame([], $hints, 'a render starts with no hints');
            $this->assertFalse($required, 'nor htmx asked for');
            $this->assertStringNotContainsString('htmx-config', $head);

            $this->assertSame(['HX-Push-Url' => '/outer'], $headers->all(), 'the inner render\'s hints stay in its frame');
            $response = $driver->applyEffects(new \Nyholm\Psr7\Response(200));
            $this->assertSame('/outer', $response->getHeaderLine('HX-Push-Url'));
            $this->assertFalse($response->hasHeader('HX-Trigger'));
            $this->assertSame($assets->getNonce(), $response->getHeaderLine('HX-Nonce'));
        });

        $this->assertSame([], $headers->all(), 'outside every render: none of a render\'s hints');
    }

    public function test_the_htmx_config_carries_the_nonce_of_the_render_that_prints_it(): void
    {
        $assets = new AssetCollector(new NonceGenerator());
        (new HtmxAssets($assets, 'always'))->require(); // at boot
        $assets->markBooted();

        foreach ([self::ctx(), self::ctx(Purpose::Rebuild)] as $ctx) {
            [$nonce, $head] = Contexts::run($ctx, static fn (): array => [$assets->getNonce(), $assets->renderHead()]);
            $this->assertStringContainsString('"inlineScriptNonce":"' . $nonce . '"', $head, $ctx->purpose->name);
            $this->assertStringNotContainsString(AssetCollector::NONCE, $head);
        }
    }

    public function test_geo_blocks_go_into_the_capture_the_render_was_handed(): void
    {
        $geo = new GeoCollector();
        $capture = new GeoCapture();

        Contexts::run(self::ctx(), function () use ($geo, $capture): void {
            $this->assertFalse($geo->capturing(), 'the request itself records nothing');
            Contexts::run(self::ctx(Purpose::Geo), function () use ($geo, $capture): void {
                // Something used the collector earlier in the frame (a scope, a hook): no failure.
                $this->assertFalse($geo->capturing());
                $geo->captureInto($capture, function () use ($geo): void {
                    $geo->add('page block');
                    $html = $geo->component(static function () use ($geo): string {
                        $geo->add('component block');

                        return '<p>card</p>';
                    });
                    $this->assertSame('<preflow-geo data-geo="0"></preflow-geo>', $html);
                });
                $this->assertFalse($geo->capturing(), 'the frame\'s own capture is back');
            });
            $this->assertSame([], $geo->pageBlocks(), 'nothing reaches the frame around it');
        });

        $this->assertSame(['page block'], $capture->pageBlocks());
        $this->assertSame('component block', $capture->block(0));
    }

    public function test_render_scopes_add_is_a_deprecated_adapter(): void
    {
        Deprecations::reset();
        $scopes = new RenderScopes();
        $notices = [];
        set_error_handler(static function (int $level, string $message) use (&$notices): bool {
            $notices[] = $message;

            return true;
        }, E_USER_DEPRECATED);
        try {
            $ran = [];
            $scopes->add(static function (ServerRequestInterface $r, \Closure $next) use (&$ran): ResponseInterface {
                $ran[] = 'scope';

                return $next();
            });
        } finally {
            restore_error_handler();
        }

        $this->assertSame(['Preflow\View\Geo\RenderScopes::add() is deprecated since 0.81 and is removed in 0.83; use a context facet or RenderContext::memoKey() (each internal render has its own frame and context).'], $notices);
        $response = Contexts::run(self::ctx(Purpose::Audit), static fn (): ResponseInterface => $scopes->run(new ServerRequest('GET', '/'), static fn (): ResponseInterface => new \Nyholm\Psr7\Response(204)));
        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame(['scope'], $ran, 'an added scope still runs around the render');
    }

    /** A site whose pages and components add response headers. @param array<string, mixed> $cache config/page_cache.php */
    private function hintSite(array $cache = []): void
    {
        $this->dir = sys_get_temp_dir() . '/frame_hints_' . bin2hex(random_bytes(4));
        ini_set('error_log', $this->dir . '/php.log');
        $this->write('config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'frame-hints-test-key-0123456789abcd','url'=>'https://example.com','locale'=>'en'];\n");
        $this->write('config/page_cache.php', '<?php return ' . var_export($cache + ['enabled' => true, 'explain' => true, 'verify' => 'off'], true) . ';');
        $this->write('config/data.php', "<?php\nreturn ['default'=>'json','drivers'=>['json'=>['path'=>'{$this->dir}/storage/data']]];\n");
        $this->write('config/geo.php', "<?php\nreturn ['enabled' => true];\n");
        $this->component('NoStore', <<<'PHP'
            public function __construct(private readonly \Preflow\Core\Http\ResponseHeaderHints $hints) {}
            public function resolveState(): void
            {
                $this->hints->set('Cache-Control', 'no-store');
            }
            PHP, '<p>Sent.</p>');
        // No store once the flag file exists (a page that turns per visitor between two renders).
        $this->component('NoStoreWhenFlagged', <<<'PHP'
            public function __construct(private readonly \Preflow\Core\Http\ResponseHeaderHints $hints) {}
            public function resolveState(): void
            {
                if (is_file(dirname(__DIR__, 3) . '/flag')) {
                    $this->hints->set('Cache-Control', 'no-store');
                }
            }
            PHP, '<p>Maybe.</p>');
        $this->component('LiveNoStore', <<<'PHP'
            public function __construct(private readonly \Preflow\Core\Http\ResponseHeaderHints $hints) {}
            public function resolveState(): void
            {
                $this->hints->set('Cache-Control', 'no-store');
            }
            PHP, '<p>Yours.</p>', live: true);
        $this->component('LivePublic', <<<'PHP'
            public function __construct(private readonly \Preflow\Core\Http\ResponseHeaderHints $hints) {}
            public function resolveState(): void
            {
                $this->hints->set('Cache-Control', 'public, max-age=3600');
            }
            PHP, '<p>Mine.</p>', live: true);
        $this->write('app/pages/live-public.twig', "<html><body><main><p>Shared.</p>{{ component('LivePublic') }}</main></body></html>");
        $this->write('app/pages/sent.twig', "<html><body><main>{{ component('NoStore') }}</main></body></html>");
        $this->write('app/pages/thanks.twig', "<html><body><main>{{ response_header('Cache-Control', 'no-store') }}<p>Thanks.</p></main></body></html>");
        $this->write('app/pages/plain.twig', '<html><body><main><p>Plain.</p></main></body></html>');
        $this->write('app/pages/flagged.twig', "<html><body><main>{{ component('NoStoreWhenFlagged') }}</main></body></html>");
        $this->write('app/pages/live.twig', "<html><body><main><p>Shared.</p>{{ component('LiveNoStore') }}</main></body></html>");
        $this->write('app/pages/public-error.twig', "<html><body>{{ response_header('Cache-Control', 'public, max-age=3600') }}{% include 'missing-template.twig' %}</body></html>");
        $this->write('app/pages/private-error.twig', "<html><body>{{ response_header('Cache-Control', 'private, max-age=60') }}{% include 'missing-template.twig' %}</body></html>");
    }

    private function write(string $path, string $content): void
    {
        @mkdir(dirname($this->dir . '/' . $path), 0777, true);
        file_put_contents($this->dir . '/' . $path, $content);
    }

    private function component(string $name, string $body, string $template, bool $live = false): void
    {
        $attribute = $live ? "#[\\Preflow\\Components\\Live]\n" : '';
        $this->write("app/Components/{$name}/{$name}.php", "<?php\nnamespace App\\Components\\{$name};\n{$attribute}final class {$name} extends \\Preflow\\Components\\Component\n{\n{$body}\n}\n");
        $this->write("app/Components/{$name}/{$name}.twig", $template);
        require_once $this->dir . "/app/Components/{$name}/{$name}.php";
    }

    private function hintApp(): Application
    {
        $app = Application::create($this->dir);
        $app->setRunningInConsole(false);
        $app->runAfterResponseInHandle();
        $app->boot();

        return $app;
    }

    /** @param array<string, string> $headers */
    private function get(string $path, array $headers = []): ResponseInterface
    {
        return $this->hintApp()->handle(new ServerRequest('GET', 'https://example.com' . $path, $headers));
    }

    private function stored(string $path): ?\Preflow\Core\PageCache\StoredPage
    {
        $config = $this->hintApp()->container()->get(\Preflow\Core\PageCache\PageCacheConfig::class);
        $store = new \Preflow\Core\PageCache\PageStore($this->dir . '/storage/cache/pages', \Preflow\Core\PageCache\PageStore::releaseKey($this->dir));

        return $store->get(\Preflow\Core\PageCache\PageKey::fromRequest(new ServerRequest('GET', 'https://example.com' . $path), $config, []));
    }

    #[RunInSeparateProcess]
    public function test_a_component_can_add_a_response_header(): void
    {
        $this->hintSite();

        foreach (['/sent' => 'a component', '/thanks' => 'a page'] as $path => $who) {
            $first = $this->get($path);
            $this->assertSame('no-store', $first->getHeaderLine('Cache-Control'), "{$who} added the header");
            $this->assertSame('cache-control', $first->getHeaderLine('X-Page-Cache-Skip'), "{$who}: the page is not stored, as any no-store response");
            $this->assertSame('miss', $this->get($path)->getHeaderLine('X-Page-Cache'), "{$who}: never served from the store");
        }

        // Without a header added, the default is unchanged: the page is stored and served.
        $plain = $this->get('/plain');
        $this->assertSame('', $plain->getHeaderLine('X-Page-Cache-Skip'));
        $this->assertSame('hit', $this->get('/plain')->getHeaderLine('X-Page-Cache'));
        $this->assertNotSame('no-store', $plain->getHeaderLine('Cache-Control'));
    }

    #[RunInSeparateProcess]
    public function test_a_no_store_page_is_never_stored_when_every_page_is_verified(): void
    {
        $this->hintSite(['verify' => 'always']);

        $first = $this->get('/sent');
        $this->assertSame('cache-control', $first->getHeaderLine('X-Page-Cache-Skip'), 'refused as no-store, not verified or stored');
        $this->assertNull($this->stored('/sent'));
        $this->assertSame('miss', $this->get('/sent')->getHeaderLine('X-Page-Cache'));
        $this->get('/plain');
        $this->assertSame('hit', $this->get('/plain')->getHeaderLine('X-Page-Cache'), 'a plain page is still verified and stored');
    }

    #[RunInSeparateProcess]
    public function test_a_rebuild_of_a_page_that_turned_no_store_stores_nothing(): void
    {
        $this->hintSite();
        $this->get('/flagged');
        $this->assertNotNull($this->stored('/flagged'), 'stored while it adds no header');

        touch($this->dir . '/flag');
        $refresher = $this->hintApp()->container()->get(\Preflow\Core\PageCache\Refresher::class);
        $refresher->refreshAll();
        $refresher->work(50.0);

        $this->assertNull($this->stored('/flagged'), 'the rebuild added no-store: nothing stored');
        $this->assertNotSame('hit', $this->get('/flagged')->getHeaderLine('X-Page-Cache'));
    }

    #[RunInSeparateProcess]
    public function test_a_live_part_header_goes_on_the_served_response_never_on_the_stored_page(): void
    {
        $this->hintSite();

        $miss = $this->get('/live');
        $this->assertSame('miss', $miss->getHeaderLine('X-Page-Cache'));
        $this->assertStringContainsString('Yours.', (string) $miss->getBody());
        $this->assertSame('no-store', $miss->getHeaderLine('Cache-Control'), 'the visitor\'s response carries what its live part added');

        $stored = $this->stored('/live');
        $this->assertNotNull($stored, 'the shared page is stored: the live part is per visitor');
        $control = '';
        foreach ($stored->headers as $name => $values) {
            if (strcasecmp((string) $name, 'Cache-Control') === 0) {
                $control = implode(', ', $values);
            }
        }
        $this->assertStringNotContainsString('no-store', $control, 'the stored page does not change');

        $hit = $this->get('/live');
        $this->assertSame('hit', $hit->getHeaderLine('X-Page-Cache'));
        $this->assertSame('no-store', $hit->getHeaderLine('Cache-Control'), 'a hit carries the restrictive part');

        // A live part never widens caching, on a miss as on a hit.
        foreach (['miss', 'hit'] as $expected) {
            $response = $this->get('/live-public');
            $this->assertSame($expected, $response->getHeaderLine('X-Page-Cache'));
            $this->assertStringContainsString('Mine.', (string) $response->getBody());
            $this->assertStringNotContainsString('public', $response->getHeaderLine('Cache-Control'), "{$expected}: not made public");
            $this->assertStringNotContainsString('max-age=3600', $response->getHeaderLine('Cache-Control'), $expected);
        }
    }

    #[RunInSeparateProcess]
    public function test_the_error_page_gets_only_the_hints_that_make_it_less_cacheable(): void
    {
        $this->hintSite();

        $public = $this->get('/public-error');
        $this->assertSame(500, $public->getStatusCode());
        $this->assertStringNotContainsString('public', $public->getHeaderLine('Cache-Control'), 'a failure is never made cacheable');
        $this->assertStringNotContainsString('max-age=3600', $public->getHeaderLine('Cache-Control'));

        $private = $this->get('/private-error');
        $this->assertSame(500, $private->getStatusCode());
        $this->assertSame('private', $private->getHeaderLine('Cache-Control'), 'only the restrictive directive');
    }

    public function test_headers_core_owns_are_refused(): void
    {
        $hints = ResponseHeaderHints::ofCurrentFrame(static fn (): array => ['Signal-Nonce']);
        foreach (['Set-Cookie', 'content-security-policy', 'Content-Security-Policy-Report-Only', 'Content-Length', 'Content-Type', 'Transfer-Encoding', 'Location', 'HX-Trigger', 'hx-push-url', 'X-Page-Cache', 'X-Page-Cache-Skip', 'signal-nonce'] as $name) {
            try {
                $hints->set($name, 'x');
                $this->fail("{$name} was accepted");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('set by the framework', $e->getMessage(), $name);
            }
        }
        foreach ([['Bad Name', 'x'], ['X-Ok', "a\r\nSet-Cookie: x=1"], ['X-Ok', "a\nb"], ["X\nY", 'x']] as [$name, $value]) {
            try {
                $hints->set($name, $value);
                $this->fail('a split header was accepted');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Not a response header', $e->getMessage());
            }
        }

        $hints->set('Cache-Control', 'no-store');
        $hints->set('X-Robots-Tag', 'noindex');
        $this->assertSame(['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex'], $hints->all());
    }

    #[RunInSeparateProcess]
    public function test_geo_serves_no_markdown_for_a_page_that_is_per_visitor(): void
    {
        $this->hintSite();

        $this->assertSame(404, $this->get('/thanks.md')->getStatusCode(), 'no Markdown view, as for a noindex page');
        $asked = $this->get('/thanks', ['Accept' => 'text/markdown']);
        $this->assertStringContainsString('text/html', $asked->getHeaderLine('Content-Type'), 'the HTML response the client would get anyway');
        $this->assertSame(200, $this->get('/plain.md')->getStatusCode(), 'a shared page keeps its Markdown');
    }
}
