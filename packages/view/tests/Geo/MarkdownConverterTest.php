<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Geo;

use PHPUnit\Framework\TestCase;
use Preflow\View\Geo\GeoCollector;
use Preflow\View\Geo\MarkdownConverter;

final class MarkdownConverterTest extends TestCase
{
    private const BASE = 'https://site.test';

    private function md(string $body, ?GeoCollector $geo = null): string
    {
        return (new MarkdownConverter($geo))->convert(
            '<!DOCTYPE html><html><head><title>T</title></head><body>' . $body . '</body></html>',
            self::BASE,
        );
    }

    public function test_headings(): void
    {
        $html = '<h1>One</h1><h2>Two</h2><h3>Three</h3><h4>Four</h4><h5>Five</h5><h6>Six</h6>';

        $this->assertSame(
            "# One\n\n## Two\n\n### Three\n\n#### Four\n\n##### Five\n\n###### Six",
            $this->md($html),
        );
    }

    public function test_paragraphs(): void
    {
        $this->assertSame("First.\n\nSecond.", $this->md('<p>First.</p><p>Second.</p>'));
    }

    public function test_strong_and_b(): void
    {
        $this->assertSame(
            'A **bold** and **b** word.',
            $this->md('<p>A <strong>bold</strong> and <b>b</b> word.</p>'),
        );
    }

    public function test_em_and_i(): void
    {
        $this->assertSame(
            'An *em* and *i* word, **spaces *outside*** .',
            $this->md('<p>An <em>em</em> and <i>i</i> word, <strong>spaces <em>outside </em></strong>.</p>'),
        );
    }

    public function test_links(): void
    {
        $html = '<p><a href="https://other.test/x">Abs</a> <a href="#top">Anchor</a> '
            . '<a href="javascript:void(0)">Js</a> <a href="mailto:a@b.test">Mail</a> '
            . '<a href="tel:+41123">Call</a> <a>No href</a></p>';

        $this->assertSame(
            '[Abs](https://other.test/x) Anchor Js [Mail](mailto:a@b.test) [Call](tel:+41123) No href',
            $this->md($html),
        );
    }

    public function test_only_web_mail_and_phone_links_are_kept(): void
    {
        $html = '<p><a href="http://a.test/">Http</a> <a href="ftp://a.test/f">Ftp</a> <a href="data:text/html,x">Data</a> '
            . '<a href="vbscript:x">Vb</a> <a href="sms:+41">Sms</a> <a href="JavaScript:x">Js</a> <a href="page">Rel</a></p>';

        $this->assertSame(
            '[Http](http://a.test/) Ftp Data Vb Sms Js [Rel](https://site.test/page)',
            $this->md($html),
        );
    }

    public function test_data_and_other_non_web_images_are_dropped(): void
    {
        $html = '<p><img src="data:image/png;base64,iVBORw0KGgo=" alt="Inline"> <img src="file:///etc/x.png" alt="File">'
            . ' <img src="HTTPS://cdn.test/a.png" alt="Web"> <img src="b.png" alt="Rel"></p>';

        $this->assertSame('![Web](HTTPS://cdn.test/a.png) ![Rel](https://site.test/b.png)', $this->md($html));
    }

    public function test_linked_cards_become_blocks_with_the_link_on_their_heading(): void
    {
        $card = static fn (string $slug, string $title, string $text): string =>
            '<a class="card" href="/forces/' . $slug . '"><article><img src="/i/' . $slug . '.jpg" alt="' . $title . ' photo">'
            . '<h3>' . $title . '</h3><p>' . $text . '</p><span class="more">Read more</span></article></a>';
        $html = '<h2>Forces</h2><div class="grid">' . $card('one', 'Force one', 'First description.')
            . $card('two', 'Force two', 'Second description.') . '</div>';

        $this->assertSame(
            "## Forces\n\n"
            . "![Force one photo](https://site.test/i/one.jpg)\n\n### [Force one](https://site.test/forces/one)\n\nFirst description.\n\nRead more\n\n"
            . "![Force two photo](https://site.test/i/two.jpg)\n\n### [Force two](https://site.test/forces/two)\n\nSecond description.\n\nRead more",
            $this->md($html),
        );
    }

