<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Content\Highlighter;

final class HighlighterTest extends TestCase
{
    public function test_no_search_only_escapes(): void
    {
        $this->assertSame('Tom &amp; &lt;b&gt;Jerry&lt;/b&gt;', Highlighter::html('Tom & <b>Jerry</b>', ''));
    }

    public function test_marks_every_word_case_insensitively(): void
    {
        $this->assertSame(
            '<mark>Night</mark> at the <mark>Zo</mark>o',
            Highlighter::html('Night at the Zoo', 'night zo'),
        );
    }

    public function test_marks_every_occurrence(): void
    {
        $this->assertSame('<mark>a</mark>b<mark>a</mark>', Highlighter::html('aba', 'A'));
    }

    public function test_word_never_matches_inside_an_escaped_entity(): void
    {
        $this->assertSame('Tom &amp; Jerry', Highlighter::html('Tom & Jerry', 'amp'));
        $this->assertSame('<mark>&amp;</mark> &lt;x&gt;', Highlighter::html('& <x>', '&'));
        $this->assertSame('&lt;<mark>b</mark>&gt;', Highlighter::html('<b>', 'b'));
    }

    public function test_search_word_with_markup_is_text(): void
    {
        $this->assertSame('a <mark>&lt;i&gt;</mark> b', Highlighter::html('a <i> b', '<i>'));
        $this->assertSame('plain', Highlighter::html('plain', '<script>'));
    }

    public function test_overlapping_words_make_one_mark(): void
    {
        $this->assertSame('<mark>Nightsky</mark>', Highlighter::html('Nightsky', 'nights tsky'));
    }

    public function test_multibyte_case_insensitive(): void
    {
        $this->assertSame('<mark>Über</mark> <mark>straße</mark>', Highlighter::html('Über straße', 'über STRASSE straße'));
        $this->assertSame('f<mark>ö</mark>o', Highlighter::html('föo', 'Ö'));
    }

    public function test_regex_characters_are_literal(): void
    {
        $this->assertSame('a<mark>.*</mark>b (c)', Highlighter::html('a.*b (c)', '.*'));
        $this->assertSame('100<mark>%</mark>', Highlighter::html('100%', '%'));
    }
}
