<?php

declare(strict_types=1);

namespace Preflow\View\Geo;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;

/**
 * Turns a rendered page into clean Markdown for AI assistants.
 *
 * Takes the page's `<main>` (else `<body>`), drops scripts, styles, form
 * controls and hidden parts, and writes headings, paragraphs, lists, tables,
 * code, links and images. Links and images get absolute URLs; images without
 * alt text are left out. `<preflow-geo>` placeholders become the Markdown the
 * collector stored for them, verbatim.
 */
final class MarkdownConverter
{
    private const REMOVE = 'script, style, template, svg, noscript, iframe, input, select, textarea, button, '
        . '[hidden], [aria-hidden="true"], [data-geo="skip"]';

    /** Elements that start their own block; any other element is inline unless it holds one of these. */
    private const BLOCKS = [
        'address', 'article', 'aside', 'blockquote', 'caption', 'dd', 'details', 'dialog', 'div', 'dl', 'dt',
        'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header',
        'hgroup', 'hr', 'legend', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'preflow-geo', 'search', 'section',
        'summary', 'table', 'ul',
    ];

    /** Elements that keep their inline meaning even around blocks (a card link stays one link). */
    private const INLINE = ['a', 'b', 'code', 'em', 'i', 'strong'];

    private string $baseUrl = '';

    public function __construct(private readonly ?GeoCollector $collector = null)
    {
    }

    /** Convert a page's HTML; relative links and images resolve against $baseUrl. */
    public function convert(string $html, string $baseUrl): string
    {
        $this->baseUrl = $baseUrl;

        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        $root = $document->querySelector('main') ?? $document->body;
        if ($root === null) {
            return '';
        }

        foreach ($root->querySelectorAll(self::REMOVE) as $element) {
            $element->remove();
        }

        return trim(implode("\n\n", $this->blocks($root)));
    }

    /**
     * The Markdown blocks of a container's children, in order.
     *
     * @return list<string>
     */
    private function blocks(Node $parent): array
    {
        $blocks = [];
        $run = '';

        foreach ($parent->childNodes as $node) {
            if (!$node instanceof Element || !$this->isBlock($node)) {
                $run .= $this->inline($node);
                continue;
            }

            array_push($blocks, ...$this->paragraphs($run));
            $run = '';
            array_push($blocks, ...$this->block($node));
        }

        array_push($blocks, ...$this->paragraphs($run));

        return $blocks;
    }

    /** @return list<string> */
    private function block(Element $element): array
    {
        $name = $element->localName;

        switch ($name) {
            case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
                $text = $this->line($this->inline($element));
                return $text === '' ? [] : [str_repeat('#', (int) $name[1]) . ' ' . $text];
            case 'dt':
                $text = $this->line($this->inline($element));
                return $text === '' ? [] : ['**' . $text . '**'];
            case 'hr':
                return ['---'];
            case 'pre':
                return [$this->fence($element)];
            case 'ul':
            case 'ol':
                $list = $this->listing($element);
                return $list === '' ? [] : [$list];
            case 'blockquote':
                $inner = implode("\n\n", $this->blocks($element));
                return $inner === '' ? [] : [$this->prefixLines($inner, '> ', '>')];
            case 'table':
                return $this->table($element);
            case 'preflow-geo':
                $markdown = trim($this->placeholder($element));
                return $markdown === '' ? [] : [$markdown];
            default:
                return $this->blocks($element);
        }
    }

    private function isBlock(Element $element): bool
    {
        $name = $element->localName;
        if (in_array($name, self::BLOCKS, true)) {
            return true;
        }
        if (in_array($name, self::INLINE, true)) {
            return false;
        }

        return $element->querySelector(implode(', ', self::BLOCKS)) !== null;
    }

    /** Inline Markdown for a node; block elements met here are flattened into the text. */
    private function inline(Node $node): string
    {
        if ($node instanceof Text) {
            return $this->escape((string) preg_replace('/[ \t\n\r\f]+/', ' ', $node->data));
        }
        if (!$node instanceof Element) {
            return '';
        }

        return match ($node->localName) {
            'br' => "\n",
            'strong', 'b' => $this->wrap($this->children($node), '**'),
            'em', 'i' => $this->wrap($this->children($node), '*'),
            'code' => $this->code($node),
            'a' => $this->link($node),
            'img' => $this->image($node),
            'preflow-geo' => $this->placeholder($node),
            default => in_array($node->localName, self::BLOCKS, true)
                ? ' ' . $this->children($node) . ' '
                : $this->children($node),
        };
    }

