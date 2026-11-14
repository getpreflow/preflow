<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Psr\Http\Message\ResponseInterface;

/**
 * The admin's 404 (NotFound::response): a page the browser opened gets the ink "not found" page with
 * the controller's message and the path, and with redirects.manage a link that starts a redirect from
 * that path. htmx swaps, fetch() calls, JSON clients and static files keep the plain text they parse.
 * Without auth (every permission) unless signed in; the forbidden page is the same ink card.
 */
final class NotFoundPageTest extends TestCase
{
    use PublishingScaffold;

    private const string HTML = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';

    private ?Application $booted = null;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_a_page_the_browser_opens_gets_the_ink_page_with_the_message_and_path(): void
    {
        $res = $this->open('/folio/post/nope/edit', ['Accept' => self::HTML, 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document']);
        $body = (string) $res->getBody();

        $this->assertSame(404, $res->getStatusCode());
        $this->assertStringStartsWith('text/html', $res->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('class="folio-sidebar', $body, 'in the admin layout');
        $this->assertMatchesRegularExpression('#<div class="folio-ink folio-ink-card folio-ink-error">\s*<svg class="folio-ink-glyph mc-3" aria-hidden="true"[^>]*><use href="[^"]*icons\.svg[^"]*\#mark-pilcrow">#', $body);
        $this->assertStringContainsString('<h2 class="folio-ink-title">This page wandered off</h2>', $body);
        $this->assertStringContainsString('<p class="folio-ink-muted">Not found</p>', $body);
        $this->assertMatchesRegularExpression('#<p class="folio-ink-line">/folio/post/nope/edit #', $body);

        $type = $this->open('/folio/nosuchtype', ['Accept' => self::HTML]);
        $this->assertSame(404, $type->getStatusCode());
        $this->assertStringContainsString('<p class="folio-ink-muted">Unknown type</p>', (string) $type->getBody());
    }

    public function test_htmx_and_fetch_keep_plain_text(): void
    {
        foreach ([
            'htmx' => ['Accept' => self::HTML, 'HX-Request' => 'true'],
            'fetch (cors)' => ['Accept' => self::HTML, 'Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Dest' => 'empty'],
            'JSON' => ['Accept' => 'application/json'],
            'any' => ['Accept' => '*/*'],
            'no Accept' => [],
        ] as $caller => $headers) {
            $res = $this->open('/folio/post/nope/edit', $headers);
            $this->assertSame(404, $res->getStatusCode(), $caller);
            $this->assertSame('Not found', (string) $res->getBody(), $caller);
            $this->assertStringStartsWith('text/plain', $res->getHeaderLine('Content-Type'), $caller);
        }
    }

    public function test_static_files_never_get_the_admin_page(): void
    {
        $res = $this->open('/folio/_assets/nope.css', ['Accept' => self::HTML]);
        $this->assertSame(404, $res->getStatusCode());
        $this->assertSame('Not found', (string) $res->getBody());
    }

    public function test_the_hint_starts_a_redirect_only_with_redirects_manage(): void
    {
        $this->signInAs('manager');
        $body = (string) $this->open('/folio/post/nope/edit', ['Accept' => self::HTML])->getBody();
        $this->assertStringContainsString('<p class="folio-ink-line">/folio/post/nope/edit <a href="/folio/redirects/new?address=%2Ffolio%2Fpost%2Fnope%2Fedit">→ add a redirect</a>', $body);

        $this->booted = null;
        $this->signInAs('editor');
        $res = $this->open('/folio/post/nope/edit', ['Accept' => self::HTML]);
        $body = (string) $res->getBody();
        $this->assertSame(404, $res->getStatusCode());
        $this->assertStringContainsString('This page wandered off', $body);
        $this->assertStringContainsString('<p class="folio-ink-line">/folio/post/nope/edit<span class="folio-caret"', $body);
        $this->assertStringNotContainsString('redirects/new', $body);
    }

    public function test_forbidden_is_the_same_ink_card_with_its_own_title(): void
    {
        $this->signInAs('editor');
        $res = $this->open('/folio/redirects', ['Accept' => self::HTML]);
        $body = (string) $res->getBody();

        $this->assertSame(403, $res->getStatusCode());
        $this->assertStringContainsString('<div class="folio-ink folio-ink-card folio-ink-error">', $body);
        $this->assertStringContainsString('#mark-pilcrow', $body);
        $this->assertStringContainsString('<h2 class="folio-ink-title">This door stays closed</h2>', $body);
        $this->assertStringContainsString("<p class=\"folio-ink-muted\">You don&#039;t have access to this.</p>", $body);
        $this->assertStringContainsString('<p class="folio-ink-line">Ask an administrator if you need it.<span class="folio-caret" aria-hidden="true"></span></p>', $body);
    }

    public function test_every_404_in_http_goes_through_not_found(): void
    {
        $dir = dirname(__DIR__, 2) . '/src/Http';
        $left = [];
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            if (basename($file) === 'NotFound.php') {
                continue;
            }
            if (preg_match('/new\s+(?:\\\\?Nyholm\\\\Psr7\\\\)?Response\(\s*404\b/', (string) file_get_contents($file)) === 1) {
                $left[] = basename($file);
            }
        }
        $this->assertSame([], $left, 'answer 404 with NotFound::response($request, $message)');
    }

    /** @param array<string, string> $headers */
    private function open(string $uri, array $headers): ResponseInterface
    {
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $request = (new Psr17Factory())->createServerRequest('GET', $uri)->withQueryParams($query);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if (session_id() !== '') {
            $request = $request->withCookieParams([session_name() => session_id()]);
        }

        return $this->booted()->handle($request);
    }

    private function booted(): Application
    {
        return $this->booted ??= $this->app();
    }

    /** Signed in with auth and roles: "manager" may manage redirects, "editor" may not. */
    private function signInAs(string $role): void
    {
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                ['email' => 'manager@site.test', 'password_hash' => $hash, 'roles' => ['manager']],
                ['email' => 'editor@site.test', 'password_hash' => $hash, 'roles' => ['editor']],
            ]]],
            'session' => ['cookie' => 'folio_not_found_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $this->write('config/roles.php', "<?php\nreturn " . var_export(['staff' => [
            'manager' => ['folio.dashboard', 'post.view', 'redirects.manage'],
            'editor' => ['folio.dashboard', 'post.view'],
        ]], true) . ";\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Roles\\RolesServiceProvider::class];\n");

        $f = new Psr17Factory();
        $page = (string) $this->booted()->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $login = $this->booted()->handle($f->createServerRequest('POST', '/folio/login')->withHeader('Sec-Fetch-Site', 'same-origin')->withParsedBody([
            '_csrf_token' => $m[1] ?? '', 'email' => $role . '@site.test', 'password' => 'secret',
        ]));
        $this->assertSame(302, $login->getStatusCode());
    }
}
