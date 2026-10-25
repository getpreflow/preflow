<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Cache\Bytes;
use Preflow\Core\Cache\CacheRegistry;
use Preflow\Core\Cache\OpcacheStatus;
use Preflow\Folio\Http\FolioPermissionMiddleware;
use Preflow\Folio\Tests\Support\CounterExtension;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Psr\Http\Message\ResponseInterface;

/**
 * Settings → Cache ({prefix}/cache): the registered caches with their size, clearing one or
 * all, and the OPcache of the web SAPI. Without auth (a fresh app per request) for the
 * screen and the files; signed in for the flashes and the CSRF check.
 */
final class CacheScreenTest extends TestCase
{
    use PublishingScaffold;
    use CounterExtension;

    private ?Application $session = null;
    private string $token = '';

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
    }

    protected function tearDown(): void
    {
        $this->removeCounter();
        $this->removeScaffold();
    }

    public function test_routes_ask_folio_cache(): void
    {
        foreach (['index', 'clear', 'opcacheReset'] as $action) {
            $this->assertSame('folio.cache', FolioPermissionMiddleware::rule('Preflow\\Folio\\Http\\CacheController@' . $action));
        }
    }

    #[RunInSeparateProcess]
    public function test_screen_lists_caches_with_forms_and_opcache(): void
    {
        $this->write('storage/cache/folio-blocks.cache', str_repeat('b', 2000));

        $res = $this->request('GET', '/folio/cache', opcache: $this->opcache(true));
        $body = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertMatchesRegularExpression('#<h1>\s*Cache\s*</h1>#', $body);
        $this->assertStringContainsString('Folio extensions', $body);
        $this->assertMatchesRegularExpression('#Folio blocks.*?<td>1</td>\s*<td>' . preg_quote(Bytes::human(2000), '#') . '</td>#s', $body);
        $this->assertStringContainsString('<form method="post" action="/folio/cache/clear">', $body);
        $this->assertStringContainsString('<input type="hidden" name="key" value="folio.blocks">', $body);
        $this->assertStringContainsString('<input type="hidden" name="key" value="folio.extensions">', $body);
        $this->assertStringContainsString('<input type="hidden" name="all" value="1">', $body);
        $this->assertStringContainsString('name="_csrf_token"', $body);
        $this->assertStringContainsString('Clear all', $body);
        // OPcache: memory, scripts, hit rate and the reset form.
        $this->assertStringContainsString(Bytes::human(3 * 1024 * 1024), $body);
        $this->assertStringContainsString(Bytes::human(5 * 1024 * 1024), $body);
        $this->assertStringContainsString('<td>42</td>', $body);
        $this->assertStringContainsString('97.5%', $body);
        $this->assertStringContainsString('<form method="post" action="/folio/cache/opcache-reset">', $body);
        $this->assertStringContainsString('Reset OPcache', $body);
        $this->assertStringNotContainsString('OPcache is off', $body);
        // The sidebar item under Settings, always there.
        $this->assertMatchesRegularExpression('#<span class="folio-nav-label">Settings</span>.*?<a href="/folio/cache" class="active"#s', $body);
        $this->assertStringContainsString('href="/folio/cache"', (string) $this->get('/folio')->getBody());
    }

    #[RunInSeparateProcess]
    public function test_screen_says_when_opcache_is_off(): void
    {
        $body = (string) $this->request('GET', '/folio/cache', opcache: $this->opcache(false))->getBody();

        $this->assertStringContainsString('OPcache is off', $body);
        $this->assertStringNotContainsString('/folio/cache/opcache-reset', $body);
    }

    #[RunInSeparateProcess]
    public function test_clear_one_cache_removes_its_file(): void
    {
        $this->write('storage/cache/folio-blocks.cache', 'blocks');
        $this->write('storage/cache/folio-extensions.cache', 'extensions');

        $res = $this->post('/folio/cache/clear', ['key' => 'folio.blocks']);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/cache', $res->getHeaderLine('Location'));
        $this->assertFileDoesNotExist($this->dir . '/storage/cache/folio-blocks.cache');
        $this->assertFileExists($this->dir . '/storage/cache/folio-extensions.cache');
    }

    #[RunInSeparateProcess]
    public function test_clear_unknown_key_is_404_and_clears_nothing(): void
    {
        $this->write('storage/cache/folio-blocks.cache', 'blocks');
        $this->write('storage/cache/folio-extensions.cache', 'extensions');

        $res = $this->post('/folio/cache/clear', ['key' => 'nope']);

        $this->assertSame(404, $res->getStatusCode());
        $this->assertSame('Unknown cache', (string) $res->getBody());
        $this->assertFileExists($this->dir . '/storage/cache/folio-blocks.cache');
        $this->assertFileExists($this->dir . '/storage/cache/folio-extensions.cache');
        $this->assertSame(404, $this->post('/folio/cache/clear', [])->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_clear_all_clears_every_registered_cache(): void
    {
        $this->write('storage/cache/folio-blocks.cache', 'blocks');
        $this->write('storage/cache/folio-extensions.cache', 'extensions');

        $res = $this->post('/folio/cache/clear', ['all' => '1']);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/cache', $res->getHeaderLine('Location'));
        foreach ($this->app()->container()->get(CacheRegistry::class)->all() as $source) {
            $this->assertSame(0, $source->stats()->entries, $source->key());
        }
    }

    #[RunInSeparateProcess]
    public function test_flashes_after_clearing_and_resetting(): void
    {
        $this->signIn();
        $this->write('storage/cache/folio-blocks.cache', str_repeat('b', 1500));

        $this->assertSame(302, $this->send('POST', '/folio/cache/clear', ['key' => 'folio.blocks'])->getStatusCode());
        $this->assertStringContainsString('Folio blocks: 1 file(s), ' . Bytes::human(1500) . ' cleared.', (string) $this->send('GET', '/folio/cache')->getBody());

        $this->write('storage/cache/folio-blocks.cache', str_repeat('b', 100));
        $this->write('storage/cache/folio-extensions.cache', str_repeat('e', 50));
        $entries = 0;
        $bytes = 0;
        foreach ($this->session->container()->get(CacheRegistry::class)->all() as $source) {
            $entries += $source->stats()->entries;
            $bytes += $source->stats()->bytes;
        }
        $this->send('POST', '/folio/cache/clear', ['all' => '1']);
        $this->assertStringContainsString("Cleared all caches: {$entries} file(s), " . Bytes::human($bytes) . '.', (string) $this->send('GET', '/folio/cache')->getBody());

        $this->session->container()->instance(OpcacheStatus::class, $this->opcache(true));
        $res = $this->send('POST', '/folio/cache/opcache-reset');
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/cache', $res->getHeaderLine('Location'));
        $this->assertStringContainsString('OPcache reset.', (string) $this->send('GET', '/folio/cache')->getBody());

        $this->session->container()->instance(OpcacheStatus::class, $this->opcache(false));
        $this->send('POST', '/folio/cache/opcache-reset');
        $this->assertStringContainsString('<div class="folio-alert folio-alert-error" role="alert">OPcache is off.</div>', (string) $this->send('GET', '/folio/cache')->getBody());

        $this->session->container()->instance(OpcacheStatus::class, $this->opcache(true, resets: false));
        $this->send('POST', '/folio/cache/opcache-reset');
        $this->assertStringContainsString('<div class="folio-alert folio-alert-error" role="alert">OPcache reset failed.</div>', (string) $this->send('GET', '/folio/cache')->getBody());
    }

    #[RunInSeparateProcess]
    public function test_post_without_csrf_token_is_403(): void
    {
        $this->signIn();
        $this->write('storage/cache/folio-blocks.cache', 'blocks');

        $res = $this->session->handle((new Psr17Factory())->createServerRequest('POST', '/folio/cache/clear')->withParsedBody(['key' => 'folio.blocks']));

        $this->assertSame(403, $res->getStatusCode());
        $this->assertFileExists($this->dir . '/storage/cache/folio-blocks.cache');
    }

    #[RunInSeparateProcess]
    public function test_cleared_extension_registry_rebuilds_on_next_request(): void
    {
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Pub', 'debug' => 0, 'engine' => 'twig', 'key' => 'folio-integration-test-key'];\n");
        $this->counter([]);
        $file = $this->dir . '/storage/cache/folio-extensions.cache';

        $this->assertSame(200, $this->get('/folio')->getStatusCode());
        $this->assertFileExists($file);

        $this->assertSame(302, $this->post('/folio/cache/clear', ['key' => 'folio.extensions'])->getStatusCode());
        $this->assertFileDoesNotExist($file);

        $this->assertSame(200, $this->get('/folio')->getStatusCode());
        $this->assertFileExists($file);
    }

    /** A fresh app (no auth), with this OPcache status. */
    private function request(string $method, string $uri, ?OpcacheStatus $opcache = null): ResponseInterface
    {
        $app = $this->app();
        if ($opcache !== null) {
            $app->container()->instance(OpcacheStatus::class, $opcache);
        }

        return $app->handle((new Psr17Factory())->createServerRequest($method, $uri));
    }

    private function opcache(bool $enabled, bool $resets = true): OpcacheStatus
    {
        return new class ($enabled, $resets) extends OpcacheStatus {
            public function __construct(private readonly bool $on, private readonly bool $resets) {}

            public function enabled(): bool
            {
                return $this->on;
            }

            public function stats(): ?array
            {
                return $this->on ? ['memory_used' => 3 * 1024 * 1024, 'memory_free' => 5 * 1024 * 1024, 'scripts' => 42, 'hit_rate' => 97.5] : null;
            }

            public function reset(): bool
            {
                return $this->on && $this->resets;
            }
        };
    }

    /** Auth with one admin, signed in on one in-process app (the session and its flashes live there). */
    private function signIn(): void
    {
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                ['email' => 'admin@site.test', 'password_hash' => $hash, 'roles' => ['admin']],
            ]]],
            'session' => ['cookie' => 'folio_cache_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $this->write('config/roles.php', "<?php\nreturn " . var_export(['staff' => ['admin' => ['*']]], true) . ";\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Roles\\RolesServiceProvider::class];\n");

        $this->session = $this->app();
        $f = new Psr17Factory();
        $page = (string) $this->session->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $this->token = $m[1] ?? '';
        $login = $this->session->handle($f->createServerRequest('POST', '/folio/login')->withParsedBody([
            '_csrf_token' => $this->token, 'email' => 'admin@site.test', 'password' => 'secret',
        ]));
        $this->assertSame(302, $login->getStatusCode());
    }

    /** @param array<string, string> $body */
    private function send(string $method, string $uri, array $body = []): ResponseInterface
    {
        $req = (new Psr17Factory())->createServerRequest($method, $uri);
        if ($method === 'POST') {
            $req = $req->withParsedBody(['_csrf_token' => $this->token] + $body);
        }

        return $this->session->handle($req);
    }
}
