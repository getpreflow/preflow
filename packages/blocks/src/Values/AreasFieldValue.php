<?php

declare(strict_types=1);

namespace Preflow\Blocks\Values;

use Preflow\Blocks\BlockLists;
use Preflow\Blocks\Layouts\LayoutInput;
use Preflow\Blocks\Layouts\LayoutResource;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\CleansOnWrite;
use Preflow\Data\Values\DescribesShapes;
use Preflow\Data\Values\FieldValue;
use Preflow\Data\Values\ListsReferences;
use Preflow\Data\Values\MergesInput;

/**
 * An "areas" field's value (0.93; the columns block's columns), shape "areas_value": a layout from
 * the field's set and the blocks of each area, stored as {"layout": key or null, "areas": {name:
 * [blocks…]}} (AreasValue; JSON text on SQL).
 *
 * - toStored(): the input's blocks through BlocksFieldValue (each block field through its value),
 *   in every area; any other input passes through for the rules.
 * - toOutput(): as LayoutResource::areasField() reads it ({"layout", "resolved", "areas",
 *   "unplaced"}), each block's fields through their values as a blocks field's.
 * - merge(): LayoutInput::mergeField(): the areas the input names replace the stored ones, the
 *   others are kept (refusals as ValidationException with the field's keys).
 * - clean() and references(): every stored area's blocks, as a blocks field's (BlocksFieldValue).
 *
 * @since 0.93.0
 */
final class AreasFieldValue implements FieldValue, CleansOnWrite, MergesInput, ListsReferences, DescribesShapes
{
    public const array SHAPES = [
        'areas_value' => 'A layout and its areas: an object {"layout": a key of the field\'s area layout set or null, "resolved": {"key", "fallback"} or null, "areas": the shown areas, "unplaced": stored areas the layout lacks}, each area a list of blocks. Written as {"layout", "areas"}.',
    ];

    /**
     * @param \Closure(): LayoutResource $resource asked on read
     * @param \Closure(): LayoutInput    $input    asked on merge
     */
    public function __construct(
        private readonly BlocksFieldValue $blocks,
        private readonly \Closure $resource,
        private readonly \Closure $input,
    ) {}

    public function toStored(mixed $input, TypeFieldDefinition $field): mixed
    {
        if (!is_array($input) && !(is_string($input) && is_array(json_decode($input, true)))) {
            return $input;
        }

        return BlockLists::map($field, $input, fn (array $list): array => $this->blocks->toStored($list, new TypeFieldDefinition($field->name, BlockLists::BLOCKS)));
    }

    public function toOutput(mixed $stored, TypeFieldDefinition $field): mixed
    {
        $out = ($this->resource)()->areasField($field, $stored);
        foreach (['areas', 'unplaced'] as $key) {
            if (is_array($out[$key])) {
                foreach ($out[$key] as $name => $list) {
                    $out[$key][$name] = array_map($this->blocks->outputBlock(...), $list);
                }
            }
        }

        return $out;
    }

    public function shape(TypeFieldDefinition $field): string
    {
        return 'areas_value';
    }

    public function shapes(): array
    {
        return self::SHAPES;
    }

    public function merge(mixed $stored, mixed $input, TypeFieldDefinition $field): mixed
    {
        return ($this->input)()->mergeField($field, $stored, $input);
    }

    public function clean(mixed $value, TypeFieldDefinition $field): mixed
    {
        return $this->blocks->clean($value, $field);
    }

    public function references(mixed $stored, TypeFieldDefinition $field): array
    {
        return $this->blocks->references($stored, $field);
    }
}
