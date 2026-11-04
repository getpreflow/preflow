<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

require_once __DIR__ . '/Fixtures/RedirectStoreProvider.php';
require_once __DIR__ . '/Fixtures/LegacyPageMiddleware.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Http\NonPublicPaths;
use Preflow\Core\Tests\Fixtures\LegacyPageMiddleware;
use Preflow\Core\Tests\Fixtures\RedirectStoreProvider;
use Psr\Http\Message\ResponseInterface;

/** The Application switches redirects on when a provider binds a RedirectStore. */
final class RedirectBootTest extends TestCase
{
    #[RunInSeparateProcess]
    public function test_middleware_is_added_when_a_store_is_bound(): void
    {
        $app = Application::create($this->app(withStore: true));
        $app->boot();

        $response = $this->get($app, '/old?x=1');

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/new?x=1', $response->getHeaderLine('Location'));
        $this->assertSame('public, max-age=86400', $response->getHeaderLine('Cache-Control'));

        $this->assertSame(404, $this->get($app, '/missing')->getStatusCode());
        $this->assertSame([['/missing', 'en']], RedirectStoreProvider::$recorded);
        $this->assertTrue($app->container()->get(NonPublicPaths::class)->matches('/--component/x'));
    }

    #[RunInSeparateProcess]
    public function test_no_store_no_middleware(): void
    {
        $app = Application::create($this->app(withStore: false));
        $app->boot();

        $this->assertSame(404, $this->get($app, '/old')->getStatusCode());
        $this->assertSame([], RedirectStoreProvider::$recorded);
    }

    #[RunInSeparateProcess]
    public function test_not_found_off_in_config_records_nothing(): void
    {
        $app = Application::create($this->app(withStore: true, redirects: "'not_found' => false"));
        $app->boot();

        $this->assertSame(404, $this->get($app, '/missing')->getStatusCode());
        $this->assertSame(301, $this->get($app, '/old')->getStatusCode());
        $this->assertSame([], RedirectStoreProvider::$recorded);
    }

    #[RunInSeparateProcess]
    public function test_site_middleware_runs_first(): void
    {
        $app = Application::create($this->app(withStore: true, withSiteMiddleware: true));
        $app->boot();

        $response = $this->get($app, '/legacy');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('legacy page', (string) $response->getBody());
    }

    #[RunInSeparateProcess]
    public function test_a_target_at_the_visited_localized_address_keeps_the_404(): void
    {
        $dir = $this->app(withStore: true);
        file_put_contents($dir . '/config/i18n.php', "<?php\nreturn ['default'=>'en','available'=>['en','de'],'fallback'=>'en','url_strategy'=>'prefix'];\n");
        $app = Application::create($dir);
        $app->boot();

        // /de/x reaches the store as /x in German; its target /de/x is the visited address itself.
        $this->assertSame(404, $this->get($app, '/de/x')->getStatusCode());
        $this->assertSame('/de/x', $this->get($app, '/x')->getHeaderLine('Location'));
    }

    private function get(Application $app, string $target): ResponseInterface
    {
        return $app->handle((new Psr17Factory())->createServerRequest('GET', 'https://example.com' . $target));
    }

    private function app(bool $withStore, ?string $redirects = null, bool $withSiteMiddleware = false): string
    {
        $dir = sys_get_temp_dir() . '/redirectboot_' . bin2hex(random_bytes(4));
        mkdir($dir . '/config', 0777, true);
        file_put_contents($dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','url'=>'https://example.com','key'=>'a-real-application-key-of-32-bytes'];\n");
        if ($withStore) {
            file_put_contents($dir . '/config/providers.php', "<?php\nreturn [\\" . RedirectStoreProvider::class . "::class];\n");
        }
        if ($redirects !== null) {
            file_put_contents($dir . '/config/redirects.php', "<?php\nreturn [{$redirects}];\n");
        }
        if ($withSiteMiddleware) {
            file_put_contents($dir . '/config/middleware.php', "<?php\nreturn [\\" . LegacyPageMiddleware::class . "::class];\n");
        }

        return $dir;
    }
}
