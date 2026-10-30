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
}
