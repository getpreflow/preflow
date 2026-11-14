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
        $res = $this->booted()->handle((new Psr17Factory())->createServerRequest('GET', $uri)->withQueryParams($query));
        $this->assertSame(200, $res->getStatusCode(), $uri);

        return (string) $res->getBody();
    }

    private function booted(): Application
    {
        return $this->booted ??= $this->app();
    }
}
