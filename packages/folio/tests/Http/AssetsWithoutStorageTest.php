<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Tests\Support\PublishingScaffold;

/**
 * Media stores its assets in the "json" storage unless config/media.php says otherwise. A site whose
 * config/data.php has no such driver (it keeps everything in SQLite, say) boots and works; the media
 * library answers with what is missing and where to configure it instead of a 500 (a site may have no media).
 */
final class AssetsWithoutStorageTest extends TestCase
{
    use PublishingScaffold;

    protected function setUp(): void
    {
        // The content lives in "json"; the library is told to use a driver ("media_files") that config/data.php lacks.
        $this->scaffoldPublishing();
        $this->write('config/media.php', "<?php\nreturn ['storage' => 'media_files'];\n");
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_the_assets_screen_names_the_missing_storage_driver_and_where_to_set_it(): void
    {
        $res = $this->get('/folio/assets');
        $html = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode(), 'no media is a state, not an outage: ' . substr($html, 0, 300));
        $this->assertMatchesRegularExpression('/(&quot;|")media_files(&quot;|")/', $html, 'the driver that is missing');
        $this->assertStringContainsString('config/data.php', $html, 'where to add it');
        $this->assertStringContainsString('config/media.php', $html, 'or which setting points elsewhere');
        $this->assertStringContainsString('Assets', $html, 'inside the admin');
    }

    public function test_pages_answer_200_and_actions_that_did_not_happen_answer_503_and_the_rest_of_the_admin_works(): void
    {
        $this->assertSame(200, $this->get('/folio/assets/bin')->getStatusCode());
        $this->assertSame(200, $this->get('/folio/assets/abc')->getStatusCode());
        $this->assertSame(200, $this->get('/folio/assets?picker=1&_drawer=1')->getStatusCode(), 'the picker drawer');
        $this->assertSame(503, $this->post('/folio/assets', [])->getStatusCode(), 'a form post that stored nothing');
        $this->assertSame(200, $this->get('/folio')->getStatusCode());
        $this->assertSame(200, $this->get('/folio/translations')->getStatusCode());
    }

    public function test_htmx_and_json_actions_get_a_non_2xx_with_the_message(): void
    {
        $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
        $htmx = $this->app()->handle($factory->createServerRequest('POST', '/folio/assets')->withHeader('HX-Request', 'true')->withHeader('Sec-Fetch-Site', 'same-origin'));
        $this->assertSame(503, $htmx->getStatusCode());
        $this->assertStringContainsString('media_files', (string) $htmx->getHeaderLine('HX-Trigger'));

        $json = $this->app()->handle($factory->createServerRequest('POST', '/folio/assets')->withHeader('Accept', 'application/json')->withHeader('Sec-Fetch-Site', 'same-origin'));
        $this->assertSame(503, $json->getStatusCode());
        $this->assertStringContainsString('config/media.php', (string) $json->getBody());
    }

