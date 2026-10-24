<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

/**
 * Marks the words of a list search in a plain-text cell. Matches are found on the plain text
 * (case-insensitive, multibyte-safe, every occurrence of every word, overlaps merged) and each
 * piece is escaped on its own, so a word can never match inside an entity ("amp" in "&amp;")
 * and neither the value nor the search can inject markup.
 */
final class Highlighter
{
    /** Escaped HTML of $text with the words of $q wrapped in <mark>. */
    public static function html(string $text, string $q): string
    {
        $ranges = [];
        foreach (preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (preg_match_all('/' . preg_quote($word, '/') . '/iu', $text, $found, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($found[0] as [$match, $offset]) {
                    $ranges[] = [$offset, $offset + strlen($match)];
                }
            }
        }
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

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
