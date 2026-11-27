<?php

declare(strict_types=1);

namespace Preflow\BlockLibrary\Columns;

use Preflow\BlockLibrary\ColumnLayouts;
use Preflow\Blocks\Block;
use Preflow\Blocks\BlockComponent;
use Preflow\Blocks\BlockField;
use Preflow\Blocks\Layouts\AreaLayoutSets;
use Preflow\Blocks\Layouts\AreasValue;
use Preflow\View\AssetCollector;

/**
 * The columns block: a column layout from the set "columns" (ColumnLayouts, config/blocks.php)
 * and the blocks of each column. One template renders every layout: each placed column is a cell
 * with its width class (pf-columns__cell--w1…w12, Columns.css); a column the layout does not show
 * is kept in the stored value and never rendered.
 *
 * The template gets cells (the placed columns in order: width and blocks) and layout (the key it
 * renders with, the stored one or its fallback).
 *
 * @since 0.92.0
 */
#[Block(key: 'columns', label: 'block-library.columns.label', group: 'block-library.groups.layout', icon: 'columns')]
final class Columns extends BlockComponent
{
    public const string KEY = 'columns';

    /** @var array<string, mixed> the stored value: {"layout": "2-1", "areas": {"c1": [blocks…], "c2": […]}} */
    #[BlockField(type: 'areas', label: 'block-library.columns.columns', config: ['layouts' => ColumnLayouts::SET])]
    public array $columns = [];

    /** @var list<array{width: int, blocks: list<array<string, mixed>>}> */
    public array $cells = [];

    public string $layout = '';

    public function __construct(
        private readonly AreaLayoutSets $sets,
        private readonly AssetCollector $assets,
    ) {}

    public function resolveState(): void
    {
        $set = $this->sets->get(ColumnLayouts::SET);
        $value = AreasValue::fromStored($this->columns);
        $resolved = $value->resolve($set);
        $placed = $value->placement($set)->placed();

        $this->layout = $resolved->key ?? '';
        $this->cells = [];
        foreach ($resolved->areas ?? [] as $name => $area) {
            $this->cells[] = ['width' => $area->width ?? 1, 'blocks' => $placed[$name] ?? []];
        }

        // The page's style element (with its CSP nonce), once per page; never inside the CSS scope
        // of a component the block is rendered in (resolveState() runs in its parent's scope).
        $scope = $this->assets->getCssScope();
        $this->assets->setCssScope(null);
        try {
            $this->assets->addCss((string) file_get_contents(__DIR__ . '/Columns.css'), 'pf-columns');
        } finally {
            $this->assets->setCssScope($scope);
        }
    }
}