    private function children(Element $element): string
    {
        $out = '';
        foreach ($element->childNodes as $child) {
            $out .= $this->inline($child);
        }

        return $out;
    }

    /** Wrap text in a marker, keeping surrounding spaces outside it. */
    private function wrap(string $text, string $marker): string
    {
        $inner = trim($text, " \n");
        if ($inner === '') {
            return $text === '' ? '' : ' ';
        }

        $lead = str_starts_with($text, ' ') ? ' ' : '';
        $tail = str_ends_with($text, ' ') ? ' ' : '';

        return $lead . $marker . $inner . $marker . $tail;
    }

    private function code(Element $element): string
    {
        $text = (string) preg_replace('/[ \t\n\r\f]+/', ' ', $element->textContent ?? '');
        if (trim($text) === '') {
            return $text === '' ? '' : ' ';
        }
        if (!str_contains($text, '`')) {
            return '`' . $text . '`';
        }

        $ticks = str_repeat('`', $this->longestRun($text, '`') + 1);

        return $ticks . ' ' . $text . ' ' . $ticks;
    }

    private function link(Element $element): string
    {
        $text = $this->children($element);
        $href = trim((string) $element->getAttribute('href'));

        if (
            trim($text) === ''
            || $href === ''
            || str_starts_with($href, '#')
            || preg_match('/^javascript:/i', $href) === 1
        ) {
            return $text;
        }

        $label = trim($text);
        $lead = str_starts_with($text, ' ') ? ' ' : '';
        $tail = str_ends_with($text, ' ') ? ' ' : '';

        return $lead . '[' . $label . '](' . $this->url($href) . ')' . $tail;
    }

    private function image(Element $element): string
    {
        $alt = trim((string) preg_replace('/\s+/', ' ', (string) $element->getAttribute('alt')));
        $src = trim((string) $element->getAttribute('src'));
        if ($alt === '' || $src === '') {
            return '';
        }

        return '![' . $this->escape($alt) . '](' . $this->url($src) . ')';
    }

    private function placeholder(Element $element): string
    {
        $n = $element->getAttribute('data-geo');
        if ($this->collector === null || $n === null || !ctype_digit($n)) {
            return '';
        }

        return $this->collector->block((int) $n);
    }

    private function fence(Element $pre): string
    {
        $code = $pre->querySelector('code');
        $class = $pre->getAttribute('class') . ' ' . ($code?->getAttribute('class') ?? '');
        $language = preg_match('/(?:^|\s)language-([^\s]+)/', $class, $m) === 1 ? $m[1] : '';

        $text = rtrim($pre->textContent ?? '', "\n");
        $fence = str_repeat('`', max(3, $this->longestRun($text, '`') + 1));

        return $fence . $language . "\n" . $text . "\n" . $fence;
    }

    private function listing(Element $list): string
    {
        $ordered = $list->localName === 'ol';
        $items = [];
        $number = 0;

        foreach ($list->childNodes as $node) {
            if (!$node instanceof Element) {
                continue;
            }
            if ($node->localName !== 'li') {
                // A placeholder or wrapper inside a list: its Markdown goes in as it is.
                $items = [...$items, ...$this->block($node)];
                continue;
            }

            $blocks = $this->blocks($node);
            if ($blocks === []) {
                continue;
            }

            $marker = $ordered ? ++$number . '. ' : '- ';
            $item = array_shift($blocks);
            foreach ($blocks as $block) {
                $item .= preg_match('/^(?:- |\d+\. )/', $block) === 1 ? "\n" . $block : "\n\n" . $block;
            }

            $items[] = $marker . $this->prefixLines($item, '  ', '', true);
        }

        return implode("\n", $items);
    }

