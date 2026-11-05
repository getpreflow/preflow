<?php

declare(strict_types=1);

namespace Preflow\View\Geo;

/**
 * Writes `llms.txt` (the site's pages as Markdown links, by section) and
 * `llms-full.txt` (the pages' Markdown in one file), following llmstxt.org.
 */
final class LlmsTxt
{
    /** preflow/view's lang folder (the "geo" group: en, de, fr). */
    public const string LANG_PATH = __DIR__ . '/../../lang';

    /** The translation key of the heading over the other locales' llms.txt links. */
    public const string OTHER_LANGUAGES_KEY = 'geo.other_languages';

    /**
     * @param list<GeoEntry>                               $entries        sorted
     * @param list<array{locale: string, url: string}>     $otherLanguages the other locales' llms.txt (default locale only)
     * @param string                                       $otherLanguagesHeading their section's heading, in this file's language
     */
    public function index(string $name, string $summary, array $entries, array $otherLanguages, string $otherLanguagesHeading = 'Other languages'): string
    {
        $sections = [];
        foreach ($entries as $entry) {
            $description = self::line($entry->description);
            $sections[$entry->section][] = '- [' . self::linkText($entry->title) . '](' . $entry->markdownUrl() . ')'
                . ($description !== '' ? ': ' . $description : '');
        }
        foreach ($otherLanguages as $language) {
            $sections[$otherLanguagesHeading][] = '- [' . self::linkText($language['locale']) . '](' . $language['url'] . ')';
        }

        $txt = self::header($name, $summary);
        foreach ($sections as $section => $lines) {
            $txt .= "\n## " . self::line((string) $section) . "\n\n" . implode("\n", $lines) . "\n";
        }

        return $txt;
    }

    /**
     * Each page as `## {title}`, `Source: {url}` and its Markdown, headings shifted down one level.
     *
     * @param list<array{entry: GeoEntry, markdown: string}> $pages
     */
    public function full(string $name, string $summary, array $pages): string
    {
        $parts = [];
        foreach ($pages as $page) {
            $body = trim(self::shiftHeadings($page['markdown']), "\n");
            $parts[] = '## ' . self::line($page['entry']->title) . "\n\nSource: " . $page['entry']->url . "\n"
                . ($body !== '' ? "\n" . $body . "\n" : '');
        }

        return self::header($name, $summary) . ($parts !== [] ? "\n" . implode("\n", $parts) : '');
    }

    private static function header(string $name, string $summary): string
    {
        $summary = self::line($summary);

        return '# ' . self::line($name) . "\n" . ($summary !== '' ? "\n> " . $summary . "\n" : '');
    }

    /** One line of text: whitespace and line breaks collapsed. */
    private static function line(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function linkText(string $text): string
    {
        return addcslashes(self::line($text), '[]');
    }

    /** `#` → `##` … `#####` → `######` (level 6 stays), outside fenced code blocks. */
    private static function shiftHeadings(string $markdown): string
    {
        $fence = null;
        $lines = explode("\n", $markdown);
        foreach ($lines as $i => $line) {
            if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $m) === 1) {
                if ($fence === null) {
                    $fence = $m[1];
                } elseif ($m[1][0] === $fence[0] && strlen($m[1]) >= strlen($fence)) {
                    $fence = null;
                }
                continue;
            }
            if ($fence === null && preg_match('/^ {0,3}#{1,5}(?:[ \t]|$)/', $line) === 1) {
                $lines[$i] = (string) preg_replace('/^( {0,3})#/', '$1##', $line);
            }
        }

        return implode("\n", $lines);
    }
}
