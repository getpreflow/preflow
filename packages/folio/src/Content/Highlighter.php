<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\Text\TextMatch;

/**
 * Marks the words of a list search in a plain-text cell. Matches are found on the plain text by
 * the rule the search itself follows (TextMatch::matchFold(): case, accents, width and
 * compatibility forms ignored, so "elise" marks "Élise"; every occurrence of every word, overlaps
 * merged), so the marks show what the search found. Each piece is escaped on its own, so a word
 * can never match inside an entity ("amp" in "&amp;") and neither the value nor the search can
 * inject markup.
 */
final class Highlighter
{
    /** Escaped HTML of $text with the words of $q wrapped in <mark>. */
    public static function html(string $text, string $q): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $words = preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $ranges = $words === [] ? [] : self::ranges($text, $words);
        if ($ranges === []) {
            return self::escape($text);
        }

        sort($ranges);
        $merged = [];
        foreach ($ranges as [$start, $end]) {
            $last = array_key_last($merged);
            if ($last !== null && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        $html = '';
        $at = 0;
        foreach ($merged as [$start, $end]) {
            $html .= self::escape(substr($text, $at, $start - $at))
                . '<mark>' . self::escape(substr($text, $start, $end - $start)) . '</mark>';
            $at = $end;
        }

        return $html . self::escape(substr($text, $at));
    }

    /**
     * Byte ranges of $text where a word occurs under the rule: the text is folded character by
     * character, so each folded character points back to the one it came from.
     *
     * @param list<string> $words
     * @return list<array{int, int}>
     */
    private static function ranges(string $text, array $words): array
    {
        $chars = mb_str_split($text, 1, 'UTF-8');
        $byteAt = [];
        $origin = [];
        $folded = [];
        $pos = 0;
        foreach ($chars as $i => $char) {
            $byteAt[$i] = $pos;
            $pos += strlen($char);
            foreach (mb_str_split(TextMatch::matchFold($char), 1, 'UTF-8') as $piece) {
                $origin[] = $i;
                $folded[] = $piece;
            }
        }
        $byteAt[count($chars)] = $pos;
        $haystack = implode('', $folded);

        $ranges = [];
        foreach ($words as $word) {
            $needle = TextMatch::matchFold($word);
            $length = mb_strlen($needle, 'UTF-8');
            if ($length === 0) {
                continue;
            }
            $from = 0;
            while (($at = mb_strpos($haystack, $needle, $from, 'UTF-8')) !== false) {
                $ranges[] = [$byteAt[$origin[$at]], $byteAt[$origin[$at + $length - 1] + 1]];
                $from = $at + 1;
            }
        }

        return $ranges;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
