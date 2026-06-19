<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Psr\Http\Message\ResponseInterface;

/**
 * End-to-end coverage for Folio booted inside a real Preflow application:
 * the service provider wires routes + the @folio template namespace, content
 * types are discovered from config/models, and admin CRUD + frontend rendering
 * work through the real kernel and real Twig.
 *
 * Debug is enabled so Twig runs with strict_variables — this is what catches
 * template defects (e.g. missing-key access on a new record's empty values).
 *
 * No config/auth.php is scaffolded, so no session is started and CsrfMiddleware
 * is a no-op; the POST therefore needs no CSRF token. (CSRF token emission is
 * covered separately and exercised by the example app.)
 */
final class FolioAppTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        putenv('APP_DEBUG=1'); // -> Twig strict_variables on
        $this->dir = sys_get_temp_dir() . '/folio_app_' . bin2hex(random_bytes(5));
        $this->scaffold();
    }

    protected function tearDown(): void
    {
        putenv('APP_DEBUG');
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $this->rrmdir($this->dir);
    }

    public function test_admin_dashboard_lists_discovered_type(): void
    {
        $res = $this->get('/folio');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('Pages', (string) $res->getBody()); // the type label
    }

    public function test_dashboard_renders_type_card_with_count(): void
    {
        // Seed one record so the count is non-zero and deterministic.
        $app = $this->app();
        $app->handle((new Psr17Factory())->createServerRequest('POST', '/folio/page')
            ->withParsedBody(['title' => 'Seed', 'slug' => 'seed', 'body' => 'B', 'status' => 'published']));

        $body = (string) $app->handle((new Psr17Factory())->createServerRequest('GET', '/folio'))->getBody();
        $this->assertStringContainsString('folio-card-grid', $body);
        $this->assertStringContainsString('folio-card-label', $body);
        $this->assertStringContainsString('Pages', $body);
        $this->assertStringContainsString('1 record', $body);
    }

    public function test_create_form_renders_under_strict_twig(): void
    {
        // New-record form passes an empty values map; the template must guard
        // missing-key access or it throws a Twig RuntimeError under strict mode.
        $res = $this->get('/folio/page/new');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('name="title"', (string) $res->getBody());
    }

    public function test_create_then_render_on_frontend(): void
    {
        $app = $this->app();

        $create = $app->handle(
            (new Psr17Factory())->createServerRequest('POST', '/folio/page')
                ->withParsedBody([
                    'title'  => 'About Us',
                    'slug'   => 'about',
                    'body'   => '<p>Hello from Folio.</p>',
                    'status' => 'published',
                ])
        );
        $this->assertSame(302, $create->getStatusCode());

        $page = $app->handle((new Psr17Factory())->createServerRequest('GET', '/about'));
        $this->assertSame(200, $page->getStatusCode());
        $this->assertStringContainsString('About Us', (string) $page->getBody());
    }

    public function test_admin_shell_renders_sidebar_stylesheet_and_toggle(): void
    {
        $body = (string) $this->get('/folio')->getBody();
        $this->assertStringContainsString('class="folio-shell"', $body);
        $this->assertStringContainsString('class="folio-sidebar"', $body);
        $this->assertStringContainsString('/folio/_assets/admin.css?v=', $body); // versioned link
        $this->assertStringContainsString('id="folio-theme-toggle"', $body);
        $this->assertStringContainsString("localStorage.getItem('folio-theme')", $body); // no-flash script
    }

    public function test_admin_stylesheet_is_served(): void
    {
        $res = $this->get('/folio/_assets/admin.css');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('text/css', $res->getHeaderLine('Content-Type'));
        $this->assertStringContainsString(':root', (string) $res->getBody());
        $this->assertStringContainsString('--c-accent', (string) $res->getBody());
    }

    public function test_unknown_slug_is_404(): void
    {
        $this->assertSame(404, $this->get('/no-such-page')->getStatusCode());
    }

    public function test_app_route_beats_folio_catch_all(): void
    {
        // app/pages/index.twig serves '/'; Folio's catch-all must not shadow it.
        $res = $this->get('/');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('HOME PAGE', (string) $res->getBody());
    }

    public function test_list_shows_empty_state_when_no_records(): void
    {
        $body = (string) $this->get('/folio/page')->getBody();
        $this->assertStringContainsString('folio-empty', $body);
        $this->assertStringContainsString('No records yet', $body);
    }

    public function test_list_shows_row_with_edit_and_delete_actions(): void
    {
        $app = $this->app();
        $app->handle((new Psr17Factory())->createServerRequest('POST', '/folio/page')
            ->withParsedBody(['title' => 'Listed', 'slug' => 'listed', 'body' => 'B', 'status' => 'published']));

        $body = (string) $app->handle((new Psr17Factory())->createServerRequest('GET', '/folio/page'))->getBody();
        $this->assertStringContainsString('folio-table', $body);
        $this->assertStringContainsString('Listed', $body);
        $this->assertStringContainsString('/edit', $body);
        $this->assertStringContainsString('/delete', $body);
    }

    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();
        return $app;
    }

    private function get(string $uri): ResponseInterface
    {
        return $this->app()->handle((new Psr17Factory())->createServerRequest('GET', $uri));
    }

    private function scaffold(): void
    {
        $w = function (string $rel, string $content): void {
            $path = $this->dir . '/' . $rel;
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, $content);
        };

        $w('config/app.php', <<<'PHP'
            <?php
            return [
                'name'   => 'Folio Test',
                'debug'  => (int) (getenv('APP_DEBUG') ?: 0),
                'engine' => 'twig',
                'key'    => 'folio-integration-test-key',
            ];
            PHP);

        $w('config/data.php', <<<'PHP'
            <?php
            return [
                'drivers' => [
                    'json' => [
                        'driver' => \Preflow\Data\Driver\JsonFileDriver::class,
                        'path'   => __DIR__ . '/../storage/data',
                    ],
                ],
                'default'     => 'json',
                'models_path' => __DIR__ . '/models',
            ];
            PHP);

        $w('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class];\n");
        $w('config/folio.php', "<?php\nreturn ['path' => '/folio'];\n");

        $w('config/models/page.json', json_encode([
            'key'      => 'page',
            'table'    => 'page',
            'storage'  => 'json',
            'id_field' => 'uuid',
            'label'    => 'Pages',
            'fields'   => [
                'title'  => ['type' => 'string', 'searchable' => true, 'validate' => ['required']],
                'slug'   => ['type' => 'string', 'searchable' => true, 'validate' => ['required']],
                'body'   => ['type' => 'text'],
                'status' => ['type' => 'string', 'validate' => ['required', 'in:draft,published']],
            ],
        ], JSON_PRETTY_PRINT));

        $w('app/pages/index.twig', 'HOME PAGE');
        @mkdir($this->dir . '/storage/data', 0777, true);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
