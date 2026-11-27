<?php

declare(strict_types=1);

namespace Preflow\Blocks;

use Preflow\Blocks\Layouts\AreaLayoutSets;
use Preflow\Blocks\Layouts\AreasValue;
use Preflow\Data\TypeFieldDefinition;

/**
 * The block lists a field's value holds: the one place that knows which field types hold blocks.
 * A "blocks" field holds one list, at the path "" under the field; an "areas" field (a layout from
 * a named set and its areas, AreasValue) one list per area, at "areas.<area>"; every other type
 * holds none. Values may be arrays or their JSON text. Whoever walks blocks (the value checks, the
 * visible values, rich text, assets, translation, fresh uids, the editor) goes through holds(),
 * of() and map(), so a type that holds blocks elsewhere in its value is added here once.
 *
 * @since 0.92.0
 */
final class BlockLists
{
    /** A block's uid as Folio's editor stores it. */
    private const string UID = '/^[a-f0-9]{16}$/';

    /** The field type that holds one list of blocks. */
    public const string BLOCKS = 'blocks';

    /** The field type that holds a layout from a named set and a list of blocks per area (since 0.92). */
    public const string AREAS = 'areas';

    /** Whether a field of this type holds block lists, whatever its value. */
    public static function holds(TypeFieldDefinition $field): bool
    {
        return $field->type === self::BLOCKS || $field->type === self::AREAS;
    }

    /**
     * The block lists of the value, keyed by their path under the field ("" for a blocks field,
     * "areas.<area>" for an areas field); [] for a type that holds none. Each list holds the arrays
     * of the value, in order.
     *
     * An areas field gives every stored area; with $sets (and the field's set registered there)
     * only the areas its layout shows, in the layout's order, as the page shows them: the value
     * checks read those (an area the layout does not show is kept, not checked).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function of(TypeFieldDefinition $field, mixed $value, ?AreaLayoutSets $sets = null): array
    {
        if ($field->type === self::BLOCKS) {
            return ['' => self::listOf($value)];
        }
        if ($field->type !== self::AREAS) {
            return [];
        }
        $areas = AreasValue::fromStored($value);
        $set = self::setName($field);
        $lists = $sets !== null && $set !== null && $sets->has($set) ? $areas->placement($sets->get($set))->placed() : $areas->areas;
        $out = [];
        foreach ($lists as $name => $list) {
            $out['areas.' . $name] = $list;
        }

        return $out;
    }

    /**
     * The value with each block list replaced by what $map gives for it (with its path), the
     * shape of the value kept: a blocks field's list, an areas field's {layout, areas} with every
     * stored area mapped. A value of a type that holds no blocks, and an areas value with neither
     * layout nor areas, come back as given.
     *
     * @param \Closure(list<array<string, mixed>> $list, string $path): list<array<string, mixed>> $map
     */
    public static function map(TypeFieldDefinition $field, mixed $value, \Closure $map): mixed
    {
        if ($field->type === self::BLOCKS) {
            return $map(self::listOf($value), '');
        }
        if ($field->type !== self::AREAS) {
            return $value;
        }
        $areas = AreasValue::fromStored($value);
        if ($areas->layout === null && $areas->areas === []) {
            return $value;
        }
        $mapped = [];
        foreach ($areas->areas as $name => $list) {
            $mapped[$name] = $map($list, 'areas.' . $name);
        }

        return AreasValue::of($areas->layout, $mapped)->toStored();
    }

    /** The name of the area layout set an areas field's config names ("layouts"); null when it names none. */
    public static function setName(TypeFieldDefinition $field): ?string
    {
        $set = $field->config['layouts'] ?? null;

        return is_string($set) && $set !== '' ? $set : null;
    }

    /**
     * The uid a block is known by: its "_uid" when it is one ("<16 hex digits>"), else one derived
     * from its place and content, the same on every read (a hand-edited or imported block), as
     * Folio's editor gives it.
     *
     * @param array<string, mixed> $block
     * @param int $index its place among the arrays of its list
     */
    public static function uidOf(array $block, int $index): string
    {
        $uid = $block['_uid'] ?? null;
        if (is_string($uid) && preg_match(self::UID, $uid) === 1) {
            return $uid;
        }

        return hash('xxh3', 'folio-block:' . $index . ':' . json_encode($uid) . ':' . json_encode($block['_block'] ?? null));
    }

    /**
     * A stored list: an array or its JSON text; entries that are no arrays are left out.
     *
     * @return list<array<string, mixed>>
     */
    private static function listOf(mixed $value): array
    {
        if (is_string($value)) {
            $value = trim($value) === '' ? [] : json_decode($value, true);
        }

        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }
}
