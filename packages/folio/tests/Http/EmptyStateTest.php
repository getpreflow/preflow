<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Folio\Tests\Support\PublishingScaffold;

/**
 * The ink empty state (partials/_empty.twig; INK.md "Empty states"): an ink card with six aria-hidden
 * marks seeded by the screen's name, the title, a mono line with a caret and the primary action in
 * --ink-accent. Every screen with nothing in it yet shows one; a search or filter with no results stays
 * plain (.folio-no-matches). Without auth.
 */
final class EmptyStateTest extends TestCase
{
    use PublishingScaffold;

    private ?Application $booted = null;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_an_empty_list_shows_the_ink_card_with_its_action(): void
    {
        $body = $this->body('/folio/post');
        $card = $this->card($body, 'list');

        $this->assertStringContainsString('<p class="folio-ink-title">No posts yet.</p>', $card);
        $this->assertStringContainsString('<p class="folio-ink-line">Add the first one<span class="folio-caret" aria-hidden="true"></span></p>', $card);
        $this->assertStringContainsString('<a class="btn folio-ink-action" href="/folio/post/new">Create one</a>', $card);
        $this->assertSame(1, substr_count($body, 'class="folio-ink folio-ink-card'), 'one ink place per screen');
    }

    public function test_the_marks_are_six_hidden_marks_on_the_right_half_seeded_by_the_screen(): void
    {
        $card = $this->card($this->body('/folio/post'), 'list');

        $this->assertMatchesRegularExpression('#<svg class="folio-ink-marks" aria-hidden="true" focusable="false">#', $card);
        $this->assertSame(6, preg_match_all('#<use href="[^"]*\#mark-[a-z]+" class="mc-[1-6]"#', $card));
        preg_match_all('#<svg x="([\d.]+)%"#', $card, $x);
        foreach ($x[1] as $left) {
            $this->assertGreaterThanOrEqual(50.0, (float) $left, 'right half: the text stays clear');
        }
        // The same screen keeps its marks on every request; another screen has its own.
        $this->booted = null;
        $this->assertSame($this->marks($card), $this->marks($this->card($this->body('/folio/note'), 'list')));
        $this->assertNotSame($this->marks($card), $this->marks($this->card($this->body('/folio/assets'), 'assets')));
    }

    public function test_assets_bin_extensions_translations_and_redirects_have_their_card(): void
    {
        $this->assertStringContainsString('<p class="folio-ink-title">No assets yet.</p>', $this->card($this->body('/folio/assets'), 'assets'));
        $bin = $this->card($this->body('/folio/assets/bin'), 'assets-bin');
        $this->assertStringContainsString('<p class="folio-ink-title">The bin is empty.</p>', $bin);
        $this->assertStringContainsString('Nothing to restore<span class="folio-caret"', $bin);
        $extensions = $this->card($this->body('/folio/extensions'), 'extensions');
        $this->assertStringContainsString('<p class="folio-ink-title">No extensions found.</p>', $extensions);
        $this->assertStringContainsString('app/Folio/*/Extension.php', $extensions);
        $redirects = $this->card($this->body('/folio/redirects'), 'redirects');
        $this->assertStringContainsString('<p class="folio-ink-title">No redirects yet.</p>', $redirects);
        $this->assertStringContainsString('<a class="btn folio-ink-action" href="/folio/redirects/new">New redirect</a>', $redirects);
        $this->assertStringContainsString('<p class="folio-ink-title">No address was asked for twice without being found.</p>', $this->card($this->body('/folio/redirects?tab=not-found'), 'redirects-not-found'));
    }

    public function test_the_assets_line_invites_only_those_who_may_upload(): void
    {
        $this->signInAs('uploader');
        $card = $this->card($this->body('/folio/assets'), 'assets');
        $this->assertStringContainsString('<p class="folio-ink-title">No assets yet.</p>', $card);
        $this->assertStringContainsString('<p class="folio-ink-line">Upload the first files<span class="folio-caret" aria-hidden="true"></span></p>', $card);

        $this->booted = null;
        $this->signInAs('viewer');
        $card = $this->card($this->body('/folio/assets'), 'assets');
        $this->assertStringContainsString('<p class="folio-ink-title">No assets yet.</p>', $card);
        $this->assertStringNotContainsString('folio-ink-line', $card);
        $this->assertStringNotContainsString('folio-ink-action', $card);
    }

    public function test_translations_without_texts_show_the_card(): void
    {
        // The site group lists the models' labels: a site without models has none.
        foreach (glob($this->dir . '/config/models/*.json') ?: [] as $model) {
            unlink($model);
        }
        $card = $this->card($this->body('/folio/translations?group=site'), 'translations');
        $this->assertStringContainsString('<p class="folio-ink-title">No texts here yet</p>', $card);
        $this->assertStringContainsString('Texts appear once the site uses them<span class="folio-caret"', $card);
    }

    public function test_a_search_with_no_results_stays_plain(): void
    {
        $this->working('note', 'n1', ['name' => 'Kept']);

        foreach (['/folio/note?q=zzz', '/folio/assets?q=zzz', '/folio/redirects?q=zzz', '/folio/translations?q=zzzqqq'] as $uri) {
            $body = $this->body($uri);
            $this->assertStringNotContainsString('folio-ink-empty', $body, $uri);
            $this->assertStringContainsString('folio-no-matches', $body, $uri);
        }
    }

    private function card(string $body, string $screen): string
    {
        $found = preg_match('#<div class="folio-ink folio-ink-card folio-ink-empty" data-folio-empty="' . preg_quote($screen, '#') . '">.*?</div>#s', $body, $m);
        $this->assertSame(1, $found, "the {$screen} empty state");

        return $m[0];
    }

    private function marks(string $card): string
    {
        preg_match('#<svg class="folio-ink-marks".*?</svg>(?=\s*<p)#s', $card, $m);

        return (string) preg_replace('#href="[^"]*\##', 'href="#', $m[0] ?? '');
    }

    private function body(string $uri): string
    {
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $request = (new Psr17Factory())->createServerRequest('GET', $uri)->withQueryParams($query);
        if (session_id() !== '') {
            $request = $request->withCookieParams([session_name() => session_id()]);
        }
        $res = $this->booted()->handle($request);
        $this->assertSame(200, $res->getStatusCode(), $uri);

        return (string) $res->getBody();
    }

    /** Signed in with auth and roles: "uploader" may upload assets, "viewer" may only see them. */
    private function signInAs(string $role): void
    {
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                ['email' => 'uploader@site.test', 'password_hash' => $hash, 'roles' => ['uploader']],
                ['email' => 'viewer@site.test', 'password_hash' => $hash, 'roles' => ['viewer']],
            ]]],
            'session' => ['cookie' => 'folio_empty_state_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $this->write('config/roles.php', "<?php\nreturn " . var_export(['staff' => [
            'uploader' => ['folio.dashboard', 'assets.view', 'assets.upload'],
            'viewer' => ['folio.dashboard', 'assets.view'],
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

    private function booted(): Application
    {
        return $this->booted ??= $this->app();
    }
}
