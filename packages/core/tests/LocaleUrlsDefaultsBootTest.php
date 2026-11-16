<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Http\LocaleUrls;
use Psr\Http\Message\ResponseInterface;

/**
 * Guard: a site that sets none of the locale URL options answers every URL as before
 * (default locale unprefixed, no redirects, no cookies, no Accept-Language with "prefix").
 */
final class LocaleUrlsDefaultsBootTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/locale_urls_defaults_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        mkdir($this->dir . '/app/pages', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'k','locale'=>'en'];\n");
        file_put_contents($this->dir . '/config/i18n.php', "<?php\nreturn ['default'=>'en','available'=>['en','de'],'url_strategy'=>'prefix'];\n");
        $page = '<html><head></head><body>locale={{ current_locale() }}|{{ locale_url(\'/about\', \'en\') }}|{{ locale_url(\'/about\', \'de\') }}|{{ locale_url(\'/\', \'de\') }}|{{ locale_url(\'/x\', \'fr\') }}</body></html>';
        file_put_contents($this->dir . '/app/pages/index.twig', $page);
        file_put_contents($this->dir . '/app/pages/about.twig', $page);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/app/pages/index.twig');
        @unlink($this->dir . '/app/pages/about.twig');
        @unlink($this->dir . '/config/app.php');
        @unlink($this->dir . '/config/i18n.php');
        @rmdir($this->dir . '/app/pages');
        @rmdir($this->dir . '/app');
        @rmdir($this->dir . '/config');
        @rmdir($this->dir);
    }

    public function test_defaults_keep_todays_urls(): void
    {
        $responses = [
            '/' => $root = $this->get('/'),
            '/de' => $de = $this->get('/de'),
            '/en/about' => $enAbout = $this->get('/en/about'),
            '/de/' => $deSlash = $this->get('/de/'),
            '/about/' => $slash = $this->get('/about/'),
            '/about (de)' => $accept = $this->get('/about', ['Accept-Language' => 'de']),
        ];

        $this->assertSame(200, $root->getStatusCode());
        $this->assertStringContainsString('locale=en|/about|/de/about|/de|/fr/x', (string) $root->getBody());
        $this->assertSame(200, $de->getStatusCode());
        $this->assertStringContainsString('locale=de|/about|/de/about|/de|/fr/x', (string) $de->getBody());
        $this->assertSame(200, $enAbout->getStatusCode());
        $this->assertStringContainsString('locale=en|/about|/de/about|/de|/fr/x', (string) $enAbout->getBody());
        $this->assertSame(200, $deSlash->getStatusCode());
        $this->assertStringContainsString('locale=de|/about|/de/about|/de|/fr/x', (string) $deSlash->getBody());
        $this->assertSame(200, $slash->getStatusCode());
        $this->assertSame(200, $accept->getStatusCode());
        $this->assertStringContainsString('locale=en|/about|/de/about|/de|/fr/x', (string) $accept->getBody());

        foreach ($responses as $uri => $response) {
            $this->assertFalse($response->hasHeader('Set-Cookie'), "{$uri} sets a cookie");
            $this->assertFalse($response->hasHeader('Location'), "{$uri} redirects");
        }

        $app = Application::create($this->dir);
        $app->boot();
        $urls = $app->container()->get(LocaleUrls::class);
        $this->assertSame('', $urls->prefix('en'));
        $this->assertSame('/de', $urls->prefix('de'));
        $this->assertFalse($urls->prefixDefault);
        $this->assertFalse($urls->redirectBare);
    }

    /** @param array<string, string> $headers */
    private function get(string $uri, array $headers = []): ResponseInterface
    {
        $app = Application::create($this->dir);
        $app->boot();

        $request = (new Psr17Factory())->createServerRequest('GET', $uri);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $app->handle($request);
    }
}
