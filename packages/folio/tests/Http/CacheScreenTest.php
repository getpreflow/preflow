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
        foreach (['index', 'clear', 'opcacheReset', 'refreshPage', 'refreshAll'] as $action) {
            $this->assertSame('folio.cache', FolioPermissionMiddleware::rule('Preflow\\Folio\\Http\\CacheController@' . $action));
        }
    }

    public function test_core_and_folio_caches_have_a_label_in_every_folio_language(): void
    {
        $labels = (new \ReflectionClassConstant(\Preflow\Folio\Http\CacheController::class, 'LABELS'))->getValue();
        foreach (['folio.extensions', 'blocks', 'data.json', 'blade', 'twig', 'assets', 'composer', 'boot', 'pages'] as $key) {
            $this->assertArrayHasKey($key, $labels, $key);
        }
        foreach (['en', 'de', 'fr'] as $lang) {
            $texts = require dirname(__DIR__, 2) . "/lang/{$lang}/folio.php";
            foreach ($labels as $cache => $textKey) {
                $leaf = substr($textKey, strlen('folio.cache.'));
                $this->assertNotSame('', $texts['cache'][$leaf] ?? '', "{$lang}: {$cache}");
            }
            foreach (['clear_all_note', 'boot_cleared', 'pages_title', 'pages_intro', 'pages_queued', 'refresh_url', 'refresh_url_help', 'refresh_page', 'refresh_all', 'refresh_all_note', 'refreshed', 'refreshed_all', 'refresh_bad_url', 'refresh_failed'] as $leaf) {
                $this->assertNotSame('', $texts['cache'][$leaf] ?? '', "{$lang}: {$leaf}");
            }
        }
    }

    #[RunInSeparateProcess]
    public function test_screen_lists_caches_with_forms_and_opcache(): void
    {
        $this->write('storage/cache/blocks.cache', str_repeat('b', 2000));

        $res = $this->request('GET', '/folio/cache', opcache: $this->opcache(true));
        $body = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertMatchesRegularExpression('#<h1>\s*Cache\s*</h1>#', $body);
        $this->assertStringContainsString('Folio extensions', $body);
        // A row: its files cell, then its size cell (the bar, then the human size).
        $this->assertMatchesRegularExpression('#Blocks.*?<td class="folio-cache-num">1</td>\s*<td class="folio-cache-sizecell">.*?<span class="folio-cache-bytes">' . preg_quote(Bytes::human(2000), '#') . '</span>#s', $body);
        $this->assertStringContainsString('<form method="post" action="/folio/cache/clear">', $body);
        $this->assertStringContainsString('<input type="hidden" name="key" value="blocks">', $body);
        $this->assertStringContainsString('<input type="hidden" name="key" value="folio.extensions">', $body);
        $this->assertStringContainsString('<input type="hidden" name="all" value="1">', $body);
        $this->assertStringContainsString('name="_csrf_token"', $body);
        $this->assertStringContainsString('Clear all', $body);
        // OPcache: memory, scripts, hit rate and the reset form.
        $this->assertStringContainsString(Bytes::human(3 * 1024 * 1024), $body);
        $this->assertStringContainsString(Bytes::human(5 * 1024 * 1024), $body);
        $this->assertMatchesRegularExpression('#<dt>Scripts</dt><dd>42</dd>#', $body);
        $this->assertMatchesRegularExpression('#<dt>Hit rate</dt><dd>97\.5%</dd>#', $body);
        $this->assertStringContainsString('<form method="post" action="/folio/cache/opcache-reset">', $body);
        $this->assertStringContainsString('Reset OPcache', $body);
        $this->assertStringNotContainsString('OPcache is off', $body);
        // The sidebar item under Settings, always there.
        $this->assertMatchesRegularExpression('#<span class="folio-nav-label">Settings</span>.*?<a href="/folio/cache" class="active"#s', $body);
        $this->assertStringContainsString('href="/folio/cache"', (string) $this->get('/folio')->getBody());
    }

    #[RunInSeparateProcess]
    public function test_total_bar_and_row_bars_follow_the_bytes(): void
    {
        $this->write('storage/cache/blocks.cache', str_repeat('b', 9000));
        $this->write('storage/cache/folio-extensions.cache', str_repeat('e', 1000));

        $body = (string) $this->request('GET', '/folio/cache', opcache: $this->opcache(true))->getBody();

        $total = 10000;
        $this->assertMatchesRegularExpression('#<strong id="folio-cache-total" class="folio-cache-total-size">' . preg_quote(Bytes::human($total), '#') . '</strong>\s*<span class="folio-cache-total-files">in 2 files</span>#', $body);
        // The biggest cache is segment 1 of the bar; widths as data-folio-share (CSP: no style attribute), the colour by class.
        preg_match('#<div class="folio-stack" role="img" aria-label="([^"]*)">(.*?)</div>#s', $body, $bar);
        $this->assertStringContainsString('Blocks ' . Bytes::human(9000), $bar[1]);
        $this->assertMatchesRegularExpression('#\s*<span class="folio-stack-1" data-folio-share="[0-9.]+"></span>#', $bar[2]);
        $this->assertStringNotContainsString('style=', $body, 'the strict CSP refuses style attributes: widths go through data-folio-share');
        $this->assertMatchesRegularExpression('#<span class="folio-stack-swatch folio-stack-1" aria-hidden="true"></span>Blocks#', $body);
        // The row's bar: the largest cache is full width, in its segment's colour.
        $this->assertMatchesRegularExpression('#Blocks.*?<span class="folio-cache-bar" aria-hidden="true"><span class="folio-stack-1" data-folio-share="100\.0"></span>#s', $body);
        // Clear all asks first, with the total as its message.
        $this->assertMatchesRegularExpression('#<form method="post" action="/folio/cache/clear" data-confirm="Clear every cache\?" data-confirm-message="' . preg_quote(Bytes::human($total), '#') . ' in \d+ files?\.[^"]*" data-confirm-label="Clear all" data-confirm-tone="danger">#', $body);
    }

    #[RunInSeparateProcess]
    public function test_an_empty_cache_has_a_disabled_clear_button(): void
    {
        $this->write('storage/cache/blocks.cache', str_repeat('b', 2000));

        $body = (string) $this->request('GET', '/folio/cache', opcache: $this->opcache(true))->getBody();

        preg_match_all('#<tr>.*?</tr>#s', $body, $rows);
        $byKey = [];
        foreach ($rows[0] as $row) {
            if (preg_match('#name="key" value="([^"]+)"#', $row, $m) === 1) {
                $byKey[$m[1]] = $row;
            }
        }
        $this->assertArrayHasKey('blocks', $byKey);
        $this->assertDoesNotMatchRegularExpression('#<button[^>]*\sdisabled#', $byKey['blocks']);
        $this->assertMatchesRegularExpression('#<button type="submit" class="btn btn-ghost btn-sm" aria-label="Clear cache Folio extensions" disabled>#', $byKey['folio.extensions']);
        $this->assertStringContainsString('<span class="folio-cache-bytes">0 B</span>', $byKey['folio.extensions']);
        $this->assertStringContainsString('<span class="folio-stack-rest" data-folio-share="0.0"></span>', $byKey['folio.extensions']);
    }

    #[RunInSeparateProcess]
    public function test_nothing_cached_shows_zero_without_a_bar(): void
    {
        $body = (string) $this->request('GET', '/folio/cache', opcache: $this->opcache(true))->getBody();

        $this->assertStringContainsString('0 B</strong>', $body);
        $this->assertStringContainsString('in 0 files', $body);
        $this->assertStringNotContainsString('class="folio-stack"', $body);
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
        $this->write('storage/cache/blocks.cache', 'blocks');
        $this->write('storage/cache/folio-extensions.cache', 'extensions');

        $res = $this->post('/folio/cache/clear', ['key' => 'blocks']);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/cache', $res->getHeaderLine('Location'));
        $this->assertFileDoesNotExist($this->dir . '/storage/cache/blocks.cache');
        $this->assertFileExists($this->dir . '/storage/cache/folio-extensions.cache');
    }

    #[RunInSeparateProcess]
    public function test_clear_unknown_key_is_404_and_clears_nothing(): void
    {
        $this->write('storage/cache/blocks.cache', 'blocks');
        $this->write('storage/cache/folio-extensions.cache', 'extensions');

        $res = $this->post('/folio/cache/clear', ['key' => 'nope']);

        $this->assertSame(404, $res->getStatusCode());
        $this->assertSame('Unknown cache', (string) $res->getBody());
        $this->assertFileExists($this->dir . '/storage/cache/blocks.cache');
        $this->assertFileExists($this->dir . '/storage/cache/folio-extensions.cache');
        $this->assertSame(404, $this->post('/folio/cache/clear', [])->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_clear_all_clears_every_registered_cache(): void
    {
        $this->write('storage/cache/blocks.cache', 'blocks');
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
        $this->write('storage/cache/blocks.cache', str_repeat('b', 1500));

        $this->assertSame(302, $this->send('POST', '/folio/cache/clear', ['key' => 'blocks'])->getStatusCode());
        $this->assertStringContainsString('Blocks: 1 file(s), ' . Bytes::human(1500) . ' cleared.', (string) $this->send('GET', '/folio/cache')->getBody());

        $this->write('storage/cache/blocks.cache', str_repeat('b', 100));
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

    /** "Clear all" also removes the boot manifest (core's `boot` cache): the screen says it comes back with the next deploy or `preflow optimize`. */
    #[RunInSeparateProcess]
    public function test_clearing_the_boot_manifest_says_how_it_comes_back(): void
    {
        $body = (string) $this->request('GET', '/folio/cache', opcache: $this->opcache(true))->getBody();
        $this->assertStringContainsString('Boot manifest', $body);
        $this->assertStringContainsString('<input type="hidden" name="key" value="boot">', $body);
        $this->assertStringContainsString('until the next deploy or preflow optimize rebuilds it', $body, 'next to "Clear all"');

        $this->signIn();
        $this->write('bootstrap/cache/boot.php', "<?php\nreturn [];\n");
        $this->send('POST', '/folio/cache/clear', ['all' => '1']);
        $this->assertFileDoesNotExist($this->dir . '/bootstrap/cache/boot.php');
        $this->assertStringContainsString('The boot manifest is rebuilt on the next deploy or with preflow optimize.', (string) $this->send('GET', '/folio/cache')->getBody());

        $this->write('bootstrap/cache/boot.php', "<?php\nreturn [];\n");
        $this->send('POST', '/folio/cache/clear', ['key' => 'boot']);
        $this->assertFileDoesNotExist($this->dir . '/bootstrap/cache/boot.php');
        $this->assertMatchesRegularExpression('#Boot manifest \\(preflow optimize\\): 1 file\\(s\\), .*? cleared\\. The boot manifest is rebuilt on the next deploy or with preflow optimize\\.#', (string) $this->send('GET', '/folio/cache')->getBody());

        // Without a manifest to remove, nothing about it.
        $this->send('POST', '/folio/cache/clear', ['all' => '1']);
        $this->assertStringNotContainsString('The boot manifest is rebuilt', (string) $this->send('GET', '/folio/cache')->getBody());
    }

    #[RunInSeparateProcess]
    public function test_refreshing_pages_needs_folio_cache(): void
    {
        $this->write('config/page_cache.php', "<?php\nreturn ['enabled' => true, 'hosts' => ['site.test']];\n");
        $this->signIn(['folio.dashboard']);

        $this->assertSame(403, $this->send('POST', '/folio/cache/pages/refresh', ['url' => '/'])->getStatusCode());
        $this->assertSame(403, $this->send('POST', '/folio/cache/pages/refresh-all')->getStatusCode());
        $this->assertSame(403, $this->send('GET', '/folio/cache')->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_the_page_cache_actions_flash_what_they_did(): void
    {
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Pub', 'debug' => 0, 'engine' => 'twig', 'key' => 'folio-integration-test-key', 'url' => 'https://site.test'];\n");
        $this->write('config/page_cache.php', "<?php\nreturn ['enabled' => true];\n");
        $this->signIn();

        $this->assertStringContainsString('<section class="folio-panel folio-cache-card folio-cache-pages" aria-labelledby="folio-cache-pages-title">', (string) $this->send('GET', '/folio/cache')->getBody(), 'shown with the cache on');
        $this->send('POST', '/folio/cache/pages/refresh', ['url' => '/about']);
        $this->assertStringContainsString('/about refreshed (0 cached copies forgotten); it is rebuilt now.', (string) $this->send('GET', '/folio/cache')->getBody());
        // The field shows the "/" as its prefix: a path typed without it is the same page.
        $this->send('POST', '/folio/cache/pages/refresh', ['url' => 'about']);
        $this->assertStringContainsString('/about refreshed (0 cached copies forgotten)', (string) $this->send('GET', '/folio/cache')->getBody());
        $screen = (string) $this->send('GET', '/folio/cache')->getBody();
        $this->assertMatchesRegularExpression('#<span class="folio-input-prefix" aria-hidden="true">/</span><input type="text" id="folio-cache-refresh-url" name="url" placeholder="about"#', $screen);
        $this->assertStringContainsString('Refresh everything in the background', $screen);
        $this->send('POST', '/folio/cache/pages/refresh', ['url' => 'https://elsewhere.test/about']);
        $this->assertStringContainsString('Not an address of this site: https://elsewhere.test/about', (string) $this->send('GET', '/folio/cache')->getBody());
        $this->send('POST', '/folio/cache/pages/refresh-all');
        $this->assertStringContainsString('Every page refreshed:', (string) $this->send('GET', '/folio/cache')->getBody());
    }

    #[RunInSeparateProcess]
    public function test_without_the_page_cache_its_actions_are_not_there(): void
    {
        $body = (string) $this->request('GET', '/folio/cache')->getBody();
        $this->assertStringNotContainsString('/folio/cache/pages/refresh', $body);
        $this->assertSame(404, $this->post('/folio/cache/pages/refresh-all')->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_post_without_csrf_token_is_403(): void
    {
        $this->signIn();
        $this->write('storage/cache/blocks.cache', 'blocks');

        $res = $this->session->handle((new Psr17Factory())->createServerRequest('POST', '/folio/cache/clear')->withHeader('Sec-Fetch-Site', 'same-origin')->withCookieParams([session_name() => session_id()])->withParsedBody(['key' => 'blocks']));

        $this->assertSame(403, $res->getStatusCode());
        $this->assertFileExists($this->dir . '/storage/cache/blocks.cache');
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

        $request = (new Psr17Factory())->createServerRequest($method, $uri);

        // As a browser posts a form of the site.
        return $app->handle($method === 'GET' ? $request : $request->withHeader('Sec-Fetch-Site', 'same-origin'));
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

    /**
     * Auth with one admin, signed in on one in-process app (the session and its flashes live there).
     *
     * @param list<string>|null $permissions the admin role's permissions (all when null)
     */
    private function signIn(?array $permissions = null): void
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
        $this->write('config/roles.php', "<?php\nreturn " . var_export(['staff' => ['admin' => $permissions ?? ['*']]], true) . ";\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Roles\\RolesServiceProvider::class];\n");

        $this->session = $this->app();
        $f = new Psr17Factory();
        $page = (string) $this->session->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $this->token = $m[1] ?? '';
        $login = $this->session->handle($f->createServerRequest('POST', '/folio/login')->withHeader('Sec-Fetch-Site', 'same-origin')->withParsedBody([
            '_csrf_token' => $this->token, 'email' => 'admin@site.test', 'password' => 'secret',
        ]));
        $this->assertSame(302, $login->getStatusCode());
    }

    /** @param array<string, string> $body */
    private function send(string $method, string $uri, array $body = []): ResponseInterface
    {
        $req = (new Psr17Factory())->createServerRequest($method, $uri);
        if ($method !== 'GET') {
            // As a browser posts a form of the site: same origin, with the session cookie.
            $req = $req->withHeader('Sec-Fetch-Site', 'same-origin');
            if (session_id() !== '') {
                $req = $req->withCookieParams([session_name() => session_id()]);
            }
        }
        if ($method === 'POST') {
            $req = $req->withParsedBody(['_csrf_token' => $this->token] + $body);
        }

        return $this->session->handle($req);
    }
}
