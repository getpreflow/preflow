<?php

declare(strict_types=1);

namespace Preflow\Data\Text;

/**
 * The text rules in PHP (the JSON driver, SQLite's ulike() and preflow_text).
 *
 * Matching (LIKE, NOT LIKE, CONTAINS, search(): like(), contains(), matchFold(); accents ignored
 * since 0.78.1) compares text character by character, as MySQL/MariaDB's LIKE does under an
 * accent-insensitive, case-insensitive collation. Case is ignored in every alphabet ("über" finds
 * "Über", the Kelvin sign is a k, ẞ is ß), and so are accents and other marks ("uber" finds
 * "Über", "elise" finds "Élise", "istanbul" finds "İstanbul", "lodz" finds "Łódź") and width and
 * compatibility forms ("ａｂｃ", "①", "ª" are "abc", "1", "a"). ß is one letter ("strasse" does
 * not find "Straße"), and so are æ ("aegir" does not find "Ægir") and the dotless ı ("ilik" does
 * not find "ılık"). Text is compared as stored: a decomposed "Ü" (U followed by a combining
 * diaeresis) is two characters, not the composed one. Which characters are taken as which is
 * the matching table (search-fold-table.php).
 *
 * Exact comparisons (=, !=, IN, NOT IN, unique checks, ORDER BY on text fields: equals(),
 * compare(), key()) compare the whole text, as "=" does under the server's accent-sensitive,
 * case-insensitive NO PAD collation: case is ignored as in matching, but accents count ("muller"
 * is not "Müller", "istanbul" not "İstanbul") and the long s ſ is a letter of its own; each
 * character is taken as fold() takes it (the fold table, fold-table.php). Further a decomposed
 * "Ü" is "Ü", a ligature or other compatibility character is the text it stands for ("ﬁ" is
 * "fi"), characters the collation ignores (the soft hyphen) are left out, and a trailing space
 * counts: what "=" needs beyond the fold table is equality-table.php. Texts are ordered by the
 * Unicode Collation Algorithm (ICU's root order, intl Collator at secondary strength) and, where
 * it sees no difference but "=" does (a Hangul syllable and its letters, combining marks in
 * another order, characters newer than the server's Unicode version), by their equality form's
 * bytes: compare() is 0 exactly when equals() holds.
 *
 * All three tables are derived from the server's collations by bin/text-fold-table and checked
 * against them by the MySQL test suite.
 *
 * @since 0.78
 */
final class TextMatch
{
    /** @var array<string, string>|null character => the character it folds to */
    private static ?array $table = null;

    /** @var array<string, string>|null character => the character matching folds it to */
    private static ?array $matchTable = null;

    /** @var array<string, true>|null ASCII characters some character outside ASCII match-folds into */
    private static ?array $asciiTargets = null;

    /** @var array<string, string> escape and pattern => regular expression */
    private static array $patterns = [];

    /** @var array{ignore: array<string, true>, expand: array<string, string>}|null */
    private static ?array $equality = null;

    private static ?\Collator $collator = null;

    /**
     * Texts up to this many bytes have their key() remembered: names, titles, slugs and list
     * values repeat across the rows of one query (sorting, grouping, IN). A longer text (a body,
     * JSON) rarely repeats and would make the cache hold up to CACHED_KEYS times its size.
     */
    public const int CACHED_KEY_BYTES = 256;

    /** At most this many keys are remembered (then the cache starts over). */
    public const int CACHED_KEYS = 4096;

    /** @var array<string, string> text => key(), for texts of at most CACHED_KEY_BYTES */
    private static array $keys = [];

    /**
     * The text with each character replaced by the one exact comparisons take it as (A by a, ａ by
     * a, ẞ by ß; Ü by ü, accents stay): the character-by-character part of equalityForm().
     */
    public static function fold(string $text): string
    {
        return self::foldWith(self::table(), $text);
    }

    /**
     * The text with each character replaced by the one matching takes it as (A by a, ａ by a, ẞ by
     * ß, Ü and ü by u, É by e, İ by i; ß, æ and ı stay): a text holds a term (contains()) exactly
     * when its matchFold() holds the term's. One character for one, so a position in the fold is
     * the position in the text (for highlighting a match).
     *
     * @since 0.78.1
     */
    public static function matchFold(string $text): string
    {
        return self::foldWith(self::matchTable(), $text);
    }

