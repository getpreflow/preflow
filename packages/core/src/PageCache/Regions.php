<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

/**
 * Names the part of a rendered page a byte lies in: the innermost component (the wrapper element
 * with its `Name-hash` id) or, outside every component, the innermost landmark element. Used to
 * say where two renders of a page differ.
 */
final class Regions
{
    private const string COMPONENT = '/<([a-z][a-z0-9]*)\b[^>]*?\sid="([A-Z][A-Za-z0-9]*-[0-9a-f]{8}[^"]*)"/';

    private const string LANDMARK = '/<(header|nav|main|footer|aside|section|article|form)\b/i';

    /** `component Name-1a2b3c4d`, `the <footer> element`, or null when the byte lies in neither. */
    public static function at(string $body, int $offset): ?string
    {
        $head = substr($body, 0, $offset + 1);
        if (preg_match_all(self::COMPONENT, $head, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
            foreach (array_reverse($found) as $match) {
                if (self::open($head, $match[1][0], $match[0][1])) {
                    return 'component ' . html_entity_decode($match[2][0]);
                }
            }
        }
        if (preg_match_all(self::LANDMARK, $head, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
            foreach (array_reverse($found) as $match) {
                if (self::open($head, strtolower($match[1][0]), $match[0][1])) {
                    return 'the <' . strtolower($match[1][0]) . '> element';
                }
            }
        }

        return null;
    }

    /** Whether the element opened at $from in $head is still open at its end. */
    private static function open(string $head, string $tag, int $from): bool
    {
        $rest = substr($head, $from);
        $tag = preg_quote($tag, '/');

        return preg_match_all('/<' . $tag . '\b/i', $rest) > preg_match_all('/<\/' . $tag . '\s*>/i', $rest);
    }
}
