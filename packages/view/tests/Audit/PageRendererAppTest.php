<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Audit;

require_once __DIR__ . '/Fixtures/AuditTestRoutes.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Debug\DebugCollector;
use Preflow\Core\Http\InternalRender;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Core\Http\Session\SessionMiddleware;
use Preflow\View\Audit\AuditRegistry;
use Preflow\View\Audit\PageRenderer;
use Preflow\View\Tests\Audit\Fixtures\AuditTestRoutes;

/** PageRenderer as the app binds it: renders through the full pipeline, production-like, from the CLI. */
final class PageRendererAppTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/audit_render_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/config', 0777, true);
        @mkdir($this->dir . '/app/pages', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>1,'engine'=>'twig','key'=>'audit-render-test-key-0123456789ab','url'=>'https://site.test'];\n");
        file_put_contents($this->dir . '/config/i18n.php', "<?php\nreturn ['default'=>'en','available'=>['en','de'],'fallback'=>'en','url_strategy'=>'prefix','cookie'=>true];\n");
        file_put_contents($this->dir . '/config/middleware.php', "<?php\nreturn [" . var_export(AuditTestRoutes::class, true) . "];\n");
        // A session on every path, started by the session middleware (not at boot).
        file_put_contents($this->dir . '/config/auth.php', "<?php\nreturn " . var_export([
            'session' => ['cookie' => 'audit_test', 'secure' => false, 'paths' => ['/']],
        ], true) . ";\n");
        file_put_contents(
            $this->dir . '/app/pages/about.twig',
            '<html><head><title>About {{ current_locale() }}</title></head><body><main><h1>About us</h1></main></body></html>',
        );
    }

    protected function tearDown(): void
    {
        AuditTestRoutes::$seen = null;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    #[RunInSeparateProcess]
    public function test_a_page_answers_200_with_its_body_and_locale(): void
    {
        $renderer = $this->app()->container()->get(PageRenderer::class);

        $page = $renderer->render('/about');
        $de = $renderer->render('/de/about');

        $this->assertSame(200, $page->status, $page->body);
        $this->assertSame('https://site.test/about', $page->url);
        $this->assertSame('en', $page->locale);
        $this->assertStringContainsString('<title>About en</title>', $page->body);
        $this->assertStringContainsString('<h1>About us</h1>', $page->body);
        $this->assertSame('About en', $page->dom()?->getElementsByTagName('title')->item(0)?->textContent);

        $this->assertSame(200, $de->status, $de->body);
        $this->assertSame('de', $de->locale);
        $this->assertStringContainsString('<title>About de</title>', $de->body);

        // One render after another: the second sees the same page, not what the first left behind.
        $this->assertSame($page->body, $renderer->render('/about')->body);
    }

    #[RunInSeparateProcess]
    public function test_a_throwing_route_gives_a_production_500_without_an_exception(): void
    {
        $page = $this->app()->container()->get(PageRenderer::class)->render('/boom');

        $this->assertSame(500, $page->status);
        $this->assertStringContainsString('Internal Server Error', $page->body);
        // app.debug is on, but the render gets the visitor's error page, not the debug one.
        $this->assertStringNotContainsString('Kaboom', $page->body);
        $this->assertStringNotContainsString('AuditTestRoutes', $page->body);
    }

    #[RunInSeparateProcess]
    public function test_the_request_carries_the_marker_and_gets_no_session(): void
    {
        $app = $this->app();

        $page = $app->container()->get(PageRenderer::class)->render('/seen');

        $this->assertSame(200, $page->status, $page->body);
        $this->assertNotNull(AuditTestRoutes::$seen);
        $this->assertTrue(InternalRender::is(AuditTestRoutes::$seen));
        $this->assertTrue(AuditTestRoutes::$seen->getAttribute(SessionMiddleware::WITHOUT_SESSION));
        // No session was started, so no session cookie (the locale cookie a first visit gets is the site's own).
        $this->assertNull(AuditTestRoutes::$seen->getAttribute(SessionInterface::class));
        $this->assertSame('', session_id());
        $this->assertStringNotContainsString('audit_test', $page->header('Set-Cookie'));

        // Proof the session middleware starts a session for a visitor's request.
        $app->handle((new Psr17Factory())->createServerRequest('GET', 'https://site.test/seen'));
        $this->assertFalse(InternalRender::is(AuditTestRoutes::$seen));
        $this->assertInstanceOf(SessionInterface::class, AuditTestRoutes::$seen->getAttribute(SessionInterface::class));
        $this->assertNotSame('', session_id());
    }

    #[RunInSeparateProcess]
    public function test_the_debug_toolbar_is_absent_with_app_debug_on(): void
    {
        $app = $this->app();

        $page = $app->container()->get(PageRenderer::class)->render('/about');

        $this->assertSame(200, $page->status, $page->body);
        $this->assertStringNotContainsString('preflow-debug-toolbar', $page->body);

        // Proof the toolbar is there for a visitor's request.
        $visitor = $app->handle((new Psr17Factory())->createServerRequest('GET', 'https://site.test/about'));
        $this->assertStringContainsString('preflow-debug-toolbar', (string) $visitor->getBody());
    }

    #[RunInSeparateProcess]
    public function test_the_debug_collector_starts_empty_for_each_render(): void
    {
        $container = $this->app()->container();
        $collector = $container->get(DebugCollector::class);
        $collector->logQuery('SELECT left_over', [], 1.0);

        $page = $container->get(PageRenderer::class)->render('/about');

        $this->assertSame(200, $page->status, $page->body);
        // Hundreds of renders in one process don't pile up in the collector.
        $this->assertNotContains('SELECT left_over', array_column($collector->getQueries(), 'sql'));
    }

    #[RunInSeparateProcess]
    public function test_the_registry_and_the_renderer_are_one_shared_instance_each(): void
    {
        $container = $this->app()->container();

        $this->assertSame($container->get(PageRenderer::class), $container->get(PageRenderer::class));

        $this->assertInstanceOf(AuditRegistry::class, $container->get(AuditRegistry::class));
        $this->assertSame($container->get(AuditRegistry::class), $container->get(AuditRegistry::class));
    }
}
