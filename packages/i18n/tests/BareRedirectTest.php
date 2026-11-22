<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Http\LocaleUrls;
use Preflow\Core\Http\NonPublicPaths;
use Preflow\I18n\LocaleMiddleware;
use Preflow\I18n\Translator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class BareRedirectTest extends TestCase
{
    private const array LOCALES = ['en', 'de', 'fr'];

    private Translator $translator;
    private bool $handled = false;
    private ?ServerRequestInterface $captured = null;
    /** The translator's locale at the end of the last dispatch(). */
    private ?string $locale = null;

    protected function setUp(): void
    {
        // The middleware only sets the locale; no translation files are read here.
        $this->translator = new Translator(sys_get_temp_dir() . '/preflow_bare_redirect_none', 'en', 'en');
        $this->handled = false;
        $this->captured = null;
    }

    protected function tearDown(): void
    {
        \Preflow\Core\Context\Contexts::reset();
    }

    private function middleware(
        bool $prefixDefault = true,
        bool $redirectBare = true,
        ?\Closure $pageExists = null,
        ?NonPublicPaths $nonPublic = null,
        bool $cookie = false,
        bool $withPageLookup = true,
    ): LocaleMiddleware {
        if ($withPageLookup) {
            $pageExists ??= static fn (string $locale, string $path): bool => true;
        }

        return new LocaleMiddleware(
            self::LOCALES,
            'en',
            'prefix',
            $cookie,
            urls: new LocaleUrls(self::LOCALES, 'en', true, $prefixDefault, $redirectBare),
            pageExists: $pageExists,
            nonPublic: $nonPublic,
        );
    }

    /** @param array<string, string> $headers @param array<string, string> $cookies */
    private function request(string $uri, array $headers = [], array $cookies = [], string $method = 'GET'): ServerRequestInterface
    {
        $request = (new Psr17Factory())->createServerRequest($method, $uri);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request->withCookieParams($cookies);
    }

    private function handler(): RequestHandlerInterface
    {
        $test = $this;

        return new class ($test) implements RequestHandlerInterface {
            public function __construct(private readonly BareRedirectTest $test) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->test->record($request);

                return new Response(200);
            }
        };
    }

    public function record(ServerRequestInterface $request): void
    {
        $this->handled = true;
        $this->captured = $request;
    }

    /** $mw in the current render frame, else in a visitor's frame of its own (as Application::handle() opens one). */
    private function dispatch(LocaleMiddleware $mw, ServerRequestInterface $request): ResponseInterface
    {
        $process = function () use ($mw, $request): ResponseInterface {
            try {
                return $mw->process($request, $this->handler());
            } finally {
                $this->locale = $this->translator->getLocale();
            }
        };

        return \Preflow\Core\Context\Contexts::depth() > 0
            ? $process()
            : \Preflow\Core\Context\Contexts::run(\Preflow\Core\Context\RenderContext::fromRequest($request, new \Preflow\Core\Context\Site('http://localhost', self::LOCALES, 'en'), new \Preflow\Core\Context\SystemClock()), $process);
    }

    private function assertRedirect(string $location, ResponseInterface $response): void
    {
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame($location, $response->getHeaderLine('Location'));
        $this->assertFalse($this->handled, 'The handler must not run on a redirect.');
    }

    private function assertServed(ResponseInterface $response): void
    {
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($this->handled);
    }

    public function test_bare_page_redirects_to_cookie_locale(): void
    {
        $response = $this->dispatch($this->middleware(), $this->request('/faq', ['Accept-Language' => 'de'], ['locale' => 'fr']));

        $this->assertRedirect('/fr/faq', $response);
    }

    public function test_bare_page_redirects_to_accept_language_without_cookie(): void
    {
        $response = $this->dispatch($this->middleware(), $this->request('/faq', ['Accept-Language' => 'de-CH,de;q=0.9']));

        $this->assertRedirect('/de/faq', $response);
    }

    public function test_crawler_without_accept_language_goes_to_default(): void
    {
        $response = $this->dispatch($this->middleware(), $this->request('/faq'));

        $this->assertRedirect('/en/faq', $response);
    }

    public function test_invalid_cookie_is_ignored(): void
    {
        foreach (['xx', '../de', 'es'] as $cookie) {
            $this->handled = false;
            $response = $this->dispatch($this->middleware(), $this->request('/faq', ['Accept-Language' => 'de'], ['locale' => $cookie]));

            $this->assertRedirect('/de/faq', $response);
        }
    }

    public function test_redirect_keeps_query_string(): void
    {
        $response = $this->dispatch($this->middleware(), $this->request('/faq?a=1&b=2', ['Accept-Language' => 'de']));

        $this->assertRedirect('/de/faq?a=1&b=2', $response);
    }

    public function test_redirect_headers(): void
    {
        // Even with the cookie switched on, the redirect writes none (only an explicit pick does).
        $response = $this->dispatch($this->middleware(cookie: true), $this->request('/faq', ['Accept-Language' => 'de']));

        $this->assertRedirect('/de/faq', $response);
        $this->assertSame('Accept-Language, Cookie', $response->getHeaderLine('Vary'));
        $this->assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertFalse($response->hasHeader('Set-Cookie'));
    }

    public function test_root_redirects_to_bare_prefix(): void
    {
        $response = $this->dispatch($this->middleware(), $this->request('/', ['Accept-Language' => 'de']));

        $this->assertRedirect('/de', $response);
    }

    public function test_prefixed_paths_are_never_redirected(): void
    {
        foreach (['/en', '/de/', '/en/faq', '/de?x=1'] as $uri) {
            $this->handled = false;
            $response = $this->dispatch($this->middleware(), $this->request($uri, ['Accept-Language' => 'fr']));

            $this->assertServed($response);
        }
    }

    public function test_post_is_never_redirected(): void
    {
        $response = $this->dispatch($this->middleware(), $this->request('/faq', ['Accept-Language' => 'de'], [], 'POST'));

        $this->assertServed($response);
    }

    public function test_head_is_redirected(): void
    {
        $response = $this->dispatch($this->middleware(), $this->request('/faq', ['Accept-Language' => 'de'], [], 'HEAD'));

        $this->assertRedirect('/de/faq', $response);
    }

    public function test_non_public_paths_are_never_redirected(): void
    {
        $nonPublic = new NonPublicPaths();
        $nonPublic->add('/studio');

        foreach (['/--component/x', '/studio/x'] as $uri) {
            $this->handled = false;
            $response = $this->dispatch($this->middleware(nonPublic: $nonPublic), $this->request($uri, ['Accept-Language' => 'de']));

            $this->assertServed($response);
        }

        // Without the registry, a first segment starting with "--" is still never a page.
        $this->handled = false;
        $this->assertServed($this->dispatch($this->middleware(), $this->request('/--component/x', ['Accept-Language' => 'de'])));
    }

    public function test_unknown_page_is_not_redirected(): void
    {
        $asked = [];
        $pageExists = static function (string $locale, string $path) use (&$asked): bool {
            $asked[] = [$locale, $path];

            return false;
        };

        $response = $this->dispatch($this->middleware(pageExists: $pageExists), $this->request('/nope', ['Accept-Language' => 'de']));

        $this->assertServed($response);
        $this->assertSame([['de', '/nope']], $asked);
        $this->assertSame('en', $this->captured?->getAttribute('locale'));
        $this->assertSame('en', $this->locale);
    }

    public function test_internal_render_is_not_redirected(): void
    {
        $request = $this->request('/faq', ['Accept-Language' => 'de']);

        $this->assertServed(self::internally(fn () => $this->dispatch($this->middleware(), $request)));
    }

    public function test_without_prefix_default_default_preference_is_served_with_vary(): void
    {
        $response = $this->dispatch($this->middleware(prefixDefault: false), $this->request('/faq', ['Accept-Language' => 'en']));

        $this->assertServed($response);
        $this->assertSame('en', $this->captured?->getAttribute('locale'));
        $this->assertSame('Accept-Language, Cookie', $response->getHeaderLine('Vary'));
    }

    public function test_without_prefix_default_other_preference_redirects(): void
    {
        $response = $this->dispatch($this->middleware(prefixDefault: false), $this->request('/faq', ['Accept-Language' => 'de']));

        $this->assertRedirect('/de/faq', $response);
    }

    public function test_redirect_bare_off_changes_nothing(): void
    {
        $response = $this->dispatch($this->middleware(redirectBare: false), $this->request('/faq', ['Accept-Language' => 'de']));

        $this->assertServed($response);
        $this->assertFalse($response->hasHeader('Vary'));
        $this->assertSame('en', $this->captured?->getAttribute('locale'));
    }

    public function test_redirect_restores_url_prefix(): void
    {
        $mw = $this->middleware();
        $this->dispatch($mw, $this->request('/faq', ['Accept-Language' => 'de']));

        $this->assertSame('', (Contexts::peek()?->request?->localePrefix ?? ''));
    }

    public function test_cookie_name_is_configurable(): void
    {
        $mw = new LocaleMiddleware(
            self::LOCALES,
            'en',
            'prefix',
            urls: new LocaleUrls(self::LOCALES, 'en', true, true, true),
            cookieName: 'lang',
            pageExists: static fn (string $locale, string $path): bool => true,
        );

        $this->assertRedirect('/fr/faq', $this->dispatch($mw, $this->request('/faq', ['Accept-Language' => 'de'], ['lang' => 'fr'])));
        $this->handled = false;
        $this->assertRedirect('/de/faq', $this->dispatch($mw, $this->request('/faq', ['Accept-Language' => 'de'], ['locale' => 'fr'])));
    }

    public function test_without_page_lookup_no_bare_path_is_redirected(): void
    {
        $response = $this->dispatch($this->middleware(withPageLookup: false), $this->request('/faq', ['Accept-Language' => 'de']));

        $this->assertServed($response);
        $this->assertSame('en', $this->captured?->getAttribute('locale'));
        $this->assertFalse($response->hasHeader('Location'));
        $this->assertFalse($response->hasHeader('Vary'));
    }

    public function test_without_page_lookup_default_preference_gets_no_vary(): void
    {
        $response = $this->dispatch(
            $this->middleware(prefixDefault: false, withPageLookup: false),
            $this->request('/faq', ['Accept-Language' => 'en']),
        );

        $this->assertServed($response);
        $this->assertFalse($response->hasHeader('Vary'));
    }

    public function test_page_missing_in_preferred_locale_is_served_with_vary(): void
    {
        // /faq exists only in en and fr.
        $pageExists = static fn (string $locale, string $path): bool => $path === '/faq' && in_array($locale, ['en', 'fr'], true);

        $response = $this->dispatch($this->middleware(prefixDefault: false, pageExists: $pageExists), $this->request('/faq', ['Accept-Language' => 'de']));
        $this->assertServed($response);
        $this->assertSame('en', $this->captured?->getAttribute('locale'));
        $this->assertSame('Accept-Language, Cookie', $response->getHeaderLine('Vary'));

        $this->handled = false;
        $response = $this->dispatch($this->middleware(prefixDefault: false, pageExists: $pageExists), $this->request('/faq', ['Accept-Language' => 'fr']));
        $this->assertRedirect('/fr/faq', $response);
        $this->assertSame('Accept-Language, Cookie', $response->getHeaderLine('Vary'));
    }

    public function test_malformed_accept_language_falls_back(): void
    {
        $header = ['Accept-Language' => ';;q=abc,*'];

        $this->assertRedirect('/en/faq', $this->dispatch($this->middleware(), $this->request('/faq', $header)));
        $this->handled = false;
        $this->assertRedirect('/fr/faq', $this->dispatch($this->middleware(), $this->request('/faq', $header, ['locale' => 'fr'])));
    }

    public function test_double_slash_path_never_redirects_off_site(): void
    {
        // Nyholm collapses leading slashes itself; other PSR-7 implementations may hand the
        // path over as sent, so this URI does.
        $raw = new class ((new Psr17Factory())->createUri('/')) implements UriInterface {
            public function __construct(private readonly UriInterface $uri) {}
            public function getScheme(): string { return $this->uri->getScheme(); }
            public function getAuthority(): string { return $this->uri->getAuthority(); }
            public function getUserInfo(): string { return $this->uri->getUserInfo(); }
            public function getHost(): string { return $this->uri->getHost(); }
            public function getPort(): ?int { return $this->uri->getPort(); }
            public function getPath(): string { return '//evil.com/x'; }
            public function getQuery(): string { return ''; }
            public function getFragment(): string { return ''; }
            public function withScheme(string $scheme): UriInterface { return $this; }
            public function withUserInfo(string $user, ?string $password = null): UriInterface { return $this; }
            public function withHost(string $host): UriInterface { return $this; }
            public function withPort(?int $port): UriInterface { return $this; }
            public function withPath(string $path): UriInterface { return $this; }
            public function withQuery(string $query): UriInterface { return $this; }
            public function withFragment(string $fragment): UriInterface { return $this; }
            public function __toString(): string { return '//evil.com/x'; }
        };
        $request = $this->request('/', ['Accept-Language' => 'de'])->withUri($raw, true);
        $this->assertSame('//evil.com/x', $request->getUri()->getPath());

        $this->assertRedirect('/de/evil.com/x', $this->dispatch($this->middleware(), $request));
    }

    public function test_well_known_files_are_never_language_redirected(): void
    {
        foreach ([true, false] as $pageAnswers) {
            $mw = $this->middleware(pageExists: static fn (string $locale, string $path): bool => $pageAnswers);
            foreach (['/robots.txt', '/sitemap.xml', '/llms.txt'] as $path) {
                $this->handled = false;
                $response = $this->dispatch($mw, $this->request($path, ['Accept-Language' => 'de']));

                $this->assertServed($response);
                $this->assertFalse($response->hasHeader('Vary'), 'No Vary on the well-known files.');
            }
        }

        $this->handled = false;
        $this->assertRedirect('/de/faq', $this->dispatch($this->middleware(), $this->request('/faq', ['Accept-Language' => 'de'])));
    }

    public function test_bare_root_redirect_with_home_slash_goes_to_the_slash_form(): void
    {
        $mw = new LocaleMiddleware(
            self::LOCALES,
            'en',
            'prefix',
            false,
            urls: new LocaleUrls(self::LOCALES, 'en', true, true, true, true),
            pageExists: static fn (string $locale, string $path): bool => true,
        );

        $this->assertRedirect('/de/', $this->dispatch($mw, $this->request('/', ['Accept-Language' => 'de'])));
    }

    /** @return array{int, array<string, list<string>>, string} */
    private static function full(ResponseInterface $r): array
    {
        return [$r->getStatusCode(), $r->getHeaders(), (string) $r->getBody()];
    }

    public function test_home_slash_off_answers_exactly_as_0_78_1(): void
    {
        $mw = $this->middleware(prefixDefault: false);
        $redirect = static fn (string $to): array => [302, [
            'Location' => [$to],
            'Vary' => ['Accept-Language, Cookie'],
            'Cache-Control' => ['private, no-store'],
        ], ''];

        $this->assertSame($redirect('/de'), self::full($this->dispatch($mw, $this->request('/', ['Accept-Language' => 'de']))));
        $this->assertSame($redirect('/de/faq'), self::full($this->dispatch($mw, $this->request('/faq', ['Accept-Language' => 'de']))));

        $served = $this->dispatch($mw, $this->request('/faq', ['Accept-Language' => 'en']));
        $this->assertSame([200, ['Vary' => ['Accept-Language, Cookie']], ''], self::full($served));

        $switch = $this->dispatch($mw, $this->request('/--locale/de'));
        $this->assertSame([302, [
            'Location' => ['/de'],
            'Set-Cookie' => ['locale=de; Path=/; Max-Age=31536000; SameSite=Lax; HttpOnly'],
            'Cache-Control' => ['private, no-store'],
            'X-Robots-Tag' => ['noindex'],
        ], ''], self::full($switch));
    }

    /** $fn run as an internal render runs (the SEO audit: it leaves no trace); the purpose says it, not the request. */
    private static function internally(\Closure $fn): mixed
    {
        return \Preflow\Core\Context\Contexts::run(
            \Preflow\Core\Context\RenderContext::internal(\Preflow\Core\Context\Purpose::Audit, new \Preflow\Core\Context\Site('http://localhost', ['en'], 'en'), new \Preflow\Core\Context\SystemClock(), '/'),
            $fn,
        );
    }
}
