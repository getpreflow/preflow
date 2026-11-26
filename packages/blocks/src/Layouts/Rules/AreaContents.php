<?php

declare(strict_types=1);

namespace Preflow\Blocks\Layouts\Rules;

use Preflow\Blocks\BlockRegistry;
use Preflow\Blocks\Layouts\AreaLayout;
use Preflow\Blocks\Layouts\AreaLayoutSet;
use Preflow\Blocks\Layouts\PageLayouts;
use Preflow\Blocks\Layouts\Placement;
use Preflow\Data\DataManager;
use Preflow\Data\TypeDefinition;
use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/**
 * What the areas of a layout hold: each area the layout shows takes only the blocks it accepts
 * ("allowed", "except") and as many as its "min" and "max" say. Areas the layout does not show are
 * not checked (they are kept, "Not shown in this layout"), and a block no class declares any more
 * is kept, as in a blocks field. The first problem, in the layout's area order, is the message.
 *
 * Only what a write introduces is refused, so a site may narrow an area's "allowed" or lower its
 * "max" without making existing pages unsavable: a block the area does not accept passes when it
 * was stored in that area already (same uid and key); "max" refuses only a count above it and
 * above the stored count, "min" only a count below it and below the stored count (an editor may
 * reduce an over-full area step by step). An area a stored record lacks counts as stored empty
 * (a type that opts into layouts keeps its pages savable); only a new record is checked plainly.
 *
 * forPage(): a page's "_areas", on the layout its values resolve to (PageLayouts::resolveValues()).
 * forField(): a value {layout, areas} whose layout is from a named set (the areas field).
 *
 * @since 0.92.0
 */
final readonly class AreaContents implements ValidationRule
{
    /**
     * @param \Closure(mixed, ValidationContext): array{?AreaLayout, array<string, list<array<string, mixed>>>, array<string, list<array<string, mixed>>>|null} $read
     *        the layout, the areas map of the value and the stored areas map (null: nothing stored)
     */
    private function __construct(private \Closure $read, private ?BlockRegistry $blocks) {}

    /**
     * A page's "_areas": the layout from the record's values (its pin, "_layout", the default); the
     * stored "_areas" read through $data (without it, every area is checked as new).
     */
    public static function forPage(PageLayouts $layouts, TypeDefinition $def, ?BlockRegistry $blocks = null, ?DataManager $data = null): self
    {
        return new self(static function (mixed $value, ValidationContext $context) use ($layouts, $def, $data): array {
            $resolved = $layouts->resolveValues($def, [PageLayouts::AREAS => $value] + $context->data);
            $id = $context->data[$def->idField] ?? null;
            $stored = $data !== null && is_scalar($id) && (string) $id !== '' ? $data->findIn($def, (string) $id) : null;

            return [$resolved?->layout, Placement::areasOf($value), $stored === null ? null : Placement::areasOf($stored->get(PageLayouts::AREAS))];
        }, $blocks);
    }

    /** A value {"layout": key, "areas": {…}} whose layout the set resolves (by key, else by its number of areas). */
    public static function forField(AreaLayoutSet $set, ?BlockRegistry $blocks = null): self
    {
        return new self(static function (mixed $value) use ($set): array {
            if (is_string($value)) {
                $value = trim($value) === '' ? [] : json_decode($value, true);
            }
            $value = is_array($value) ? $value : [];
            $areas = Placement::areasOf($value['areas'] ?? null);
            $key = $value['layout'] ?? null;

            return [$set->resolve(is_string($key) ? $key : null, $areas === [] ? null : count($areas)), $areas, null];
        }, $blocks);
    }

    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        [$layout, $areas, $storedAreas] = ($this->read)($value, $context);
        foreach ($layout->areas ?? [] as $name => $area) {
            $blocks = $areas[$name] ?? [];
            // A stored record without this area (no "_areas" yet, or the layout gained the area) holds it empty.
            $stored = $storedAreas !== null ? self::listOf($storedAreas[$name] ?? []) : null;
            foreach ($blocks as $block) {
                $key = $block['_block'] ?? null;
                if (!is_string($key) || ($this->blocks !== null && $this->blocks->get($key) === null)) {
                    continue;
                }
                $uid = $block['_uid'] ?? null;
                $kept = $stored !== null && is_string($uid) && in_array([$uid, $key], $stored, true);
                if (!$area->accepts($key) && !$kept) {
                    return new Message('validation.layout.area_block', ['area' => $name, 'block' => $key]);
                }
            }
            // Against the stored count: a write may move an area towards its limits, never further past them.
            $count = count($blocks);
            if ($area->max !== null && $count > $area->max && ($stored === null || $count > count($stored))) {
                return new Message('validation.layout.area_max', ['area' => $name, 'max' => (string) $area->max]);
            }
            if ($area->min !== null && $count < $area->min && ($stored === null || $count < count($stored))) {
                return new Message('validation.layout.area_min', ['area' => $name, 'min' => (string) $area->min]);
            }
        }

        return true;
    }

    /**
     * An area's stored blocks as their uids and keys.
     *
     * @param list<array<string, mixed>> $blocks
     * @return list<array{mixed, mixed}>
     */
    private static function listOf(array $blocks): array
    {
        return array_map(static fn (array $block): array => [$block['_uid'] ?? null, $block['_block'] ?? null], $blocks);
    }
}
