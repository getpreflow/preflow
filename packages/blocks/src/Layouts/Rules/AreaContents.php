<?php

declare(strict_types=1);

namespace Preflow\Blocks\Layouts\Rules;

use Preflow\Blocks\BlockRegistry;
use Preflow\Blocks\Layouts\AreaLayout;
use Preflow\Blocks\Layouts\AreaLayoutSet;
use Preflow\Blocks\Layouts\AreasValue;
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
 * is kept, as in a blocks field. validate() gives the first problem, in the layout's area order;
 * problems() gives each area's first one, by area (a page's record keys them "_areas.<area>").
 *
 * Only what a write introduces is refused, so a site may narrow an area's "allowed" or lower its
 * "max" without making existing pages unsavable: a block the area does not accept passes when it
 * was stored in that area already (same uid and key); "max" refuses only a count above it and
 * above the stored count, "min" only a count below it and below the stored count (an editor may
 * reduce an over-full area step by step). An area a stored record lacks counts as stored empty
 * (a type that opts into layouts keeps its pages savable); only a new record is checked plainly.
 *
 * forPage(): a page's "_areas", on the layout its values resolve to (PageLayouts::resolveValues()).
 * forField(): a value {layout, areas} whose layout is from a named set (the areas field), with the
 * same tolerance against the field's stored value.
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

    /**
     * A value {"layout": key, "areas": {…}} (an areas field, AreasValue) whose layout the set
     * resolves (by key, else by its number of areas). $stored is the field's stored value, read by
     * the caller as forPage() reads "_areas" (the stored record's field, or the stored block's with
     * the same uid; JSON text or an array): its areas are what the write may keep. Null: nothing is
     * stored (a new record, a new block), and every area is checked as new.
     */
    public static function forField(AreaLayoutSet $set, ?BlockRegistry $blocks = null, mixed $stored = null): self
    {
        $storedAreas = $stored === null ? null : AreasValue::fromStored($stored)->areas;

        return new self(static function (mixed $value) use ($set, $storedAreas): array {
            $areas = AreasValue::fromStored($value);

            return [$areas->resolve($set), $areas->areas, $storedAreas];
        }, $blocks);
    }

    /** The first problem, in the layout's area order (a rule on the whole value; problems() gives each area's). */
    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        $problems = $this->problems($value, $context);

        return $problems === [] ? true : reset($problems);
    }

    /**
     * Each area's first problem, by area name in the layout's order; [] when the value passes. A
     * page's record reports them keyed "_areas.<area>" (BlocksServiceProvider's record check), so an
     * editor and an API client see the area that is wrong.
     *
     * @return array<string, Message>
     * @since 0.92.0
     */
    public function problems(mixed $value, ValidationContext $context): array
    {
        [$layout, $areas, $storedAreas] = ($this->read)($value, $context);
        $problems = [];
        foreach ($layout->areas ?? [] as $name => $area) {
            $problem = $this->problemOf($area, (string) $name, $areas[$name] ?? [], $storedAreas);
            if ($problem !== null) {
                $problems[(string) $name] = $problem;
            }
        }

        return $problems;
    }

    /**
     * @param list<array<string, mixed>>                     $blocks
     * @param array<string, list<array<string, mixed>>>|null $storedAreas
     */
    private function problemOf(\Preflow\Blocks\Layouts\AreaDefinition $area, string $name, array $blocks, ?array $storedAreas): ?Message
    {
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

        return null;
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
