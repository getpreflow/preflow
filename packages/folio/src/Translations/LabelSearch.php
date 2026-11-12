<?php

declare(strict_types=1);

namespace Preflow\Folio\Translations;

/** The screen's paging and search over its rows, shared with the AI button's units (LabelUnits). */
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
}
