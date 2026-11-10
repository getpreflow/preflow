<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Psr\Http\Message\ResponseInterface;

/** Controllers render page templates through PageResponder, through a whole booted app. */
final class PageResponderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pageresp_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        unset($_SESSION);
        // What the page cache logs (no site logger here) goes to a file, not the test's output.
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

    private function site(int $debug = 0, bool $locales = false): void
    {
        if ($locales) {
            $this->write('config/i18n.php', "<?php\nreturn ['default'=>'en','available'=>['en','de'],'fallback'=>'en','url_strategy'=>'prefix'];\n");
        }
        $this->write('config/app.php', "<?php\nreturn ['name'=>'t','debug'=>{$debug},'engine'=>'twig','key'=>'page-resp-test-key-0123456789abc','url'=>'https://example.com','locale'=>'en'];\n");
        $this->write('config/data.php', "<?php\nreturn ['default'=>'json','drivers'=>['json'=>['path'=>'{$this->dir}/storage/data']]];\n");
        $this->write('config/page_cache.php', "<?php return ['enabled' => true, 'explain' => true, 'verify' => 'off'];");
        $this->write('app/pages/_views/layout.twig', '<html><head><title>T</title></head><body>{% block body %}{% endblock %}</body></html>');
        $this->write('app/pages/_views/t.twig', <<<'TWIG'
            {% extends "_views/layout.twig" %}
            {% block body %}{% apply css %}.t-box { color: red; }{% endapply %}<p class="t-box">{{ title ?? 'none' }}|{{ request.path }}|{{ request.query.q ?? '' }}|{{ request.method }}</p>{% endblock %}
            TWIG);
        $this->write('app/Controllers/TestPageController.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Controllers;

            use Preflow\Routing\Attributes\Get;
            use Preflow\Routing\Attributes\Route;
            use Preflow\View\PageResponder;
            use Psr\Http\Message\ResponseInterface;

            #[Route('/')]
            final class TestPageController
            {
                public function __construct(private readonly PageResponder $pages) {}

                #[Get('ok')]
                public function ok(): ResponseInterface
                {
                    return $this->pages->render('_views/t.twig', ['title' => 'Hello']);
                }

                #[Get('missing')]
                public function missing(): ResponseInterface
                {
                    return $this->pages->render('_views/t.twig', ['title' => 'Gone'], 404, ['X-Test' => 'yes']);
                }
            }
            PHP);
        $dir = $this->dir;
        spl_autoload_register(static function (string $class) use ($dir): void {
            if ($class === 'App\\Controllers\\TestPageController') {
                require $dir . '/app/Controllers/TestPageController.php';
            }
        });
    }

    private function get(string $path): ResponseInterface
    {
        $app = Application::create($this->dir);
        $app->setRunningInConsole(false);
        $app->boot();

        return $app->handle(new ServerRequest('GET', 'https://example.com' . $path));
    }

    #[RunInSeparateProcess]
    public function test_controller_page_renders_with_layout_and_assets(): void
    {
        $this->site();
        $response = $this->get('/ok');
        $html = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/html; charset=UTF-8', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('<title>T</title>', $html);
        $this->assertMatchesRegularExpression('/<head>.*\.t-box.*<\/head>/s', $html);
    }

    #[RunInSeparateProcess]
    public function test_status_404_is_kept(): void
    {
        $this->site();
        $response = $this->get('/missing');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('yes', $response->getHeaderLine('X-Test'));
        $this->assertStringContainsString('Gone', (string) $response->getBody());
        $this->assertStringContainsString('<title>T</title>', (string) $response->getBody());
    }

    #[RunInSeparateProcess]
    public function test_request_context_is_available(): void
    {
        $this->site();
        $html = (string) $this->get('/ok?q=chairs')->getBody();

        $this->assertStringContainsString('Hello|/ok|chairs|GET', $html);
    }

    #[RunInSeparateProcess]
    public function test_anonymous_controller_page_is_page_cached(): void
    {
        $this->site();
        $app = Application::create($this->dir);
        $app->setRunningInConsole(false);
        $app->boot();

        $first = $app->handle(new ServerRequest('GET', 'https://example.com/ok'));
        $second = $app->handle(new ServerRequest('GET', 'https://example.com/ok'));

        $this->assertSame('miss', $first->getHeaderLine('X-Page-Cache'));
        $this->assertSame('hit', $second->getHeaderLine('X-Page-Cache'));
        $this->assertSame((string) $first->getBody(), (string) $second->getBody());
    }

    #[RunInSeparateProcess]
    public function test_request_path_has_no_locale_prefix_on_a_controller_page_in_debug(): void
    {
        $this->site(debug: 1, locales: true);
        $html = (string) $this->get('/de/ok?q=x')->getBody();

        $this->assertStringContainsString('Hello|/ok|x|GET', $html);
    }

    #[RunInSeparateProcess]
    public function test_request_path_has_no_locale_prefix_in_a_page_cache_rebuild(): void
    {
        $this->site(locales: true);
        $app = Application::create($this->dir);
        $app->setRunningInConsole(true);
        $app->boot();

        $response = $app->handle(\Preflow\Core\PageCache\Rebuild::request('https://example.com/de/ok'));

        $this->assertStringContainsString('Hello|/ok||GET', (string) $response->getBody());
    }

    #[RunInSeparateProcess]
    public function test_request_path_has_no_locale_prefix_in_an_audit_render(): void
    {
        $this->site(locales: true);
        $app = Application::create($this->dir);
        $app->boot();

        $page = $app->container()->get(\Preflow\View\Audit\PageRenderer::class)->render('/de/ok');

        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('Hello|/ok||GET', $page->body);
    }

    #[RunInSeparateProcess]
    public function test_request_path_has_no_locale_prefix_on_a_normal_production_request(): void
    {
        $this->site(locales: true);
        $html = (string) $this->get('/de/ok')->getBody();

        $this->assertStringContainsString('Hello|/ok||GET', $html);
    }
}
