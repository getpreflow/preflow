<?php

declare(strict_types=1);

namespace Preflow\Examples\FolioDemo\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Psr\Http\Message\ResponseInterface;

/**
 * Smoke test for the shipped examples/folio-demo app: it must boot through the
 * real kernel and serve the Folio admin. This keeps the example from silently
 * rotting as the framework evolves. GET-only — it never writes records, so it is
 * independent of seed state.
 */
final class FolioDemoTest extends TestCase
{
    private function get(string $uri): ResponseInterface
    {
        $app = Application::create(dirname(__DIR__));
        $app->boot();

        return $app->handle((new Psr17Factory())->createServerRequest('GET', $uri));
    }

    /**
     * @runInSeparateProcess
     */
    public function test_admin_redirects_to_login_when_unauthenticated(): void
    {
        $res = $this->get('/folio');
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/login', $res->getHeaderLine('Location'));
    }

    /**
     * @runInSeparateProcess
     */
    public function test_login_page_renders(): void
    {
        $res = $this->get('/folio/login');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('name="email"', (string) $res->getBody());
    }

    /**
     * @runInSeparateProcess
     */
    public function test_stylesheet_route_serves_css(): void
    {
        $res = $this->get('/folio/_assets/admin.css');
        $this->assertSame(200, $res->getStatusCode());
    }

    /**
     * Seeds a temporary copy of the demo (the real storage stays untouched)
     * and serves the seeded German translation of the About page.
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function test_demo_serves_german_page(): void
    {
        $copy = sys_get_temp_dir() . '/folio_demo_' . bin2hex(random_bytes(4));
        foreach (['config', 'resources'] as $dir) {
            $this->copyDir(dirname(__DIR__) . '/' . $dir, $copy . '/' . $dir);
        }
        mkdir($copy . '/storage/data', 0777, true);

        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/seed.php') . ' ' . escapeshellarg($copy), $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));

        $app = Application::create($copy);
        $app->boot();
        $res = $app->handle((new Psr17Factory())->createServerRequest('GET', '/de/ueber-uns'));

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('Über uns', (string) $res->getBody());
    }

    private function copyDir(string $from, string $to): void
    {
        @mkdir($to, 0777, true);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
            $target = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
            $item->isDir() ? @mkdir($target, 0777, true) : copy($item->getPathname(), $target);
        }
    }

    /** Seeds a temporary copy and renders the Home page's hero block and the site singleton. */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function test_demo_renders_hero_block(): void
    {
        $copy = sys_get_temp_dir() . '/folio_demo_' . bin2hex(random_bytes(4));
        foreach (['config', 'resources'] as $dir) {
            $this->copyDir(dirname(__DIR__) . '/' . $dir, $copy . '/' . $dir);
        }
        mkdir($copy . '/storage/data', 0777, true);
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/seed.php') . ' ' . escapeshellarg($copy), $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));

        $app = Application::create($copy);
        $app->boot();
        $body = (string) $app->handle((new Psr17Factory())->createServerRequest('GET', '/'))->getBody();

        $this->assertStringContainsString('<h1>Pages built from blocks</h1>', $body);
        $this->assertStringContainsString('<a href="/about">About us</a>', $body);
        $this->assertStringContainsString('Folio Demo', $body);
    }

    /** The demo must not bcrypt the admin password on every boot (~200 ms each). */
    public function test_demo_auth_config_does_not_hash_on_every_boot(): void
    {
        $first = require dirname(__DIR__) . '/config/auth.php';
        $second = require dirname(__DIR__) . '/config/auth.php';
        $hash = $first['providers']['config']['users'][0]['password_hash'];

        $this->assertSame($hash, $second['providers']['config']['users'][0]['password_hash']);
        $this->assertTrue(password_verify('folio-admin', $hash));
    }
}
