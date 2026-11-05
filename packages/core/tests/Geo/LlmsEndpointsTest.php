<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Geo;

require_once __DIR__ . '/../Fixtures/Geo/GeoEntriesProvider.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Tests\Fixtures\Geo\GeoEntriesProvider;
use Preflow\View\Geo\GeoEntry;
use Psr\Http\Message\ResponseInterface;

/** /llms.txt and /llms-full.txt per locale, built from GeoEntries (config/geo.php). */
final class LlmsEndpointsTest extends TestCase
{
    private string $dir;

    private const KEY = 'geo-llms-test-key-0123456789abcdef';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/geo_llms_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/config', 0777, true);
        @mkdir($this->dir . '/app/pages', 0777, true);
        $this->appConfig(0);
        file_put_contents($this->dir . '/config/i18n.php', "<?php\nreturn ['default'=>'en','available'=>['en','de'],'fallback'=>'en','url_strategy'=>'prefix'];\n");
        file_put_contents($this->dir . '/config/providers.php', "<?php\nreturn [" . var_export(GeoEntriesProvider::class, true) . "];\n");
        $this->geo("['enabled' => true, 'name' => 'Game Club', 'summary' => 'Board games, explained.', 'exclude' => ['/secret']]");

        $page = static fn (string $title, string $main, string $head = ''): string =>
            '<html><head><title>' . $title . '</title>' . $head . '</head><body><nav>Menu</nav><main>' . $main . '</main></body></html>';
        $this->page('index', $page('Home {{ current_locale() }}', '<h1>Welcome {{ current_locale() }}</h1><h2>News</h2><p>Hello.</p>'));
        $this->page('about', $page('About us', '<h1>About</h1><p>We play games.</p>'));
        $this->page('contact', $page('Contact', '<p>Write to us.</p>'));
        $this->page('noindex', $page('No index', '<p>Hidden text</p>', '<meta name="robots" content="noindex">'));
        $this->page('secret', $page('Secret', '<p>Secret text</p>'));
        $this->page('broken', $page('Broken', '{{ component("No\\\\Such\\\\Component") }}'));

