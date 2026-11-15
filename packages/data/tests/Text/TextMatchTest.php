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
        $this->assertSame('s', TextMatch::fold("\u{017F}"), 'the long s folds to s');
        $this->assertSame('İ', TextMatch::fold('İ'), 'İ has no simple fold');
        $this->assertSame('ı', TextMatch::fold('ı'), 'ı is a letter of its own');
        $this->assertSame('i', TextMatch::fold('I'), 'no Turkish locale rules');
    }

    public function test_only_the_kelvin_sign_and_long_s_fold_into_ascii(): void
    {
        $found = [];
        for ($code = 0x80; $code <= 0x10FFFF; $code++) {
            if ($code >= 0xD800 && $code <= 0xDFFF) {
                continue; // surrogates are no characters
            }
            $char = mb_chr($code, 'UTF-8');
            if (preg_match('/^[\x00-\x7F]+$/', TextMatch::fold($char)) === 1) {
                $found[] = $char;
            }
        }
        sort($found);
        $expected = array_values(TextMatch::FOLDS_INTO_ASCII);
        sort($expected);

        $this->assertSame($expected, $found);
        foreach (TextMatch::FOLDS_INTO_ASCII as $ascii => $char) {
            $this->assertSame($ascii, TextMatch::fold($char));
        }
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
        $this->assertTrue(TextMatch::asciiSafe('%ber%'));
        $this->assertFalse(TextMatch::asciiSafe('%kart%'));
        $this->assertFalse(TextMatch::asciiSafe('%S%'));
        $this->assertFalse(TextMatch::asciiSafe('%über%'));
    }
}
