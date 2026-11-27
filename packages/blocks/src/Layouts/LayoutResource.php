<?php

declare(strict_types=1);

namespace Preflow\Blocks\Layouts;

use Preflow\Blocks\BlockLists;
use Preflow\Blocks\BlockRegistry;
use Preflow\Blocks\Values\BlocksFieldValue;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Records\ListsRecordReferences;
use Preflow\Data\Records\OutputPart;
use Preflow\Data\Records\ReadOptions;
use Preflow\Data\TypeDefinition;
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
 * Since 0.93 it is the blocks package's output part of the one read class (OutputPart, registered
 * on RecordParts by BlocksServiceProvider): RecordOutput::toArray() of a type with layouts gets
 * these three keys from read(), each block's fields through their values as in a blocks field
 * (BlocksFieldValue: a number stored as text reads as a number, rich text cleaned again; locale
 * maps kept, shape "area_map"), and references() lists what the blocks of every stored area
 * reference (the media package's "_media" covers them). toArray() and areasField() keep their
 * 0.92 output and are deprecated: read a record through RecordOutput, an areas field through its
 * value (AreasFieldValue).
 *
 * @since 0.92.0
 */
final readonly class LayoutResource implements OutputPart, ListsRecordReferences
{
    /** The key of the stored areas the layout lacks. */
    public const string UNPLACED = '_unplaced';

    public function __construct(
        private PageLayouts $layouts,
        private BlockRegistry $blocks,
        /** The area layout sets, for an areas field (areasField(), 0.92). */
        private ?AreaLayoutSets $sets = null,
        /** Each block's fields through their values in read() (0.93); null: blocks as visibleBlock() reads them. */
        private ?BlocksFieldValue $values = null,
    ) {}

    /** @return list<string> "_layout", "_areas" and "_unplaced" for a type with "layouts", else none */
    public function keys(TypeDefinition $def): array
    {
        return $this->layouts->forType($def->key) === null ? [] : [PageLayouts::LAYOUT, PageLayouts::AREAS, self::UNPLACED];
    }

    /**
     * The record's layout and areas as toArray() gives them, each block through its fields'
     * values; [] for a type without "layouts". The language option leaves blocks as they are (their
     * translatable fields stay maps, as "area_map" says).
     *
     * @return array<string, mixed>
     */
    public function read(DynamicRecord $record, ReadOptions $options): array
    {
        $out = $this->output($record);
        if ($out === null) {
            return [];
        }
        if ($this->values !== null) {
            foreach ([PageLayouts::AREAS, self::UNPLACED] as $key) {
                if (is_array($out[$key])) {
                    foreach ($out[$key] as $name => $list) {
                        $out[$key][$name] = array_map($this->values->outputBlock(...), $list);
                    }
                }
            }
        }

        return $out;
    }

    /**
     * What the blocks of every stored area of the record reference (placed or not, their columns
     * included), by target type; [] for a type without "layouts" or without the blocks' values.
     *
     * @return array<string, list<string>>
     */
    public function references(DynamicRecord $record): array
    {
        if ($this->values === null || $this->layouts->forType($record->getType()->key) === null) {
            return [];
        }
        $areas = $record->toArray()[PageLayouts::AREAS] ?? null;
        $areas = is_string($areas) ? json_decode($areas, true) : $areas;
        if (!is_array($areas) || $areas === []) {
            return [];
        }

        return $this->values->references(['layout' => null, 'areas' => $areas], new TypeFieldDefinition(PageLayouts::AREAS, BlockLists::AREAS));
    }

    /**
     * The record's layout and areas; null for a type without "layouts".
     *
     * @return array{_layout: array{key: string, label: string, pinned: bool, fallback: bool, stored: ?string}|null, _areas: array<string, list<array<string, mixed>>>|\stdClass, _unplaced: array<string, list<array<string, mixed>>>|\stdClass}|null
     * @deprecated since 0.93.0, removed no earlier than 0.95: read the record through RecordOutput::toArray(), which adds these keys (read())
     */
    public function toArray(DynamicRecord $record): ?array
    {
        return $this->output($record);
    }

    /** @return array{_layout: array{key: string, label: string, pinned: bool, fallback: bool, stored: ?string}|null, _areas: array<string, list<array<string, mixed>>>|\stdClass, _unplaced: array<string, list<array<string, mixed>>>|\stdClass}|null */
    private function output(DynamicRecord $record): ?array
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
            self::UNPLACED => $this->map($resolved->placement->unplaced()),
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
     * @deprecated since 0.93.0, removed no earlier than 0.95: an areas field reads through its value (AreasFieldValue::toOutput(), which RecordOutput calls)
     */
    public function areasField(TypeFieldDefinition $field, mixed $value): array
    {
        return $this->areasValue($field, $value);
    }

    /**
     * areasField()'s output, for AreasFieldValue::toOutput().
     *
     * @return array{layout: ?string, resolved: array{key: string, fallback: bool}|null, areas: array<string, list<array<string, mixed>>>|\stdClass, unplaced: array<string, list<array<string, mixed>>>|\stdClass}
     * @throws LayoutConfigException when the field is no areas field or its set is not registered
     * @internal
     */
    public function areasValue(TypeFieldDefinition $field, mixed $value): array
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
