<?php

declare(strict_types=1);

namespace Preflow\View\Audit\Checks;

use Preflow\View\Audit\AuditRegistry;
use Preflow\View\Audit\PageCheck;
use Preflow\View\Audit\PageRenderer;
use Preflow\View\Audit\RenderedPage;
use Preflow\View\Audit\Severity;
use Preflow\View\Geo\GeoConfig;
use Preflow\View\Geo\GeoMiddleware;

/**
 * `geo.markdown-thin` (warning): GEO is on and the page's Markdown view is
 * empty, or holds under a third of the words the page shows in its `<main>`
 * (else `<body>`; scripts, styles, navigation, header and footer left out).
 * Content in JavaScript or attributes without a geo block doesn't reach AI
 * assistants. Pages under 50 words are only reported when the Markdown is
 * empty; pages without a Markdown view (excluded, noindex) are not. Renders
 * each page's `.md` URL once more. With GEO off it finds nothing.
 *
 * @since 0.55
 */
final class GeoMarkdownThin implements PageCheck
{
    /** Below this many words of page text, only an empty Markdown view is reported. */
    private const MIN_WORDS = 50;

    /** Elements whose text is no part of what the page says. */
    private const SKIPPED = ['script', 'style', 'noscript', 'template', 'svg', 'nav', 'header', 'footer', 'button', 'select', 'textarea'];

    public function __construct(
        private readonly AuditRegistry $registry,
        private readonly PageRenderer $renderer,
        private readonly GeoConfig $geo,
    ) {}

    public function id(): string
    {
        return 'geo.markdown-thin';
    }

    public function check(RenderedPage $page): array
    {
        $dom = $page->status === 200 && $this->geo->enabled() ? $page->dom() : null;
        if ($dom === null) {
            return [];
        }

        $path = (string) (parse_url($page->url, PHP_URL_PATH) ?: '/');
        $markdown = $this->renderer->render(GeoMiddleware::markdownUrl($path));
        if ($markdown->status !== 200) {
            return [];
        }

        $pageWords = self::count(self::pageText($dom));
        $markdownWords = self::count(self::markdownText($markdown->body));
        if ($markdownWords === 0 && $pageWords > 0) {
            return [Html::pageFinding($this->registry, $page, $this->id(), Severity::Warning,
                'The page\'s Markdown view for AI assistants is empty. Check that its content is in the HTML, or write it in a geo block.')];
        }
        if ($pageWords >= self::MIN_WORDS && $markdownWords * 3 < $pageWords) {
            return [Html::pageFinding($this->registry, $page, $this->id(), Severity::Warning,
                "The page's Markdown view has {$markdownWords} words, the page shows {$pageWords}: content built by JavaScript or kept in attributes doesn't reach AI assistants. Write it in a geo block ({% apply geo %}).")];
        }

        return [];
    }

    /** The words the page shows in its main content. */
    private static function pageText(\DOMDocument $dom): string
    {
        $root = $dom->getElementsByTagName('main')->item(0) ?? $dom->getElementsByTagName('body')->item(0);
        if ($root === null) {
            return '';
        }
        $root = $root->cloneNode(true);
        foreach (self::SKIPPED as $tag) {
            foreach (iterator_to_array($root->getElementsByTagName($tag)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        return (string) $root->textContent;
    }

    /** The Markdown's text: without its title, Source and Updated lines, link targets and syntax. */
    private static function markdownText(string $markdown): string
    {
        $text = (string) preg_replace('/\A# [^\n]*\n+(?:Source: [^\n]*\n)?(?:Updated: [^\n]*\n)?/', '', $markdown);
        $text = (string) preg_replace('/\]\([^)]*\)/', ']', $text);

        return (string) preg_replace('/[#*_`>|\[\]!-]+/', ' ', $text);
    }

    private static function count(string $text): int
    {
        return count(preg_split('/[\s\x{00A0}]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
