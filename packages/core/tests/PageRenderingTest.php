<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

require_once __DIR__ . '/Fixtures/Rendering/RenderingComponents.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Tests\Fixtures\Rendering\RenderingBroken;
use Preflow\Core\Tests\Fixtures\Rendering\RenderingOuter;
use Psr\Http\Message\ResponseInterface;

/** File pages through the whole app: Blade pages, inline JS, HTTP errors thrown by components. */
final class PageRenderingTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/page_rendering_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        mkdir($this->dir . '/app/pages/blog', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function app(string $engine, int $debug = 0): Application
    {
        file_put_contents(
            $this->dir . '/config/app.php',
            "<?php\nreturn ['name'=>'t','debug'=>{$debug},'engine'=>'{$engine}','key'=>'page-rendering-test-key-0123456789'];\n",
        );
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    private function page(string $file, string $source): void
    {
        file_put_contents($this->dir . '/app/pages/' . $file, $source);
    }

    private function get(Application $app, string $path): ResponseInterface
    {
        return $app->handle((new Psr17Factory())->createServerRequest('GET', 'http://localhost' . $path));
    }

    #[RunInSeparateProcess]
    public function test_blade_file_pages_render(): void
    {
        $this->page('_layout.blade.php', '<html><head><title>@yield(\'title\')</title></head><body>@yield(\'content\')</body></html>');
        $this->page('about.blade.php', "@extends('_layout')\n@section('title')About @endsection\n@section('content')<h1>About us</h1>@endsection");
        $this->page('index.blade.php', '<html><body>Home</body></html>');
        $this->page('blog/[slug].blade.php', '<html><body>Post {{ $route->slug }}</body></html>');
        $app = $this->app('blade');

        $about = $this->get($app, '/about');
        $this->assertSame(200, $about->getStatusCode(), (string) $about->getBody());
        $this->assertStringContainsString('<h1>About us</h1>', (string) $about->getBody());
        $this->assertStringContainsString('Home', (string) $this->get($app, '/')->getBody());
        $this->assertStringContainsString('Post first', (string) $this->get($app, '/blog/first')->getBody());
    }

    #[RunInSeparateProcess]
    public function test_inline_js_is_printed_on_full_pages(): void
    {
        $this->page('index.twig', '<html><head>{{ head() }}</head><body><p>Home</p>'
            . "{% apply js('inline') %}window.inlinePage = 1;{% endapply %}{% apply js %}window.bodyPage = 1;{% endapply %}"
            . '{{ assets() }}</body></html>');
        $this->page('bare.twig', "<html><head></head><body><p>Bare</p>{% apply js('inline') %}window.bare = 1;{% endapply %}</body></html>");
        $app = $this->app('twig');

        $html = (string) $this->get($app, '/')->getBody();
        $this->assertSame(1, substr_count($html, 'window.inlinePage=1'), $html);
        $this->assertLessThan(strrpos($html, '</body>'), strpos($html, 'window.inlinePage=1'));
        $this->assertLessThan(strpos($html, 'window.inlinePage=1'), strpos($html, 'window.bodyPage=1'));

        // (Minified in production.) A page without {{ assets() }}: the injected assets carry it.
        $bare = (string) $this->get($app, '/bare')->getBody();
        $this->assertSame(1, substr_count($bare, 'window.bare=1'), $bare);
    }

    #[RunInSeparateProcess]
    public function test_an_http_exception_in_a_nested_component_answers_the_page_with_its_status(): void
    {
        $outer = addslashes(RenderingOuter::class);
        $broken = addslashes(RenderingBroken::class);
        $this->page('_layout.twig', '<html><head></head><body>{% block content %}{% endblock %}</body></html>');
        $this->page('staff.twig', '{% extends "_layout.twig" %}{% block content %}<main>{{ component("' . $outer . '") }}</main>{% endblock %}');
        $this->page('broken.twig', '<html><body><main>{{ component("' . $broken . '") }}</main></body></html>');

        $response = $this->get($this->app('twig'), '/staff');
        $body = (string) $response->getBody();

        // Production: the status, and only its status text.
        $this->assertSame(403, $response->getStatusCode(), $body);
        $this->assertStringContainsString('Forbidden', $body);
        $this->assertStringNotContainsString('Only staff', $body);
        $this->assertStringNotContainsString('Secret', $body);
        $this->assertStringNotContainsString('Fallback shown', $body);
        $this->assertStringNotContainsString('RuntimeError', $body);

        // Any other failure keeps the boundary: the page answers 200 with the fallback.
        $broken = $this->get($this->app('twig'), '/broken');
        $this->assertSame(200, $broken->getStatusCode());
        $this->assertStringContainsString('Fallback shown', (string) $broken->getBody());
    }

    #[RunInSeparateProcess]
    public function test_debug_mode_shows_the_components_http_exception(): void
    {
        $outer = addslashes(RenderingOuter::class);
        $this->page('staff.twig', '<html><body>{{ component("' . $outer . '") }}</body></html>');

        $response = $this->get($this->app('twig', 1), '/staff');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('Only staff', (string) $response->getBody());
    }
}
