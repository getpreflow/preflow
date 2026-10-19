<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Psr\Http\Message\ResponseInterface;

final class HtmxIncludeModeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/htmx_mode_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        mkdir($this->dir . '/app/pages', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'k'];\n");
        file_put_contents($this->dir . '/app/pages/index.twig', '<html><head></head><body>plain</body></html>');
        file_put_contents($this->dir . '/app/pages/uses.twig', '<html><head></head><body>{{ hd.require() }}uses</body></html>');
    }

    protected function tearDown(): void
    {
        foreach (['app/pages/index.twig', 'app/pages/uses.twig', 'config/app.php', 'config/htmx.php'] as $file) {
            @unlink($this->dir . '/' . $file);
        }
        @rmdir($this->dir . '/app/pages');
        @rmdir($this->dir . '/app');
        @rmdir($this->dir . '/config');
        @rmdir($this->dir);
    }

    public function test_always_mode_is_default_without_config(): void
    {
        $body = (string) $this->get('/')->getBody();

        $this->assertStringContainsString('<meta name="htmx-config"', $body);
        $this->assertStringNotContainsString('unpkg.com', $body);
    }

    public function test_on_demand_includes_only_when_used(): void
    {
        $this->mode('on-demand');

        $this->assertStringNotContainsString('<meta name="htmx-config"', (string) $this->get('/')->getBody());
        $this->assertStringContainsString('<meta name="htmx-config"', (string) $this->get('/uses')->getBody());
    }

    public function test_never_mode(): void
    {
        $this->mode('never');

        $this->assertStringNotContainsString('<meta name="htmx-config"', (string) $this->get('/uses')->getBody());
    }

    public function test_fragment_contract_uses_page_nonce(): void
    {
        $this->mode('on-demand');
        $response = $this->get('/uses');

        preg_match('/"inlineScriptNonce":"([^"]+)"/', (string) $response->getBody(), $config);
        preg_match("/'nonce-([^']+)'/", $response->getHeaderLine('Content-Security-Policy'), $header);

        $this->assertNotEmpty($config[1] ?? '');
        $this->assertSame($header[1] ?? null, $config[1]);
    }

    private function mode(string $mode): void
    {
        file_put_contents($this->dir . '/config/htmx.php', "<?php\nreturn ['include' => '{$mode}'];\n");
    }

    private function get(string $uri): ResponseInterface
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app->handle((new Psr17Factory())->createServerRequest('GET', $uri));
    }
}
