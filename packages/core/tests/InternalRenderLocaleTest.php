<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\I18n\Translator;
use Preflow\View\Audit\PageRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Since 0.75 an internal render starts in the site's default locale: on a site without a locale
 * middleware (no lang/, no config/i18n.php) nothing else sets it, and an audit step run from an
 * admin request whose interface is German rendered the pages in German.
 */
final class InternalRenderLocaleTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/internal_locale_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/config', 0777, true);
        @mkdir($this->dir . '/app/pages', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'internal-locale-test-key-0123456789','url'=>'https://site.test','locale'=>'en'];\n");
        file_put_contents($this->dir . '/config/middleware.php', "<?php\nreturn [" . var_export(GermanAdminStepRoute::class, true) . "];\n");
        @mkdir($this->dir . '/demo-lang/en', 0777, true);
        file_put_contents($this->dir . '/demo-lang/en/demo.php', "<?php\nreturn ['hi' => 'Hello visitor'];\n");
        file_put_contents($this->dir . '/app/pages/about.twig', '<html><head><title>About</title></head><body><main><h1>About {{ current_locale() }}</h1><p>{{ t(\'demo.hi\') }}</p></main></body></html>');
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    #[RunInSeparateProcess]
    public function test_an_audit_render_from_a_german_admin_request_renders_the_site_s_locale(): void
    {
        $app = Application::create($this->dir);
        $app->boot();
        $app->container()->get(Translator::class)->addDefaults('demo', $this->dir . '/demo-lang');

        $response = $app->handle((new Psr17Factory())->createServerRequest('GET', 'https://site.test/step'));

        $this->assertSame(
            '<h1>About en</h1><p>Hello visitor</p> after: de, overrides on',
            (string) $response->getBody(),
            'the page as visitors get it (site locale, no admin overrides); the admin request\'s state back afterwards',
        );
    }
}

/** /step: an admin request in German (as Folio's AdminLocaleMiddleware sets it) renders /about internally. */
final class GermanAdminStepRoute implements MiddlewareInterface
{
    public function __construct(private readonly \Preflow\Core\Container\Container $container) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getUri()->getPath() !== '/step') {
            return $handler->handle($request);
        }
        $translator = $this->container->get(Translator::class);
        $translator->setLocale('de');
        // Admin requests show the texts staff changed: an override for a key public pages render too.
        $translator->setOverrides(new class implements \Preflow\I18n\TranslationOverrides {
            public function get(string $locale, string $key): ?string
            {
                return $key === 'demo.hi' ? 'Admin override' : null;
            }
        });
        $translator->overridable('demo.');
        $translator->useOverrides(true);
        $page = $this->container->get(PageRenderer::class)->render('/about');
        preg_match('~<h1>.*?</p>~', $page->body, $main);

        return new Response(200, [], ($main[0] ?? $page->body) . ' after: ' . $translator->getLocale() . ', overrides ' . ($translator->usesOverrides() ? 'on' : 'off'));
    }
}
