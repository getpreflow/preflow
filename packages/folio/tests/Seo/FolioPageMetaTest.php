<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Seo;

use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Publishing\Publisher;
use Preflow\Folio\Seo\FolioPageMeta;
use Preflow\Folio\Tests\Support\AssetFixtures;
use Preflow\Media\MediaLibrary;
use Preflow\View\Seo\PageMeta;

/** A Folio page's share card data from its record, the type's mapped fields and the site singleton. */
#[RequiresPhpExtension('gd')]
#[RequiresFunction('imagewebp')]
final class FolioPageMetaTest extends TestCase
{
    use AssetFixtures;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/folio_page_meta_' . bin2hex(random_bytes(4));
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Meta', 'debug' => 0, 'engine' => 'twig', 'key' => 'folio-page-meta-test-key-01234'];\n");
        $this->write('config/data.php', "<?php\nreturn ['drivers' => ['json' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data']], 'default' => 'json', 'models_path' => __DIR__ . '/models'];\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class];\n");
        $this->write('config/folio.php', "<?php\nreturn ['path' => '/folio'];\n");
        $this->write('config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en', 'de'], 'fallback' => 'en', 'url_strategy' => 'prefix'];\n");
        $this->seo(['enabled' => true, 'types' => ['post' => ['title' => ['headline'], 'description' => ['teaser'], 'image' => ['cover'], 'og_type' => 'article']]]);
        @mkdir($this->dir . '/storage/data', 0777, true);

        $this->model('post', [
            'frontend' => ['route' => '/posts/{slug}'],
            'fields' => [
                'title' => ['type' => 'string', 'translatable' => true],
                'slug' => ['type' => 'string', 'translatable' => true],
                'meta_title' => ['type' => 'string', 'translatable' => true],
                'meta_description' => ['type' => 'string', 'translatable' => true],
                'headline' => ['type' => 'string', 'translatable' => true],
                'teaser' => ['type' => 'text', 'translatable' => true],
                'cover' => ['type' => 'asset', 'asset' => ['multiple' => true]],
            ],
        ]);
        $this->model('note', [
            'frontend' => ['route' => '/notes/{slug}'],
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
    private function record(Application $app, string $type, string $id, array $data, bool $publish = false): DynamicRecord
    {
        $dm = $app->container()->get(DataManager::class);
        $record = DynamicRecord::fromArray($dm->getTypeRegistry()->get($type), ['uuid' => $id] + $data);
        $dm->saveType($record, false);
        if ($publish) {
            $app->container()->get(Publisher::class)->publish($record, null);
        }

        return $record;
    }

    private function image(Application $app, bool $private = false): string
    {
        return $app->container()->get(MediaLibrary::class)->upload($this->upload($this->png(1600, 900), 'photo.png'), null, $private)->id;
    }

    /** The share image URL of an asset as a page on https://site.test gets it. */
    private function shareUrl(Application $app, string $id): string
    {
        $url = $app->container()->get(MediaLibrary::class)->shareImage($id)['url'];

        return str_starts_with($url, 'https://') ? $url : 'https://site.test/' . ltrim($url, '/');
    }

    private function meta(Application $app, DynamicRecord $record, string $locale = 'en'): PageMeta
    {
        return $app->container()->get(FolioPageMeta::class)->for($record, $locale, 'https://site.test/page', ['en', 'de']);
    }

    /** @return array{0: string, 1: string, 2: ?string} title, description, image URL */
    private function card(Application $app, DynamicRecord $record, string $locale = 'en'): array
    {
        $meta = $this->meta($app, $record, $locale);

        return [$meta->title, $meta->description, $meta->image['url'] ?? null];
    }

    #[RunInSeparateProcess]
    public function test_precedence(): void
    {
        $app = $this->app();
        [$own, $mapped, $siteImage] = [$this->image($app), $this->image($app), $this->image($app)];
        $this->record($app, 'site', 'site', ['name' => ['en' => 'Game Club'], 'meta_description' => ['en' => 'Board games'],
            '_seo' => ['title' => ['en' => 'Club share'], 'description' => ['en' => 'Club share text'], 'image' => $siteImage]], publish: true);

        // The record's own SEO section wins over everything.
        $all = $this->record($app, 'post', 'a', ['title' => ['en' => 'Title A'], 'headline' => ['en' => 'Head A'], 'teaser' => ['en' => 'Teaser A'], 'cover' => [$mapped],
            'meta_description' => ['en' => 'Desc A'], '_seo' => ['title' => ['en' => 'Share A'], 'description' => ['en' => 'Share text A'], 'image' => $own]]);
        $this->assertSame(['Share A', 'Share text A', $this->shareUrl($app, $own)], $this->card($app, $all));

        // Then the type's mapped fields (rich text as plain text, the first asset of a list).
        $mappedRecord = $this->record($app, 'post', 'b', ['title' => ['en' => 'Title B'], 'meta_title' => ['en' => 'Meta B'], 'headline' => ['en' => 'Head B'],
            'teaser' => ['en' => '<p>Teaser <b>B</b></p>'], 'meta_description' => ['en' => 'Desc B'], 'cover' => json_encode([$mapped, $own])]);
        $this->assertSame(['Head B', 'Teaser B', $this->shareUrl($app, $mapped)], $this->card($app, $mappedRecord));

        // Then the built-in fields, then (description and image only) the site's share section.
        $defaults = $this->record($app, 'post', 'c', ['meta_title' => ['en' => 'Meta C'], 'title' => ['en' => 'Title C'], 'meta_description' => ['en' => 'Desc C']]);
        $this->assertSame(['Meta C', 'Desc C', $this->shareUrl($app, $siteImage)], $this->card($app, $defaults));
        $this->assertSame('Title only', $this->meta($app, $this->record($app, 'post', 'c2', ['title' => ['en' => 'Title only']]))->title);
        // An untitled record gets no title from the site: the middleware takes the page's <title>.
        $nothing = $this->record($app, 'post', 'd', ['slug' => ['en' => 'd']]);
        $this->assertSame(['', 'Club share text', $this->shareUrl($app, $siteImage)], $this->card($app, $nothing));

        // Each field on its own.
        $mixed = $this->record($app, 'post', 'e', ['title' => ['en' => 'Title E'], 'teaser' => ['en' => 'Teaser E'], '_seo' => ['title' => ['en' => 'Share E']]]);
        $this->assertSame(['Share E', 'Teaser E', $this->shareUrl($app, $siteImage)], $this->card($app, $mixed));

        $meta = $this->meta($app, $mixed);
        $this->assertSame(['article', 'https://site.test/page', 'en', ['de'], false, []], [$meta->ogType, $meta->url, $meta->locale, $meta->alternateLocales, $meta->noindex, $meta->schemas]);
        $this->assertSame([1200, 630], [$meta->image['width'], $meta->image['height']]);
    }

    #[RunInSeparateProcess]
    public function test_modified_is_the_records_publish_time(): void
    {
        $app = $this->app();

        $published = $this->record($app, 'post', 'p', ['title' => ['en' => 'P'], '_published_at' => '2026-11-05T10:00:00+00:00']);
        $this->assertSame('2026-11-05T10:00:00+00:00', $this->meta($app, $published)->modified);
        $this->assertSame('', $this->meta($app, $this->record($app, 'post', 'q', ['title' => ['en' => 'Q']]))->modified);
    }

    #[RunInSeparateProcess]
    public function test_geos_noindex_field_makes_the_page_noindex(): void
    {
        $this->write('config/geo.php', "<?php\nreturn ['enabled' => false, 'noindex_field' => 'hidden'];\n");
        $this->model('note', [
            'frontend' => ['route' => '/notes/{slug}'],
            'fields' => ['title' => ['type' => 'string'], 'slug' => ['type' => 'string'], 'hidden' => ['type' => 'boolean']],
        ]);
        $app = $this->app();

        // Left out of the sitemap and llms.txt by the field, so no cards either (honoured with GEO off, as there).
        $this->assertTrue($this->meta($app, $this->record($app, 'note', 'h', ['title' => 'Hidden', 'hidden' => true]))->noindex);
        $this->assertTrue($this->meta($app, $this->record($app, 'note', 'h2', ['title' => 'Hidden', 'hidden' => 'yes']))->noindex);
        $this->assertFalse($this->meta($app, $this->record($app, 'note', 'v', ['title' => 'Visible', 'hidden' => false]))->noindex);
        $this->assertFalse($this->meta($app, $this->record($app, 'note', 'v2', ['title' => 'Visible', 'hidden' => '0']))->noindex);
        // A type without the field is unaffected.
        $this->assertFalse($this->meta($app, $this->record($app, 'post', 'p', ['title' => ['en' => 'Post']]))->noindex);
    }

    #[RunInSeparateProcess]
    public function test_share_texts_only_in_visible_languages(): void
    {
        $app = $this->app();
        $hidden = $this->record($app, 'post', 'f', ['title' => ['en' => 'F', 'de' => 'F de'], '_seo' => ['title' => ['de' => 'Teilen F']]]);
        $visible = $this->record($app, 'post', 'g', ['title' => ['en' => 'G', 'de' => 'G de'], '_locales' => ['de'], '_seo' => ['title' => ['de' => 'Teilen G']]]);
        // A type without translations keeps its share texts under the default language and shows them in all.
        $note = $this->record($app, 'note', 'n', ['title' => 'Note', '_seo' => ['title' => ['en' => 'Note share']]]);

        $this->assertSame('F de', $this->meta($app, $hidden, 'de')->title);
        $this->assertSame('Teilen G', $this->meta($app, $visible, 'de')->title);
        $this->assertSame('Note share', $this->meta($app, $note, 'de')->title);
    }

    #[RunInSeparateProcess]
    public function test_unusable_share_image_is_skipped(): void
    {
        $app = $this->app();
        $library = $app->container()->get(MediaLibrary::class);
        $deleted = $this->image($app);
        $library->delete($deleted);
        $unusable = [
            'missing' => str_repeat('0', 32),
            'deleted' => $deleted,
            'private' => $this->image($app, private: true),
            'pdf' => $library->upload($this->upload("%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n", 'doc.pdf', 'application/pdf'), null)->id,
        ];

        foreach ($unusable as $case => $id) {
            $record = $this->record($app, 'post', 'p-' . $case, ['title' => ['en' => 'T'], '_seo' => ['image' => $id]]);
            $this->assertNull($this->meta($app, $record)->image, $case);
        }

        // The next usable one is taken instead.
        $cover = $this->image($app);
        $record = $this->record($app, 'post', 'p-next', ['title' => ['en' => 'T'], 'cover' => [$cover], '_seo' => ['image' => $unusable['private']]]);
        $this->assertSame($this->shareUrl($app, $cover), $this->meta($app, $record)->image['url']);
    }

    #[RunInSeparateProcess]
    public function test_site_defaults(): void
    {
        $app = $this->app();
        $this->record($app, 'site', 'site', ['name' => ['en' => 'Game Club', 'de' => 'Spieleclub'], 'meta_description' => ['en' => 'Games', 'de' => 'Spiele'], '_locales' => ['de']], publish: true);

        $de = $app->container()->get(FolioPageMeta::class)->siteDefaults('de');
        $this->assertSame(['Spieleclub', 'Spiele', null], [$de->title, $de->description, $de->image]);

        $this->seo(['enabled' => true, 'name' => 'Configured']);
        $app = $this->app();
        $image = $this->image($app);
        $this->record($app, 'site', 'site', ['name' => ['en' => 'Game Club'], 'meta_description' => ['en' => 'Games'],
            '_seo' => ['description' => ['en' => 'Shared'], 'image' => $image]], publish: true);

        $en = $app->container()->get(FolioPageMeta::class)->siteDefaults('en');
        $this->assertSame(['Configured', 'Shared'], [$en->title, $en->description]);
        $this->assertSame($app->container()->get(MediaLibrary::class)->shareImage($image)['url'], $en->image['url']);
    }
}
