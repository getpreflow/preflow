<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\Site;
use Preflow\Core\Context\SystemClock;
use Preflow\Core\Http\LocaleUrls;
use Preflow\I18n\LocaleMiddleware;
use Preflow\I18n\Translator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class LocaleSwitchTest extends TestCase
{
    private const array LOCALES = ['en', 'de', 'fr'];

    private bool $handled = false;

    protected function setUp(): void
    {
        $this->handled = false;
    }

    protected function tearDown(): void
    {
        Contexts::reset();
    }

    private function middleware(bool $redirectBare = true, bool $prefixDefault = true, string $cookieName = 'locale', bool $cookie = false, bool $prefixed = true): LocaleMiddleware
    {
        return new LocaleMiddleware(
            self::LOCALES,
            'en',
            $prefixed ? 'prefix' : 'none',
            $cookie,
            urls: new LocaleUrls(self::LOCALES, 'en', $prefixed, $prefixDefault && $prefixed, $redirectBare),
            cookieName: $cookieName,
            pageExists: static fn (string $locale, string $path): bool => true,
        );
    }

    /** @param array<string, string> $headers @param array<string, string> $cookies */
    private function call(LocaleMiddleware $mw, string $uri, string $method = 'GET', array $headers = [], array $cookies = []): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest($method, $uri);
        foreach ($headers as $n => $v) {
            $request = $request->withHeader($n, $v);
        }
        $test = $this;
        $handler = new class ($test) implements RequestHandlerInterface {
            public function __construct(private readonly LocaleSwitchTest $test) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->test->markHandled();

                return new Response(200);
            }
        };

        $request = $request->withCookieParams($cookies);

        // In a render frame of its own, as Application::handle() runs it (since 0.81 it narrows that frame).
        return Contexts::run(
            RenderContext::fromRequest($request, new Site('https://site.test', self::LOCALES, 'en'), new SystemClock()),
            static fn (): ResponseInterface => $mw->process($request, $handler),
        );
    }

    public function markHandled(): void
    {
        $this->handled = true;
    }

    public function test_switch_sets_cookie_and_redirects_to_target(): void
    {
        $response = $this->call($this->middleware(), '/--locale/de?to=/de/faq');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/de/faq', $response->getHeaderLine('Location'));
        self::assertStringStartsWith('locale=de;', $response->getHeaderLine('Set-Cookie'));
        self::assertFalse($this->handled);
    }

    public function test_switch_cookie_attributes(): void
    {
        $http = $this->call($this->middleware(), 'http://example.test/--locale/de?to=/de');
        self::assertSame('locale=de; Path=/; Max-Age=31536000; SameSite=Lax; HttpOnly', $http->getHeaderLine('Set-Cookie'));
        self::assertSame('private, no-store', $http->getHeaderLine('Cache-Control'));
        self::assertSame('noindex', $http->getHeaderLine('X-Robots-Tag'));

        $https = $this->call($this->middleware(), 'https://example.test/--locale/de?to=/de');
        self::assertSame('locale=de; Path=/; Max-Age=31536000; SameSite=Lax; HttpOnly; Secure', $https->getHeaderLine('Set-Cookie'));
    }

    /** @return iterable<string, array{string}> */
    public static function foreignTargets(): iterable
    {
        yield 'scheme-relative' => ['/--locale/de?to=' . rawurlencode('//evil.example/x')];
        yield 'absolute' => ['/--locale/de?to=' . rawurlencode('https://evil.example')];
        yield 'encoded slashes' => ['/--locale/de?to=' . rawurlencode('/%2F%2Fevil.example')];
        yield 'backslash' => ['/--locale/de?to=' . rawurlencode('/\\evil.example')];
        yield 'missing' => ['/--locale/de'];
        yield 'array' => ['/--locale/de?to[]=/x'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('foreignTargets')]
    public function test_switch_refuses_foreign_targets(string $uri): void
    {
        $response = $this->call($this->middleware(), $uri);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/de', $response->getHeaderLine('Location'));
        self::assertNotSame('', $response->getHeaderLine('Set-Cookie'));
    }

    public function test_switch_to_unknown_locale_is_not_handled(): void
    {
        $response = $this->call($this->middleware(), '/--locale/xx?to=/faq');

        self::assertTrue($this->handled);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Set-Cookie'));
    }

    public function test_switch_with_other_method_is_not_handled(): void
    {
        $this->call($this->middleware(), '/--locale/de', 'POST');

        self::assertTrue($this->handled);
    }

    public function test_switch_endpoint_absent_without_redirect_bare(): void
    {
        $response = $this->call($this->middleware(redirectBare: false), '/--locale/de?to=/de/faq');

        self::assertTrue($this->handled);
        self::assertSame('', $response->getHeaderLine('Set-Cookie'));
    }

    public function test_cookie_name_is_configurable(): void
    {
        $mw = $this->middleware(cookieName: 'lang');

        $set = $this->call($mw, '/--locale/de?to=/de/faq');
        self::assertStringStartsWith('lang=de;', $set->getHeaderLine('Set-Cookie'));

        $read = $this->call($mw, '/faq', cookies: ['lang' => 'fr']);
        self::assertSame('/fr/faq', $read->getHeaderLine('Location'));

        $ignored = $this->call($mw, '/faq', cookies: ['locale' => 'fr']);
        self::assertSame('/en/faq', $ignored->getHeaderLine('Location'));
    }

    public function test_pages_never_set_the_cookie(): void
    {
        $page = $this->call($this->middleware(), '/de/faq');
        self::assertSame('', $page->getHeaderLine('Set-Cookie'));

        $bare = $this->call($this->middleware(), '/faq', headers: ['Accept-Language' => 'de']);
        self::assertSame(302, $bare->getStatusCode());
        self::assertSame('', $bare->getHeaderLine('Set-Cookie'));
    }

    public function test_switched_cookie_wins_next_bare_visit(): void
    {
        $response = $this->call($this->middleware(prefixDefault: false), '/faq', headers: ['Accept-Language' => 'de'], cookies: ['locale' => 'en']);

        self::assertTrue($this->handled);
        self::assertSame(200, $response->getStatusCode());
    }

    public function test_switch_to_default_locale_without_prefix_lands_on_root(): void
    {
        $response = $this->call($this->middleware(prefixDefault: false), '/--locale/en');

        self::assertSame('/', $response->getHeaderLine('Location'));
    }

    public function test_secure_flag_behind_a_tls_proxy(): void
    {
        $response = $this->call($this->middleware(), '/--locale/de', headers: ['X-Forwarded-Proto' => 'https']);

        self::assertStringEndsWith('; Secure', $response->getHeaderLine('Set-Cookie'));

        $plain = $this->call($this->middleware(), '/--locale/de', headers: ['X-Forwarded-Proto' => 'http']);
        self::assertStringEndsNotWith('; Secure', $plain->getHeaderLine('Set-Cookie'));
    }

    public function test_head_switch_is_handled(): void
    {
        $response = $this->call($this->middleware(), '/--locale/de?to=/de/faq', 'HEAD');

        self::assertSame(302, $response->getStatusCode());
        self::assertStringStartsWith('locale=de;', $response->getHeaderLine('Set-Cookie'));
        self::assertFalse($this->handled);
    }

    public function test_switch_is_absent_on_a_site_without_url_prefixes(): void
    {
        $response = $this->call($this->middleware(prefixed: false), '/--locale/de?to=/faq');

        self::assertTrue($this->handled);
        self::assertSame('', $response->getHeaderLine('Set-Cookie'));
    }

    public function test_switch_keeps_an_encoded_non_ascii_target(): void
    {
        $response = $this->call($this->middleware(), '/--locale/de?to=' . rawurlencode('/de/%C3%BCber-uns'));

        self::assertSame('/de/%C3%BCber-uns', $response->getHeaderLine('Location'));
    }

    public function test_the_page_view_cookie_writer_never_writes_with_redirect_bare(): void
    {
        $mw = $this->middleware(cookie: true);

        self::assertSame('', $this->call($mw, '/de/faq')->getHeaderLine('Set-Cookie'));
        self::assertSame('', $this->call($mw, '/faq', headers: ['Accept-Language' => 'de'])->getHeaderLine('Set-Cookie'));
    }

    public function test_without_a_cookie_the_accept_language_decides_the_bare_visit(): void
    {
        $response = $this->call($this->middleware(prefixDefault: false), '/faq', headers: ['Accept-Language' => 'de']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/de/faq', $response->getHeaderLine('Location'));
    }

    public function test_switch_without_target_lands_on_the_slash_home_with_home_slash(): void
    {
        $mw = new LocaleMiddleware(
            self::LOCALES,
            'en',
            'prefix',
            false,
            urls: new LocaleUrls(self::LOCALES, 'en', true, true, true, true),
            pageExists: static fn (string $locale, string $path): bool => true,
        );

        $response = $this->call($mw, '/--locale/de');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/de/', $response->getHeaderLine('Location'));
    }
}
