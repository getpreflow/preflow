<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Blocks;

use Preflow\Blocks\Layouts\AreaDefinition;
use Preflow\Blocks\Layouts\AreaLayout;
use Preflow\Blocks\Layouts\AreaLayoutSet;
use Preflow\Blocks\Layouts\AreaLayoutSets;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;

/**
 * Registers the area layout set "grid" in register(), as a package bringing a columns block does:
 * "full" (c1), "1-1" (c1, c2), "1-1-1" (c1, c2, c3); no column takes a grid; fallback "full".
 */
final class GridSetProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        if (!$container->bound(AreaLayoutSets::class)) {
            $container->instance(AreaLayoutSets::class, new AreaLayoutSets());
        }
        $container->get(AreaLayoutSets::class)->add('grid', static function (): AreaLayoutSet {
            $column = static fn (int $n): AreaDefinition => new AreaDefinition('c' . $n, 'Column ' . $n, except: ['grid'], width: 1);

            return new AreaLayoutSet('grid', [
                new AreaLayout('full', 'Full width', ['c1' => $column(1)]),
                new AreaLayout('1-1', 'Two columns', ['c1' => $column(1), 'c2' => $column(2)]),
                new AreaLayout('1-1-1', 'Three columns', ['c1' => $column(1), 'c2' => $column(2), 'c3' => $column(3)]),
            ], fallback: 'full');
        });
    }
}
