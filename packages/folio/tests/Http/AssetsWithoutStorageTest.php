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
        $this->scaffoldPublishing('sqlite');
        $this->write('config/data.php', "<?php\nreturn ['drivers' => ['sqlite' => ['path' => __DIR__ . '/../storage/app.sqlite']], 'default' => 'sqlite', 'models_path' => __DIR__ . '/models'];\n");
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_the_assets_screen_names_the_missing_storage_driver_and_where_to_set_it(): void
    {
        $res = $this->get('/folio/assets');
        $html = (string) $res->getBody();

        $this->assertSame(503, $res->getStatusCode(), substr($html, 0, 300));
        $this->assertMatchesRegularExpression('/(&quot;|")json(&quot;|")/', $html, 'the driver that is missing');
        $this->assertStringContainsString('config/data.php', $html, 'where to add it');
        $this->assertStringContainsString('config/media.php', $html, 'or which setting points elsewhere');
        $this->assertStringContainsString('Assets', $html, 'inside the admin');
    }

    public function test_every_assets_route_answers_the_same_and_the_rest_of_the_admin_works(): void
    {
        $this->assertSame(503, $this->get('/folio/assets/bin')->getStatusCode());
        $this->assertSame(503, $this->get('/folio/assets/abc')->getStatusCode());
        $this->assertSame(503, $this->post('/folio/assets', [])->getStatusCode());
        $this->assertSame(200, $this->get('/folio')->getStatusCode());
        $this->assertSame(200, $this->get('/folio/translations')->getStatusCode());
    }

    public function test_a_configured_storage_is_not_in_the_way(): void
    {
        $this->write('config/data.php', "<?php\nreturn ['drivers' => ['sqlite' => ['path' => __DIR__ . '/../storage/app.sqlite'], 'files' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data']], 'default' => 'sqlite', 'models_path' => __DIR__ . '/models'];\n");
        $this->write('config/media.php', "<?php\nreturn ['storage' => 'files'];\n");

        $this->assertSame(200, $this->get('/folio/assets')->getStatusCode());
    }
}
