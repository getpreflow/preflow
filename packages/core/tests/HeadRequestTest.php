<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;

/** HEAD is answered like GET (status, headers) but without a body. */
final class HeadRequestTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/head_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        mkdir($this->dir . '/app/pages', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'head-test-key-0123456789abcdef'];\n");
        file_put_contents($this->dir . '/app/pages/index.twig', '<html><body><h1>Hello</h1></body></html>');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function handle(string $method, string $uri): \Psr\Http\Message\ResponseInterface
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app->handle((new Psr17Factory())->createServerRequest($method, $uri));
    }

    public function test_head_returns_get_status_and_headers_without_body(): void
    {
        $get = $this->handle('GET', '/');
        $head = $this->handle('HEAD', '/');

        $this->assertSame(200, $get->getStatusCode());
        $this->assertStringContainsString('Hello', (string) $get->getBody());
        $this->assertSame(200, $head->getStatusCode());
        $strip = static fn (array $h): array => array_diff_key(array_change_key_case($h), ['content-security-policy' => 1, 'content-security-policy-report-only' => 1]);
        $this->assertSame($strip($get->getHeaders()), $strip($head->getHeaders())); // only the per-request nonce differs
        $this->assertSame('', (string) $head->getBody());
    }

    public function test_head_on_unknown_path_is_404_without_body(): void
    {
        $head = $this->handle('HEAD', '/nope');

        $this->assertSame(404, $head->getStatusCode());
        $this->assertSame('', (string) $head->getBody());
    }
}
