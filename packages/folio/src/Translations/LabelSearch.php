<?php

declare(strict_types=1);

namespace Preflow\Folio\Translations;

/** The screen's paging, search and "Changed only" filter over its rows, shared with the AI button's units (LabelUnits). */
final class LabelSearch
{
    public const int PER_PAGE = 50;

    /**
     * The search over a row's key and texts.
     *
     * @param array{key: string, delivered: ?string, site: ?string, override: ?string} $row
     */
    public static function matches(string $q, array $row): bool
    {
        foreach ([$row['key'], $row['delivered'], $row['site'], $row['override']] as $text) {
            if ($text !== null && mb_stripos($text, $q) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * The rows the screen lists, in order, and how many of the rows the search finds have an override
     * (the "Changed only · N" count): the search over each row, then, with $changedOnly, only the rows
     * with an override. One filter for the screen and for the AI button's units, so the AI translates
     * exactly the rows on screen.
     *
     * @param iterable<string, array{key: string, delivered: ?string, site: ?string}> $rows EditableTexts::rows()
     * @param array<string, string> $overrides the language's overrides, key => text
     * @return array{rows: list<array{key: string, delivered: ?string, site: ?string, override: ?string}>, changed: int}
     */
    public static function filter(iterable $rows, array $overrides, string $q, bool $changedOnly): array
    {
        $listed = [];
        $changed = 0;
        foreach ($rows as $key => $row) {
            $row['override'] = $overrides[$key] ?? null;
            if ($q !== '' && !self::matches($q, $row)) {
                continue;
            }
            if ($row['override'] !== null) {
                $changed++;
            } elseif ($changedOnly) {
                continue;
            }
            $listed[] = $row;
        }

        return ['rows' => $listed, 'changed' => $changed];
    }
}
