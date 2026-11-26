<?php

declare(strict_types=1);

namespace Preflow\Blocks\Layouts\Rules;

use Preflow\Blocks\BlockRegistry;
use Preflow\Blocks\Layouts\AreaLayout;
use Preflow\Blocks\Layouts\AreaLayoutSet;
use Preflow\Blocks\Layouts\PageLayouts;
use Preflow\Blocks\Layouts\Placement;
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
 * forPage(): a page's "_areas", on the layout its values resolve to (PageLayouts::resolveValues()).
 * forField(): a value {layout, areas} whose layout is from a named set (the areas field).
 *
 * @since 0.92.0
 */
final readonly class AreaContents implements ValidationRule
{
    /**
     * @param \Closure(mixed, ValidationContext): array{?AreaLayout, array<string, list<array<string, mixed>>>} $read
     *        the layout and the areas map of the value
     */
    private function __construct(private \Closure $read, private ?BlockRegistry $blocks) {}

    /** A page's "_areas": the layout from the record's values (its pin, "_layout", the default). */
    public static function forPage(PageLayouts $layouts, TypeDefinition $def, ?BlockRegistry $blocks = null): self
    {
        return new self(static function (mixed $value, ValidationContext $context) use ($layouts, $def): array {
            $resolved = $layouts->resolveValues($def, [PageLayouts::AREAS => $value] + $context->data);

            return [$resolved?->layout, Placement::areasOf($value)];
        }, $blocks);
    }

    /** A value {"layout": key, "areas": {…}} whose layout the set resolves (by key, else by its number of areas). */
    public static function forField(AreaLayoutSet $set, ?BlockRegistry $blocks = null): self
    {
        return new self(static function (mixed $value): array {
            if (is_string($value)) {
                $value = trim($value) === '' ? [] : json_decode($value, true);
            }
            $value = is_array($value) ? $value : [];
            $areas = Placement::areasOf($value['areas'] ?? null);
            $key = $value['layout'] ?? null;

            return [$set->resolve(is_string($key) ? $key : null, $areas === [] ? null : count($areas)), $areas];
        }, $blocks);
    }

    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        [$layout, $areas] = ($this->read)($value, $context);
        foreach ($layout?->areas ?? [] as $name => $area) {
            $blocks = $areas[$name] ?? [];
            foreach ($blocks as $block) {
                $key = $block['_block'] ?? null;
                if (!is_string($key) || ($this->blocks !== null && $this->blocks->get($key) === null)) {
                    continue;
                }
                if (!$area->accepts($key)) {
                    return new Message('validation.layout.area_block', ['area' => $name, 'block' => $key]);
                }
            }
            if ($area->max !== null && count($blocks) > $area->max) {
                return new Message('validation.layout.area_max', ['area' => $name, 'max' => (string) $area->max]);
            }
            if ($area->min !== null && count($blocks) < $area->min) {
                return new Message('validation.layout.area_min', ['area' => $name, 'min' => (string) $area->min]);
            }
        }

        return true;
    }
}