    public function test_game_card_without_heading_links_its_first_text_block(): void
    {
        // Shaped like a game card: cover, title and designer in divs, badges, a footer with a call to action.
        $card = '<a href="/play/river-towns" class="game-card">'
            . '<div class="game-card__cover"><img src="/media/river.jpg" alt="Cover of River Towns"></div>'
            . '<div class="game-card__body"><div class="game-card__title">River Towns</div>'
            . '<div class="game-card__designer">Ada Example</div>'
            . '<div class="game-card__meta"><span class="badge"><i class="fa-solid fa-brain"></i> Light</span> '
            . '<span class="badge"><i class="fa-solid fa-users"></i> 2–4</span> '
            . '<span class="badge"><i class="fa-solid fa-clock"></i> 15 min</span></div></div>'
            . '<div class="game-card__footer"><span class="steps"><i class="fa-solid fa-layer-group"></i> 3 layers, 12 steps</span>'
            . "\n    " . '<span class="cta">Learn it <i class="fa-solid fa-arrow-right"></i></span></div></a>';
        $empty = '<a href="/play/no-cover" class="game-card"><div class="game-card__cover"><i class="fa-solid fa-dice"></i></div>'
            . '<div class="game-card__body"><div class="game-card__title">No Cover</div></div></a>';

        $this->assertSame(
            "![Cover of River Towns](https://site.test/media/river.jpg)\n\n[River Towns](https://site.test/play/river-towns)\n\n"
            . "Ada Example\n\nLight 2–4 15 min\n\n3 layers, 12 steps Learn it\n\n"
            . "[No Cover](https://site.test/play/no-cover)",
            $this->md('<div class="games">' . $card . $empty . '</div>'),
        );
    }

    public function test_gallery_item_link_in_a_list(): void
    {
        // Shaped like a gallery list item: rank, thumbnail without alt, title and maker in spans.
        $item = static fn (int $n, string $title, string $maker): string =>
            '<li class="gallery-item" id="item-' . $n . '"><a href="/gallery/' . $n . '#item-' . $n . '" hx-get="/gallery/' . $n . '">'
            . '<span class="item-rank">' . $n . '.</span> <span class="item-thumb"><img src="/m/' . $n . '.jpg" alt="" loading="lazy"></span> '
            . '<span class="item-text"><strong class="item-title">' . $title . '</strong> <span class="item-builder">' . $maker . '</span></span>'
            . '</a></li>';

        $this->assertSame(
            "- [1\\. **Oak bowl** Bea Sample](https://site.test/gallery/1#item-1)\n- [2\\. **Ash stool** Cy Muster](https://site.test/gallery/2#item-2)",
            $this->md('<ul class="gallery">' . $item(1, 'Oak bowl', 'Bea Sample') . $item(2, 'Ash stool', 'Cy Muster') . '</ul>'),
        );
    }

    public function test_linked_block_with_a_non_web_href_keeps_its_blocks_unlinked(): void
    {
        $this->assertSame(
            "### Title\n\nText",
            $this->md('<a href="javascript:void(0)"><div><h3>Title</h3><p>Text</p></div></a>'),
        );
    }

    public function test_lists(): void
    {
        $html = '<ul><li>One</li><li>Two<ul><li>Two a</li><li>Two b<ol><li>Deep</li></ol></li></ul></li></ul>'
            . '<ol><li>First</li><li>Second</li></ol>';

        $this->assertSame(
            "- One\n- Two\n  - Two a\n  - Two b\n    1. Deep\n\n1. First\n2. Second",
            $this->md($html),
        );
    }

    public function test_blockquote(): void
    {
        $this->assertSame(
            "> Quoted line.\n>\n> Second paragraph.",
            $this->md('<blockquote><p>Quoted line.</p><p>Second paragraph.</p></blockquote>'),
        );
    }

    public function test_pre_becomes_fenced_block(): void
    {
        $html = "<pre><code class=\"language-php\">\$a = 1;\n\nif (\$a) {\n    echo '*x*';\n}\n</code></pre><pre>plain  text</pre>";

        $this->assertSame(
            "```php\n\$a = 1;\n\nif (\$a) {\n    echo '*x*';\n}\n```\n\n```\nplain  text\n```",
            $this->md($html),
        );
    }

    public function test_inline_code(): void
    {
        $this->assertSame(
            'Run `composer *install*` now, or `` a`b ``.',
            $this->md('<p>Run <code>composer   *install*</code> now, or <code>a`b</code>.</p>'),
        );
    }

    public function test_images_need_alt(): void
    {
        $html = '<p><img src="/img/a.png" alt="A chart"> <img src="/img/deco.png"> <img src="/img/e.png" alt=" "></p>';

        $this->assertSame('![A chart](https://site.test/img/a.png)', $this->md($html));
    }

    public function test_table_with_thead(): void
    {
        $html = '<table><thead><tr><th>Name</th><th>Score</th></tr></thead>'
            . '<tbody><tr><td>Ann</td><td>3 | 4</td></tr><tr><td><strong>Bob</strong></td><td>5</td></tr></tbody></table>';

        $this->assertSame(
            "| Name | Score |\n| --- | --- |\n| Ann | 3 \\| 4 |\n| **Bob** | 5 |",
            $this->md($html),
        );
    }

    public function test_table_without_thead_uses_first_row(): void
    {
        $html = '<table><tr><td>A</td><td>B</td></tr><tr><td>1</td></tr></table>';

        $this->assertSame("| A | B |\n| --- | --- |\n| 1 |  |", $this->md($html));
    }

    public function test_definition_list(): void
    {
        $html = '<dl><dt>Term</dt><dd>The definition.</dd><div><dt>Other</dt><dd>More.</dd></div></dl>';

        $this->assertSame("**Term**\n\nThe definition.\n\n**Other**\n\nMore.", $this->md($html));
    }

