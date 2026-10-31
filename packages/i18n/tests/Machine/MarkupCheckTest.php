<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests\Machine;

use PHPUnit\Framework\TestCase;
use Preflow\I18n\Machine\MarkupCheck;
use Preflow\I18n\Machine\TextFormat;

/** A machine translation must keep the markup and placeholders of its source intact. */
final class MarkupCheckTest extends TestCase
{
    public function test_html_same_tags_different_attributes_ok(): void
    {
        $this->assertTrue(MarkupCheck::matches('<a href="x">A</a>', '<a class="y" href="z">B</a>', TextFormat::Html));
    }

    public function test_html_dropped_tag_fails(): void
    {
        $this->assertFalse(MarkupCheck::matches('<p>Hi <strong>you</strong></p>', '<p>Hallo du</p>', TextFormat::Html));
    }

    public function test_trix_div_br_kept(): void
    {
        $source = '<div>One<br>Two</div>';
        $this->assertTrue(MarkupCheck::matches($source, '<div>Eins<br />Zwei</div>', TextFormat::Html));
        $this->assertFalse(MarkupCheck::matches($source, '<div>Eins Zwei</div>', TextFormat::Html));
    }

    public function test_placeholders_multiset(): void
    {
        $this->assertTrue(MarkupCheck::matches('{count} :name', ':name {count}', TextFormat::Plain));
        $this->assertFalse(MarkupCheck::matches('{count} :name', '{count}', TextFormat::Plain));
        $this->assertFalse(MarkupCheck::matches('{0} {0}', '{0}', TextFormat::Markdown));
        $this->assertTrue(MarkupCheck::matches('Note: 10:30', 'Hinweis: 10:30', TextFormat::Plain));
    }

    public function test_plain_result_with_tag_fails(): void
    {
        $this->assertFalse(MarkupCheck::matches('Hello', '<b>Hallo</b>', TextFormat::Plain));
        $this->assertTrue(MarkupCheck::matches('<b>Hello</b>', '<b>Hallo</b>', TextFormat::Plain));
    }

    private const GLOSSARY = '/\{\{glossary:(\w+)(?:\|[^{}\r\n]*)?\}\}/';

    public function test_protected_token_key_kept_passes(): void
    {
        $this->assertTrue(MarkupCheck::matches('Take a {{glossary:meeple}} and {{glossary:dice}}', 'Nimm {{glossary:dice}} und {{glossary:meeple}}', TextFormat::Plain, [self::GLOSSARY]));
    }

    public function test_protected_token_dropped_fails(): void
    {
        $this->assertFalse(MarkupCheck::matches('Take a {{glossary:meeple}}', 'Nimm einen Meeple', TextFormat::Plain, [self::GLOSSARY]));
        $this->assertFalse(MarkupCheck::matches('{{glossary:a}} {{glossary:a}}', '{{glossary:a}}', TextFormat::Plain, [self::GLOSSARY]), 'a multiset: each one counts');
        $this->assertFalse(MarkupCheck::matches('Take one', 'Nimm {{glossary:meeple}}', TextFormat::Plain, [self::GLOSSARY]), 'none may be added');
    }

    public function test_protected_token_key_changed_fails(): void
    {
        $this->assertFalse(MarkupCheck::matches('Take a {{glossary:meeple}}', 'Nimm einen {{glossary:figur}}', TextFormat::Plain, [self::GLOSSARY]));
    }

    public function test_protected_token_shown_text_may_change_or_be_added(): void
    {
        $this->assertTrue(MarkupCheck::matches('{{glossary:meeple|the meeples}}', '{{glossary:meeple|die Meeples}}', TextFormat::Plain, [self::GLOSSARY]));
        $this->assertTrue(MarkupCheck::matches('{{glossary:meeple}}', '{{glossary:meeple|Meeples}}', TextFormat::Plain, [self::GLOSSARY]));
        $this->assertTrue(MarkupCheck::matches('<p>{{glossary:meeple|a}}</p>', '<p>{{glossary:meeple|b}}</p>', TextFormat::Html, [self::GLOSSARY]));
    }

    public function test_a_shown_text_never_swallows_a_following_token(): void
    {
        // An unclosed shown text must not reach into the next token and hide that it was dropped.
        $this->assertFalse(MarkupCheck::matches('{{glossary:a|Cards and then {{glossary:b}}', '{{glossary:a|Karten und dann}}', TextFormat::Plain, [self::GLOSSARY]));
    }

    public function test_protected_token_without_a_group_compares_the_whole_match(): void
    {
        $pattern = '/\[\[[a-z]+\]\]/';
        $this->assertTrue(MarkupCheck::matches('Hi [[user]]', 'Hallo [[user]]', TextFormat::Plain, [$pattern]));
        $this->assertFalse(MarkupCheck::matches('Hi [[user]]', 'Hallo [[nutzer]]', TextFormat::Plain, [$pattern]));
    }

    public function test_without_protected_tokens_nothing_changes(): void
    {
        $this->assertTrue(MarkupCheck::matches('Take a {{glossary:meeple}}', 'Nimm einen Meeple', TextFormat::Plain));
    }

    public function test_a_pattern_with_a_group_compares_the_group_also_where_it_did_not_take_part(): void
    {
        // The source token has no key ("ab", the group does not take part); the result's has one ("abc").
        $this->assertFalse(MarkupCheck::matches('ab', 'abc', TextFormat::Plain, ['/(ab)?c|ab/']));
        $this->assertTrue(MarkupCheck::matches('Take ab', 'Nimm ab', TextFormat::Plain, ['/(ab)?c|ab/']));
    }

    public function test_a_pattern_that_cannot_run_fails_the_check(): void
    {
        $jit = ini_get('pcre.jit');
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1');
        try {
            $this->assertFalse(MarkupCheck::matches('aaaaaaaaaab', 'aaaaaaaaaab', TextFormat::Markdown, ['/(a+)+b/']), 'an error is not a pass');
        } finally {
            ini_set('pcre.jit', (string) $jit);
            ini_set('pcre.backtrack_limit', (string) $limit);
        }
    }
}
