<?php

declare(strict_types=1);

namespace Preflow\Members\Tests\Support;

/** XPath over rendered HTML, for assertions on structure and visible text. */
final class Dom
{
    public static function load(string $html): \DOMXPath
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true); // HTML5 elements are fine, libxml just doesn't know them
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($doc);
    }

    /** @return list<\DOMElement> */
    public static function all(string $html, string $xpath): array
    {
        $nodes = [];
        foreach (self::load($html)->query($xpath) ?: [] as $node) {
            if ($node instanceof \DOMElement) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    public static function first(string $html, string $xpath): ?\DOMElement
    {
        return self::all($html, $xpath)[0] ?? null;
    }

    /** @return list<\DOMElement> matches of a relative XPath under an element found by all()/first() */
    public static function within(\DOMElement $context, string $xpath): array
    {
        $nodes = [];
        foreach ((new \DOMXPath($context->ownerDocument ?? throw new \LogicException('detached node')))->query($xpath, $context) ?: [] as $node) {
            if ($node instanceof \DOMElement) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /** Visible text of the first match, whitespace collapsed ('' when nothing matches). */
    public static function text(string $html, string $xpath): string
    {
        return self::normalize(self::first($html, $xpath)?->textContent ?? '');
    }

    public static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** XPath predicate for "has this class". */
    public static function hasClass(string $class): string
    {
        return "contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')";
    }
}
