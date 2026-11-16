<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

require_once __DIR__ . '/Fixtures/LocaleUrls/PageLookupFixtures.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Http\LocaleUrls;
use Preflow\Core\Http\NonPublicPaths;
use Preflow\Core\Routing\PageExists;
use Preflow\Core\Tests\Fixtures\LocaleUrls\AnsweringRouter;
use Preflow\Core\Tests\Fixtures\LocaleUrls\PageLookupProvider;
use Preflow\Core\Tests\Fixtures\LocaleUrls\PlainRouter;
use Psr\Http\Message\ResponseInterface;

/** The application wires LocaleUrls, the bare-path redirect and the language switch (config/i18n.php). */
final class LocaleUrlsBootTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/locale_urls_boot_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        mkdir($this->dir . '/app/pages', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'k','locale'=>'en'];\n");
        $this->i18n(['prefix_default' => true, 'redirect_bare' => true]);
        $page = '<html><head></head><body>locale={{ current_locale() }}|{{ locale_url(\'/about\', \'en\') }}|{{ locale_switch_url(\'/about\', \'de\') }}</body></html>';
        file_put_contents($this->dir . '/app/pages/index.twig', $page);
        file_put_contents($this->dir . '/app/pages/about.twig', $page);
    }

    protected function tearDown(): void
    {
        PageLookupProvider::reset();
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    /** @param array<string, mixed> $options */
    private function i18n(array $options): void
    {
        file_put_contents($this->dir . '/config/i18n.php', '<?php return ' . var_export(
            $options + ['default' => 'en', 'available' => ['en', 'de'], 'url_strategy' => 'prefix'],
            true,
        ) . ';');
    }

    private function app(?\Preflow\Core\Routing\RouterInterface $router = null): Application
    {
        $app = Application::create($this->dir);
        if ($router !== null) {
            $app->setRouter($router);
        }
        $app->boot();

        return $app;
    }

    /** @param array<string, string> $headers */
    private function get(string $uri, array $headers = [], ?\Preflow\Core\Routing\RouterInterface $router = null): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest('GET', $uri);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->app($router)->handle($request);
    }

    private function provide(string $binding, string ...$paths): void
    {
        $this->provideEach([$binding => array_values($paths)]);
    }

    /** @param array<class-string, list<string>> $lookups binding => the paths its lookup knows */
    private function provideEach(array $lookups): void
    {
        PageLookupProvider::$lookups = $lookups;
        file_put_contents($this->dir . '/config/providers.php', "<?php\nreturn [" . var_export(PageLookupProvider::class, true) . "];\n");
    }

    public function test_bare_file_page_redirects_with_redirect_bare(): void
    {
        $response = $this->get('/about', ['Accept-Language' => 'de']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/de/about', $response->getHeaderLine('Location'));
        $this->assertFalse($response->hasHeader('Set-Cookie'), 'the bare redirect never writes the cookie');

        // The root is a file page too; a visitor without a preference goes to the default's prefix.
        $root = $this->get('/');
        $this->assertSame(302, $root->getStatusCode());
        $this->assertSame('/en', $root->getHeaderLine('Location'));
    }

    public function test_prefixed_pages_answer_and_print_prefixed_urls(): void
    {
        $response = $this->get('/en/about', ['Accept-Language' => 'de']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('locale=en|/en/about|/--locale/de?to=%2Fde%2Fabout', (string) $response->getBody());
    }

    public function test_bare_unknown_path_is_404_not_302(): void
    {
        $response = $this->get('/nope', ['Accept-Language' => 'de']);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Location'));
    }

    public function test_container_binds_locale_urls(): void
    {
        $urls = $this->app()->container()->get(LocaleUrls::class);

        $this->assertInstanceOf(LocaleUrls::class, $urls);
        $this->assertSame(['en', 'de'], $urls->locales);
        $this->assertSame('en', $urls->default);
        $this->assertTrue($urls->prefixDefault);
        $this->assertTrue($urls->redirectBare);
        $this->assertSame('/en', $urls->prefix('en'));
    }

    public function test_locale_switch_endpoint_is_non_public(): void
    {
        $this->assertTrue($this->app()->container()->get(NonPublicPaths::class)->matches('/--locale/de'));

        $this->i18n([]);
        $this->assertFalse($this->app()->container()->get(NonPublicPaths::class)->matches('/--locale/de'), 'only with redirect_bare');
    }

    public function test_locale_switch_writes_the_configured_cookie(): void
    {
        $this->i18n(['prefix_default' => true, 'redirect_bare' => true, 'cookie_name' => 'lang']);

        $response = $this->get('/--locale/de?to=%2Fde%2Fabout');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/de/about', $response->getHeaderLine('Location'));
        $this->assertSame('lang=de; Path=/; Max-Age=31536000; SameSite=Lax; HttpOnly', $response->getHeaderLine('Set-Cookie'));

        // The remembered pick wins over the browser's language on the next bare visit.
        $request = (new Psr17Factory())->createServerRequest('GET', '/about')
            ->withHeader('Accept-Language', 'en')
            ->withCookieParams(['lang' => 'de']);
        $this->assertSame('/de/about', $this->app()->handle($request)->getHeaderLine('Location'));
    }

    public function test_bare_served_page_varies_on_language_and_cookie(): void
    {
        $this->i18n(['redirect_bare' => true]);

        $response = $this->get('/about', ['Accept-Language' => 'en']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('locale=en', (string) $response->getBody());
        $vary = strtolower($response->getHeaderLine('Vary'));
        $this->assertStringContainsString('accept-language', $vary);
        $this->assertStringContainsString('cookie', $vary);

        // Another preference is sent to its prefix; the default stays unprefixed.
        $this->assertSame('/de/about', $this->get('/about', ['Accept-Language' => 'de'])->getHeaderLine('Location'));
    }

    /** @return iterable<string, array{mixed}> */
    public static function badCookieNames(): iterable
    {
        yield 'empty' => [''];
        yield 'not a string' => [12];
        yield 'separator' => ['a;b'];
        yield 'line break' => ["a\r\nb"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badCookieNames')]
    public function test_cookie_name_must_be_a_non_empty_string(mixed $name): void
    {
        $this->i18n(['redirect_bare' => true, 'cookie_name' => $name]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('config/i18n.php: cookie_name must be a non-empty cookie name (letters, digits and !#$%&\'*+-.^_`|~).');
        $this->app();
    }

    public function test_invalid_flags_stop_the_boot(): void
    {
        $this->i18n(['url_strategy' => 'none', 'redirect_bare' => true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('config/i18n.php: redirect_bare needs url_strategy "prefix".');
        $this->app();
    }

    public function test_redirect_bare_stops_the_boot_when_nothing_can_tell_a_page(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('config/i18n.php: redirect_bare needs to know which paths are pages: use preflow/routing\'s Router, or bind Preflow\Core\Routing\PageExists (a router that implements it counts too).');
        $this->app(new PlainRouter());
    }

    public function test_any_router_boots_without_redirect_bare(): void
    {
        $this->i18n(['prefix_default' => true]);

        $this->assertInstanceOf(Application::class, $this->app(new PlainRouter()));
    }

    public function test_a_router_answering_page_exists_drives_the_redirect(): void
    {
        $router = new AnsweringRouter(['/about']);

        $this->assertSame('/de/about', $this->get('/about', ['Accept-Language' => 'de'], $router)->getHeaderLine('Location'));
        $this->assertSame(404, $this->get('/nope', ['Accept-Language' => 'de'], $router)->getStatusCode());
    }

    public function test_a_bound_page_exists_answers_for_a_router_that_cannot(): void
    {
        $this->provide(PageExists::class, '/about');

        $this->assertSame('/de/about', $this->get('/about', ['Accept-Language' => 'de'], new PlainRouter())->getHeaderLine('Location'));
        $this->assertSame(404, $this->get('/nope', ['Accept-Language' => 'de'], new PlainRouter())->getStatusCode());
    }

    public function test_a_bound_redirect_page_exists_adds_pages_the_router_cannot_see(): void
    {
        // A catch-all site's pages (as Folio binds them): no file page at /blog, the lookup knows it.
        $this->provide(\Preflow\Routing\Redirect\PageExists::class, '/blog');

        $this->assertSame('/de/blog', $this->get('/blog', ['Accept-Language' => 'de'])->getHeaderLine('Location'));
        $this->assertSame('/de/about', $this->get('/about', ['Accept-Language' => 'de'])->getHeaderLine('Location'), 'the router still answers');
        $this->assertSame(404, $this->get('/nope', ['Accept-Language' => 'de'])->getStatusCode());
    }

    public function test_a_site_binding_only_routings_page_exists_still_answers(): void
    {
        // preflow/routing adds its own name as a page lookup source: core asks it when a request does.
        $this->provide(\Preflow\Routing\Redirect\PageExists::class, '/blog');

        $this->assertSame('/de/blog', $this->get('/blog', ['Accept-Language' => 'de'], new PlainRouter())->getHeaderLine('Location'));
        $this->assertSame(404, $this->get('/nope', ['Accept-Language' => 'de'], new PlainRouter())->getStatusCode());
    }

    public function test_lookups_bound_under_both_names_both_answer(): void
    {
        $this->provideEach([PageExists::class => ['/about'], \Preflow\Routing\Redirect\PageExists::class => ['/blog']]);

        $this->assertSame('/de/about', $this->get('/about', ['Accept-Language' => 'de'], new PlainRouter())->getHeaderLine('Location'));
        $this->assertSame('/de/blog', $this->get('/blog', ['Accept-Language' => 'de'], new PlainRouter())->getHeaderLine('Location'));
        $this->assertSame(404, $this->get('/nope', ['Accept-Language' => 'de'], new PlainRouter())->getStatusCode());
    }

    public function test_routings_name_bound_in_a_later_hook_is_asked_beside_cores(): void
    {
        $this->provideEach([PageExists::class => ['/about']]);
        PageLookupProvider::$late = [\Preflow\Routing\Redirect\PageExists::class => ['/blog']];

        $this->assertSame('/de/about', $this->get('/about', ['Accept-Language' => 'de'], new PlainRouter())->getHeaderLine('Location'));
        $this->assertSame('/de/blog', $this->get('/blog', ['Accept-Language' => 'de'], new PlainRouter())->getHeaderLine('Location'));
        $this->assertSame(404, $this->get('/nope', ['Accept-Language' => 'de'], new PlainRouter())->getStatusCode());
    }

    public function test_routings_name_alone_bound_late_boots_and_answers(): void
    {
        $this->provideEach([]);
        PageLookupProvider::$late = [\Preflow\Routing\Redirect\PageExists::class => ['/blog']];

        $this->assertSame('/de/blog', $this->get('/blog', ['Accept-Language' => 'de'], new PlainRouter())->getHeaderLine('Location'));
        $this->assertSame(404, $this->get('/nope', ['Accept-Language' => 'de'], new PlainRouter())->getStatusCode());
    }

    public function test_the_boot_builds_no_page_lookup(): void
    {
        $this->provideEach([PageExists::class => ['/about'], \Preflow\Routing\Redirect\PageExists::class => ['/blog']]);

        $app = $this->app(new PlainRouter());
        $this->assertSame(0, PageLookupProvider::$built, 'built only when a bare path asks');

        $app->handle((new Psr17Factory())->createServerRequest('GET', '/de/about'));
        $this->assertSame(0, PageLookupProvider::$built, 'a prefixed path asks no lookup');
    }

    public function test_a_page_lookup_source_is_core_api(): void
    {
        $app = $this->app();
        $sources = $app->container()->get(\Preflow\Core\Routing\PageLookups::class);
        $this->assertContains(\Preflow\Routing\Redirect\PageExists::class, $sources->ids(), 'routing adds its own name');
        $this->assertSame(PageExists::class, $sources->ids()[0], "core's name is asked first");
    }
}
