<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Layouts;

use PHPUnit\Framework\TestCase;
use Preflow\Blocks\Layouts\AreaDefinition;
use Preflow\Blocks\Layouts\AreaLayout;
use Preflow\Blocks\Layouts\Placement;

final class PlacementTest extends TestCase
{
    private function standard(): AreaLayout
    {
        return new AreaLayout('standard', 'Standard', ['main' => new AreaDefinition('main', 'Main')]);
    }

    public function test_placement_keeps_what_the_layout_lacks(): void
    {
        $h1 = ['_uid' => 'h1', '_block' => 'hero'];
        $m1 = ['_uid' => 'm1', '_block' => 'text'];
        $s1 = ['_uid' => 's1', '_block' => 'text'];
        $placement = Placement::of($this->standard(), ['hero' => [$h1], 'main' => [$m1], 'side' => [$s1]]);

        $this->assertSame(['main' => [$m1]], $placement->placed());
        $this->assertSame(['hero' => [$h1], 'side' => [$s1]], $placement->unplaced());
        $this->assertSame(['hero', 'main', 'side'], array_keys($placement->areas()));
    }

    public function test_placed_lists_every_area_of_the_layout_in_its_order(): void
    {
        $layout = new AreaLayout('l', 'L', ['hero' => new AreaDefinition('hero', 'Hero'), 'main' => new AreaDefinition('main', 'Main')]);

        $this->assertSame(['hero' => [], 'main' => [['_uid' => 'm']]], Placement::of($layout, ['main' => [['_uid' => 'm']]])->placed());
    }

    public function test_placement_drops_empty_unplaced_areas_only_from_the_panel(): void
    {
        $placement = Placement::of($this->standard(), ['hero' => [], 'main' => []]);

        $this->assertSame([], $placement->unplaced());
        $this->assertSame(['hero' => [], 'main' => []], $placement->areas(), 'the stored map keeps the empty area');
    }

    public function test_without_a_layout_everything_is_unplaced(): void
    {
        $placement = Placement::of(null, ['main' => [['_uid' => 'm']]]);

        $this->assertSame([], $placement->placed());
        $this->assertSame(['main' => [['_uid' => 'm']]], $placement->unplaced());
    }

    public function test_moved_and_removed(): void
    {
        $areas = ['hero' => [['_uid' => 'h1']], 'main' => [['_uid' => 'm1'], ['_uid' => 'm2']]];

        $moved = Placement::moved($areas, 'h1', 'main');
        $this->assertSame(['hero' => [], 'main' => [['_uid' => 'm1'], ['_uid' => 'm2'], ['_uid' => 'h1']]], $moved);
        $this->assertSame(['hero' => [['_uid' => 'h1']], 'main' => [['_uid' => 'm2'], ['_uid' => 'm1']]], Placement::moved($areas, 'm1', 'main'), 'within an area the block goes to its end');
        $this->assertSame($areas, Placement::moved($areas, 'nobody', 'main'));

        $this->assertSame(['hero' => [['_uid' => 'h1']], 'main' => [['_uid' => 'm2']]], Placement::removed($areas, 'm1'));
        $this->assertSame(['hero' => [], 'main' => [['_uid' => 'm1'], ['_uid' => 'm2']]], Placement::removed($areas, 'h1'), 'the area stays, empty');
    }

    public function test_areas_of_reads_json_text_and_empty_objects(): void
    {
        $this->assertSame([], Placement::areasOf('{}'));
        $this->assertSame([], Placement::areasOf('[]'));
        $this->assertSame([], Placement::areasOf([]));
        $this->assertSame([], Placement::areasOf(null));
        $this->assertSame([], Placement::areasOf(''));
        $this->assertSame(['main' => [['_uid' => 'm']]], Placement::areasOf('{"main":[{"_uid":"m"}]}'));
        $this->assertSame(['main' => [['_uid' => 'm']]], Placement::areasOf(['main' => [['_uid' => 'm']]]));
    }
}
