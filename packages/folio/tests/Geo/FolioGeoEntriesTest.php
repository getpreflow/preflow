<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Geo;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Publishing\Publisher;
use Preflow\View\Geo\GeoEntries;
use Preflow\View\Geo\GeoEntry;

/** Folio's published pages as GEO entries (llms.txt), per locale. */
final class FolioGeoEntriesTest extends TestCase
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
}
