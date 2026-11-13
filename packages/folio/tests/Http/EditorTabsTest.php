<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Tests\Support\PublishingScaffold;

/**
 * The editor's tabs (0.75): Content · SEO & sharing replace the "SEO & sharing" disclosure, only
 * where the section exists. Both panels stay in the record form (one save posts both); without JS
 * both show, the second under its heading; a refused share image opens the form on the SEO tab.
 */
final class EditorTabsTest extends TestCase
{
    use PublishingScaffold;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->write('config/seo.php', "<?php\nreturn ['enabled' => true];\n");
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_tablist_with_both_panels_inside_the_record_form(): void
    {
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello']]);

        $page = (string) $this->get('/folio/post/p1/edit')->getBody();
        $form = substr($page, (int) strpos($page, '<form'), (int) strpos($page, '</form>') - (int) strpos($page, '<form'));

        $this->assertStringContainsString('<div class="folio-editor-tabs" data-folio-tabs>', $form);
        $this->assertMatchesRegularExpression('~<div class="folio-tabs" role="tablist" aria-label="Editor sections">\s*<button type="button" data-folio-tab="content" data-folio-tab-active>Content</button>\s*<button type="button" data-folio-tab="seo">SEO & sharing</button>~', $form);
        $this->assertStringContainsString('<div class="folio-tab-panel" data-folio-tabpanel="content" id="folio-tab-content">', $form);
        $this->assertStringContainsString('<div class="folio-tab-panel" data-folio-tabpanel="seo" id="folio-tab-seo">', $form);
        $this->assertLessThan(strpos($form, 'id="folio-tab-seo"'), strpos($form, 'name="title"'), 'the fields in the first panel');
        $this->assertGreaterThan(strpos($form, 'id="folio-tab-seo"'), strpos($form, 'name="_seo[title]"'), 'the share texts in the second');
        $this->assertStringNotContainsString('<details class="folio-seo"', $page, 'no disclosure any more');
        $this->assertStringNotContainsString('folio-tab-error', $page);

        // No section, no tabs.
        $this->working('note', 'n1', ['name' => 'A note']);
        $note = (string) $this->get('/folio/note/n1/edit')->getBody();
        $this->assertStringNotContainsString('data-folio-tabs', $note);
        $this->assertStringContainsString('<fieldset class="folio-fields">', $note);
    }

    #[RunInSeparateProcess]
    public function test_without_js_both_panels_show_one_after_the_other(): void
    {
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello']]);

        $page = (string) $this->get('/folio/post/p1/edit')->getBody();

        // Nothing is hidden in the markup: admin.js hides the inactive panel and shows the tab bar.
        $this->assertDoesNotMatchRegularExpression('~data-folio-tabpanel="[^"]*"[^>]* hidden~', $page);
        $this->assertStringContainsString('<h2 class="folio-tab-heading">SEO & sharing</h2>', $page, 'the second panel\'s heading (hidden once the tabs run)');
        $css = (string) file_get_contents(__DIR__ . '/../../assets/admin.css');
        $this->assertStringContainsString('.folio-editor-tabs:not([data-folio-tabs-ready]) > .folio-tabs { display: none; }', $css);
        $this->assertStringContainsString('[data-folio-tabs-ready] .folio-tab-heading { display: none; }', $css);
    }

    #[RunInSeparateProcess]
    public function test_a_refused_share_image_selects_the_seo_tab_and_marks_it(): void
    {
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello']]);
        $f = new Psr17Factory();
        $text = $f->createUploadedFile($f->createStream('not an image'), 12, UPLOAD_ERR_OK, 'share.txt', 'text/plain');
        $req = $f->createServerRequest('POST', '/folio/post/p1')->withHeader('Sec-Fetch-Site', 'same-origin')
            ->withParsedBody(['title' => 'Hello', 'slug' => 'hello', '_locale' => 'en', '_seo_submitted' => '1', '_seo' => ['title' => 'Share']])
            ->withUploadedFiles(['_seo' => ['image' => $text]]);

        $res = $this->app()->handle($req);
        $page = (string) $res->getBody();

        $this->assertSame(422, $res->getStatusCode());
        $this->assertMatchesRegularExpression('~<button type="button" data-folio-tab="content">Content</button>~', $page, 'Content is not the active tab');
        $this->assertMatchesRegularExpression('~<button type="button" data-folio-tab="seo" data-folio-tab-active>SEO & sharing<span class="folio-tab-error" aria-hidden="true"></span><span class="folio-sr"> \(has errors\)</span></button>~', $page);
    }
}