    /**
     * SQL LIKE under the matching rule: % any text, _ one character (an umlaut is one), $escape
     * (one character) takes the next one literally; both sides match-folded ("%uber%" and
     * "%ÜBER%" both find "Über uns").
     */
    public static function like(string $value, string $pattern, ?string $escape = null): bool
    {
        return preg_match(self::regex($pattern, $escape ?? ''), self::matchFold($value)) === 1;
    }

    /** Whether the text holds the term under the matching rule ("Straße" holds "STRAẞ", "Élise" holds "elise"). */
    public static function contains(string $text, string $term): bool
    {
        return str_contains(self::matchFold($text), self::matchFold($term));
    }

    /** @param array<string, string> $table both tables' only ASCII entries are A-Z */
    private static function foldWith(array $table, string $text): string
    {
        if (preg_match('/[^\x00-\x7F]/', $text) !== 1) {
            return strtolower($text);
        }
        $folded = preg_replace_callback('/[^\x00-\x40\x5B-\x7F]/u', static fn (array $m): string => $table[$m[0]] ?? $m[0], $text);

        return $folded ?? self::foldWith($table, mb_scrub($text, 'UTF-8'));
    }

    /**
     * Whether two texts are equal under the rule, as "=" compares them under the server's
     * accent-sensitive, case-insensitive NO PAD collation: "über" = "ÜBER", "Straße" = "STRAẞE",
     * the Kelvin sign = k, "U" with a combining diaeresis = "Ü", "a­b" (soft hyphen) = "ab"; but
     * "uber" ≠ "über", "Straße" ≠ "STRASSE", "istanbul" ≠ "İstanbul", "abc" ≠ "abc ".
     *
     * @since 0.78
     */
    public static function equals(string $a, string $b): bool
    {
        return $a === $b || self::equalityForm($a) === self::equalityForm($b);
    }

    /**
     * The order of two texts under the rule: -1, 0 or 1. Accented letters sort with their base
     * letter ("Apfel", "äpfel", "eagle", "Émile", "Zebra"), digits as text ("10" before "9");
     * 0 exactly when equals().
     *
     * @throws \RuntimeException when intl's Collator fails
     * @since 0.78
     */
    public static function compare(string $a, string $b): int
    {
        return $a === $b ? 0 : strcmp(self::key($a), self::key($b)) <=> 0;
    }

    /**
     * A key for the text: two keys are equal exactly when the texts are (equals()), and keys
     * compared byte by byte (strcmp) order as compare() does. For grouping, sets and sorting many
     * texts. The collator's sort key (secondary strength) of the text's equality form, then the
     * form itself.
     *
     * @throws \RuntimeException when intl's Collator fails
     * @since 0.78
     */
    public static function key(string $text): string
    {
        if (isset(self::$keys[$text])) {
            return self::$keys[$text];
        }
        $form = self::equalityForm($text);
        $sortKey = self::collator()->getSortKey($form);
        if (!is_string($sortKey)) {
            throw new \RuntimeException('The text collator failed: ' . intl_get_error_message());
        }

        $key = $sortKey . "\0" . $form;
        if (strlen($text) <= self::CACHED_KEY_BYTES) {
            if (count(self::$keys) >= self::CACHED_KEYS) {
                self::$keys = [];
            }
            self::$keys[$text] = $key;
        }

        return $key;
    }

    /**
     * The text as "=" sees it: each character folded (fold()), a character the collation ignores
     * left out, one it takes as its decomposition replaced by it (equality-table.php). Two texts
     * are equal (equals()) exactly when their forms are: for comparing one text with many.
     *
     * @since 0.78
     */
    public static function equalityForm(string $text): string
    {
        if (preg_match('/[^\x20-\x7E]/', $text) !== 1) {
            return strtolower($text);
        }
        $table = self::table();
        self::$equality ??= self::equalityTable();
        ['ignore' => $ignore, 'expand' => $expand] = self::$equality;
        $form = preg_replace_callback('/[^\x20-\x40\x5B-\x7E]/u', static function (array $m) use ($table, $ignore, $expand): string {
            if (isset($ignore[$m[0]])) {
                return '';
            }
            $fold = $table[$m[0]] ?? $m[0];

            return $expand[$fold] ?? $fold;
        }, $text);

        return $form ?? self::equalityForm(mb_scrub($text, 'UTF-8'));
    }

