<?php

declare(strict_types=1);

namespace Preflow\View\Audit\Checks;

use Dom\HTMLDocument;
use Preflow\View\Audit\AuditRegistry;
use Preflow\View\Audit\PageCheck;
use Preflow\View\Audit\PageRenderer;
use Preflow\View\Audit\RenderedPage;
use Preflow\View\Audit\Severity;
use Preflow\View\Geo\GeoConfig;
use Preflow\View\Geo\GeoMiddleware;
use Preflow\View\Geo\MarkdownConverter;

/**
 * `geo.markdown-thin` (warning): GEO is on and the page's Markdown view is
 * empty, or holds under a third of the words the page shows in its `<main>`
 * (else `<body>`; what the converter leaves out, such as `data-geo="skip"`, and
 * navigation, header and footer left out). Words are counted between spaces,
 * so text without spaces (Chinese, Japanese) counts as few words.
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

    /** What never reaches the Markdown (the converter's own list), and the page's navigation, header and footer. */
    private const SKIPPED = MarkdownConverter::REMOVE . ', nav, header, footer';

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

        $pageWords = self::count(self::pageText($page->body));
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

    /** The words the page shows in its main content, measured as the converter reads it. */
    private static function pageText(string $html): string
    {
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        $root = $document->querySelector('main') ?? $document->body;
        if ($root === null) {
            return '';
        }
        foreach ($root->querySelectorAll(self::SKIPPED) as $element) {
            if ($element->parentNode !== null) {
                $element->remove();
            }
        }

        return (string) $root->textContent;
    }

    /** The Markdown's text: without its title, Source and Updated lines, link targets and syntax. */
    private static function markdownText(string $markdown): string
    {
        $text = (string) preg_replace('/\A(?:# [^\n]*\n+)?(?:Source: [^\n]*\n)?(?:Updated: [^\n]*\n)?/', '', $markdown);
        $text = (string) preg_replace('/\]\([^)]*\)/', ']', $text);

        return (string) preg_replace('/[#*_`>|\[\]!-]+/', ' ', $text);
    }

    private static function count(string $text): int
    {
        return count(preg_split('/[\s\x{00A0}]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
