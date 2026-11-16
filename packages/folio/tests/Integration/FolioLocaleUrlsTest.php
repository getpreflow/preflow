<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Redirects\RedirectEntries;
use Preflow\Publishing\Publisher;
use Psr\Http\Message\ResponseInterface;

/**
 * Folio on a site whose default language carries its prefix too (config/i18n.php prefix_default,
 * redirect_bare): page URLs, canonical and hreflang, the sitemap and llms.txt, the admin's address
 * field and the bare-path redirect all follow core's LocaleUrls. Without the new keys nothing changes.
 */
final class FolioLocaleUrlsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/folio_locale_urls_' . bin2hex(random_bytes(4));
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Club', 'debug' => 0, 'engine' => 'twig', 'key' => 'folio-locale-urls-test-key-01234', 'url' => 'https://club.test'];\n");
        $this->write('config/data.php', "<?php\nreturn ['drivers' => ['json' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data']], 'default' => 'json', 'models_path' => __DIR__ . '/models'];\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class];\n");
        $this->write('config/folio.php', "<?php\nreturn ['path' => '/folio'];\n");
        $this->write('config/geo.php', "<?php\nreturn ['enabled' => true, 'types' => ['page']];\n");
        $this->write('config/seo.php', "<?php\nreturn ['enabled' => true, 'name' => 'Game Club'];\n");
        $this->i18n(['prefix_default' => true, 'redirect_bare' => true]);
        @mkdir($this->dir . '/storage/data', 0777, true);

        $this->write('config/models/page.json', (string) json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Pages',
            'frontend' => ['route' => '/{slug}', 'home' => 'home'],
            'fields' => [
                'title' => ['type' => 'string', 'translatable' => true],
                'slug' => ['type' => 'string', 'translatable' => true],
            ],
        ]));
        // The canonical a template names: its own language's alternate.
        $this->write('resources/folio/frontend/page.twig', '<html><head>'
            . '{% for alt in alternates %}{% if alt.locale == locale %}<link rel="canonical" href="{{ alt.href }}">{% endif %}{% endfor %}'
            . '{{ folio_alternate_links(alternates) }}</head><body><h1>{{ record.title }}</h1></body></html>');
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    /** @param array<string, bool> $keys the new config/i18n.php keys */
    private function i18n(array $keys): void
    {
        $this->write('config/i18n.php', "<?php\nreturn " . var_export(['default' => 'en', 'available' => ['en', 'de'], 'fallback' => 'en', 'url_strategy' => 'prefix'] + $keys, true) . ";\n");
    }

    private function write(string $rel, string $content): void
    {
        @mkdir(dirname($this->dir . '/' . $rel), 0777, true);
        file_put_contents($this->dir . '/' . $rel, $content);
    }

    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    /** The bilingual page "hello"/"hallo", stored and published. */
    private function publishHello(): void
    {
        $app = $this->app();
        $dm = $app->container()->get(DataManager::class);
        $record = DynamicRecord::fromArray($dm->getTypeRegistry()->get('page'), [
            'uuid' => 'p1', 'title' => ['en' => 'Hello', 'de' => 'Hallo'], 'slug' => ['en' => 'hello', 'de' => 'hallo'], '_locales' => ['en', 'de'],
        ]);
        $dm->saveType($record, false);
        $app->container()->get(Publisher::class)->publish($record, null);
    }

    private function get(string $path): ResponseInterface
    {
        return $this->app()->handle((new Psr17Factory())->createServerRequest('GET', 'https://club.test' . $path));
    }

    private function body(string $path): string
    {
        $response = $this->get($path);
        $this->assertSame(200, $response->getStatusCode(), $path);

        return (string) $response->getBody();
    }

    #[RunInSeparateProcess]
    public function test_canonical_and_hreflang_carry_the_default_prefix(): void
    {
        $this->publishHello();

        $html = $this->body('/en/hello');
        $this->assertStringContainsString('<h1>Hello</h1>', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://club.test/en/hello">', $html);
        $this->assertStringContainsString('<meta property="og:url" content="https://club.test/en/hello">', $html);
        $this->assertStringContainsString('<link rel="alternate" hreflang="en" href="https://club.test/en/hello">', $html);
        $this->assertStringContainsString('<link rel="alternate" hreflang="de" href="https://club.test/de/hallo">', $html);
        $this->assertStringContainsString('<link rel="alternate" hreflang="x-default" href="https://club.test/en/hello">', $html);

        $german = $this->body('/de/hallo');
        $this->assertStringContainsString('<link rel="canonical" href="https://club.test/de/hallo">', $german);
    }

    #[RunInSeparateProcess]
    public function test_sitemap_lists_prefixed_default_urls(): void
    {
        $this->publishHello();

        $sitemap = $this->body('/sitemap.xml');
        $this->assertStringContainsString('<loc>https://club.test/en/hello</loc>', $sitemap);
        $this->assertStringContainsString('<loc>https://club.test/de/hallo</loc>', $sitemap);
        $this->assertStringContainsString('hreflang="x-default" href="https://club.test/en/hello"', $sitemap);
        $this->assertStringNotContainsString('<loc>https://club.test/hello</loc>', $sitemap);

        $llms = $this->body('/llms.txt');
        $this->assertStringContainsString('(https://club.test/en/hello.md)', $llms);
        $this->assertStringNotContainsString('(https://club.test/hello.md)', $llms);
        $this->assertStringContainsString('# Hello', $this->body('/en/hello.md'), 'the Markdown view llms.txt names answers');
    }

    #[RunInSeparateProcess]
    public function test_bare_folio_page_redirects_and_unknown_slug_is_404(): void
    {
        $this->publishHello();

        $bare = $this->get('/hello');
        $this->assertSame(302, $bare->getStatusCode());
        $this->assertSame('/en/hello', $bare->getHeaderLine('Location'));

        $unknown = $this->get('/no-such-page');
        $this->assertSame(404, $unknown->getStatusCode());
        $this->assertSame('', $unknown->getHeaderLine('Location'));
    }

    #[RunInSeparateProcess]
    public function test_bare_old_address_still_answers_from_redirects(): void
    {
        $this->publishHello();
        $this->app()->container()->get(RedirectEntries::class)->save(['path' => '/old', 'locale' => 'en', 'target_type' => 'page', 'target_id' => 'p1']);

        $prefixed = $this->get('/en/old');
        $this->assertSame(301, $prefixed->getStatusCode());
        $this->assertSame('/en/hello', $prefixed->getHeaderLine('Location'));

        // "/old" is no page: not sent to a language, the redirect store answers it.
        $bare = $this->get('/old');
        $this->assertSame(301, $bare->getStatusCode());
        $this->assertSame('/en/hello', $bare->getHeaderLine('Location'));

        // Settings → Redirects shows the address as visitors type it.
        $this->assertMatchesRegularExpression('~>\s*/en/old\s*<~', $this->body('/folio/redirects'));
    }

    #[RunInSeparateProcess]
    public function test_address_affix_shows_the_default_prefix(): void
    {
        $this->publishHello();

        $page = $this->body('/folio/page/p1/edit');
        $this->assertStringContainsString('<span class="folio-input-prefix" aria-hidden="true">/en/</span>', $page);
        $this->assertStringContainsString('data-address-before="https://club.test/en/"', $page);
    }

    #[RunInSeparateProcess]
    public function test_default_locale_urls_stay_unprefixed_without_prefix_default(): void
    {
        $this->i18n([]);
        $this->publishHello();

        $html = $this->body('/hello');
        $this->assertStringContainsString('<link rel="canonical" href="https://club.test/hello">', $html);
        $this->assertStringContainsString('<link rel="alternate" hreflang="de" href="https://club.test/de/hallo">', $html);
        $this->assertStringContainsString('<loc>https://club.test/hello</loc>', $this->body('/sitemap.xml'));
        $this->assertStringContainsString('(https://club.test/hello.md)', $this->body('/llms.txt'));
        $this->assertStringContainsString('<span class="folio-input-prefix" aria-hidden="true">/</span>', $this->body('/folio/page/p1/edit'));
    }

    /** @return iterable<string, array{0: bool}> */
    public static function siteLookupOrder(): iterable
    {
        yield 'site provider after Folio' => [true];
        yield 'site provider before Folio' => [false];
    }

    #[RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\DataProvider('siteLookupOrder')]
    public function test_folio_pages_and_a_site_page_lookup_both_redirect(bool $siteLast): void
    {
        $providers = [var_export(\Preflow\Folio\FolioServiceProvider::class, true), var_export(SiteToolsProvider::class, true)];
        if (!$siteLast) {
            $providers = array_reverse($providers);
        }
        $this->write('config/providers.php', "<?php\nreturn [" . implode(', ', $providers) . "];\n");
        $this->publishHello();

        // Core's PageExists stays free for the site; Folio adds its own lookup beside it.
        $this->assertSame('/en/hello', $this->get('/hello')->getHeaderLine('Location'));
        $this->assertSame('/en/tools', $this->get('/tools')->getHeaderLine('Location'));
        $this->assertSame(404, $this->get('/no-such-page')->getStatusCode());
        $this->assertInstanceOf(SiteToolsLookup::class, $this->app()->container()->get(\Preflow\Core\Routing\PageExists::class));
    }
}

/** A site's own catch-all pages: '/tools' in every language. */
final class SiteToolsLookup implements \Preflow\Core\Routing\PageExists
{
    public function exists(string $locale, string $path): bool
    {
        return $path === '/tools';
    }
}

/** Binds the site's lookup under core's PageExists. */
final class SiteToolsProvider extends \Preflow\Core\Container\ServiceProvider
{
    public function register(\Preflow\Core\Container\Container $container): void
    {
        $container->singleton(\Preflow\Core\Routing\PageExists::class, static fn (): SiteToolsLookup => new SiteToolsLookup());
    }
}
