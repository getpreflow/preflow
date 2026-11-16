<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Http\LocaleUrls;
use Preflow\Core\Http\NonPublicPaths;
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

    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    /** @param array<string, string> $headers */
    private function get(string $uri, array $headers = []): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest('GET', $uri);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->app()->handle($request);
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

    public function test_cookie_name_must_be_a_non_empty_string(): void
    {
        foreach (['', 12, 'a;b', "a\r\nb"] as $name) {
            $this->i18n(['redirect_bare' => true, 'cookie_name' => $name]);
            try {
                $this->app();
                $this->fail('accepted cookie_name ' . var_export($name, true));
            } catch (\RuntimeException $e) {
                $this->assertSame('config/i18n.php: cookie_name must be a non-empty cookie name (letters, digits and !#$%&\'*+-.^_`|~).', $e->getMessage());
            }
        }
    }

    public function test_invalid_flags_stop_the_boot(): void
    {
        $this->i18n(['url_strategy' => 'none', 'redirect_bare' => true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('config/i18n.php: redirect_bare needs url_strategy "prefix".');
        $this->app();
    }
}