        GeoEntriesProvider::$source = static fn (string $locale): array => $locale === 'de'
            ? [new GeoEntry('Start', '/de/', order: 0), new GeoEntry('Über uns', '/de/about', 'Wer wir sind')]
            : [
                new GeoEntry('Contact', '/contact', 'How to reach us', order: 20),
                new GeoEntry('About', '/about', 'Who we are', order: 10),
                new GeoEntry('Home', '/', 'The start page', order: 0),
                new GeoEntry('Rules', '/rules', 'Only listed', 'Games', full: false),
                new GeoEntry('Hidden', '/noindex'),
                new GeoEntry('Missing', '/missing'),
                new GeoEntry('Broken', '/broken'),
                new GeoEntry('Secret', '/secret'),
                new GeoEntry('Elsewhere', 'https://other.test/page', 'Another site'),
            ];
        GeoEntriesProvider::$site = null;
    }

    protected function tearDown(): void
    {
        GeoEntriesProvider::$source = null;
        GeoEntriesProvider::$site = null;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    private function appConfig(int $debug): void
    {
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'App Name','debug'=>{$debug},'engine'=>'twig','key'=>'" . self::KEY . "','url'=>'https://site.test'];\n");
    }

    private function page(string $name, string $twig): void
    {
        file_put_contents($this->dir . '/app/pages/' . $name . '.twig', $twig);
    }

    private function geo(?string $config): void
    {
        $path = $this->dir . '/config/geo.php';
        if ($config === null) {
            @unlink($path);
            return;
        }
        file_put_contents($path, "<?php\nreturn {$config};\n");
    }

    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    private function get(Application $app, string $path, string $method = 'GET'): ResponseInterface
    {
        return $app->handle((new Psr17Factory())->createServerRequest($method, 'http://localhost' . $path)
            ->withHeader('Cookie', 'PHPSESSID=visitor'));
    }

    private function assertPlainText(ResponseInterface $response, string $path): string
    {
        $body = (string) $response->getBody();
        $this->assertSame(200, $response->getStatusCode(), $path . ': ' . $body);
        $this->assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'), $path);
        $this->assertSame([], $response->getHeader('Set-Cookie'), $path);
        // A CDN or browser can absorb repeat hits (llms-full.txt renders every listed page).
        $this->assertSame('public, max-age=3600', $response->getHeaderLine('Cache-Control'), $path);

        return $body;
    }

    #[RunInSeparateProcess]
    public function test_llms_txt_lists_the_default_locales_entries_and_the_other_languages(): void
    {
        $body = $this->assertPlainText($this->get($this->app(), '/llms.txt'), '/llms.txt');

        $this->assertSame(
            "# Game Club\n\n> Board games, explained.\n\n"
            . "## Pages\n\n"
            . "- [Home](https://site.test/index.md): The start page\n"
            . "- [About](https://site.test/about.md): Who we are\n"
            . "- [Contact](https://site.test/contact.md): How to reach us\n"
            . "- [Broken](https://site.test/broken.md)\n"
            . "- [Elsewhere](https://other.test/page.md): Another site\n"
            . "- [Hidden](https://site.test/noindex.md)\n"
            . "- [Missing](https://site.test/missing.md)\n\n"
            . "## Games\n\n"
            . "- [Rules](https://site.test/rules.md): Only listed\n\n"
            . "## Other languages\n\n"
            . "- [de](https://site.test/de/llms.txt)\n",
            $body,
        );
    }

    #[RunInSeparateProcess]
    public function test_the_other_languages_heading_is_in_the_llms_txts_language(): void
    {
        file_put_contents($this->dir . '/config/i18n.php', "<?php\nreturn ['default'=>'de','available'=>['de','en'],'fallback'=>'de','url_strategy'=>'prefix'];\n");

        $body = $this->assertPlainText($this->get($this->app(), '/llms.txt'), '/llms.txt');
        $this->assertStringEndsWith("\n## Andere Sprachen\n\n- [en](https://site.test/en/llms.txt)\n", $body);
        $this->assertStringNotContainsString('Other languages', $body);
    }

    #[RunInSeparateProcess]
    public function test_a_site_renames_the_other_languages_heading_in_its_lang_file(): void
    {
        file_put_contents($this->dir . '/config/i18n.php', "<?php\nreturn ['default'=>'de','available'=>['de','en'],'fallback'=>'de','url_strategy'=>'prefix'];\n");
        @mkdir($this->dir . '/lang/de', 0777, true);
        file_put_contents($this->dir . '/lang/de/geo.php', "<?php\nreturn ['other_languages' => 'Weitere Sprachen'];\n");

        $body = $this->assertPlainText($this->get($this->app(), '/llms.txt'), '/llms.txt');
        $this->assertStringEndsWith("\n## Weitere Sprachen\n\n- [en](https://site.test/en/llms.txt)\n", $body);
    }

    #[RunInSeparateProcess]
    public function test_locale_llms_txt_lists_that_locales_entries(): void
    {
        $body = $this->assertPlainText($this->get($this->app(), '/de/llms.txt'), '/de/llms.txt');

        $this->assertSame(
            "# Game Club\n\n> Board games, explained.\n\n"
            . "## Pages\n\n"
            . "- [Start](https://site.test/de/index.md)\n"
            . "- [Über uns](https://site.test/de/about.md): Wer wir sind\n",
            $body,
        );

        // Locales that aren't configured have no llms.txt.
        $this->assertSame(404, $this->get($this->app(), '/fr/llms.txt')->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_name_and_summary_from_the_site_fields_else_the_app_name(): void
    {
        $this->geo("['enabled' => true, 'site_fields' => ['name' => 'title', 'summary' => 'description']]");
        GeoEntriesProvider::$site = static fn (string $locale): array => [
            'title' => 'Site ' . $locale,
            'description' => $locale === 'de' ? "Brett-\nspiele" : '',
        ];

        $this->assertStringStartsWith("# Site en\n\n## Pages\n\n", $this->assertPlainText($this->get($this->app(), '/llms.txt'), '/llms.txt'));
        $this->assertStringStartsWith("# Site de\n\n> Brett- spiele\n\n## Pages", $this->assertPlainText($this->get($this->app(), '/de/llms.txt'), '/de/llms.txt'));

        GeoEntriesProvider::$site = null;
        $this->assertStringStartsWith("# App Name\n\n## Pages", $this->assertPlainText($this->get($this->app(), '/llms.txt'), '/llms.txt'));
    }

    #[RunInSeparateProcess]
    public function test_llms_full_holds_each_listed_pages_markdown_in_order(): void
    {
        $logger = new class extends \Psr\Log\AbstractLogger {
            public int $errors = 0;

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->errors++;
            }
        };
        $app = $this->app();
        $app->container()->instance(\Psr\Log\LoggerInterface::class, $logger);

        $body = $this->assertPlainText($this->get($app, '/llms-full.txt'), '/llms-full.txt');

        $this->assertSame(
            "# Game Club\n\n> Board games, explained.\n\n"
            . "## Home\n\nSource: https://site.test/\n\n## Welcome en\n\n### News\n\nHello.\n\n"
            . "## About\n\nSource: https://site.test/about\n\n## About\n\nWe play games.\n\n"
            . "## Contact\n\nSource: https://site.test/contact\n\nWrite to us.\n",
            $body,
        );
        // The failing page was skipped and logged; the others aren't errors.
        $this->assertSame(1, $logger->errors);

        $de = $this->assertPlainText($this->get($app, '/de/llms-full.txt'), '/de/llms-full.txt');
        $this->assertStringContainsString("## Start\n\nSource: https://site.test/de/\n\n## Welcome de\n", $de);
    }

    #[RunInSeparateProcess]
    public function test_a_failing_page_fails_llms_full_in_debug_mode(): void
    {
        $this->appConfig(1);

        $res = $this->get($this->app(), '/llms-full.txt');

        $this->assertSame(500, $res->getStatusCode());
        $this->assertStringContainsString('No\\Such\\Component', (string) $res->getBody());
    }

    #[RunInSeparateProcess]
    public function test_full_off_answers_404_for_llms_full(): void
    {
        $this->geo("['enabled' => true, 'full' => false]");
        $app = $this->app();

        $full = $this->get($app, '/llms-full.txt');
        $this->assertSame(404, $full->getStatusCode());
        $this->assertSame([], $full->getHeader('Set-Cookie'));
        $this->assertSame(404, $this->get($app, '/de/llms-full.txt')->getStatusCode());
        $this->assertSame(200, $this->get($app, '/llms.txt')->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_head_gets_the_headers_without_a_body(): void
    {
        $res = $this->get($this->app(), '/llms.txt', 'HEAD');

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('text/plain; charset=utf-8', $res->getHeaderLine('Content-Type'));
        $this->assertSame('', (string) $res->getBody());
    }

    #[RunInSeparateProcess]
    public function test_disabled_geo_passes_the_llms_paths_through(): void
    {
        $this->geo(null);
        $plain = $this->get($this->app(), '/llms.txt');

        $this->geo("['enabled' => false]");
        $app = $this->app();

        foreach (['/llms.txt', '/llms-full.txt', '/de/llms.txt'] as $path) {
            $res = $this->get($app, $path);
            $this->assertSame(404, $res->getStatusCode(), $path);
            $this->assertSame($plain->getHeaderLine('Content-Type'), $res->getHeaderLine('Content-Type'), $path);
            $this->assertStringStartsWith('text/html', $res->getHeaderLine('Content-Type'), $path);
        }
    }
}
