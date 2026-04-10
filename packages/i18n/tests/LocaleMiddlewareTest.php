<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests;

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Preflow\I18n\LocaleMiddleware;
use Preflow\I18n\Translator;

final class LocaleMiddlewareTest extends TestCase
{
    private string $langDir;
    private Translator $translator;

    protected function setUp(): void
    {
        $this->langDir = sys_get_temp_dir() . '/preflow_locale_mw_' . uniqid();
        mkdir($this->langDir . '/en', 0755, true);
        mkdir($this->langDir . '/de', 0755, true);
        file_put_contents($this->langDir . '/en/app.php', '<?php return [];');
        file_put_contents($this->langDir . '/de/app.php', '<?php return [];');

        $this->translator = new Translator($this->langDir, 'en', 'en');
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->langDir);
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->deleteDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function createRequest(string $uri, array $headers = [], array $cookies = []): ServerRequestInterface
    {
        $request = (new Psr17Factory())->createServerRequest('GET', $uri);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        foreach ($cookies as $name => $value) {
            $request = $request->withCookieParams([$name => $value]);
        }
        return $request;
    }

    private function createHandler(?ServerRequestInterface &$captured = null): RequestHandlerInterface
    {
        return new class($captured) implements RequestHandlerInterface {
            public function __construct(private ?ServerRequestInterface &$captured) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->captured = $request;
                return new Response(200);
            }
        };
    }

    public function test_detects_locale_from_url_prefix(): void
    {
        $mw = new LocaleMiddleware($this->translator, ['en', 'de'], 'en', 'prefix');

        $captured = null;
        $mw->process($this->createRequest('/de/blog'), $this->createHandler($captured));

        $this->assertSame('de', $this->translator->getLocale());
    }

    public function test_strips_locale_prefix_from_uri(): void
    {
        $mw = new LocaleMiddleware($this->translator, ['en', 'de'], 'en', 'prefix');

        $captured = null;
        $mw->process($this->createRequest('/de/blog/post'), $this->createHandler($captured));

        $this->assertSame('/blog/post', $captured->getUri()->getPath());
    }

    public function test_no_prefix_uses_default(): void
    {
        $mw = new LocaleMiddleware($this->translator, ['en', 'de'], 'en', 'prefix');

        $captured = null;
        $mw->process($this->createRequest('/blog'), $this->createHandler($captured));

        $this->assertSame('en', $this->translator->getLocale());
        $this->assertSame('/blog', $captured->getUri()->getPath());
    }

    public function test_detects_locale_from_cookie(): void
    {
        $mw = new LocaleMiddleware($this->translator, ['en', 'de'], 'en', 'none');

        $request = $this->createRequest('/blog', cookies: ['locale' => 'de']);
        $captured = null;
        $mw->process($request, $this->createHandler($captured));

        $this->assertSame('de', $this->translator->getLocale());
    }

    public function test_detects_locale_from_accept_language(): void
    {
        $mw = new LocaleMiddleware($this->translator, ['en', 'de'], 'en', 'none');

        $request = $this->createRequest('/blog', headers: ['Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8']);
        $captured = null;
        $mw->process($request, $this->createHandler($captured));

        $this->assertSame('de', $this->translator->getLocale());
    }

    public function test_invalid_locale_uses_default(): void
    {
        $mw = new LocaleMiddleware($this->translator, ['en', 'de'], 'en', 'prefix');

        $captured = null;
        $mw->process($this->createRequest('/fr/blog'), $this->createHandler($captured));

        $this->assertSame('en', $this->translator->getLocale());
        // Invalid prefix not stripped
        $this->assertSame('/fr/blog', $captured->getUri()->getPath());
    }

    public function test_sets_locale_cookie_in_response(): void
    {
        $mw = new LocaleMiddleware($this->translator, ['en', 'de'], 'en', 'prefix');

        $captured = null;
        $response = $mw->process($this->createRequest('/de/blog'), $this->createHandler($captured));

        $this->assertTrue($response->hasHeader('Set-Cookie'));
        $cookie = $response->getHeaderLine('Set-Cookie');
        $this->assertStringContainsString('locale=de', $cookie);
    }

    public function test_root_url_with_prefix(): void
    {
        $mw = new LocaleMiddleware($this->translator, ['en', 'de'], 'en', 'prefix');

        $captured = null;
        $mw->process($this->createRequest('/de'), $this->createHandler($captured));

        $this->assertSame('de', $this->translator->getLocale());
        $this->assertSame('/', $captured->getUri()->getPath());
    }

    public function test_locale_attribute_set_on_request(): void
    {
        $mw = new LocaleMiddleware($this->translator, ['en', 'de'], 'en', 'prefix');

        $captured = null;
        $mw->process($this->createRequest('/de/page'), $this->createHandler($captured));

        $this->assertSame('de', $captured->getAttribute('locale'));
    }
}
