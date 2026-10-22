<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Extension;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Http\FolioPermissionMiddleware;
use Preflow\Folio\Tests\Support\CounterExtension;
use Preflow\Folio\Tests\Support\PublishingScaffold;

/**
 * Settings → Extensions ({prefix}/extensions) in a scaffolded app (no auth) with the
 * "counter" extension: read-only in production, toggles when admin changes are allowed.
 */
final class ExtensionsScreenTest extends TestCase
{
    use PublishingScaffold;
    use CounterExtension;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
    }

    protected function tearDown(): void
    {
        $this->removeCounter();
        $this->removeScaffold();
    }

    public function test_routes_ask_folio_extensions(): void
    {
        $this->assertSame('folio.extensions', FolioPermissionMiddleware::rule('Preflow\\Folio\\Http\\ExtensionsController@index'));
        $this->assertSame('folio.extensions', FolioPermissionMiddleware::rule('Preflow\\Folio\\Http\\ExtensionsController@toggle'));
    }

    #[RunInSeparateProcess]
    public function test_screen_read_only_in_production(): void
    {
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Pub', 'debug' => 0, 'engine' => 'twig', 'key' => 'folio-integration-test-key'];\n");
        $this->counter(['screen:board']);
        $before = (string) file_get_contents($this->dir . '/config/folio/extensions.json');

        $res = $this->get('/folio/extensions');
        $body = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertMatchesRegularExpression('#<h1>\s*Extensions\s*</h1>#', $body);
        $this->assertStringContainsString('Counter', $body);
        $this->assertStringContainsString('1.0.0', $body);
        $this->assertStringContainsString('screen:board', $body);
        $this->assertStringContainsString('config/folio/extensions.json', $body);
        $this->assertStringNotContainsString('<form method="post" action="/folio/extensions/', $body);
        // The sidebar item under Settings.
        $this->assertMatchesRegularExpression('#<span class="folio-nav-label">Settings</span>.*?<a href="/folio/extensions" class="active"#s', $body);

        $this->assertSame(403, $this->post('/folio/extensions/counter', ['enabled' => '0'])->getStatusCode());
        $this->assertSame($before, file_get_contents($this->dir . '/config/folio/extensions.json'));
    }

    #[RunInSeparateProcess]
    public function test_screen_toggle_writes_file_when_allowed(): void
    {
        $this->counter([]);

        $body = (string) $this->get('/folio/extensions')->getBody();
        $this->assertStringContainsString('<form method="post" action="/folio/extensions/counter">', $body);
        $this->assertStringContainsString('name="_csrf_token"', $body);
        $this->assertStringContainsString('folio:extensions:apply', $body); // toggling never installs

        $res = $this->post('/folio/extensions/counter', ['part' => 'screen:board', 'enabled' => '0']);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/extensions', $res->getHeaderLine('Location'));
        $this->assertSame(['enabled' => ['counter' => ['disabled_parts' => ['screen:board']]]], $this->extensionsFile());

        $this->post('/folio/extensions/counter', ['enabled' => '0']);
        $this->assertSame(['enabled' => []], $this->extensionsFile());
        $this->post('/folio/extensions/counter', ['enabled' => '1']);
        $this->assertSame(['enabled' => ['counter' => ['disabled_parts' => []]]], $this->extensionsFile());
        $this->assertFileDoesNotExist($this->dir . '/installs.log');

        $this->assertSame(404, $this->post('/folio/extensions/ghost', ['enabled' => '1'])->getStatusCode());
        $this->assertSame(404, $this->post('/folio/extensions/counter', ['part' => 'screen:nope', 'enabled' => '0'])->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_allow_admin_changes_config_wins_over_debug(): void
    {
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Pub', 'debug' => 0, 'engine' => 'twig', 'key' => 'folio-integration-test-key'];\n");
        $this->write('config/folio.php', "<?php\nreturn ['path' => '/folio', 'extensions' => ['allow_admin_changes' => true]];\n");
        $this->counter([]);

        $this->assertStringContainsString('<form method="post" action="/folio/extensions/counter">', (string) $this->get('/folio/extensions')->getBody());
    }

    public function test_no_extensions_no_nav_item(): void
    {
        $this->assertStringNotContainsString('href="/folio/extensions"', (string) $this->get('/folio')->getBody());
    }
}
