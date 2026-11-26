<?php

declare(strict_types=1);

namespace Preflow\Blocks\Layouts;

use Preflow\Blocks\BlockRegistry;

/**
 * The layout a page renders with (PageLayouts::resolve()): $layout (null when no allowed layout
 * exists), the key stored on the page, whether the page is pinned (by $pinnedSlug, its
 * default-locale slug), whether a stored or pinned key could not be used ($fallback: its class is
 * gone or the type no longer allows it), and where the page's area blocks go ($placement).
 *
 * @since 0.92.0
 */
final readonly class ResolvedLayout
{
    public function __construct(
        public ?AreaLayout $layout,
        public ?string $storedKey,
        public bool $pinned,
        public ?string $pinnedSlug,
        public bool $fallback,
        public Placement $placement,
        private ?BlockRegistry $blocks = null,
    ) {}

    /** The layout's template name (the site's engine resolves it); null without a layout. */
    public function template(): ?string
    {
        return $this->layout?->template;
    }

    /**
     * What a page template gets: "layout" (key, label, pinned; null without a layout) and "areas",
     * the areas the layout shows by name, in its order, each a block list for blocks(areas.main).
     * Areas the layout lacks are not in it (they are never rendered). With a block registry each
     * block is as BlockRegistry::visibleBlock() reads it (a field its show_when hides is null).
     *
     * @return array{layout: array{key: string, label: string, pinned: bool}|null, areas: array<string, list<array<string, mixed>>>}
     */
    public function context(): array
    {
        $areas = $this->placement->placed();
        if ($this->blocks !== null) {
            foreach ($areas as $name => $blocks) {
                $areas[$name] = array_map($this->blocks->visibleBlock(...), $blocks);
            }
        }

        return [
            'layout' => $this->layout === null ? null : ['key' => $this->layout->key, 'label' => $this->layout->label, 'pinned' => $this->pinned],
            'areas' => $areas,
        ];
    }
}
