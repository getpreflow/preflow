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

    public function test_a_configured_storage_is_not_in_the_way(): void
    {
        $this->write('config/data.php', "<?php\nreturn ['drivers' => ['json' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data'], 'media_files' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data']], 'default' => 'json', 'models_path' => __DIR__ . '/models'];\n");

        $this->assertSame(200, $this->get('/folio/assets')->getStatusCode());
        $this->assertStringNotContainsString('config/data.php', (string) $this->get('/folio/assets')->getBody());
    }
}
