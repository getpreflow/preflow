<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Folio\Http\NotFound;
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
        $this->assertStringContainsString('<p class="folio-ink-line">/folio/post/nope/edit<span class="folio-caret" aria-hidden="true"></span></p>', $body, 'an admin path: no redirect hint');

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

    public function test_an_admin_path_shows_only_the_path_never_the_redirect_hint(): void
    {
        // Redirects refuse the admin's own addresses, so even with redirects.manage there is no hint.
        $this->signInAs('manager');
        $res = $this->open('/folio/post/nope/edit', ['Accept' => self::HTML]);
        $body = (string) $res->getBody();
        $this->assertSame(404, $res->getStatusCode());
        $this->assertStringContainsString('This page wandered off', $body);
        $this->assertStringContainsString('<p class="folio-ink-line">/folio/post/nope/edit<span class="folio-caret" aria-hidden="true"></span></p>', $body);
        $this->assertStringNotContainsString('redirects/new', $body);
    }

    public function test_a_path_outside_the_admin_starts_a_redirect_only_with_redirects_manage(): void
    {
        $request = fn (bool $may): \Psr\Http\Message\ServerRequestInterface => (new Psr17Factory())->createServerRequest('GET', '/old-page')
            ->withHeader('Accept', self::HTML)
            ->withAttribute(NotFound::class, new NotFound(
                $this->booted()->container()->get(\Preflow\Folio\View\FolioEngine::class),
                '/folio',
                $this->booted()->container()->get(\Preflow\Folio\Content\TypeCatalog::class),
                static fn (string $permission): bool => $may && $permission === 'redirects.manage',
            ));

        $manager = (string) NotFound::response($request(true), 'Not found')->getBody();
        $this->assertStringContainsString('<p class="folio-ink-line">/old-page <a href="/folio/redirects/new?address=%2Fold-page">→ add a redirect</a>', $manager);

        $editor = NotFound::response($request(false), 'Not found');
        $this->assertSame(404, $editor->getStatusCode());
        $this->assertStringContainsString('<p class="folio-ink-line">/old-page<span class="folio-caret" aria-hidden="true"></span></p>', (string) $editor->getBody());
        $this->assertStringNotContainsString('redirects/new', (string) $editor->getBody());
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

    /**
     * A status in a variable could be a 404 that escapes NotFound. These are the known ones, none of
     * them ever a 404 to a page: each renders its own page at 200 or a validation/limit status, except
     * AiTranslateController::json, the JSON API whose 404 is JSON its callers parse.
     */
    private const array VARIABLE_STATUS = [
        'AccountController::page',            // the account page: 200 or 422
        'AdminController::form',              // the record form: 200 or 422
        'AiSettingsController::render',       // Settings → AI pages: 200 or 422
        'AiTranslateController::json',        // JSON for fetch() callers, its 404s included (they parse the JSON)
        'AiTranslateController::page',        // the run screen: 200, 409 or 422
        'AiTranslateController::progress',    // the run's progress partial: 200 or 409
        'AssetLibraryController::html',       // the asset screens and panels: 200 or 422
        'AssetLibraryController::upload',     // the picker upload's JSON: 200 or 422
        'AuthController::page',               // the sign-in page: 200, 422 or 429
        'PasswordController::render',         // the password pages: 200 or 422
        'RedirectsController::html',          // the redirect screens: 200, 422 or 503 (not migrated)
        'TranslationsController::screen',     // the translations screen: 200 or 422
    ];

    public function test_no_variable_status_response_can_hide_a_404(): void
    {
        $found = [];
        foreach (glob(dirname(__DIR__, 2) . '/src/Http/*.php') ?: [] as $file) {
            $php = (string) file_get_contents($file);
            preg_match_all('/new\s+(?:\\\\?Nyholm\\\\Psr7\\\\)?Response\(\s*\$/', $php, $calls, PREG_OFFSET_CAPTURE);
            foreach ($calls[0] as [, $offset]) {
                preg_match_all('/function\s+(\w+)\s*\(/', substr($php, 0, $offset), $functions);
                $found[] = basename($file, '.php') . '::' . (end($functions[1]) ?: '?');
            }
        }
        sort($found);
        $this->assertSame(self::VARIABLE_STATUS, $found, 'a Response with a status in a variable: route its 404 through NotFound::response, or list it here with why it is never one');
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
