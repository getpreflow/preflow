<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Console\ExtensionsCheckCommand;

/**
 * The shipped examples/folio-demo "demo" extension: it passes folio:extensions:check, and
 * its screen, settings form, note panel and record action work in a signed-in admin.
 * Reads run against the real demo; the action runs on a copy (the demo's storage stays untouched).
 */
final class DemoExtensionTest extends TestCase
{
    private const DEMO = __DIR__ . '/../../../../examples/folio-demo';

    private string $copy = '';

    protected function tearDown(): void
    {
        if ($this->copy !== '') {
            exec('rm -rf ' . escapeshellarg($this->copy));
        }
    }

    #[RunInSeparateProcess]
    public function test_demo_extension_checks(): void
    {
        ob_start();
        $code = (new ExtensionsCheckCommand(self::DEMO))->execute([]);
        $out = (string) ob_get_clean();
        $this->assertSame(0, $code, $out);
        $this->assertSame("ok\n", $out);
    }

    #[RunInSeparateProcess]
    public function test_demo_extension_renders_screen_and_settings(): void
    {
        $app = Application::create(self::DEMO);
        $app->boot();
        $this->login($app);

        $screen = $app->handle((new Psr17Factory())->createServerRequest('GET', '/folio/demo'));
        $this->assertSame(200, $screen->getStatusCode());
        $this->assertStringContainsString('Hello from the demo extension', (string) $screen->getBody());
        $this->assertStringContainsString('<span>Demo overview</span>', (string) $screen->getBody());

        $settings = $app->handle((new Psr17Factory())->createServerRequest('GET', '/folio/demo_settings'));
        $this->assertSame(200, $settings->getStatusCode());
        $this->assertStringContainsString('value="Hello from the demo extension"', (string) $settings->getBody());
    }

    #[RunInSeparateProcess]
    public function test_note_panel_and_action(): void
    {
        $this->copy = sys_get_temp_dir() . '/folio_demo_ext_' . bin2hex(random_bytes(4));
        mkdir($this->copy, 0777, true);
        foreach (['config', 'resources', 'app'] as $dir) {
            exec('cp -R ' . escapeshellarg(self::DEMO . '/' . $dir) . ' ' . escapeshellarg($this->copy . '/' . $dir));
        }
        mkdir($this->copy . '/storage/data', 0777, true);

        $app = Application::create($this->copy);
        $app->boot();
        $dm = $app->container()->get(DataManager::class);
        $dm->saveType(DynamicRecord::fromArray($dm->getTypeRegistry()->get('note'), ['uuid' => 'n1', 'name' => 'Quiet']), false);
        $token = $this->login($app);

        $edit = (string) $app->handle((new Psr17Factory())->createServerRequest('GET', '/folio/note/n1/edit'))->getBody();
        $this->assertStringContainsString('5 characters', $edit);
        $this->assertStringContainsString('action="/folio/note/n1/_action/shout"', $edit);

        $res = $app->handle((new Psr17Factory())->createServerRequest('POST', '/folio/note/n1/_action/shout')->withParsedBody(['_csrf_token' => $token]));
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('QUIET', $dm->findType('note', 'n1')?->get('name'));
    }

    private function login(Application $app): string
    {
        $f = new Psr17Factory();
        $page = (string) $app->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $token = $m[1] ?? '';
        $login = $app->handle($f->createServerRequest('POST', '/folio/login')->withParsedBody([
            '_csrf_token' => $token, 'email' => 'admin@folio.test', 'password' => 'folio-admin',
        ]));
        $this->assertSame(302, $login->getStatusCode());

        return $token;
    }
}
