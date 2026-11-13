<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Tests\Support\PublishingScaffold;

/**
 * The preview bar (0.75, board PageEditor): the device switch, the URL pill (host and path of the
 * edited copy, "· draft" while it differs from what is live), Copy preview link (the share route,
 * then the clipboard: "Link copied · N days" from the real lifetime), Open (the live address, only
 * while the record is on the site) and the focus toggle.
 */
final class PreviewBarTest extends TestCase
{
    use PublishingScaffold;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Pub', 'debug' => 1, 'engine' => 'twig', 'key' => 'folio-integration-test-key', 'url' => 'https://example.test'];\n");
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    private static function bar(string $page): string
    {
        $start = strpos($page, '<div class="folio-preview-bar">');
        self::assertNotFalse($start, 'the preview bar');

        return substr($page, $start, (int) strpos($page, '<iframe', $start) - $start);
    }

    private function editor(string $id): string
    {
        $res = $this->get('/folio/post/' . $id . '/edit');
        $this->assertSame(200, $res->getStatusCode());

        return (string) $res->getBody();
    }

    private function publishPost(string $id, string $slug): void
    {
        $this->publisher()->publish($this->working('post', $id, ['title' => ['en' => ucfirst($slug)], 'slug' => ['en' => $slug]]), null);
    }

    public function test_a_published_page_shows_its_address_copy_and_open(): void
    {
        $this->publishPost('p1', 'hello');

        $bar = self::bar($this->editor('p1'));

        // Device switch: the existing viewports, icons with their names.
        $this->assertMatchesRegularExpression('#<div class="folio-segmented folio-device-switch" role="group" aria-label="Viewport">\s*<button type="button" data-preview-viewport="100%" aria-pressed="true" aria-label="Desktop" title="Desktop">#', $bar);
        $this->assertStringContainsString('data-preview-viewport="768px" aria-pressed="false" aria-label="Tablet" title="Tablet (768px)"', $bar);
        $this->assertStringContainsString('data-preview-viewport="375px" aria-pressed="false" aria-label="Mobile" title="Mobile (375px)"', $bar);
        // The pill: host and path, live (no draft mark).
        $this->assertMatchesRegularExpression('#<span class="folio-preview-url" title="https://example.test/hello"><span class="folio-preview-dot" aria-hidden="true"></span><span class="folio-preview-address">example.test/hello</span></span>#', $bar);
        // Copy: the share route (the editor's own flow), then the clipboard; its "copied" text has the real lifetime.
        $this->assertMatchesRegularExpression('#<button type="button" class="btn btn-secondary btn-icon btn-sm folio-share-button" hx-post="/folio/post/p1/share" hx-target="\#folio-share" hx-swap="outerHTML" hx-include="\[name=_csrf_token\], \[name=_locale\]" data-folio-share-copy data-text-copied="Link copied · 7 days" aria-label="Copy preview link" title="Copy preview link"><svg class="folio-icon" aria-hidden="true"><use[^>]*\#link"></use></svg><span class="folio-share-status" aria-live="polite"></span></button>#', $bar);
        // Open: the live address, in a new tab.
        $this->assertMatchesRegularExpression('#<a class="btn btn-secondary btn-icon btn-sm folio-preview-open" href="https://example.test/hello" target="_blank" rel="noopener" aria-label="Open the live page in a new tab" title="Open the live page in a new tab">#', $bar);
        // The focus toggle stays.
        $this->assertStringContainsString('data-folio-focus aria-pressed="false"', $bar);
    }

    public function test_changes_not_live_show_the_draft_mark_and_open_the_live_address(): void
    {
        $this->publishPost('c1', 'live');
        $this->working('post', 'c1', ['title' => ['en' => 'Live'], 'slug' => ['en' => 'renamed']]);

        $bar = self::bar($this->editor('c1'));

        $this->assertMatchesRegularExpression('#<span class="folio-preview-url is-draft" title="https://example.test/renamed"><span class="folio-preview-dot" aria-hidden="true"></span><span class="folio-preview-address">example.test/renamed</span><span class="folio-preview-draft">· draft</span></span>#', $bar);
        $this->assertStringContainsString('class="btn btn-secondary btn-icon btn-sm folio-preview-open" href="https://example.test/live"', $bar, 'what visitors see now');
    }

    public function test_a_draft_has_no_open_button(): void
    {
        $this->working('post', 'd1', ['title' => ['en' => 'Draft'], 'slug' => ['en' => 'draft']]);

        $bar = self::bar($this->editor('d1'));

        $this->assertStringContainsString('<span class="folio-preview-url is-draft" title="https://example.test/draft">', $bar);
        $this->assertStringNotContainsString('folio-preview-open', $bar, 'nothing is live yet');
        $this->assertStringContainsString('folio-share-button', $bar, 'a draft can still be shared');
    }

    public function test_without_an_address_only_the_device_switch_and_focus_remain(): void
    {
        $this->working('post', 'n1', ['title' => ['en' => 'No slug']]);

        $bar = self::bar($this->editor('n1'));

        $this->assertStringContainsString('data-preview-viewport="375px"', $bar);
        $this->assertStringContainsString('data-folio-focus', $bar);
        $this->assertStringNotContainsString('folio-preview-url', $bar);
        $this->assertStringNotContainsString('folio-share-button', $bar);
        $this->assertStringNotContainsString('folio-preview-open', $bar);
    }

    public function test_without_app_url_the_pill_shows_the_path(): void
    {
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Pub', 'debug' => 1, 'engine' => 'twig', 'key' => 'folio-integration-test-key'];\n");
        $this->publishPost('p1', 'hello');

        $bar = self::bar($this->editor('p1'));

        $this->assertStringContainsString('<span class="folio-preview-address">/hello</span>', $bar);
        $this->assertStringContainsString('class="btn btn-secondary btn-icon btn-sm folio-preview-open" href="/hello"', $bar);
    }

    public function test_the_share_answer_lands_under_the_bar(): void
    {
        $this->publishPost('p1', 'hello');

        $page = $this->editor('p1');
        $stage = substr($page, (int) strpos($page, '<section class="folio-split-stage"'));

        $this->assertMatchesRegularExpression('#</div>\s*<div class="folio-share" id="folio-share"></div>\s*<iframe#', $stage, 'the share answer sits in the stage, between the bar and the preview');
        $this->assertSame(1, substr_count($page, 'id="folio-share"'));
    }
}
