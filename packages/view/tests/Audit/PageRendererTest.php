<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Audit;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\InternalRender;
use Preflow\Core\Http\Session\SessionMiddleware;
use Preflow\View\AssetCollector;
use Preflow\View\Audit\PageRenderer;
use Preflow\View\Geo\RenderScopes;
use Preflow\View\NonceGenerator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** PageRenderer on its own, with a stand-in for the app (PageRendererAppTest renders through a booted one). */
final class PageRendererTest extends TestCase
{
    public function test_renders_a_clean_marked_get_without_session_query_or_cookies(): void
    {
        $seen = null;
        $renderer = new PageRenderer(
            static function (ServerRequestInterface $request) use (&$seen): ResponseInterface {
                $seen = $request;

                return new Response(200, ['Content-Type' => 'text/html'], '<p>ok</p>');
            },
            'https://site.test/',
            locales: ['en', 'de'],
            defaultLocale: 'en',
        );

        $page = $renderer->render('/de/spiele?page=2#top');

        $this->assertSame('https://site.test/de/spiele', $page->url);
        $this->assertSame('de', $page->locale);
        $this->assertSame(200, $page->status);
        $this->assertSame('<p>ok</p>', $page->body);
        $this->assertSame('text/html', $page->header('Content-Type'));

        $this->assertInstanceOf(ServerRequestInterface::class, $seen);
        $this->assertTrue(InternalRender::is($seen));
        $this->assertTrue($seen->getAttribute(SessionMiddleware::WITHOUT_SESSION));
        $this->assertSame('GET', $seen->getMethod());
        $this->assertSame('https://site.test/de/spiele', (string) $seen->getUri());
        $this->assertSame([], $seen->getQueryParams());
        $this->assertSame([], $seen->getCookieParams());
        $this->assertSame('text/html', $seen->getHeaderLine('Accept'));
        $this->assertFalse($seen->hasHeader('Cookie'));
        $this->assertFalse($seen->hasHeader('Authorization'));
        $this->assertSame('/de/spiele', $seen->getServerParams()['REQUEST_URI'] ?? null);
        $this->assertSame('GET', $seen->getServerParams()['REQUEST_METHOD'] ?? null);
        $this->assertSame('', $seen->getServerParams()['QUERY_STRING'] ?? null);
        $this->assertSame('on', $seen->getServerParams()['HTTPS'] ?? null);
    }

    public function test_the_locale_is_the_paths_prefix_or_the_default(): void
    {
        $renderer = new PageRenderer(
            static fn (): ResponseInterface => new Response(200),
            'http://localhost',
            locales: ['en', 'de', 'fr'],
            defaultLocale: 'en',
        );

        $this->assertSame('en', $renderer->render('/')->locale);
        $this->assertSame('en', $renderer->render('/about')->locale);
        $this->assertSame('en', $renderer->render('/en/about')->locale);
        $this->assertSame('de', $renderer->render('/de')->locale);
        $this->assertSame('fr', $renderer->render('/fr/jeux')->locale);
        $this->assertSame('en', $renderer->render('/deutsch')->locale);
        $this->assertSame('http://localhost/about', $renderer->render('about')->url);
    }

    public function test_tells_its_origin_and_locales(): void
    {
        $handle = static fn (): ResponseInterface => new Response(200);
        $prefixed = new PageRenderer($handle, 'https://site.test/', locales: ['en', 'de'], defaultLocale: 'en');
        $single = new PageRenderer($handle, '', defaultLocale: 'de');

        $this->assertSame('https://site.test', $prefixed->origin());
        $this->assertSame(['en', 'de'], $prefixed->locales());
        $this->assertSame('http://localhost', $single->origin());
        $this->assertSame(['de'], $single->locales());
    }

    public function test_runs_inside_the_render_scopes(): void
    {
        $scopes = new RenderScopes();
        $log = [];
        $scopes->add(static function (ServerRequestInterface $request, \Closure $next) use (&$log): ResponseInterface {
            $log[] = 'in ' . $request->getUri()->getPath() . (InternalRender::is($request) ? ' marked' : '');
            $response = $next();
            $log[] = 'out';

            return $response;
        });
        $renderer = new PageRenderer(
            static function () use (&$log): ResponseInterface {
                $log[] = 'render';

                return new Response(204);
            },
            'https://site.test',
            $scopes,
        );

        $this->assertSame(204, $renderer->render('/x')->status);
        $this->assertSame(['in /x marked', 'render', 'out'], $log);
    }

    public function test_each_render_starts_from_the_assets_it_found_and_leaves_none_behind(): void
    {
        $assets = new AssetCollector(new NonceGenerator());
        $assets->addJs('window.booted = 1;');
        $renderer = new PageRenderer(
            static function () use ($assets): ResponseInterface {
                $assets->addCss('.page { color: red; }');

                return new Response(200, [], $assets->renderCss() . $assets->renderJsBody());
            },
            'https://site.test',
            assets: $assets,
        );

        $first = $renderer->render('/a')->body;
        $second = $renderer->render('/b')->body;

        $this->assertStringContainsString('.page', $first);
        $this->assertStringContainsString('window.booted', $first);
        $this->assertSame($first, $second);
        $this->assertFalse($assets->hasCss());
        $this->assertTrue($assets->hasJs());
    }

    public function test_a_thrown_error_becomes_a_500_page_with_its_message(): void
    {
        $renderer = new PageRenderer(
            static fn (): ResponseInterface => throw new \LogicException('Template missing'),
            'https://site.test',
        );

        $page = $renderer->render('/broken');

        $this->assertSame(500, $page->status);
        $this->assertSame('https://site.test/broken', $page->url);
        $this->assertSame('text/plain; charset=UTF-8', $page->header('Content-Type'));
        $this->assertNull($page->dom());
        $this->assertSame('LogicException: Template missing', $page->body);
    }

    public function test_an_error_in_a_scope_is_caught_too(): void
    {
        $scopes = new RenderScopes();
        $scopes->add(static fn (): ResponseInterface => throw new \RuntimeException('Scope failed'));
        $renderer = new PageRenderer(static fn (): ResponseInterface => new Response(200), 'https://site.test', $scopes);

        $page = $renderer->render('/');

        $this->assertSame(500, $page->status);
        $this->assertSame('RuntimeException: Scope failed', $page->body);
    }
}
