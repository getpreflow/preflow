<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
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
        // A POST is never redirected.
        $this->assertNotSame(301, $this->get('/about/', 'POST')->getStatusCode());
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
}
