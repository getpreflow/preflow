<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Seo;

use PHPUnit\Framework\TestCase;
use Preflow\View\Seo\HeadTags;
use Preflow\View\Seo\PageMeta;
use Preflow\View\Seo\Schema;

final class HeadTagsTest extends TestCase
{
    private const string PAGE = "<!doctype html>\n<html><head>\n<title>About</title>\n</head><body><p>Hi</p></body></html>";

    private function meta(array $changes = []): PageMeta
    {
        return (new PageMeta(
            title: 'About us',
            description: 'Who we are.',
            image: ['url' => 'https://example.com/media/share-1200.jpg', 'width' => 1200, 'height' => 630, 'alt' => 'Our team'],
            url: 'https://example.com/about',
            locale: 'de-CH',
            alternateLocales: ['fr-CH', 'en'],
        ))->with($changes);
    }

    /** @return list<array> */
    private function graph(PageMeta $meta): array
    {
        return [Schema::webPage($meta->url, $meta->title, $meta->description, 'de', 'https://example.com')];
    }

    private function assertOnce(string $needle, string $html): void
    {
        $this->assertSame(1, substr_count($html, $needle), "Expected exactly one {$needle}");
    }

    public function test_full_card(): void
    {
        $meta = $this->meta();
        $html = HeadTags::inject(self::PAGE, $meta, 'Example', ['' => '#3366ff'], $this->graph($meta));

        $this->assertOnce('<meta name="robots" content="max-image-preview:large">', $html);
        $this->assertOnce('<meta name="theme-color" content="#3366ff">', $html);
        $this->assertOnce('<meta property="og:site_name" content="Example">', $html);
        $this->assertOnce('<meta property="og:title" content="About us">', $html);
        $this->assertOnce('<meta property="og:description" content="Who we are.">', $html);
        $this->assertOnce('<meta property="og:type" content="website">', $html);
        $this->assertOnce('<meta property="og:url" content="https://example.com/about">', $html);
        $this->assertOnce('<meta property="og:locale" content="de_CH">', $html);
        $this->assertOnce('<meta property="og:locale:alternate" content="fr_CH">', $html);
        $this->assertOnce('<meta property="og:locale:alternate" content="en">', $html);
        $this->assertOnce('<meta property="og:image" content="https://example.com/media/share-1200.jpg">', $html);
        $this->assertOnce('<meta property="og:image:width" content="1200">', $html);
        $this->assertOnce('<meta property="og:image:height" content="630">', $html);
        $this->assertOnce('<meta property="og:image:alt" content="Our team">', $html);
        $this->assertOnce('<meta name="twitter:card" content="summary_large_image">', $html);
        $this->assertOnce('<script type="application/ld+json">', $html);
        $this->assertOnce('"@id":"https://example.com/about#webpage"', $html);

        // Everything lands inside the head, in the documented order.
        $head = substr($html, 0, (int) strpos($html, '</head>'));
        $order = ['name="robots"', 'name="theme-color"', 'og:site_name', 'og:title', 'og:description', 'og:type', 'og:url',
            '"og:locale"', 'og:locale:alternate', '"og:image"', 'og:image:alt', 'twitter:card', 'application/ld+json'];
        $positions = array_map(static fn (string $needle): int|false => strpos($head, $needle), $order);
        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
        $this->assertStringEndsWith('<body><p>Hi</p></body></html>', $html);
    }

    public function test_without_image_the_card_is_a_summary(): void
    {
        $html = HeadTags::inject(self::PAGE, $this->meta(['image' => null]), '', [], []);

        $this->assertOnce('<meta name="twitter:card" content="summary">', $html);
        $this->assertStringNotContainsString('og:image', $html);
        $this->assertStringNotContainsString('og:site_name', $html);
        $this->assertStringNotContainsString('theme-color', $html);
        $this->assertStringNotContainsString('application/ld+json', $html);
    }

    public function test_light_and_dark_theme_colors(): void
    {
        $html = HeadTags::inject(self::PAGE, $this->meta(), '', ['light' => '#ffffff', 'dark' => '#111111'], []);

        $this->assertOnce('<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">', $html);
        $this->assertOnce('<meta name="theme-color" content="#111111" media="(prefers-color-scheme: dark)">', $html);
    }

    public function test_existing_tags_are_not_duplicated(): void
    {
        $page = "<html><HEAD>\n<meta property='og:title' content='X'>\n<META NAME=\"Robots\" content=\"noindex\">\n"
            . "<meta name=\"theme-color\" content=\"#000\">\n"
            . "<script type=\"application/ld+json\">{\"@type\":\"Thing\"}</script>\n</HEAD><body></body></html>";
        $meta = $this->meta();
        $html = HeadTags::inject($page, $meta, 'Example', ['' => '#3366ff'], $this->graph($meta));

        $this->assertOnce("<meta property='og:title' content='X'>", $html);
        $this->assertSame(1, substr_count(strtolower($html), 'og:title'));
        $this->assertSame(1, substr_count(strtolower($html), 'name="robots"'));
        $this->assertSame(1, substr_count($html, 'theme-color'));
        $this->assertSame(1, substr_count($html, 'application/ld+json'));
        $this->assertStringNotContainsString('max-image-preview', $html);
        // Tags the page lacks are still added.
        $this->assertOnce('<meta property="og:description" content="Who we are.">', $html);
    }

