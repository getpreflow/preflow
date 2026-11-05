<?php

declare(strict_types=1);

namespace Preflow\View\Audit\Checks;

use Preflow\View\Audit\AuditRegistry;
use Preflow\View\Audit\Finding;
use Preflow\View\Audit\RenderedPage;
use Preflow\View\Audit\Severity;

/**
 * What the built-in checks read from a page's HTML (title, meta tags, links by
 * rel), and the finding for a page with its target.
 *
 * @internal
 * @since 0.54
 */
final class Html
{
    /** The text of the document's <title> (the head's, not an SVG's), trimmed; null when there is none. */
    public static function title(\DOMDocument $dom): ?string
    {
        $title = (new \DOMXPath($dom))->query('/html/head/title')?->item(0);

        return $title !== null ? trim($title->textContent) : null;
    }

    /**
     * The content of the first <meta> whose $attribute ('name', 'property') is $key (any case), trimmed; null
     * when there is none.
     */
    public static function meta(\DOMDocument $dom, string $attribute, string $key): ?string
    {
        foreach ($dom->getElementsByTagName('meta') as $meta) {
            if (strtolower(trim($meta->getAttribute($attribute))) === $key) {
                return trim($meta->getAttribute('content'));
            }
        }

        return null;
    }

    /**
     * The <link> elements whose rel names $rel (one of its words, any case).
     *
     * @return list<\DOMElement>
     */
    public static function links(\DOMDocument $dom, string $rel): array
    {
        $found = [];
        foreach ($dom->getElementsByTagName('link') as $link) {
            $rels = preg_split('/\s+/', strtolower(trim($link->getAttribute('rel')))) ?: [];
            if (in_array($rel, $rels, true)) {
                $found[] = $link;
            }
        }

        return $found;
    }

    /**
     * The page's hreflang alternates, as hreflang (lower case) => absolute URL; the first one per language.
     *
     * @return array<string, string>
     */
    public static function alternates(RenderedPage $page): array
    {
        $dom = $page->dom();
        if ($dom === null) {
            return [];
        }
        $alternates = [];
        foreach (self::links($dom, 'alternate') as $link) {
            $lang = strtolower(trim($link->getAttribute('hreflang')));
            $href = Urls::resolve($link->getAttribute('href'), $page->url);
            if ($lang !== '' && $href !== null && !isset($alternates[$lang])) {
                $alternates[$lang] = $href;
            }
        }

        return $alternates;
    }

    /** A finding for the page at $url, with the record or asset it comes from. */
    public static function finding(AuditRegistry $registry, string $check, Severity $severity, string $message, string $url, string $locale): Finding
    {
        return new Finding($check, $severity, $message, $url, $locale, $registry->target($url, $locale));
    }

    /** A finding for $page, with the record or asset it comes from. */
    public static function pageFinding(AuditRegistry $registry, RenderedPage $page, string $check, Severity $severity, string $message): Finding
    {
        return self::finding($registry, $check, $severity, $message, $page->url, $page->locale);
    }
}
