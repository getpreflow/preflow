<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Audit;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\RenderResult;
use Preflow\Core\Context\SkippedWrite;
use Preflow\Core\Http\Session\SessionMiddleware;
use Preflow\View\Audit\PageRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** PageRenderer on its own, with a stand-in for the app (PageRendererAppTest renders through a booted one). */
final class PageRendererTest extends TestCase
{
    public function test_renders_a_clean_get_without_session_query_or_cookies_in_the_pages_locale(): void
    {
        $seen = null;
        $seenLocale = null;
        $renderer = new PageRenderer(
            static function (ServerRequestInterface $request, string $locale) use (&$seen, &$seenLocale): ResponseInterface {
                $seen = $request;
                $seenLocale = $locale;

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
        $this->assertSame('de', $seenLocale, 'the app renders the page in its locale');
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
        $this->assertSame('en', $prefixed->defaultLocale());
        $this->assertSame('de', $single->defaultLocale());
    }

    public function test_adds_request_headers_given_but_never_credentials(): void
    {
        $seen = null;
        $renderer = new PageRenderer(
            static function (ServerRequestInterface $request) use (&$seen): ResponseInterface {
                $seen = $request;

                return new Response(304);
            },
            'https://site.test',
        );

        $page = $renderer->render('/', ['If-None-Match' => 'W/"2-abc"', 'Cookie' => 'locale=de', 'authorization' => 'Basic eA==']);

        $this->assertSame(304, $page->status);
        $this->assertSame('W/"2-abc"', $seen->getHeaderLine('If-None-Match'));
        $this->assertSame('text/html', $seen->getHeaderLine('Accept'));
        $this->assertFalse($seen->hasHeader('Cookie'));
        $this->assertFalse($seen->hasHeader('Authorization'));
        $this->assertSame([], $seen->getCookieParams());
    }

    public function test_the_writes_a_render_skipped_are_on_its_page(): void
    {
        $write = new SkippedWrite('default', 'counter', 'home', 'save');
        $renderer = new PageRenderer(
            static fn (): RenderResult => new RenderResult(new Response(200, ['Content-Type' => 'text/html'], '<p>1</p>'), [], [$write]),
            'https://site.test',
        );

        $page = $renderer->render('/counter');

        $this->assertSame('<p>1</p>', $page->body);
        $this->assertSame([$write], $page->skippedWrites);
        $this->assertSame([], (new PageRenderer(static fn (): ResponseInterface => new Response(200), 'https://site.test'))->render('/')->skippedWrites);
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
}