    /**
     * The version of the rule as this PHP applies it: intl's ICU version and a digest of the fold
     * and equality tables. compare() and key() may order or equate some texts differently under
     * another version, so an index a database keeps by them (SQLite's preflow_text) is rebuilt
     * when it changes (`preflow migrate`, the data README's "Exact comparisons").
     *
     * @since 0.78
     */
    public static function version(): string
    {
        static $tables = null;
        $tables ??= substr(hash('sha256', (string) file_get_contents(__DIR__ . '/fold-table.php') . "\0" . (string) file_get_contents(__DIR__ . '/equality-table.php')), 0, 16);

        return 'icu ' . (defined('INTL_ICU_VERSION') ? INTL_ICU_VERSION : 'none') . ', tables ' . $tables;
    }

    /** @return array{ignore: array<string, true>, expand: array<string, string>} */
    private static function equalityTable(): array
    {
        $table = require __DIR__ . '/equality-table.php';

        return ['ignore' => array_fill_keys($table['ignore'], true), 'expand' => $table['expand']];
    }

    /** One collator per process: the root order at secondary strength, normalizing. */
    private static function collator(): \Collator
    {
        if (self::$collator === null) {
            if (!class_exists(\Collator::class)) {
                throw new \RuntimeException('preflow/data compares text with intl\'s Collator: install the PHP extension intl.');
            }
            $collator = \Collator::create('root');
            if (!$collator instanceof \Collator
                || !$collator->setStrength(\Collator::SECONDARY)
                || !$collator->setAttribute(\Collator::NORMALIZATION_MODE, \Collator::ON)) {
                throw new \RuntimeException('The text collator could not be set up: ' . intl_get_error_message());
            }
            self::$collator = $collator;
        }

        return self::$collator;
    }

    /**
     * Whether a LIKE pattern needs nothing beyond ASCII case folding: ASCII characters only, none
     * of which a character outside ASCII match-folds into (the accented letters and the fullwidth
     * forms make that every letter, digit and printable sign today). SQLite's native LIKE then
     * gives the matching rule's answer.
     */
    public static function asciiSafe(string $pattern): bool
    {
        if (preg_match('/[^\x00-\x7F]/', $pattern) === 1) {
            return false;
        }
        if (self::$asciiTargets === null) {
            self::$asciiTargets = [];
            foreach (self::matchTable() as $char => $fold) {
                if (strlen((string) $char) > 1 && strlen($fold) === 1) {
                    self::$asciiTargets[$fold] = true;
                }
            }
        }
        foreach (str_split(strtolower($pattern)) as $char) {
            if (isset(self::$asciiTargets[$char])) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> */
    private static function table(): array
    {
        return self::$table ??= require __DIR__ . '/fold-table.php';
    }

    /** @return array<string, string> */
    private static function matchTable(): array
    {
        return self::$matchTable ??= require __DIR__ . '/search-fold-table.php';
    }

    /** The wildcards are read from the pattern as written, then each literal character is match-folded. */
    private static function regex(string $pattern, string $escape): string
    {
        $key = $escape . "\0" . $pattern;
        if (isset(self::$patterns[$key])) {
            return self::$patterns[$key];
        }
        if (count(self::$patterns) > 500) {
            self::$patterns = [];
        }
        $regex = '';
        $chars = mb_str_split($pattern, 1, 'UTF-8');
        for ($i = 0, $n = count($chars); $i < $n; $i++) {
            $c = $chars[$i];
            if ($escape !== '' && $c === $escape && $i + 1 < $n) {
                $regex .= preg_quote(self::matchFold($chars[++$i]), '~');
            } elseif ($c === '%') {
                $regex .= '.*';
            } elseif ($c === '_') {
                $regex .= '.';
            } else {
                $regex .= preg_quote(self::matchFold($c), '~');
            }
        }

        return self::$patterns[$key] = '~^' . $regex . '$~su';
    }
}
