<?php

declare(strict_types=1);

namespace Preflow\Blocks\Layouts;

use Preflow\Blocks\BlockLists;
use Preflow\Blocks\BlockRegistry;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeFieldDefinition;

/**
 * A page's layout and areas as an API answers with them (JSON-safe), next to the fields the site's
 * own resource gives:
 *
 *     {"_layout": {"key": "standard", "label": "Standard", "pinned": false, "fallback": false, "stored": "standard"},
 *      "_areas": {"main": [{"_uid": "…", "_block": "text", "body": {"en": "…", "de": "…"}}]},
 *      "_unplaced": {"hero": […]}}
 *
 * The keys are system keys, as LayoutInput::merge() takes them (a key with an underscore is never a
 * type's own field, so a field named "layout" or "areas" sits beside them untouched).
 * "_layout" is the layout the page renders with (PageLayouts::resolve(); null when no allowed layout
 * exists): "stored" is the key on the page, "fallback" is true when a stored or pinned key could not
 * be used. "_areas" are the areas that layout shows, in its order (an area with nothing stored is
 * []); "_unplaced" the stored areas it lacks that hold blocks (kept, not shown; a layout switch
 * brings them back). Each block is as BlockRegistry::visibleBlock() reads it: a field its show_when
 * hides is null. Blocks keep "_uid", "_block" and their locale maps; the API picks a locale itself
 * (LocaleValue::resolveTree(), as blocks() does). An empty map is an object ({}), never a list.
 *
 * areasField() reads the value of an areas field (the columns block's columns) the same way.
 *
 * @since 0.92.0
 */
final readonly class LayoutResource
{
    public function __construct(
        private PageLayouts $layouts,
        private BlockRegistry $blocks,
        /** The area layout sets, for an areas field (areasField(), 0.92). */
        private ?AreaLayoutSets $sets = null,
    ) {}

    /**
     * The record's layout and areas; null for a type without "layouts".
     *
     * @return array{_layout: array{key: string, label: string, pinned: bool, fallback: bool, stored: ?string}|null, _areas: array<string, list<array<string, mixed>>>|\stdClass, _unplaced: array<string, list<array<string, mixed>>>|\stdClass}|null
     */
    public function toArray(DynamicRecord $record): ?array
    {
        $resolved = $this->layouts->resolve($record);
        if ($resolved === null) {
            return null;
        }

        return [
            PageLayouts::LAYOUT => $resolved->layout === null ? null : [
                'key' => $resolved->layout->key,
                'label' => $resolved->layout->label,
                'pinned' => $resolved->pinned,
                'fallback' => $resolved->fallback,
                'stored' => $resolved->storedKey,
            ],
            PageLayouts::AREAS => $this->map($resolved->placement->placed()),
            '_unplaced' => $this->map($resolved->placement->unplaced()),
        ];
    }

    /**
     * The value of an areas field (the columns block's columns) as an API answers with it, by the
     * same rules as a page's areas (since 0.92):
     *
     *     {"layout": "1-1-1", "resolved": {"key": "1-1", "fallback": true},
     *      "areas": {"c1": […], "c2": […]}, "unplaced": {"c3": […]}}
     *
     * "layout" is the stored key (null for none); "resolved" the layout of the field's set the
     * value shows with (AreaLayoutSet::resolve(): the key, else one with as many areas as are
     * stored, else the set's fallback; null for an empty set), "fallback" true when a stored key
     * could not be used. "areas" are that layout's areas in its order, "unplaced" the stored ones it
     * lacks that hold blocks; each block as BlockRegistry::visibleBlock() reads it.
     *
     * @return array{layout: ?string, resolved: array{key: string, fallback: bool}|null, areas: array<string, list<array<string, mixed>>>|\stdClass, unplaced: array<string, list<array<string, mixed>>>|\stdClass}
     * @throws LayoutConfigException when the field is no areas field or its set is not registered
     */
    public function areasField(TypeFieldDefinition $field, mixed $value): array
    {
        $name = BlockLists::setName($field);
        if ($field->type !== BlockLists::AREAS || $name === null || $this->sets === null) {
            throw new LayoutConfigException(sprintf('The field "%s" is no areas field with a set ("type": "areas", "layouts": "<set>"), or no area layout sets are given to LayoutResource.', $field->name));
        }
        $set = $this->sets->get($name);
        $areas = AreasValue::fromStored($value);
        $resolved = $areas->resolve($set);
        $placement = $areas->placement($set);

        return [
            'layout' => $areas->layout,
            'resolved' => $resolved === null ? null : ['key' => $resolved->key, 'fallback' => $areas->fallback($set)],
            'areas' => $this->map($placement->placed()),
            'unplaced' => $this->map($placement->unplaced()),
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
