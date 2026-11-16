<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Routing\Redirect\AreaMode;
use Preflow\Routing\Redirect\Redirect;
use Preflow\Routing\Redirect\RedirectStore;
use Psr\Http\Message\ResponseInterface;

/** `canonical_paths` in config/routing.php: 301 to the clean path, before the locale prefix is read. */
final class CanonicalPathsBootTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/canonical_paths_boot_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        mkdir($this->dir . '/app/pages', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'k','locale'=>'en'];\n");
        file_put_contents($this->dir . '/config/i18n.php', "<?php\nreturn ['default'=>'en','available'=>['en','de'],'url_strategy'=>'prefix'];\n");
        $page = '<html><head></head><body>locale={{ current_locale() }}</body></html>';
        file_put_contents($this->dir . '/app/pages/index.twig', $page);
        file_put_contents($this->dir . '/app/pages/about.twig', $page);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    private function routing(string $config): void
    {
        file_put_contents($this->dir . '/config/routing.php', "<?php\nreturn {$config};\n");
    }

    private function get(string $uri, string $method = 'GET'): ResponseInterface
    {
        $app = Application::create($this->dir);
        $app->boot();
        [$path, $query] = explode('?', $uri, 2) + [1 => ''];
        // Built from its parts: a URI string starting with '//' would read its first segment as a host.
        $url = (new Uri('http://localhost'))->withPath($path)->withQuery($query);

        return $app->handle((new Psr17Factory())->createServerRequest($method, $url));
    }

    public function test_canonical_paths_run_before_the_locale_prefix(): void
    {
        $this->routing("['canonical_paths' => true]");

        $response = $this->get('//de//about/?x=1');
        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/de/about?x=1', $response->getHeaderLine('Location'));

        $clean = $this->get('/de/about?x=1');
        $this->assertSame(200, $clean->getStatusCode());
        $this->assertStringContainsString('locale=de', (string) $clean->getBody());

        // A locale's home has one spelling, without the slash; the root stays '/'.
        $this->assertSame('/de', $this->get('/de/')->getHeaderLine('Location'));
        $this->assertSame(200, $this->get('/')->getStatusCode());
        // A POST is never redirected: it goes on to the app like the clean path's (CSRF refuses
        // both here, as the test sends no token).
        $post = $this->get('/about/', 'POST');
        $this->assertSame(403, $post->getStatusCode());
        $this->assertFalse($post->hasHeader('Location'));
        $this->assertSame(403, $this->get('/about', 'POST')->getStatusCode());
    }

    public function test_canonical_paths_off_by_default(): void
    {
        $this->assertSame(200, $this->get('/about/')->getStatusCode());

        $this->routing("['canonical_paths' => false]");
        $this->assertSame(200, $this->get('/about/')->getStatusCode());
    }

    public function test_canonical_paths_must_be_bool(): void
    {
        $this->routing("['canonical_paths' => 'yes']");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('config/routing.php: canonical_paths must be true or false, got string.');
        $this->get('/');
    }

    /** A store holding /old-help (German) and the area /old-area, counting its hits. */
    private function store(): RedirectStore
    {
        return new class implements RedirectStore {
            /** @var list<string> */
            public array $hits = [];

            public function exact(string $path, string $locale): ?Redirect
            {
                if ($path === '/about' && $locale === 'en') {
                    return new Redirect('c', '/about', 'en', false, AreaMode::One, null, null, '/elsewhere', 301);
                }

                return $path === '/old-help' && $locale === 'de'
                    ? new Redirect('a', '/old-help', 'de', false, AreaMode::One, null, null, '/de/help', 301)
                    : null;
            }

            public function areas(string $locale): array
            {
                return $locale === 'de' ? [new Redirect('b', '/old-area', 'de', true, AreaMode::Prefix, null, null, '/de/area', 302)] : [];
            }

            public function hit(Redirect $redirect, \DateTimeImmutable $at): void
            {
                $this->hits[] = $redirect->id;
            }
        };
    }

    public function test_an_untidy_address_with_an_entry_is_one_hop_with_a_store_bound_after_boot(): void
    {
        $this->routing("['canonical_paths' => true]");
        $app = Application::create($this->dir);
        $app->boot();
        // Bound after boot, the way Folio's provider does: resolved when the request comes in.
        $store = $this->store();
        $app->container()->instance(RedirectStore::class, $store);

        $one = $app->handle((new Psr17Factory())->createServerRequest('GET', 'http://localhost/de/old-help/?x=1'));
        $this->assertSame(301, $one->getStatusCode());
        $this->assertSame('/de/help?x=1', $one->getHeaderLine('Location'));
        $this->assertSame('public, max-age=86400', $one->getHeaderLine('Cache-Control'));

        $area = $app->handle((new Psr17Factory())->createServerRequest('GET', 'http://localhost/de/old-area/deep/'));
        $this->assertSame(302, $area->getStatusCode());
        $this->assertSame('/de/area/deep', $area->getHeaderLine('Location'));

        $this->assertSame(['a', 'b'], $store->hits);

        // A page answers at /about: it wins over the entry there, as it does for a 404.
        $page = $app->handle((new Psr17Factory())->createServerRequest('GET', 'http://localhost/about/'));
        $this->assertSame('/about', $page->getHeaderLine('Location'));
        $this->assertSame(['a', 'b'], $store->hits);

        // No entry: the plain clean 301, as before.
        $this->assertSame('/de/about', $app->handle((new Psr17Factory())->createServerRequest('GET', 'http://localhost/de/about/'))->getHeaderLine('Location'));
    }

    public function test_home_slash_with_canonical_paths(): void
    {
        file_put_contents($this->dir . '/config/i18n.php', "<?php\nreturn ['default'=>'en','available'=>['en','de'],'url_strategy'=>'prefix','home_slash'=>true];\n");
        $this->routing("['canonical_paths' => true]");

        $this->assertSame('/de/', $this->get('/de')->getHeaderLine('Location'));
        $this->assertSame(301, $this->get('/de')->getStatusCode());
        $this->assertSame(200, $this->get('/de/')->getStatusCode());
        $this->assertSame('/de/about', $this->get('/de/about/')->getHeaderLine('Location'));

        // Without canonical_paths both spellings answer.
        $this->routing("['canonical_paths' => false]");
        $this->assertSame(200, $this->get('/de')->getStatusCode());
        $this->assertSame(200, $this->get('/de/')->getStatusCode());
    }
}