    public function test_the_admin_search_leaves_the_library_out(): void
    {
        $res = $this->get('/folio/_search?q=hello');
        $this->assertSame(200, $res->getStatusCode(), substr((string) $res->getBody(), 0, 300));
        $this->assertArrayHasKey('groups', json_decode((string) $res->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_the_asset_field_with_a_stored_id_renders_without_the_library(): void
    {
        $this->write('storage/data/post/p1.json', (string) json_encode(['uuid' => 'p1', 'title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello'], 'cover' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90']));
        $res = $this->get('/folio/post/p1/edit');
        $this->assertSame(200, $res->getStatusCode(), substr(strip_tags((string) $res->getBody()), 0, 300));
    }

    public function test_saving_a_record_keeps_its_asset_ids_and_an_upload_is_refused_with_the_message(): void
    {
        $id = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
        $this->write('storage/data/post/p1.json', (string) json_encode(['uuid' => 'p1', 'title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello'], 'cover' => $id]));

        $res = $this->post('/folio/post/p1', ['title' => 'Hello again', 'slug' => 'hello', '_locale' => 'en', 'cover_ids' => [$id], '_action' => 'publish']);
        $this->assertContains($res->getStatusCode(), [200, 302], substr(strip_tags((string) $res->getBody()), 0, 300));
        $stored = json_decode((string) file_get_contents($this->dir . '/storage/data/post/p1.json'), true);
        $this->assertSame($id, $stored['cover'], 'the reference survives the save');
        $this->assertSame('Hello again', $stored['title']['en'] ?? null, 'and the save happened');

        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $upload = $this->app()->handle($f->createServerRequest('POST', '/folio/post')->withHeader('Sec-Fetch-Site', 'same-origin')
            ->withParsedBody(['title' => 'T', 'slug' => 'new', '_locale' => 'en'])
            ->withUploadedFiles(['cover' => $f->createUploadedFile($f->createStream('x'), 1, UPLOAD_ERR_OK, 'pic.png')]));
        $this->assertStringContainsString('pic.png was not uploaded: the media library has no storage', (string) $upload->getBody());
    }

    public function test_export_and_import_leave_the_library_out(): void
    {
        $app = $this->app();
        $dm = $app->container()->get(\Preflow\Data\DataManager::class);
        $set = \Preflow\Folio\Transfer\ContentSet::fromApp($app);
        $this->assertNotContains($app->container()->get(\Preflow\Media\MediaLibrary::class)->definition()->table, array_keys($set->tables()), 'no table to read without its storage');

        $s = fopen('php://temp', 'w+b');
        (new \Preflow\Folio\Transfer\Exporter($set, $dm))->export($s);
        rewind($s);
        $plan = (new \Preflow\Folio\Transfer\Importer($set, $dm))->read($s);
        try {
            $this->assertTrue($plan->isEmpty(), 'the same content back');
        } finally {
            $plan->cleanup();
        }
    }

    public function test_the_activity_screen_shows_asset_rows_without_the_library(): void
    {
        $log = $this->app()->container()->get(\Preflow\Core\Activity\ActivityLog::class);
        $log->record(new \Preflow\Core\Activity\Activity('post', 'p9', 'Spring launch', \Preflow\Core\Activity\ActivityKind::Created, by: 'admin@site.test'));
        $log->record(new \Preflow\Core\Activity\Activity('asset', 'a1b2c3d4e5f60718293a4b5c6d7e8f90', 'meadow.png', \Preflow\Core\Activity\ActivityKind::Created, by: 'admin@site.test'));

        $res = $this->get('/folio/activity');
        $this->assertSame(200, $res->getStatusCode(), substr(strip_tags((string) $res->getBody()), 0, 300));
        $this->assertStringContainsString('Spring launch', (string) $res->getBody(), 'the log was read: its other rows show');
        $this->assertStringNotContainsString('meadow.png', (string) $res->getBody(), 'no asset rows without a library');
    }

    public function test_the_ai_alt_text_route_is_behind_the_library_check(): void
    {
        foreach (\Preflow\Folio\Routing\FolioRoutes::admin('/folio', true, ai: true) as $entry) {
            if ($entry->pattern === '/folio/assets/{id}/_ai/suggest') {
                $this->assertContains('Preflow\\Folio\\Http\\MediaStorageMiddleware', $entry->middleware);

                return;
            }
        }
        $this->fail('no AI alt text route');
    }

    public function test_a_configured_storage_is_not_in_the_way(): void
    {
        $this->write('config/data.php', "<?php\nreturn ['drivers' => ['json' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data'], 'media_files' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data']], 'default' => 'json', 'models_path' => __DIR__ . '/models'];\n");

        $this->assertSame(200, $this->get('/folio/assets')->getStatusCode());
        $this->assertStringNotContainsString('config/data.php', (string) $this->get('/folio/assets')->getBody());
    }
}
