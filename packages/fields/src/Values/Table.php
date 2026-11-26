<?php

declare(strict_types=1);

namespace Preflow\Fields\Values;

use Preflow\Data\Locales;

/**
 * The value of a table field: an optional header row and rows of plain-text cells. A cell is one
 * text, or a text per language (as a link's label is), so each cell translates on its own while
 * the rows and columns stay the same in every language.
 *
 * Stored as an object with the keys head and rows (toStored()); head is null without a header row:
 *
 *     {"head": ["Size", "Price"], "rows": [["S", "CHF 10"], ["M", "CHF 12"]]}
 *     {"head": [{"en": "Size", "de": "Grösse"}, "CHF"], "rows": [[{"en": "Small", "de": "Klein"}, "10"]]}
 *
 * Every row has as many cells as the widest row (or the header): fromStored() pads short ones with
 * ''. A JSON file holds the object, SQL storage JSON text of it; fromStored() reads both.
 *
 * @since 0.91.0
 */
final readonly class Table
{
    /**
     * @param list<string|array<string, string>>|null $head
     * @param list<list<string|array<string, string>>> $rows
     */
    private function __construct(
        public ?array $head,
        public array $rows,
    ) {}

    /**
     * The table a stored value holds (the object, or JSON text of it), with short rows padded.
     * Null for an empty value (no rows and no header, or only empty cells) and for one that is no
     * table; reading never fails: a cell that is neither text nor a text per language reads as ''.
     * ValidTable refuses such values on save.
     */
    public static function fromStored(mixed $stored): ?self
    {
        $data = Link::decode($stored);
        if ($data === null || !is_array($data['rows'] ?? null) || !array_is_list($data['rows'])) {
            return null;
        }
        $rows = [];
        foreach ($data['rows'] as $row) {
            if (is_array($row) && array_is_list($row)) {
                $rows[] = array_map(self::cell(...), $row);
            }
        }
        $head = is_array($data['head'] ?? null) && array_is_list($data['head']) ? array_map(self::cell(...), $data['head']) : null;
        if ($head !== null && array_filter($head, static fn (string|array $c): bool => !self::isEmpty($c)) === []) {
            $head = null;
        }
        $width = max(count($head ?? []), ...array_map('count', $rows ?: [[]]));
        if ($width === 0) {
            return null;
        }
        $rows = array_map(static fn (array $row): array => self::pad($row, $width), $rows);
        $filled = array_filter(array_merge($head ?? [], ...($rows ?: [[]])), static fn (string|array $c): bool => !self::isEmpty($c));

        return $filled === [] ? null : new self($head === null ? null : self::pad($head, $width), $rows);
    }

    /**
     * @param list<string|array<string, string>> $cells
     * @return list<string|array<string, string>>
     */
    private static function pad(array $cells, int $width): array
    {
        return array_pad($cells, $width, '');
    }

    /** @return array{head: list<string|array<string, string>>|null, rows: list<list<string|array<string, string>>>} */
    public function toStored(): array
    {
        return ['head' => $this->head, 'rows' => $this->rows];
    }

    /** The number of columns (every row has them). */
    public function columns(): int
    {
        return count($this->head ?? $this->rows[0] ?? []);
    }

    /**
     * The texts in $locale: each cell's text in the language, along $locales' fallback chain
     * (locale, fallback, default) when given; '' for a cell without one.
     *
     * @return array{head: list<string>|null, rows: list<list<string>>}
     */
    public function texts(string $locale, ?Locales $locales = null): array
    {
        $text = static fn (string|array $cell): string => self::cellText($cell, $locale, $locales);

        return [
            'head' => $this->head === null ? null : array_map($text, $this->head),
            'rows' => array_map(static fn (array $row): array => array_map($text, $row), $this->rows),
        ];
    }

    /**
     * The table as HTML in $locale: `<table class="preflow-table">`, the header row in `<thead>`
     * (`<th scope="col">`), the rows in `<tbody>`; every text escaped.
     */
    public function html(string $locale, ?Locales $locales = null): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $texts = $this->texts($locale, $locales);
        $html = '<table class="preflow-table">';
        if ($texts['head'] !== null) {
            $html .= '<thead><tr>' . implode('', array_map(static fn (string $t): string => '<th scope="col">' . $e($t) . '</th>', $texts['head'])) . '</tr></thead>';
        }
        $html .= '<tbody>';
        foreach ($texts['rows'] as $row) {
            $html .= '<tr>' . implode('', array_map(static fn (string $t): string => '<td>' . $e($t) . '</td>', $row)) . '</tr>';
        }

        return $html . '</tbody></table>';
    }

    /**
     * A cell's text in $locale: a plain text in every language; a text per language in $locale,
     * with $locales along its fallback chain; '' without one.
     *
     * @param string|array<string, string> $cell
     */
    public static function cellText(string|array $cell, string $locale, ?Locales $locales = null): string
    {
        if (is_string($cell)) {
            return $cell;
        }
        foreach ($locales?->chain($locale) ?? [$locale] as $candidate) {
            if (isset($cell[$candidate]) && $cell[$candidate] !== '') {
                return $cell[$candidate];
            }
        }

        return '';
    }

    /**
     * A stored cell as text or a text per language (empty texts dropped; an empty map is '').
     *
     * @return string|array<string, string>
     */
    public static function cell(mixed $cell): string|array
    {
        if (is_string($cell)) {
            return $cell;
        }
        if (is_int($cell) || is_float($cell)) {
            return (string) $cell;
        }
        if (!is_array($cell) || array_is_list($cell)) {
            return '';
        }
        $map = [];
        foreach ($cell as $locale => $text) {
            if (is_string($locale) && is_string($text) && trim($text) !== '') {
                $map[$locale] = $text;
            }
        }

        return $map === [] ? '' : $map;
    }

    /** @param string|array<string, string> $cell */
    public static function isEmpty(string|array $cell): bool
    {
        return is_string($cell) ? trim($cell) === '' : $cell === [];
    }
}
