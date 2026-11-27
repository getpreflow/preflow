<?php

declare(strict_types=1);

namespace Preflow\Blocks\Layouts;

/**
 * A layout as data: its areas in order. $class is the declaring class (null for a layout a package
 * builds, such as a column layout).
 *
 * @since 0.92.0
 */
final readonly class AreaLayout
{
    /**
     * @param array<string, AreaDefinition> $areas by area name, in order
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $areas,
        public ?string $template = null,
        public ?string $description = null,
        public ?string $icon = null,
        public ?string $class = null,
    ) {}

    /** @return list<string> */
    public function areaNames(): array
    {
        return array_map('strval', array_keys($this->areas));
    }
}
