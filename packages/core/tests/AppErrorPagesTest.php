<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Psr\Http\Message\ResponseInterface;

/**
 * An app's own error pages (0.66): app/pages/_errors/<status>.<ext>, else _errors/error.<ext>,
 * rendered through the app's engine and layout on a live site; else the built-in page.
 */
final class AppErrorPagesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/apperr_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        ini_set('error_log', $this->dir . '/php.log');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function write(string $rel, string $content): void
    {
        $path = $this->dir . '/' . $rel;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }

    private function site(int $debug = 0): void
    {
        $this->write('config/app.php', "<?php\nreturn ['name'=>'t','debug'=>{$debug},'engine'=>'twig','key'=>'app-err-test-key-0123456789abcdef'];\n");
        $this->write('app/pages/_layout.twig', '<html><head><title>{% block title %}{% endblock %}</title></head><body><nav>Site nav</nav>{% block body %}{% endblock %}</body></html>');
        $this->write('app/pages/index.twig', '{% extends "_layout.twig" %}{% block body %}Home{% endblock %}');
        $this->write('app/pages/boom.twig', '{% extends "_layout.twig" %}{% block body %}{{ no_such_function() }}{% endblock %}');
        $this->write('app/pages/_errors/404.twig', <<<'TWIG'
            {% extends "_layout.twig" %}
            {% block title %}{{ status }} {{ title }}{% endblock %}
            {% block body %}{% apply css %}.lost { color: teal; }{% endapply %}<h1 class="lost">Nothing at {{ request.path }}</h1><p>{{ message }}</p>{% endblock %}
            TWIG);
    }

    private function get(string $path, array $headers = []): ResponseInterface
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app->handle(new ServerRequest('GET', $path, $headers));
    }

    #[RunInSeparateProcess]
    public function test_a_404_renders_the_app_s_page_in_its_layout(): void
    {
        $this->site();

        $res = $this->get('/nothing-here');
        $html = (string) $res->getBody();

        $this->assertSame(404, $res->getStatusCode());
        $this->assertStringStartsWith('text/html', $res->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('<nav>Site nav</nav>', $html);
        $this->assertStringContainsString('<title>404 Not Found</title>', $html);
        $this->assertStringContainsString('Nothing at /nothing-here', $html);
        $this->assertStringContainsString('<p>Not Found</p>', $html, 'a 404 shows the status text, not the router\'s message');
        $this->assertStringContainsString('.lost', $html, 'the page\'s CSS is collected and put in the head');
        $this->assertStringContainsString('nonce=', $html);
    }

    #[RunInSeparateProcess]
    public function test_a_500_uses_the_catch_all_page_and_hides_the_exception(): void
    {
        $this->site();
        $this->write('app/pages/_errors/error.twig', '{% extends "_layout.twig" %}{% block body %}<h1>Oops {{ status }}</h1><p>{{ message }}</p>{% endblock %}');

        $res = $this->get('/boom');
        $html = (string) $res->getBody();

        $this->assertSame(500, $res->getStatusCode());
        $this->assertStringContainsString('<nav>Site nav</nav>', $html);
        $this->assertStringContainsString('<h1>Oops 500</h1>', $html);
        $this->assertStringContainsString('<p>Internal Server Error</p>', $html);
        $this->assertStringNotContainsString('no_such_function', $html);
    }

    #[RunInSeparateProcess]
    public function test_without_a_page_for_the_status_the_built_in_page_answers(): void
    {
        $this->site();

        $res = $this->get('/boom');

        $this->assertSame(500, $res->getStatusCode());
        $this->assertStringContainsString('<div class="code">500</div>', (string) $res->getBody());
    }

    #[RunInSeparateProcess]
    public function test_a_broken_error_page_falls_back_to_the_built_in_page(): void
    {
        $this->site();
        $this->write('app/pages/_errors/404.twig', '{% extends "_layout.twig" %}{% block body %}{{ broken_helper() }}{% endblock %}');

        $res = $this->get('/nothing-here');
        $html = (string) $res->getBody();

        $this->assertSame(404, $res->getStatusCode());
        $this->assertStringContainsString('<div class="code">404</div>', $html);
        $this->assertStringContainsString('Not Found', $html);
    }

    #[RunInSeparateProcess]
    public function test_development_keeps_the_detailed_page(): void
    {
        $this->site(debug: 1);

        $html = (string) $this->get('/nothing-here')->getBody();

        $this->assertStringNotContainsString('Nothing at', $html);
        $this->assertStringContainsString('No route matches', $html);
    }

    #[RunInSeparateProcess]
    public function test_api_paths_keep_json(): void
    {
        $this->site();

        $res = $this->get('/api/nothing');

        $this->assertSame(404, $res->getStatusCode());
        $this->assertStringStartsWith('application/json', $res->getHeaderLine('Content-Type'));
        $this->assertStringNotContainsString('Nothing at', (string) $res->getBody());
    }

    #[RunInSeparateProcess]
    public function test_a_blade_app_s_page_renders_through_blade(): void
    {
        $this->write('config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'blade','key'=>'app-err-test-key-0123456789abcdef'];\n");
        $this->write('app/pages/index.blade.php', 'Home');
        $this->write('app/pages/_errors/404.blade.php', '<html><head></head><body>Lost: {{ $title }} ({{ $status }})</body></html>');

        $res = $this->get('/nothing-here');

        $this->assertSame(404, $res->getStatusCode());
        $this->assertStringContainsString('Lost: Not Found (404)', (string) $res->getBody());
    }
}
