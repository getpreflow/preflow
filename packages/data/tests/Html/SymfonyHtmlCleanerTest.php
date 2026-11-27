<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Html;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Data\Html\HtmlCleaner;
use Preflow\Data\Html\SymfonyHtmlCleaner;

/**
 * The core's rich text cleaner (0.93): the config Folio's rich text field used since 0.53 (safe
 * elements, relative links and media), built on the first clean(). Idempotent by test: cleaning
 * its own output keeps the bytes, so a record saved again keeps its change date.
 */
final class SymfonyHtmlCleanerTest extends TestCase
{
    public function test_cleans_script_and_event_handlers(): void
    {
        $cleaner = SymfonyHtmlCleaner::standard();

        $this->assertInstanceOf(HtmlCleaner::class, $cleaner);
        $this->assertSame('<p>hi</p>', $cleaner->clean('<p onclick="x()">hi<script>alert(1)</script></p>'));
        $this->assertSame('<a>link</a>', $cleaner->clean('<a href="javascript:alert(1)">link</a>'));
        $this->assertSame('<img />', $cleaner->clean('<img src="javascript:alert(1)" onerror="alert(1)">'));
        $this->assertStringNotContainsString('iframe', $cleaner->clean('<iframe src="https://example.org"></iframe>text'));
    }

    /**
     * The corpus rich text holds: headings, lists, links, relative images, entities and nested
     * inline elements, as a client writes them (not yet canonical).
     *
     * @return array<string, array{string}>
     */
    public static function corpus(): array
    {
        return [
            'headings' => ['<h1>Title</h1><h2>Sub</h2><h3>Third</h3>'],
            'paragraphs with line breaks' => ['<p>One<br>Two<br/>Three</p>'],
            'unordered list' => ['<ul><li>One</li><li>Two <strong>bold</strong></li></ul>'],
            'nested ordered list' => ['<ol><li>One<ol><li>One.one</li></ol></li><li>Two</li></ol>'],
            'absolute link' => ['<p><a href="https://example.org/a?b=1&c=2" title="Example">Example</a></p>'],
            'relative link' => ['<a href="/about-us#team">Team</a>'],
            'relative image' => ['<p><img src="/media/a0f3/photo.jpg" alt="A photo" width="640" height="480"></p>'],
            'entities' => ['<p>Fish &amp; chips &lt;3 &quot;quoted&quot; &#39;single&#39; 1 + 1 = 2 &nbsp;€ ä @team `code`</p>'],
            'nested inline' => ['<p><strong>bold <em>and italic <del>struck</del></em></strong> <code>x</code></p>'],
            'blockquote and pre' => ["<blockquote>Quote</blockquote><pre>line one\n  line two &lt;b&gt;</pre>"],
            'trix div with breaks' => ['<div>Mon–Fri <strong>9–17</strong><br>Sat <em>closed</em></div>'],
            'table' => ['<table><thead><tr><th>Size</th></tr></thead><tbody><tr><td>S</td></tr></tbody></table>'],
        ];
    }

    #[DataProvider('corpus')]
    public function test_is_idempotent_on_its_own_output(string $html): void
    {
        $cleaner = SymfonyHtmlCleaner::standard();
        $once = $cleaner->clean($html);

        $this->assertNotSame('', $once, 'the corpus is safe: cleaning keeps its text');
        $this->assertSame($once, $cleaner->clean($once), 'clean(clean(x)) === clean(x)');
    }

    /**
     * HTML as Folio 0.92 saved it (its rich text field sanitized every save with this config)
     * comes back byte for byte: a record Folio saved keeps its bytes and change date on its next
     * save by any path.
     */
    public function test_folio_saved_html_keeps_its_bytes(): void
    {
        $saved = [
            '<div>Mon–Fri <strong>9–17</strong><br />Sat <em>closed</em></div>',
            '<h1>Opening hours</h1><ul><li>One &amp; two</li><li><a href="https://example.org/a?b&#61;1&amp;c&#61;2">Link</a></li></ul>',
            '<blockquote>Quote</blockquote><pre>code &lt;b&gt;</pre><p>Grüße &#34;quoted&#34; &#039;single&#039;</p>',
            '<p><img src="/media/a0f3/photo.jpg" alt="A photo" /></p><ol><li><del>old</del> new</li></ol>',
            '',
        ];
        $cleaner = SymfonyHtmlCleaner::standard();

        foreach ($saved as $html) {
            $this->assertSame($html, $cleaner->clean($html));
        }
    }

    public function test_built_lazily(): void
    {
        SymfonyHtmlCleaner::$built = 0;
        $cleaner = SymfonyHtmlCleaner::standard();
        $this->assertSame(0, SymfonyHtmlCleaner::$built, 'no sanitizer before the first clean()');

        $cleaner->clean('<b>x</b>');
        $cleaner->clean('<i>y</i>');
        $this->assertSame(1, SymfonyHtmlCleaner::$built, 'built once, on the first clean()');
    }
}