    public function test_br_is_a_line_break(): void
    {
        $this->assertSame(
            "Line one  \nLine two\n\nNew block",
            $this->md('<p>Line one<br>Line two<br><br>New block</p>'),
        );
    }

    public function test_hr(): void
    {
        $this->assertSame("Above\n\n---\n\nBelow", $this->md('<p>Above</p><hr><p>Below</p>'));
    }

    public function test_figure_with_caption(): void
    {
        $html = '<figure><img src="/a.jpg" alt="Board"><figcaption>The <em>board</em>.</figcaption></figure>';

        $this->assertSame("![Board](https://site.test/a.jpg)\n\nThe *board*.", $this->md($html));
    }

    public function test_placeholder_is_replaced_by_its_block(): void
    {
        $geo = new GeoCollector();
        $first = $geo->placeholder("## Card\n\nBody *as is*");
        $empty = $geo->placeholder('');
        $inList = $geo->placeholder('- From geo');

        $html = '<main><p>Before</p><div class="c">' . $first . '</div><div>' . $empty . '</div>'
            . '<ul><li>Item</li>' . $inList . '</ul><p>After</p></main>';

        $this->assertSame(
            "Before\n\n## Card\n\nBody *as is*\n\n- Item\n- From geo\n\nAfter",
            $this->md($html, $geo),
        );
    }

    public function test_unknown_elements_render_their_children(): void
    {
        $html = '<section><div class="x"><span class="y">Inline <span>text</span></span>'
            . '<x-card><h3>Card</h3><p>Body</p></x-card></div></section>';

        $this->assertSame("Inline text\n\n### Card\n\nBody", $this->md($html));
    }

    public function test_whitespace_is_collapsed_outside_pre(): void
    {
        $html = "<div>\n   <p>\n  Lots   of\n\n  space  <strong> here </strong> .\n</p>\n</div>";

        $this->assertSame('Lots of space **here** .', $this->md($html));
    }

    public function test_text_with_asterisks_is_escaped(): void
    {
        $html = '<p>5 * 3 = 15, snake_case, [x] and `y`</p><p># not a heading</p><p>&gt; not a quote</p>'
            . '<p>- not a list</p><p>1. not ordered</p><h2>#1 tip</h2>';

        $this->assertSame(
            "5 \\* 3 = 15, snake\\_case, \\[x\\] and \\`y\\`\n\n\\# not a heading\n\n\\> not a quote"
            . "\n\n\\- not a list\n\n1\\. not ordered\n\n## \\#1 tip",
            $this->md($html),
        );
    }

    public function test_takes_main_and_drops_navigation_and_footer(): void
    {
        $html = '<header><nav><a href="/">Home</a></nav></header><main><h1>Title</h1></main>'
            . '<footer><p>Copyright</p></footer>';

        $this->assertSame('# Title', $this->md($html));
        $this->assertSame("Home\n\nBody", $this->md('<nav><a href="#x">Home</a></nav><p>Body</p>'));
    }

    public function test_removes_scripts_svg_forms_hidden_and_skip(): void
    {
        $html = '<main><script>var x = 1;</script><style>p{}</style><template><p>T</p></template>'
            . '<svg><text>S</text></svg><noscript>N</noscript><iframe src="/x"></iframe>'
            . '<form><label>Label</label><input value="i"><select><option>o</option></select>'
            . '<textarea>t</textarea><button>b</button></form>'
            . '<p hidden>Hidden</p><div aria-hidden="true">Aria</div><div data-geo="skip">Skip</div>'
            . '<p>Kept</p></main>';

        $this->assertSame("Label\n\nKept", $this->md($html));
    }

    public function test_relative_links_and_images_become_absolute(): void
    {
        $html = '<p><a href="/de/about">About</a> <a href="//cdn.test/f">Cdn</a> <a href="team?x=1">Team</a> '
            . '<img src="/media/a b.png" alt="A"></p>';

        $this->assertSame(
            '[About](https://site.test/de/about) [Cdn](https://cdn.test/f) [Team](https://site.test/team?x=1) '
            . '![A](https://site.test/media/a%20b.png)',
            $this->md($html),
        );
    }

    public function test_relative_links_resolve_against_a_base_path(): void
    {
        $markdown = (new MarkdownConverter())->convert(
            '<p><a href="team">Team</a> <a href="../up">Up</a> <a href="?q=1">Query</a></p>',
            'https://site.test/de/about/',
        );

        $this->assertSame(
            '[Team](https://site.test/de/about/team) [Up](https://site.test/de/up) [Query](https://site.test/de/about/?q=1)',
            $markdown,
        );
    }

    public function test_real_page_fixture(): void
    {
        $dir = __DIR__ . '/Fixtures';

        $markdown = (new MarkdownConverter())->convert(
            (string) file_get_contents($dir . '/page.html'),
            'https://smart-stack.test',
        );

        $this->assertSame(rtrim((string) file_get_contents($dir . '/page.md')), $markdown);
    }
}
