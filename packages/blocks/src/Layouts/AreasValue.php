<?php

declare(strict_types=1);

namespace Preflow\Blocks\Layouts;

/**
 * The value of an "areas" field (the columns block's columns): a layout from a named set and the
 * blocks of each area,
 *
 *     {"layout": "2-1", "areas": {"c1": [{"_uid": "…", "_block": "text", …}], "c2": []}}
 *
 * The field's config names the set ({"layouts": "columns"}); AreaLayoutSets has it. A stored key
 * the set no longer has still resolves (AreaLayoutSet::resolve(), by the number of stored areas),
 * and the areas that layout lacks are kept, "Not shown in this layout" (Placement), as for a page.
 *
 * @since 0.92.0
 */
final readonly class AreasValue
{
    /** @param array<string, list<array<string, mixed>>> $areas */
    private function __construct(public ?string $layout, public array $areas) {}

    /** A stored value: an array or its JSON text; anything else is an empty value (no layout, no areas). */
    public static function fromStored(mixed $stored): self
    {
        if (is_string($stored)) {
            $stored = trim($stored) === '' ? [] : json_decode($stored, true);
        }
        $stored = is_array($stored) ? $stored : [];
        $layout = $stored['layout'] ?? null;

        return new self(is_string($layout) && $layout !== '' ? $layout : null, Placement::areasOf($stored['areas'] ?? null));
    }

    /**
     * The value with another layout key and areas.
     *
     * @param array<string, list<array<string, mixed>>> $areas
     */
    public static function of(?string $layout, array $areas): self
    {
        return new self($layout !== '' ? $layout : null, Placement::areasOf($areas));
    }

    /**
     * The value as it is stored. Area names are never numeric (c1…cN), so the map stays a map in
     * JSON; with no area at all it is [].
     *
     * @return array{layout: ?string, areas: array<string, list<array<string, mixed>>>}
     */
    public function toStored(): array
    {
        return ['layout' => $this->layout, 'areas' => $this->areas];
    }

    /** The layout the value shows with: its key, else the set's first with as many areas as are stored, else the set's fallback. */
    public function resolve(AreaLayoutSet $set): ?AreaLayout
    {
        return $set->resolve($this->layout, $this->areas === [] ? null : count($this->areas));
    }

    /** Whether a stored key could not be used (the set lacks it): the value shows with another layout. */
    public function fallback(AreaLayoutSet $set): bool
    {
        return $this->layout !== null && $set->get($this->layout) === null;
    }

    /** The areas the resolved layout shows, and the stored ones it lacks (kept, not shown). */
    public function placement(AreaLayoutSet $set): Placement
    {
        return Placement::of($this->resolve($set), $this->areas);
    }
}