    /** @return list<string> */
    private function table(Element $table): array
    {
        $head = null;
        $rows = [];

        foreach ($table->childNodes as $node) {
            if (!$node instanceof Element) {
                continue;
            }
            $name = $node->localName;
            $trs = $name === 'tr' ? [$node] : (in_array($name, ['thead', 'tbody', 'tfoot'], true)
                ? array_filter(iterator_to_array($node->children), fn (Element $e) => $e->localName === 'tr')
                : []);

            foreach ($trs as $tr) {
                $cells = [];
                foreach ($tr->children as $cell) {
                    if (in_array($cell->localName, ['td', 'th'], true)) {
                        $text = str_replace("\n", ' ', $this->children($cell));
                        $cells[] = str_replace('|', '\\|', $this->line($text));
                    }
                }
                if ($name === 'thead' && $head === null) {
                    $head = $cells;
                } else {
                    $rows[] = $cells;
                }
            }
        }

        $head ??= array_shift($rows);
        if ($head === null) {
            return [];
        }

        $width = max(count($head), ...array_map('count', $rows ?: [[]]));
        $line = static fn (array $cells): string => '| ' . implode(' | ', array_pad($cells, $width, '')) . ' |';

        $lines = [$line($head), '| ' . implode(' | ', array_fill(0, $width, '---')) . ' |'];
        foreach ($rows as $row) {
            $lines[] = $line($row);
        }

        $caption = $table->querySelector('caption');
        $captionText = $caption === null ? '' : $this->line($this->children($caption));

        return $captionText === '' ? [implode("\n", $lines)] : [$captionText, implode("\n", $lines)];
    }

    /**
     * Paragraphs from inline Markdown: `<br>` ends a line, an empty line starts a new paragraph.
     *
     * @return list<string>
     */
    private function paragraphs(string $inline): array
    {
        $paragraphs = [];
        $lines = [];

        foreach (explode("\n", $inline) as $raw) {
            $line = $this->line($raw);
            if ($line !== '') {
                $lines[] = $line;
                continue;
            }
            if ($lines !== []) {
                $paragraphs[] = implode("  \n", $lines);
                $lines = [];
            }
        }
        if ($lines !== []) {
            $paragraphs[] = implode("  \n", $lines);
        }

        return $paragraphs;
    }

    /** One line of inline Markdown: spaces collapsed, trimmed, a leading block marker escaped. */
    private function line(string $text): string
    {
        $text = trim((string) preg_replace('/ {2,}/', ' ', str_replace("\n", ' ', $text)));

        return (string) preg_replace(['/^([#>+-])/', '/^(\d+)\.(?= |$)/'], ['\\\\$1', '$1\\.'], $text);
    }

    /** Backslash-escape the characters that would turn text into Markdown. */
    private function escape(string $text): string
    {
        return (string) preg_replace('/([*_\[\]`])/', '\\\\$1', $text);
    }

    private function prefixLines(string $text, string $prefix, string $emptyPrefix, bool $skipFirst = false): string
    {
        $lines = explode("\n", $text);
        foreach ($lines as $i => $line) {
            if ($skipFirst && $i === 0) {
                continue;
            }
            $lines[$i] = $line === '' ? $emptyPrefix : $prefix . $line;
        }

        return implode("\n", $lines);
    }

    private function longestRun(string $text, string $char): int
    {
        preg_match_all('/' . preg_quote($char, '/') . '+/', $text, $m);

        return $m[0] === [] ? 0 : max(array_map('strlen', $m[0]));
    }

    /** An absolute URL for $url, resolved against the base URL. */
    private function url(string $url): string
    {
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) !== 1) {
            $base = parse_url($this->baseUrl);
            $scheme = $base['scheme'] ?? 'https';
            $origin = $scheme . '://' . ($base['host'] ?? '') . (isset($base['port']) ? ':' . $base['port'] : '');
            $basePath = ($base['path'] ?? '') === '' ? '/' : $base['path'];

            $url = match (true) {
                str_starts_with($url, '//') => $scheme . ':' . $url,
                str_starts_with($url, '/') => $origin . $url,
                str_starts_with($url, '?') => $origin . $basePath . $url,
                default => $origin . $this->resolvePath(substr($basePath, 0, (int) strrpos($basePath, '/') + 1), $url),
            };
        }

        return strtr($url, [' ' => '%20', '(' => '%28', ')' => '%29']);
    }

    /** Join a relative path onto a directory and remove `.` and `..` segments. */
    private function resolvePath(string $directory, string $relative): string
    {
        $query = '';
        if (($cut = strcspn($relative, '?#')) < strlen($relative)) {
            $query = substr($relative, $cut);
            $relative = substr($relative, 0, $cut);
        }

        $segments = [];
        foreach (explode('/', trim($directory, '/') . '/' . $relative) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '.' && $segment !== '') {
                $segments[] = $segment;
            }
        }

        $path = '/' . implode('/', $segments);
        if (str_ends_with($relative, '/') && $path !== '/') {
            $path .= '/';
        }

        return $path . $query;
    }
}