    public function test_tags_outside_the_head_do_not_count(): void
    {
        $page = "<html><head><title>T</title></head><body><meta property=\"og:title\" content=\"Body\"></body></html>";
        $html = HeadTags::inject($page, $this->meta(), '', [], []);

        $this->assertOnce('<meta property="og:title" content="About us">', $html);
    }

    public function test_page_image_suppresses_all_image_tags(): void
    {
        $page = "<html><head><meta property=\"og:image\" content=\"https://example.com/own.jpg\"></head><body></body></html>";
        $html = HeadTags::inject($page, $this->meta(), '', [], []);

        $this->assertSame(1, substr_count($html, 'og:image'));
        $this->assertStringNotContainsString('share-1200', $html);
        $this->assertOnce('<meta name="twitter:card" content="summary_large_image">', $html);
    }

    public function test_hostile_text_is_escaped(): void
    {
        $title = 'Tom & "Jerry" </script><script>alert(1)</script>';
        $meta = $this->meta([
            'title' => $title,
            'description' => "<p>Cat &amp; <b>mouse</b></p>\n\n  <script>alert(2)</script>chase",
        ]);
        $html = HeadTags::inject(self::PAGE, $meta, 'Ex"ample', [], $this->graph($meta));

        $this->assertStringContainsString(
            '<meta property="og:title" content="Tom &amp; &quot;Jerry&quot; &lt;/script&gt;&lt;script&gt;alert(1)&lt;/script&gt;">',
            $html,
        );
        $this->assertStringContainsString('<meta property="og:site_name" content="Ex&quot;ample">', $html);
        $this->assertStringContainsString('<meta property="og:description" content="Cat &amp; mouse alert(2)chase">', $html);
        $this->assertStringNotContainsString('<script>alert', $html);

        preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $match);
        $this->assertNotEmpty($match);
        $this->assertStringNotContainsString('</script', $match[1]);
        $this->assertStringNotContainsString('<', $match[1]);
        $this->assertSame($title, json_decode($match[1], true)['@graph'][0]['name']);
    }

    public function test_noindex_page(): void
    {
        $meta = $this->meta(['noindex' => true]);
        $html = HeadTags::inject(self::PAGE, $meta, 'Example', ['' => '#3366ff'], $this->graph($meta));

        $this->assertOnce('<meta name="robots" content="noindex">', $html);
        $this->assertOnce('<meta name="theme-color" content="#3366ff">', $html);
        $this->assertStringNotContainsString('max-image-preview', $html);
        $this->assertStringNotContainsString('og:', $html);
        $this->assertStringNotContainsString('twitter:', $html);
        $this->assertStringNotContainsString('ld+json', $html);
    }

    public function test_without_head_the_html_is_unchanged(): void
    {
        $fragment = '<div>Fragment</div>';

        $this->assertSame($fragment, HeadTags::inject($fragment, $this->meta(), 'Example', ['' => '#fff'], [['@type' => 'Thing']]));
    }

    public function test_description_is_cut_at_a_word_boundary(): void
    {
        $long = str_repeat('word ', 60);
        $text = HeadTags::description($long);

        $this->assertLessThanOrEqual(200, mb_strlen($text));
        $this->assertStringEndsWith('word…', $text);
        // Multibyte text is cut on characters, never inside one.
        $umlauts = HeadTags::description(str_repeat('Größe — ', 40));
        $this->assertTrue(mb_check_encoding($umlauts, 'UTF-8'));
        $this->assertStringEndsWith('Größe…', $umlauts);
        $this->assertSame('Short text', HeadTags::description("  Short \n\t text "));
        $this->assertSame('Zürich & Genf', HeadTags::description('<em>Zürich</em> &amp; Genf'));
    }

    public function test_without_cards_only_robots_and_theme_color(): void
    {
        $html = HeadTags::inject(self::PAGE, $this->meta(), 'Example', ['' => '#112233'], $this->graph($this->meta()), cards: false);

        $this->assertStringContainsString('<meta name="robots" content="max-image-preview:large">', $html);
        $this->assertStringContainsString('<meta name="theme-color" content="#112233">', $html);
        $this->assertStringNotContainsString('og:', $html);
        $this->assertStringNotContainsString('twitter:', $html);
        $this->assertStringNotContainsString('ld+json', $html);
    }
}
