<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Http\SecurityHeadersMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SecurityHeadersMiddlewareTest extends TestCase
{
    private const NONCE = 'abc123';

    public function test_default_policy_is_strict_and_carries_nonce(): void
    {
        $csp = $this->process()->getHeaderLine('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString("script-src 'self' 'nonce-abc123'", $csp);
        $this->assertStringContainsString("style-src 'self' 'nonce-abc123'", $csp);
        $this->assertStringNotContainsString('unsafe-inline', $csp);
    }

    public function test_other_security_headers_are_sent(): void
    {
        $response = $this->process();

        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));
        $this->assertSame('camera=(), microphone=(), geolocation=()', $response->getHeaderLine('Permissions-Policy'));
    }

    public function test_config_overrides_merge_per_directive(): void
    {
        $csp = $this->process(config: ['csp' => ['img-src' => ["'self'", 'https://cdn.x']]])
            ->getHeaderLine('Content-Security-Policy');

        $this->assertStringContainsString("img-src 'self' https://cdn.x", $csp);
        $this->assertStringNotContainsString('data:', $csp);
        $this->assertStringContainsString("script-src 'self' 'nonce-abc123'", $csp);
    }

    public function test_null_header_value_removes_header(): void
    {
        $response = $this->process(config: ['headers' => ['Permissions-Policy' => null]]);

        $this->assertFalse($response->hasHeader('Permissions-Policy'));
        $this->assertTrue($response->hasHeader('X-Content-Type-Options'));
    }

    public function test_existing_header_is_not_overwritten(): void
    {
        $response = $this->process(preset: ['Content-Security-Policy' => "default-src 'none'"]);

        $this->assertSame("default-src 'none'", $response->getHeaderLine('Content-Security-Policy'));
    }

    public function test_hsts_only_on_https(): void
    {
        $this->assertFalse($this->process(uri: 'http://x.test/')->hasHeader('Strict-Transport-Security'));
        $this->assertSame(
            'max-age=31536000; includeSubDomains',
            $this->process(uri: 'https://x.test/')->getHeaderLine('Strict-Transport-Security'),
        );
        $this->assertTrue(
            $this->process(uri: 'http://x.test/', requestHeaders: ['X-Forwarded-Proto' => 'https'])
                ->hasHeader('Strict-Transport-Security'),
        );
    }

    public function test_report_only_mode(): void
    {
        $response = $this->process(reportOnly: true);

        $this->assertTrue($response->hasHeader('Content-Security-Policy-Report-Only'));
        $this->assertFalse($response->hasHeader('Content-Security-Policy'));

        $fromConfig = $this->process(config: ['report_only' => true]);
        $this->assertTrue($fromConfig->hasHeader('Content-Security-Policy-Report-Only'));
    }

    public function test_csp_nonce_function_matches_header(): void
    {
        $dir = sys_get_temp_dir() . '/csp_boot_' . bin2hex(random_bytes(4));
        mkdir($dir . '/config', 0777, true);
        mkdir($dir . '/app/pages', 0777, true);
        file_put_contents($dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'k'];\n");
        file_put_contents($dir . '/app/pages/index.twig', '<html><head></head><body><script nonce="{{ csp_nonce() }}"></script></body></html>');

        try {
            $app = Application::create($dir);
            $app->boot();
            $response = $app->handle((new Psr17Factory())->createServerRequest('GET', '/'));

            preg_match("/'nonce-([^']+)'/", $response->getHeaderLine('Content-Security-Policy'), $m);
            $this->assertNotEmpty($m[1] ?? '', 'CSP header carries a nonce');
            $this->assertStringContainsString('nonce="' . $m[1] . '"', (string) $response->getBody());
        } finally {
            @unlink($dir . '/app/pages/index.twig');
            @unlink($dir . '/config/app.php');
            @rmdir($dir . '/app/pages');
            @rmdir($dir . '/app');
            @rmdir($dir . '/config');
            @rmdir($dir);
        }
    }

    /**
     * @param array<string, mixed>  $config
     * @param array<string, string> $preset         headers the inner handler already set
     * @param array<string, string> $requestHeaders
     */
    private function process(
        array $config = [],
        bool $reportOnly = false,
        array $preset = [],
        string $uri = 'http://x.test/',
        array $requestHeaders = [],
    ): ResponseInterface {
        $request = (new Psr17Factory())->createServerRequest('GET', $uri);
        foreach ($requestHeaders as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $handler = new class ($preset) implements RequestHandlerInterface {
            /** @param array<string, string> $preset */
            public function __construct(private array $preset) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, $this->preset, 'ok');
            }
        };

        return (new SecurityHeadersMiddleware(fn () => self::NONCE, $config, $reportOnly))->process($request, $handler);
    }
}
