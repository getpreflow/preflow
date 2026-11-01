<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Publishing\PreviewLinks;
use Preflow\Folio\Publishing\Publisher;
use Preflow\Folio\Tests\Fixtures\Seo\JobSchemaProvider;
use Preflow\Folio\Tests\Support\AssetFixtures;
use Preflow\Media\MediaLibrary;
use Psr\Http\Message\ResponseInterface;

/**
 * Folio pages with SEO on: share cards and JSON-LD from the record, a site's
 * own schema for a type, and the record's "SEO & sharing" flags reaching the
 * live page, sitemap.xml and llms.txt when the record is published.
 */
#[RequiresPhpExtension('gd')]
#[RequiresFunction('imagewebp')]
final class SeoFrontendTest extends TestCase
{
    use AssetFixtures;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/folio_seo_' . bin2hex(random_bytes(4));
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Club', 'debug' => 0, 'engine' => 'twig', 'key' => 'folio-seo-frontend-test-key-0123', 'url' => 'https://club.test'];\n");
        $this->write('config/data.php', "<?php\nreturn ['drivers' => ['json' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data']], 'default' => 'json', 'models_path' => __DIR__ . '/models'];\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, " . var_export(JobSchemaProvider::class, true) . "];\n");
        $this->write('config/folio.php', "<?php\nreturn ['path' => '/folio'];\n");
        $this->write('config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en', 'de'], 'fallback' => 'en', 'url_strategy' => 'prefix'];\n");
        $this->write('config/geo.php', "<?php\nreturn ['enabled' => true, 'types' => ['page', 'job']];\n");
        $this->write('config/seo.php', "<?php\nreturn " . var_export([
            'enabled' => true,
            'name' => 'Game Club',
            'types' => ['job' => ['description' => ['summary'], 'image' => ['logo'], 'og_type' => 'article']],
        ], true) . ";\n");
        @mkdir($this->dir . '/storage/data', 0777, true);

        $this->model('page', [
            'frontend' => ['route' => '/{slug}', 'home' => 'home'],
            'fields' => [
                'title' => ['type' => 'string', 'translatable' => true],
                'slug' => ['type' => 'string', 'translatable' => true],
                'meta_description' => ['type' => 'string', 'translatable' => true],
            ],
        ]);
        $this->model('job', [
            'frontend' => ['route' => '/jobs/{slug}'],
            'fields' => [
                'title' => ['type' => 'string'],
                'slug' => ['type' => 'string'],
                'summary' => ['type' => 'text'],
                'logo' => ['type' => 'asset'],
            ],
        ]);
        $this->model('secret', [
            'frontend' => ['route' => '/members/{slug}', 'permission' => 'members.access'],
            'fields' => ['title' => ['type' => 'string'], 'slug' => ['type' => 'string']],
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

    /** Store a working copy, and publish it unless $publish is false. @param array<string, mixed> $data */
    private function save(string $type, string $id, array $data, bool $publish = true): DynamicRecord
    {
        $app = $this->app();
        $dm = $app->container()->get(DataManager::class);
        $record = DynamicRecord::fromArray($dm->getTypeRegistry()->get($type), ['uuid' => $id] + $data);
        $dm->saveType($record, false);
        if ($publish) {
            $app->container()->get(Publisher::class)->publish($record, null);
        }

        return $record;
    }

    /** @param array<string, string> $query */
    private function get(string $path, array $query = []): ResponseInterface
    {
        $uri = 'https://club.test' . $path . ($query !== [] ? '?' . http_build_query($query) : '');

        return $this->app()->handle((new Psr17Factory())->createServerRequest('GET', $uri)->withQueryParams($query));
    }

    private function body(string $path, array $query = []): string
    {
        $response = $this->get($path, $query);
        $this->assertSame(200, $response->getStatusCode(), $path);

        return (string) $response->getBody();
    }

    /** @return list<array> the JSON-LD graph in $html */
    private function graph(string $html): array
    {
        $this->assertSame(1, preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $m), 'no JSON-LD');

        return json_decode($m[1], true, flags: JSON_THROW_ON_ERROR)['@graph'];
    }

    /** A share-preview link's token for a record's working copy, as PublishingController::share makes it. */
    private function shareToken(string $type, string $id): string
    {
        $app = $this->app();
        $dm = $app->container()->get(DataManager::class);
        $record = $dm->findType($type, $id) ?? throw new \RuntimeException("missing {$type}/{$id}");
        $record->set('_share_token', bin2hex(random_bytes(8)));
        $dm->saveType($record, false);

        return $app->container()->get(PreviewLinks::class)->create($type, $id, (string) $record->get('_share_token'));
    }

    #[RunInSeparateProcess]
    public function test_a_published_page_has_share_cards_and_json_ld(): void
    {
        $library = $this->app()->container()->get(MediaLibrary::class);
        $logo = $library->upload($this->upload($this->png(1600, 900), 'logo.png'), null)->id;
        $share = $library->shareImage($logo);
        $this->save('job', 'j1', ['title' => 'Game master', 'slug' => 'game-master', 'summary' => '<p>Run our <b>Friday</b> nights.</p>', 'logo' => $logo]);
        $this->save('page', 'home', ['title' => ['en' => 'Welcome'], 'slug' => ['en' => 'home'], 'meta_description' => ['en' => 'Board games every week']]);

        $html = $this->body('/jobs/game-master');

        foreach ([
            '<meta property="og:site_name" content="Game Club">',
            '<meta property="og:title" content="Game master">',
            '<meta property="og:description" content="Run our Friday nights.">',
            '<meta property="og:type" content="article">',
            '<meta property="og:url" content="https://club.test/jobs/game-master">',
            '<meta property="og:image" content="https://club.test/' . ltrim($share['url'], '/') . '">',
            '<meta property="og:image:width" content="1200">',
            '<meta name="twitter:card" content="summary_large_image">',
            '<meta name="robots" content="max-image-preview:large">',
        ] as $tag) {
            $this->assertStringContainsString($tag, $html);
        }
        $graph = $this->graph($html);
        $this->assertSame(['WebPage', 'JobPosting'], array_column($graph, '@type'));
        $this->assertSame(['Game master', 'Run our Friday nights.', 'https://club.test/jobs/game-master', 'PART_TIME'],
            [$graph[1]['title'], $graph[1]['description'], $graph[1]['url'], $graph[1]['employmentType']]);

        $home = $this->body('/');
        $this->assertStringContainsString('<meta property="og:title" content="Welcome">', $home);
        $this->assertStringContainsString('<meta property="og:description" content="Board games every week">', $home);
        $this->assertSame(['WebSite', 'WebPage'], array_column($this->graph($home), '@type'));
    }

    #[RunInSeparateProcess]
    public function test_seo_changes_take_effect_on_publish(): void
    {
        $this->save('page', 'about', ['title' => ['en' => 'About'], 'slug' => ['en' => 'about']]);
        $this->assertStringContainsString('<loc>https://club.test/about</loc>', $this->body('/sitemap.xml'));
        $this->assertStringContainsString('[About](https://club.test/about.md)', $this->body('/llms.txt'));

        // Ticked on the working copy only: the live page and the lists don't change yet.
        $working = $this->save('page', 'about', ['title' => ['en' => 'About'], 'slug' => ['en' => 'about'], '_seo' => ['noindex' => true]], publish: false);
        $live = $this->body('/about');
        $this->assertStringContainsString('<meta name="robots" content="max-image-preview:large">', $live);
        $this->assertStringContainsString('og:title', $live);
        $this->assertStringContainsString('<loc>https://club.test/about</loc>', $this->body('/sitemap.xml'));

        // The share-preview link shows the working copy, never indexed and without cards.
        $preview = $this->body('/about', ['folio_preview' => $this->shareToken('page', 'about')]);
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $preview);
        $this->assertStringNotContainsString('og:', $preview);
        $this->assertStringNotContainsString('ld+json', $preview);

        $this->app()->container()->get(Publisher::class)->publish($working, null);
        $published = $this->body('/about');
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $published);
        $this->assertStringNotContainsString('og:', $published);
        $this->assertStringNotContainsString('/about', $this->body('/sitemap.xml'));
        $this->assertStringNotContainsString('/about', $this->body('/llms.txt'));
    }

    #[RunInSeparateProcess]
    public function test_a_preview_of_an_indexed_page_is_noindex(): void
    {
        $this->save('page', 'about', ['title' => ['en' => 'About'], 'slug' => ['en' => 'about']]);

        $preview = $this->body('/about', ['folio_preview' => $this->shareToken('page', 'about')]);

        $this->assertStringContainsString('<meta name="robots" content="noindex">', $preview);
    }

    #[RunInSeparateProcess]
    public function test_gated_pages_and_the_admin_stay_out(): void
    {
        $this->save('page', 'about', ['title' => ['en' => 'About'], 'slug' => ['en' => 'about']]);
        $this->save('secret', 's1', ['title' => 'Members only', 'slug' => 'inside']);

        $sitemap = $this->body('/sitemap.xml');
        $this->assertStringContainsString('https://club.test/about', $sitemap);
        $this->assertStringNotContainsString('/members/', $sitemap);

        $robots = $this->body('/robots.txt');
        $this->assertStringContainsString("Disallow: /folio/\n", $robots);
        $this->assertStringContainsString("Sitemap: https://club.test/sitemap.xml\n", $robots);

        // Folio's own pages get no share cards.
        $admin = $this->get('/folio/page');
        $this->assertSame(200, $admin->getStatusCode());
        $this->assertStringContainsString('</head>', (string) $admin->getBody());
        $this->assertStringNotContainsString('twitter:card', (string) $admin->getBody());
        $this->assertStringNotContainsString('name="robots"', (string) $admin->getBody());
    }
}
