<?php

declare(strict_types=1);

namespace Preflow\View\Seo;

/**
 * The order of pages in llms.txt, shared by PublicPages and GeoEntries:
 * sections in the order they first appear (the home page's — order 0 —
 * first), within a section by order, then title.
 *
 * @internal
 */
final class PageOrder
{
    /**
     * @template T of object{title: string, section: string, order: int}
     * @param list<T> $items
     * @return list<T>
     */
    public static function sort(array $items): array
    {
        $sections = [];
        foreach ($items as $item) {
            if ($item->order === 0) {
                $sections[$item->section] = 0;
                break;
            }
        }
        foreach ($items as $item) {
            $sections[$item->section] ??= count($sections);
        }

        usort($items, static fn (object $a, object $b): int =>
            [$sections[$a->section], $a->order] <=> [$sections[$b->section], $b->order]
            ?: strnatcasecmp($a->title, $b->title));

        return $items;
    }
}
