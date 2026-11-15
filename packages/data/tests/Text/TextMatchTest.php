<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Text;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Text\TextMatch;

/** The text-matching rule in PHP: Unicode simple case folding and nothing else. */
final class TextMatchTest extends TestCase
{
    public function test_fold_ignores_case_and_keeps_accents(): void
    {
        $this->assertSame('über', TextMatch::fold('ÜBER'));
        $this->assertSame('été', TextMatch::fold('Été'));
        $this->assertSame('straße', TextMatch::fold('Straße'));
        $this->assertSame('straße', TextMatch::fold('STRAẞE'), 'the capital sharp s folds to ß');
        $this->assertSame('k', TextMatch::fold("\u{212A}"), 'the Kelvin sign folds to k');
        $this->assertNotSame('s', TextMatch::fold("\u{017F}"), 'the long s is a letter of its own');
        $this->assertSame('İ', TextMatch::fold('İ'), 'İ is not i');
        $this->assertSame('ı', TextMatch::fold('ı'), 'ı is a letter of its own');
        $this->assertSame('i', TextMatch::fold('I'), 'no Turkish locale rules');
        $this->assertSame('ü', TextMatch::fold("\u{00DC}"));
        $this->assertSame("u\u{0308}", TextMatch::fold("U\u{0308}"), 'a decomposed Ü stays decomposed');
    }

    public function test_fold_takes_width_and_compatibility_forms_as_their_letter(): void
    {
        $this->assertSame('abc', TextMatch::fold('ａｂｃ'));
        $this->assertSame('abc', TextMatch::fold('ＡＢＣ'));
        $this->assertSame('1', TextMatch::fold('①'));
        $this->assertSame('a', TextMatch::fold('ª'));
        $this->assertSame('a', TextMatch::fold('Ⓐ'));
        $this->assertSame('h', TextMatch::fold('ℌ'));
        $this->assertSame('ﬁ', TextMatch::fold('ﬁ'), 'a ligature is one character, not "fi"');
    }

    /** The table maps one character to one, never to a character it maps on; in ASCII only A-Z. */
    public function test_the_fold_table_is_one_character_to_one_and_stable(): void
    {
        $table = require __DIR__ . '/../../src/Text/fold-table.php';

        $this->assertGreaterThan(1000, count($table));
        foreach ($table as $char => $fold) {
            $this->assertSame(1, mb_strlen((string) $char, 'UTF-8'));
            $this->assertSame(1, mb_strlen($fold, 'UTF-8'));
            $this->assertArrayNotHasKey($fold, $table, sprintf('U+%04X folds to a character that folds on', mb_ord((string) $char, 'UTF-8')));
            if (strlen((string) $char) === 1) {
                $this->assertSame(strtolower((string) $char), $fold);
            }
        }
        $this->assertSame(range('A', 'Z'), array_values(array_filter(array_map('strval', array_keys($table)), static fn (string $c): bool => strlen($c) === 1)));
    }

    public function test_like_and_contains_follow_the_table(): void
    {
        // Case.
        $this->assertTrue(TextMatch::like('Über', 'über'));
        $this->assertTrue(TextMatch::like('Über', 'ÜBER'));
        $this->assertTrue(TextMatch::contains('Über uns', 'über'));
        $this->assertTrue(TextMatch::contains('Über uns', 'ÜBER'));
        // Accents count.
        $this->assertFalse(TextMatch::like('Über', 'uber'));
        $this->assertFalse(TextMatch::like('été', 'ete'));
        $this->assertFalse(TextMatch::contains('Über uns', 'uber'));
        $this->assertFalse(TextMatch::contains('été', 'ete'));
        // ß is one letter.
        $this->assertFalse(TextMatch::like('Straße', 'STRASSE'));
        $this->assertFalse(TextMatch::like('Straße', 'strasse'));
        $this->assertFalse(TextMatch::contains('Straße', 'strasse'));
        // ẞ folds to ß.
        $this->assertTrue(TextMatch::like('Straße', 'STRAẞE'));
        $this->assertTrue(TextMatch::contains('Straße', 'ẞ'));
        // The Kelvin sign is a k, both directions.
        $this->assertTrue(TextMatch::like("\u{212A}elvin", 'kelvin'));
        $this->assertTrue(TextMatch::like('kelvin', "\u{212A}ELVIN"));
        $this->assertTrue(TextMatch::contains("\u{212A}elvin", 'kel'));
        $this->assertTrue(TextMatch::contains('Kelvin', "\u{212A}"));
        // İ: the same letter matches, i does not.
        $this->assertTrue(TextMatch::like('İstanbul', 'İSTANBUL'));
        $this->assertTrue(TextMatch::contains('İstanbul', 'İSTAN'));
        $this->assertFalse(TextMatch::like('İstanbul', 'istanbul'));
        $this->assertFalse(TextMatch::contains('İstanbul', 'ist'));
        // ı is a letter of its own.
        $this->assertFalse(TextMatch::like('ılık', 'ILIK'));
        $this->assertFalse(TextMatch::like('ılık', 'ilik'));
        $this->assertFalse(TextMatch::contains('ılık', 'ILI'));
        $this->assertTrue(TextMatch::like('ılık', 'ılık'));
        // The long s is a letter of its own, both ways.
        $this->assertFalse(TextMatch::like("\u{017F}uper", 'super'));
        $this->assertFalse(TextMatch::like('super', "\u{017F}uper"));
        $this->assertFalse(TextMatch::contains('super', "\u{017F}"));
        // Width and compatibility forms.
        $this->assertTrue(TextMatch::like('ａｂｃ', 'ABC'));
        $this->assertTrue(TextMatch::like('abc', 'ＡＢＣ'));
        $this->assertTrue(TextMatch::contains('Kapitel ①', '1'));
        $this->assertTrue(TextMatch::like('５０％', '50!%', '!'), 'a fullwidth percent sign is a percent sign');
        $this->assertFalse(TextMatch::like('50 Stück', '50％'), 'a fullwidth percent sign in the pattern is no wildcard');
        // Wildcards and escape.
        $this->assertTrue(TextMatch::like('Über uns', '_ber%'), '_ is one character, an umlaut too');
        $this->assertFalse(TextMatch::like('Über uns', '_ber'));
        $this->assertTrue(TextMatch::like('A% off', 'a!%%', '!'));
        $this->assertFalse(TextMatch::like('Ab off', 'a!%%', '!'));
        $this->assertTrue(TextMatch::like("line\nbreak", 'line%'), '% takes a line break');
        $this->assertTrue(TextMatch::like('a.b', 'a.b'));
        $this->assertFalse(TextMatch::like('axb', 'a.b'), 'regex characters are literal');
    }

    public function test_ascii_safe_patterns(): void
    {
        $this->assertFalse(TextMatch::asciiSafe('%ber%'), 'ｂ, ｅ and ｒ fold into b, e and r');
        $this->assertFalse(TextMatch::asciiSafe('%kart%'));
        $this->assertFalse(TextMatch::asciiSafe('%über%'));
        $this->assertTrue(TextMatch::asciiSafe("\t\n"));
        $this->assertTrue(TextMatch::asciiSafe(''));
    }
}
