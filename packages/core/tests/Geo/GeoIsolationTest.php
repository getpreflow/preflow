<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Geo;

require_once __DIR__ . '/../Fixtures/Geo/GeoEntriesProvider.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Http\RequestContext;
use Preflow\Core\PageCache\PageCacheConfig;
use Preflow\Core\PageCache\PageKey;
use Preflow\Core\PageCache\PageStore;
use Preflow\Core\Tests\Fixtures\Geo\GeoEntriesProvider;
use Preflow\View\Geo\GeoEntry;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;
use Preflow\Testing\ChecksFrameBalance;

/**
 * GEO's Markdown views and llms-full.txt render their pages through Application::render() as
 * GEO renders: an anonymous visitor's clean GET that sees nothing of the visitor (neither PHP's
 * superglobals nor the address) and is never stored in the page cache.
 */
final class GeoIsolationTest extends TestCase
{
    use ChecksFrameBalance;

    private string $dir;

    protected function setUp(): void
    {
        Contexts::reset();
        $this->dir = sys_get_temp_dir() . '/geo_isolation_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/config', 0777, true);
        @mkdir($this->dir . '/app/pages', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'Geo','debug'=>0,'engine'=>'twig','key'=>'geo-isolation-test-key-0123456789ab','url'=>'https://site.test'];\n");
        file_put_contents($this->dir . '/config/geo.php', "<?php\nreturn ['enabled' => true, 'name' => 'Geo'];\n");
        file_put_contents($this->dir . '/config/providers.php', "<?php\nreturn [" . var_export(GeoEntriesProvider::class, true) . ', ' . var_export(GeoProbeProvider::class, true) . "];\n");
        file_put_contents(
            $this->dir . '/app/pages/about.twig',
            '<html><head><title>About</title></head><body><main><p>cookie={{ cookie_global(\'m\') }}; address={{ remote_global() }}; request={{ request_address() }}; purpose={{ render_purpose() }};</p></main></body></html>',
        );
        GeoEntriesProvider::$source = static fn (string $locale): array => [new GeoEntry('About', '/about')];
        GeoEntriesProvider::$site = null;
        ini_set('error_log', $this->dir . '/php.log');
    }

    protected function tearDown(): void
    {
        Contexts::reset();
        GeoEntriesProvider::$source = null;
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[RunInSeparateProcess]
    public function test_geo_markdown_render_never_sees_the_visitors_superglobals_or_address(): void
    {
        $app = Application::create($this->dir);
        $app->boot();
        $_COOKIE['m'] = 'visitor';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $request = (new Psr17Factory())->createServerRequest('GET', 'https://site.test/about.md', ['REMOTE_ADDR' => '203.0.113.9'])
            ->withCookieParams(['m' => 'visitor'])
            ->withHeader('Cookie', 'm=visitor');

        $markdown = (string) $app->handle($request)->getBody();

        $this->assertStringContainsString('purpose=geo;', $markdown, $markdown);
        $this->assertStringContainsString('cookie=;', $markdown);
        $this->assertStringNotContainsString('203.0.113.9', $markdown, 'neither PHP\'s address nor the request\'s is the visitor\'s');
        $this->assertStringContainsString('address=127.0.0.1;', $markdown);
        $this->assertSame('visitor', $_COOKIE['m'], 'the visitor\'s superglobals are back afterwards');
        $this->assertSame('203.0.113.9', $_SERVER['REMOTE_ADDR']);
    }

    #[RunInSeparateProcess]
    public function test_llms_full_sub_renders_are_geo_and_never_stored(): void
    {
        file_put_contents($this->dir . '/config/geo.php', "<?php\nreturn ['enabled' => true, 'name' => 'Geo', 'full' => true];\n");
        file_put_contents($this->dir . '/config/page_cache.php', "<?php\nreturn ['enabled' => true, 'explain' => true, 'verify' => 'off'];\n");
        $app = Application::create($this->dir);
        $app->setRunningInConsole(false);
        $app->boot();

        $body = (string) $app->handle((new Psr17Factory())->createServerRequest('GET', 'https://site.test/llms-full.txt'))->getBody();

        $this->assertStringContainsString('purpose=geo;', $body, $body);
        $store = new PageStore($this->dir . '/storage/cache/pages', PageStore::releaseKey($this->dir));
        $key = PageKey::fromRequest((new Psr17Factory())->createServerRequest('GET', 'https://site.test/about'), $app->container()->get(PageCacheConfig::class));
        $this->assertNull($store->get($key), 'a GEO render is never stored');
        // A visitor's request is stored as before.
        $this->assertSame('miss', $app->handle((new Psr17Factory())->createServerRequest('GET', 'https://site.test/about'))->getHeaderLine('X-Page-Cache'));
        $this->assertNotNull($store->get($key));
    }
}

/** What the page reads of the visitor: PHP's cookie and address, the request's address, and the render's purpose. */
final class GeoProbeProvider extends ServiceProvider
{
    public function register(Container $container): void {}

    public function boot(Container $container): void
    {
        $engine = $container->get(TemplateEngineInterface::class);
        $engine->addFunction(new TemplateFunctionDefinition(
            name: 'cookie_global',
            callable: static fn (string $name): string => is_string($_COOKIE[$name] ?? null) ? $_COOKIE[$name] : '',
        ));
        $engine->addFunction(new TemplateFunctionDefinition(
            name: 'remote_global',
            callable: static fn (): string => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        ));
        $engine->addFunction(new TemplateFunctionDefinition(
            name: 'request_address',
            callable: static fn (): string => (string) ($container->get(RequestContext::class)?->request?->getServerParams()['REMOTE_ADDR'] ?? ''),
        ));
        $engine->addFunction(new TemplateFunctionDefinition(
            name: 'render_purpose',
            callable: static fn (): string => Contexts::current()->purpose->value,
        ));
    }
}
