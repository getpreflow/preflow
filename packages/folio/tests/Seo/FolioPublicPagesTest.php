<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Seo;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Publishing\Publisher;
use Preflow\View\Geo\GeoEntries;
use Preflow\View\Geo\GeoEntry;
use Preflow\View\Seo\PublicPage;
use Preflow\View\Seo\PublicPages;

/**
 * Folio's published pages as public pages, per locale: the sitemap channel with
 * SEO on, the llms.txt channel (through GeoEntries) with GEO on.
 */
final class FolioPublicPagesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/folio_geo_' . bin2hex(random_bytes(4));
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Geo', 'debug' => 0, 'engine' => 'twig', 'key' => 'folio-geo-entries-test-key-0123'];\n");
        $this->write('config/data.php', "<?php\nreturn ['drivers' => ['json' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data']], 'default' => 'json', 'models_path' => __DIR__ . '/models'];\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class];\n");
        $this->write('config/folio.php', "<?php\nreturn ['path' => '/folio'];\n");
        $this->write('config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en', 'de'], 'fallback' => 'en', 'url_strategy' => 'prefix'];\n");
        $this->geo(['enabled' => true, 'types' => ['page', 'post', 'event', 'secret'], 'noindex_field' => 'noindex']);
        @mkdir($this->dir . '/storage/data', 0777, true);

        $this->model('page', [
            'frontend' => ['route' => '/{slug}', 'home' => 'home'],
            'fields' => [
                'title' => ['type' => 'string', 'translatable' => true],
                'slug' => ['type' => 'string', 'translatable' => true],
                'meta_description' => ['type' => 'string', 'translatable' => true],
                'noindex' => ['type' => 'boolean'],
            ],
        ]);
        $this->model('post', [
            'frontend' => ['route' => '/posts/{slug}'],
            'fields' => ['name' => ['type' => 'string'], 'slug' => ['type' => 'string']],
        ]);
        $this->model('event', [
            'frontend' => ['route' => '/events/{slug}'],
            'list' => ['columns' => ['headline', 'slug']],
            'fields' => ['headline' => ['type' => 'string'], 'slug' => ['type' => 'string']],
        ]);
        $this->model('secret', [
            'frontend' => ['route' => '/members/{slug}', 'permission' => 'members.access'],
            'fields' => ['title' => ['type' => 'string'], 'slug' => ['type' => 'string']],
        ]);
        $this->model('news', [
            'frontend' => ['route' => '/news/{slug}'],
            'fields' => ['title' => ['type' => 'string'], 'slug' => ['type' => 'string']],
        ]);
        $this->model('site', [
            'singleton' => true,
            'fields' => [
                'name' => ['type' => 'string', 'translatable' => true],
                'meta_description' => ['type' => 'string', 'translatable' => true],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    private function write(string $rel, string $content): void
    {
        @mkdir(dirname($this->dir . '/' . $rel), 0777, true);
        file_put_contents($this->dir . '/' . $rel, $content);
    }

    /** @param array<string, mixed> $config */
    private function geo(array $config): void
    {
        $this->write('config/geo.php', "<?php\nreturn " . var_export($config, true) . ";\n");
    }

    /** @param array<string, mixed> $config */
    private function seo(array $config): void
    {
        $this->write('config/seo.php', "<?php\nreturn " . var_export($config, true) . ";\n");
    }

    /** @param array<string, mixed> $config */
    private function model(string $key, array $config): void
    {
        $this->write("config/models/{$key}.json", (string) json_encode(['key' => $key, 'table' => $key, 'storage' => 'json', 'id_field' => 'uuid'] + $config));
    }

    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    /** @param array<string, mixed> $data */
    private function publish(Application $app, string $type, string $id, array $data, bool $publish = true): void
    {
        $dm = $app->container()->get(DataManager::class);
        $record = DynamicRecord::fromArray($dm->getTypeRegistry()->get($type), ['uuid' => $id] + $data);
        $dm->saveType($record, false);
        if ($publish) {
            $app->container()->get(Publisher::class)->publish($record, null);
        }
    }

    /** @return list<string> "section|order|title|url|description" per entry */
    private function entries(Application $app, string $locale): array
    {
        return array_map(
            static fn (GeoEntry $e): string => implode('|', [$e->section, $e->order, $e->title, $e->url, $e->description]),
            $app->container()->get(GeoEntries::class)->for($locale),
        );
    }

    /** @return list<string> "url|channels" per public page; channels: s(itemap), l(lms), f(ull) */
    private function pages(Application $app, string $locale): array
    {
        return array_map(
            static fn (PublicPage $p): string => $p->url . '|' . ($p->sitemap ? 's' : '') . ($p->llms ? 'l' : '') . ($p->llmsFull ? 'f' : ''),
            $app->container()->get(PublicPages::class)->for($locale),
        );
    }

    private function page(Application $app, string $url, string $locale = 'en'): PublicPage
    {
        foreach ($app->container()->get(PublicPages::class)->for($locale) as $page) {
            if ($page->url === $url) {
                return $page;
            }
        }
        $this->fail("No public page {$url}");
    }

    private function content(Application $app): void
    {
        $this->publish($app, 'page', 'p-about', ['title' => ['en' => 'About', 'de' => 'Über uns'], 'slug' => ['en' => 'about', 'de' => 'ueber-uns'], 'meta_description' => ['en' => 'Who we are', 'de' => 'Wer wir sind'], '_locales' => ['de']]);
        $this->publish($app, 'page', 'p-home', ['title' => ['en' => 'Welcome', 'de' => 'Willkommen'], 'slug' => ['en' => 'home', 'de' => 'start'], '_locales' => ['de']]);
        $this->publish($app, 'page', 'p-english', ['title' => ['en' => 'English only'], 'slug' => ['en' => 'english']]);
        $this->publish($app, 'page', 'p-hidden', ['title' => ['en' => 'Hidden'], 'slug' => ['en' => 'hidden'], 'noindex' => true]);
        $this->publish($app, 'page', 'p-draft', ['title' => ['en' => 'Draft'], 'slug' => ['en' => 'draft']], publish: false);
        $this->publish($app, 'post', 'po-1', ['name' => 'First post', 'slug' => 'first']);
        $this->publish($app, 'event', 'e-1', ['headline' => 'Game night', 'slug' => 'game-night']);
        $this->publish($app, 'secret', 's-1', ['title' => 'Members only', 'slug' => 'inside']);
        $this->publish($app, 'news', 'n-1', ['title' => 'Not configured', 'slug' => 'news']);
    }

    #[RunInSeparateProcess]
    public function test_published_records_of_the_configured_types_home_first(): void
    {
        $app = $this->app();
        $this->content($app);

        $this->assertSame([
            'Pages|0|Welcome|/|',
            'Pages|100|About|/about|Who we are',
            'Pages|100|English only|/english|',
            'Pages|100|First post|/posts/first|',
            'Pages|100|Game night|/events/game-night|',
        ], $this->entries($app, 'en'));
    }

    #[RunInSeparateProcess]
    public function test_other_locales_get_their_own_urls_and_texts(): void
    {
        $app = $this->app();
        $this->content($app);

        $this->assertSame([
            'Pages|0|Willkommen|/de/|',
            'Pages|100|First post|/de/posts/first|',
            'Pages|100|Game night|/de/events/game-night|',
            'Pages|100|Über uns|/de/ueber-uns|Wer wir sind',
        ], $this->entries($app, 'de'));
    }

    #[RunInSeparateProcess]
    public function test_unpublishing_removes_a_page(): void
    {
        $app = $this->app();
        $this->content($app);
        $app->container()->get(Publisher::class)->unpublish('page', 'p-about');

        $this->assertNotContains('Pages|100|About|/about|Who we are', $this->entries($app, 'en'));
    }

    #[RunInSeparateProcess]
    public function test_without_a_noindex_field_every_published_page_is_listed(): void
    {
        $this->geo(['enabled' => true]);
        $app = $this->app();
        $this->content($app);

        $this->assertSame([
            'Pages|0|Welcome|/|',
            'Pages|100|About|/about|Who we are',
            'Pages|100|English only|/english|',
            'Pages|100|Hidden|/hidden|',
        ], $this->entries($app, 'en'));
    }

    #[RunInSeparateProcess]
    public function test_site_singleton_fields_per_locale(): void
    {
        $app = $this->app();
        $this->publish($app, 'site', 'site', ['name' => ['en' => 'Game Club', 'de' => 'Spieleclub'], 'meta_description' => ['en' => 'Games', 'de' => 'Spiele'], '_locales' => ['de']]);

        $entries = $app->container()->get(GeoEntries::class);
        $this->assertSame('Spieleclub', $entries->siteFields('de')['name']);
        $this->assertSame('Games', $entries->siteFields('en')['meta_description']);
    }

    #[RunInSeparateProcess]
    public function test_llms_txt_of_a_folio_site(): void
    {
        $app = $this->app();
        $this->content($app);
        $this->publish($app, 'site', 'site', ['name' => ['en' => 'Game Club', 'de' => 'Spieleclub'], 'meta_description' => ['en' => 'Games', 'de' => 'Spiele'], '_locales' => ['de']]);

        $de = $app->handle((new \Nyholm\Psr7\Factory\Psr17Factory())->createServerRequest('GET', 'https://club.test/de/llms.txt'));

        $this->assertSame(200, $de->getStatusCode());
        $this->assertStringStartsWith(
            "# Spieleclub\n\n> Spiele\n\n## Pages\n\n- [Willkommen](https://club.test/de/index.md)\n- [First post](https://club.test/de/posts/first.md)\n",
            (string) $de->getBody(),
        );
    }

    #[RunInSeparateProcess]
    public function test_nothing_is_registered_when_geo_is_off(): void
    {
        $this->geo(['enabled' => false]);
        $app = $this->app();
        $this->content($app);

        $this->assertSame([], $this->entries($app, 'en'));
        $this->assertSame([], $app->container()->get(GeoEntries::class)->siteFields('en'));
    }

    #[RunInSeparateProcess]
    public function test_with_seo_every_public_routed_type_is_in_the_sitemap(): void
    {
        $this->geo(['enabled' => false, 'noindex_field' => 'noindex']); // honoured with GEO off too
        $this->seo(['enabled' => true]);
        $app = $this->app();
        $this->content($app);

        // Not the gated "secret", not the draft, not the page flagged by GEO's noindex_field.
        $this->assertSame([
            '/about|s',
            '/english|s',
            '/events/game-night|s',
            '/news/news|s',
            '/posts/first|s',
            '/|s',
        ], $this->sortedPages($app, 'en'));
        // Records not visible in German are left out there.
        $this->assertSame(['/de/events/game-night|s', '/de/news/news|s', '/de/posts/first|s', '/de/ueber-uns|s', '/de|s'], $this->sortedPages($app, 'de'));
    }

    #[RunInSeparateProcess]
    public function test_lastmod_and_alternates(): void
    {
        $this->seo(['enabled' => true]);
        $app = $this->app();
        $this->content($app);

        $about = $this->page($app, '/about');
        $snapshot = $app->container()->get(Publisher::class)->snapshot('page', 'p-about');
        $this->assertSame($snapshot->get('_published_at'), $about->lastmod);
        $this->assertNotNull($about->lastmod);
        $this->assertSame(['en' => '/about', 'de' => '/de/ueber-uns'], $about->alternates);
        // Home pages at their canonical URLs, as the page's own hreflang and canonical name them.
        $this->assertSame(['en' => '/', 'de' => '/de'], $this->page($app, '/de', 'de')->alternates);
        // A page in one language has no alternates.
        $this->assertSame([], $this->page($app, '/english')->alternates);
    }

    #[RunInSeparateProcess]
    public function test_flags_per_channel(): void
    {
        $this->seo(['enabled' => true]);
        $this->geo(['enabled' => true, 'types' => ['page'], 'noindex_field' => 'noindex']);
        $app = $this->app();
        $this->content($app);
        $this->publish($app, 'page', 'p-no-sitemap', ['title' => ['en' => 'A'], 'slug' => ['en' => 'a'], '_seo' => ['sitemap' => false]]);
        $this->publish($app, 'page', 'p-no-llms', ['title' => ['en' => 'B'], 'slug' => ['en' => 'b'], '_seo' => ['llms' => false]]);
        $this->publish($app, 'page', 'p-noindex', ['title' => ['en' => 'C'], 'slug' => ['en' => 'c'], '_seo' => ['noindex' => true]]);
        // As SQL drivers return it: JSON text.
        $this->publish($app, 'page', 'p-json', ['title' => ['en' => 'D'], 'slug' => ['en' => 'd'], '_seo' => '{"sitemap":false,"llms":false}']);

        $pages = $this->sortedPages($app, 'en');

        $this->assertContains('/a|lf', $pages);
        $this->assertContains('/b|s', $pages);
        $this->assertContains('/d|', $pages);
        $this->assertNotContains('/c|slf', $pages);
        $this->assertSame([], array_filter($pages, static fn (string $p): bool => str_starts_with($p, '/c|')));
        // Types GEO doesn't list stay in the sitemap only.
        $this->assertContains('/posts/first|s', $pages);
        $this->assertContains('/about|slf', $pages);

        $llms = $this->entries($app, 'en');
        $this->assertContains('Pages|100|A|/a|', $llms);
        $this->assertNotContains('Pages|100|B|/b|', $llms);
        $this->assertNotContains('Pages|100|C|/c|', $llms);
    }

    #[RunInSeparateProcess]
    public function test_listed_types_narrow_the_sitemap(): void
    {
        $this->seo(['enabled' => true, 'listed_types' => ['post', 'secret']]);
        $this->geo(['enabled' => true, 'types' => ['page'], 'noindex_field' => 'noindex']);
        $app = $this->app();
        $this->content($app);

        // Pages stay in llms.txt only; the gated type is never listed.
        $this->assertSame(['/about|lf', '/english|lf', '/posts/first|s', '/|lf'], $this->sortedPages($app, 'en'));
    }

    /** @return list<string> pages() sorted by URL */
    private function sortedPages(Application $app, string $locale): array
    {
        $pages = $this->pages($app, $locale);
        sort($pages);

        return $pages;
    }
}
