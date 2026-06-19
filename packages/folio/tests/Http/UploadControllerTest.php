<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Http\UploadController;

final class UploadControllerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/folio_up_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/2026/06', 0777, true);
        file_put_contents($this->dir . '/2026/06/pic.png', 'PNGDATA');
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/2026/06/pic.png');
        @rmdir($this->dir . '/2026/06');
        @rmdir($this->dir . '/2026');
        @rmdir($this->dir);
    }

    private function get(string $path)
    {
        return (new UploadController($this->dir))->serve(
            (new Psr17Factory())->createServerRequest('GET', '/folio/_uploads/' . $path)->withAttribute('path', $path),
        );
    }

    public function test_serves_file_with_content_type(): void
    {
        $res = $this->get('2026/06/pic.png');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('image/png', $res->getHeaderLine('Content-Type'));
        $this->assertSame('PNGDATA', (string) $res->getBody());
    }

    public function test_missing_file_404(): void
    {
        $this->assertSame(404, $this->get('2026/06/nope.png')->getStatusCode());
    }

    public function test_path_traversal_blocked(): void
    {
        // a traversal that resolves outside the uploads dir must 404, never read it
        $this->assertSame(404, $this->get('../../../../etc/hosts')->getStatusCode());
    }
}
