<?php

declare(strict_types=1);

namespace Preflow\Blocks\Layouts;

/**
 * Where the blocks of an areas map (area name => blocks) go for a layout: the areas the layout has
 * are placed; stored areas it lacks are kept, "Not shown in this layout". Nothing is dropped, so a
 * layout switch loses no content. Shared by a page's areas and the columns block, and pure: it
 * only reads.
 *
 * @since 0.92.0
 */
final readonly class Placement
{
    /** @param array<string, list<array<string, mixed>>> $areas */
    private function __construct(private ?AreaLayout $layout, private array $areas) {}

    /** @param mixed $areas the stored map: JSON text or an array */
    public static function of(?AreaLayout $layout, mixed $areas): self
    {
        return new self($layout, self::areasOf($areas));
    }

    /**
     * Every area of the layout, in its order; [] where nothing is stored.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function placed(): array
    {
        $placed = [];
        foreach ($this->layout?->areaNames() ?? [] as $name) {
            $placed[$name] = $this->areas[$name] ?? [];
        }

        return $placed;
    }

    /**
     * The stored areas with at least one block that the layout lacks, in stored order.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function unplaced(): array
    {
        $unplaced = [];
        foreach ($this->areas as $name => $blocks) {
            if ($blocks !== [] && !isset($this->layout?->areas[$name])) {
                $unplaced[$name] = $blocks;
            }
        }

        return $unplaced;
    }

    /**
     * The whole map, nothing dropped.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function areas(): array
    {
        return $this->areas;
    }

    /**
     * A stored value as an areas map: JSON text or an array; null, "", [] and {} are all empty.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function areasOf(mixed $stored): array
    {
        if (is_string($stored)) {
            $stored = trim($stored) === '' ? [] : json_decode($stored, true);
        }
        if (!is_array($stored)) {
            return [];
        }
        $areas = [];
        foreach ($stored as $name => $blocks) {
            $list = [];
            foreach (is_array($blocks) ? $blocks : [] as $block) {
                if (is_array($block)) {
                    $list[] = $block;
                }
            }
            $areas[(string) $name] = $list;
        }

        return $areas;
    }

    /**
     * The block with this uid moved to the end of $to, from wherever it is; the map as given when no
     * block has the uid. $to is created when the map lacks it.
     *
     * @param array<string, list<array<string, mixed>>> $areas
     * @return array<string, list<array<string, mixed>>>
     */
    public static function moved(array $areas, string $uid, string $to): array
    {
        $found = null;
        foreach ($areas as $name => $blocks) {
            foreach ($blocks as $index => $block) {
                if (($block['_uid'] ?? null) === $uid) {
                    $found = $block;
                    unset($blocks[$index]);
                    $areas[$name] = array_values($blocks);
                    break 2;
                }
            }
        }
        if ($found === null) {
            return $areas;
        }
        $areas[$to] = [...($areas[$to] ?? []), $found];

        return $areas;
    }

    /**
     * The map without the block with this uid (an explicit Remove); the areas stay, even empty.
     *
     * @param array<string, list<array<string, mixed>>> $areas
     * @return array<string, list<array<string, mixed>>>
     */
    public static function removed(array $areas, string $uid): array
    {
        foreach ($areas as $name => $blocks) {
            $areas[$name] = array_values(array_filter($blocks, static fn (array $block): bool => ($block['_uid'] ?? null) !== $uid));
        }

        return $areas;
    }
}
