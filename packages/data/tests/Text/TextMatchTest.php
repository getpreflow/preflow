<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Text;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Text\TextMatch;

/** The text rules in PHP: matching (LIKE, CONTAINS) character by character, "=" and order over the whole text. */
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

    /** The matching table maps one character to one, never to a character it maps on; in ASCII only A-Z. @since 0.78.1 */
    public function test_the_matching_table_is_one_character_to_one_and_stable(): void
    {
        $table = require __DIR__ . '/../../src/Text/search-fold-table.php';

        $this->assertGreaterThan(5000, count($table));
        foreach ($table as $char => $fold) {
            $this->assertSame(1, mb_strlen((string) $char, 'UTF-8'));
            $this->assertSame(1, mb_strlen($fold, 'UTF-8'));
            $this->assertArrayNotHasKey($fold, $table, sprintf('U+%04X folds to a character that folds on', mb_ord((string) $char, 'UTF-8')));
        }
        $this->assertSame(range('A', 'Z'), array_values(array_filter(array_map('strval', array_keys($table)), static fn (string $c): bool => strlen($c) === 1)));
    }

    /** Matching ignores accents, exact comparisons keep them (@since 0.78.1). */
    public function test_match_fold_ignores_accents_and_fold_keeps_them(): void
    {
        $this->assertSame(TextMatch::matchFold('ELISE'), TextMatch::matchFold('Élise'));
        $this->assertSame('elise', TextMatch::matchFold('Élise'));
        $this->assertSame('uber uns', TextMatch::matchFold('Über uns'));
        $this->assertSame('istanbul', TextMatch::matchFold('İstanbul'));
        $this->assertSame('lodz', TextMatch::matchFold('Łódź'));
        $this->assertSame('straße', TextMatch::matchFold('STRAẞE'), 'ß stays one letter');
        $this->assertSame('ılık', TextMatch::matchFold('ılık'), 'ı stays a letter of its own');
        $this->assertSame('æ', TextMatch::matchFold('Æ'));
        $this->assertSame(mb_strlen("U\u{0308}ber"), mb_strlen(TextMatch::matchFold("U\u{0308}ber")), 'one character for one');
        $this->assertSame('élise', TextMatch::fold('Élise'), 'fold() keeps the accent');
        $this->assertNotSame(TextMatch::fold('elise'), TextMatch::fold('Élise'));

        $this->assertTrue(TextMatch::like('Über uns', '%uber%'));
        $this->assertTrue(TextMatch::like('uber', 'Über'), 'an accented pattern finds the plain word');
        $this->assertTrue(TextMatch::like('UBER', 'über'));
        $this->assertTrue(TextMatch::contains('Élise', 'elise'));
        $this->assertFalse(TextMatch::contains('Straße', 'strasse'));
        $this->assertFalse(TextMatch::like('Ægir', 'aegir'));
        $this->assertFalse(TextMatch::equals('Muller', 'Müller'), '"=" still respects accents');
        $this->assertTrue(TextMatch::equals('Müller', 'MÜLLER'));
    }

    public function test_like_and_contains_follow_the_matching_table(): void
    {
        // Case.
        $this->assertTrue(TextMatch::like('Über', 'über'));
        $this->assertTrue(TextMatch::like('Über', 'ÜBER'));
        $this->assertTrue(TextMatch::contains('Über uns', 'über'));
        $this->assertTrue(TextMatch::contains('Über uns', 'ÜBER'));
        // Accents are ignored.
        $this->assertTrue(TextMatch::like('Über', 'uber'));
        $this->assertTrue(TextMatch::like('été', 'ete'));
        $this->assertTrue(TextMatch::contains('Über uns', 'uber'));
        $this->assertTrue(TextMatch::contains('été', 'ETE'));
        $this->assertTrue(TextMatch::like('Øre', 'ore'));
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
        // İ is an i with an accent (its dot).
        $this->assertTrue(TextMatch::like('İstanbul', 'İSTANBUL'));
        $this->assertTrue(TextMatch::contains('İstanbul', 'İSTAN'));
        $this->assertTrue(TextMatch::like('İstanbul', 'istanbul'));
        $this->assertTrue(TextMatch::contains('İstanbul', 'ist'));
        // ı is a letter of its own.
        $this->assertFalse(TextMatch::like('ılık', 'ILIK'));
        $this->assertFalse(TextMatch::like('ılık', 'ilik'));
        $this->assertFalse(TextMatch::contains('ılık', 'ILI'));
        $this->assertTrue(TextMatch::like('ılık', 'ılık'));
        // The long s is an s, both ways (in "=" it is a letter of its own).
        $this->assertTrue(TextMatch::like("\u{017F}uper", 'super'));
        $this->assertTrue(TextMatch::like('super', "\u{017F}uper"));
        $this->assertTrue(TextMatch::contains('super', "\u{017F}"));
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
        $this->assertFalse(TextMatch::asciiSafe('abc'), 'á, ｂ, ç and more fold into a, b and c');
        $this->assertFalse(TextMatch::asciiSafe('2024'), '２ and ² fold into 2');
        $this->assertFalse(TextMatch::asciiSafe('%über%'));
        $this->assertTrue(TextMatch::asciiSafe("\t\n"));
        $this->assertTrue(TextMatch::asciiSafe(''));
    }

    /** @return list<array{string, string, bool}> a, b, equal under the rule */
    private static function equalityPairs(): array
    {
        return [
            ['über', 'ÜBER', true],
            ['uber', 'über', false],
            ['Straße', 'STRASSE', false],
            ['Straße', 'STRAẞE', true],
            ["\u{212A}elvin", 'kelvin', true],
            ['istanbul', 'İstanbul', false],
            ['ılık', 'ilik', false],
            ['abc', 'abc ', false],
            ["U\u{0308}ber", 'Über', true],
            ["a\u{00AD}b", 'ab', true],
        ];
    }

    public function test_equals_ignores_case_and_respects_accents(): void
    {
        foreach (self::equalityPairs() as [$a, $b, $equal]) {
            $this->assertSame($equal, TextMatch::equals($a, $b), "{$a} = {$b}");
            $this->assertSame($equal, TextMatch::equals($b, $a), "{$b} = {$a}");
            $this->assertSame($equal, TextMatch::compare($a, $b) === 0, "compare({$a}, {$b})");
        }
    }

    public function test_compare_sorts_accented_letters_with_their_base_letter(): void
    {
        $texts = ['Zebra', 'äpfel', 'Apfel', 'Émile', 'eagle', '9', '10'];
        usort($texts, TextMatch::compare(...));

        $this->assertSame(['10', '9', 'Apfel', 'äpfel', 'eagle', 'Émile', 'Zebra'], $texts);
        $this->assertSame(-1, TextMatch::compare('Apfel', 'äpfel'));
        $this->assertSame(1, TextMatch::compare('äpfel', 'Apfel'));
    }

    public function test_keys_are_equal_exactly_when_texts_are(): void
    {
        foreach (self::equalityPairs() as [$a, $b, $equal]) {
            $this->assertSame($equal, TextMatch::key($a) === TextMatch::key($b), "key({$a}) = key({$b})");
        }
        $texts = ['Zebra', 'äpfel', 'Apfel', 'Émile', 'eagle', '9', '10', 'ſ', 's', '', "\u{00AD}"];
        $byKey = $texts;
        usort($byKey, static fn (string $a, string $b): int => strcmp(TextMatch::key($a), TextMatch::key($b)));
        $byCompare = $texts;
        usort($byCompare, TextMatch::compare(...));
        $this->assertSame($byCompare, $byKey, 'keys sort as compare() does');
    }

    /**
     * Where ICU's root order at secondary strength and the server's "=" disagree, the server
     * decides (TextMatch's tables come from it; TextMatchServerProbeTest checks them). On
     * MariaDB 13.0.2 and ICU 79.1, ICU takes these as equal and the server does not: a Hangul
     * syllable and its letters, combining marks in another order, and about 800
     * characters newer than the server's Unicode version (UCA 14), such as the Kawi digit zero
     * and 0. And the server ignores two Tibetan signs (U+0F82, U+0F83) that ICU does not.
     */
    public function test_where_icu_and_the_server_disagree_the_server_decides(): void
    {
        $icu = \Collator::create('root');
        $icu->setStrength(\Collator::SECONDARY);
        $icu->setAttribute(\Collator::NORMALIZATION_MODE, \Collator::ON);
        $distinct = [
            ["\u{AC00}", "\u{1100}\u{1161}"],
            ["e\u{0323}\u{0301}", "e\u{0301}\u{0323}"],
            ["\u{11F50}", '0'],
        ];
        foreach ($distinct as [$a, $b]) {
            $this->assertSame(0, $icu->compare($a, $b), 'ICU takes them as equal');
            $this->assertFalse(TextMatch::equals($a, $b));
            $this->assertNotSame(0, TextMatch::compare($a, $b));
        }
        $this->assertFalse(TextMatch::equals("\u{017F}", 's'), 'the long s is a letter of its own (ICU agrees)');
        foreach (["a\u{0F82}b", "a\u{0F83}b"] as $text) {
            $this->assertNotSame(0, $icu->compare($text, 'ab'), 'ICU does not ignore it');
            $this->assertTrue(TextMatch::equals($text, 'ab'));
        }
    }

    public function test_equals_takes_compatibility_characters_as_the_text_they_stand_for(): void
    {
        $this->assertTrue(TextMatch::equals('ǆ', 'dž'));
        $this->assertTrue(TextMatch::equals('ﬁx', 'FIX'));
        $this->assertTrue(TextMatch::equals('½', '1⁄2'));
        $this->assertTrue(TextMatch::equals('ａｂｃ', 'ABC'));
        $this->assertTrue(TextMatch::equals("a\u{0001}b", 'ab'), 'a control the collation ignores');
        $this->assertFalse(TextMatch::equals("a\tb", 'ab'), 'a tab counts');
        $this->assertFalse(TextMatch::equals('æ', 'ae'), 'æ is a letter of its own');
        $this->assertFalse(TextMatch::like('ﬁx', 'fix'), 'LIKE stays letter by letter');
    }

    /** The equality table holds folded characters only, each mapped to its final form. */
    public function test_the_equality_table_is_stable(): void
    {
        $folds = require __DIR__ . '/../../src/Text/fold-table.php';
        $table = require __DIR__ . '/../../src/Text/equality-table.php';
        $ignore = array_fill_keys($table['ignore'], true);

        $this->assertGreaterThan(500, count($ignore));
        $this->assertGreaterThan(1000, count($table['expand']));
        foreach ($table['expand'] as $char => $text) {
            $this->assertArrayNotHasKey((string) $char, $folds, sprintf('U+%04X is no fold', mb_ord((string) $char, 'UTF-8')));
            $this->assertNotSame((string) $char, $text);
            foreach (mb_str_split($text, 1, 'UTF-8') as $part) {
                $this->assertArrayNotHasKey($part, $folds);
                $this->assertArrayNotHasKey($part, $ignore);
                $this->assertArrayNotHasKey($part, $table['expand']);
            }
        }
    }

    public function test_equality_forms_are_equal_exactly_when_texts_are(): void
    {
        foreach (self::equalityPairs() as [$a, $b, $equal]) {
            $this->assertSame($equal, TextMatch::equalityForm($a) === TextMatch::equalityForm($b), "form({$a}) = form({$b})");
        }
    }

    /** Short texts keep their key for the next row; a long one is not cached (it would hold the cache's memory). */
    public function test_only_short_texts_have_their_key_cached(): void
    {
        $keys = new \ReflectionProperty(TextMatch::class, 'keys');
        $short = 'Short text ' . bin2hex(random_bytes(4));
        $long = str_repeat('Lang ', 100) . bin2hex(random_bytes(4));
        $this->assertGreaterThan(TextMatch::CACHED_KEY_BYTES, strlen($long));

        $shortKey = TextMatch::key($short);
        $longKey = TextMatch::key($long);

        $cached = $keys->getValue();
        $this->assertSame($shortKey, $cached[$short] ?? null);
        $this->assertArrayNotHasKey($long, $cached);
        $this->assertSame($longKey, TextMatch::key($long), 'the same key without the cache');
        $this->assertSame(TextMatch::key(strtoupper($long)), $longKey);
    }
}
