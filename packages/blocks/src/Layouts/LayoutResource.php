<?php

declare(strict_types=1);

namespace Preflow\Blocks\Layouts;

use Preflow\Blocks\BlockRegistry;
use Preflow\Data\DynamicRecord;

/**
 * A page's layout and areas as an API answers with them (JSON-safe), next to the fields the site's
 * own resource gives:
 *
 *     {"layout": {"key": "standard", "label": "Standard", "pinned": false, "fallback": false, "stored": "standard"},
 *      "areas": {"main": [{"_uid": "…", "_block": "text", "body": {"en": "…", "de": "…"}}]},
 *      "unplaced": {"hero": […]}}
 *
 * "layout" is the layout the page renders with (PageLayouts::resolve(); null when no allowed layout
 * exists): "stored" is the key on the page, "fallback" is true when a stored or pinned key could not
 * be used. "areas" are the areas that layout shows, in its order (an area with nothing stored is
 * []); "unplaced" the stored areas it lacks that hold blocks (kept, not shown; a layout switch
 * brings them back). Each block is as BlockRegistry::visibleBlock() reads it: a field its show_when
 * hides is null. Blocks keep "_uid", "_block" and their locale maps; the API picks a locale itself
 * (LocaleValue::resolveTree(), as blocks() does). An empty map is an object ({}), never a list.
 *
 * @since 0.92.0
 */
final readonly class LayoutResource
{
    public function __construct(
        private PageLayouts $layouts,
        private BlockRegistry $blocks,
    ) {}

    /**
     * The record's layout and areas; null for a type without "layouts".
     *
     * @return array{layout: array{key: string, label: string, pinned: bool, fallback: bool, stored: ?string}|null, areas: array<string, list<array<string, mixed>>>|\stdClass, unplaced: array<string, list<array<string, mixed>>>|\stdClass}|null
     */
    public function toArray(DynamicRecord $record): ?array
    {
        $resolved = $this->layouts->resolve($record);
        if ($resolved === null) {
            return null;
        }

        return [
            'layout' => $resolved->layout === null ? null : [
                'key' => $resolved->layout->key,
                'label' => $resolved->layout->label,
                'pinned' => $resolved->pinned,
                'fallback' => $resolved->fallback,
                'stored' => $resolved->storedKey,
            ],
            'areas' => $this->map($resolved->placement->placed()),
            'unplaced' => $this->map($resolved->placement->unplaced()),
        ];
    }

    /**
     * @param array<string, list<array<string, mixed>>> $areas
     * @return array<string, list<array<string, mixed>>>|\stdClass
     */
    private function map(array $areas): array|\stdClass
    {
        if ($areas === []) {
            return new \stdClass();
        }
        foreach ($areas as $name => $blocks) {
            $areas[$name] = array_map($this->blocks->visibleBlock(...), $blocks);
        }

        return $areas;
    }
}
