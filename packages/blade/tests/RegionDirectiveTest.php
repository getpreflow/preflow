<?php

declare(strict_types=1);

namespace Preflow\Blade\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Blade\BladeEngine;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;

/** @region('name', [attrs]) … @endregion: the same as Twig's {% region %} (RegionTagTest). */
final class RegionDirectiveTest extends TestCase
{
    private string $dir;

    private BladeEngine $engine;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/preflow_region_blade_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/cache', 0777, true);
        $this->engine = new BladeEngine([$this->dir], new AssetCollector(new NonceGenerator()), cachePath: $this->dir . '/cache');
        $this->engine->addGlobal('site', 'Acme');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function test_regions_in_order_and_each_alone(): void
    {
        $this->write('page', "@region('filters')F{{ \$n }}@endregion-@region(\"results\")R{{ \$n }}@endregion");

        $this->assertSame(['filters', 'results'], $this->engine->regionsOf('page'));
        $this->assertSame('<div id="c-filters">F1</div>-<div id="c-results">R1</div>', $this->engine->render('page', ['componentId' => 'c', 'n' => 1]));
        $this->assertSame('R1', $this->engine->renderRegion('page', 'results', ['componentId' => 'c', 'n' => 1]));
        $this->assertSame([], $this->engine->regionsOf($this->write('plain', 'x')));
    }

    public function test_a_component_template_by_its_path_and_by_its_file_name(): void
    {
        $path = $this->write('Comp', "<h1>{{ \$title }}</h1>@region('list')<ul>{{ \$site }}</ul>@endregion");

        $this->assertSame('<h1>T</h1><div id="c9-list"><ul>Acme</ul></div>', $this->engine->render($path, ['componentId' => 'c9', 'title' => 'T']));
        $this->assertSame('<ul>Acme</ul>', $this->engine->renderRegion($path, 'list', ['componentId' => 'c9']), 'globals too');
        $this->assertSame(['list'], $this->engine->regionsOf('Comp.blade.php'));
    }

    public function test_attributes_are_escaped_true_prints_the_name_false_and_null_leave_it_out(): void
    {
        $this->write('a', "@region('r', ['class' => \$cls, 'hidden' => true, 'open' => false, 'title' => null, 'data-n' => 3])<i>x</i>@endregion");

        $this->assertSame('<div id="c-r" class="a&quot;&gt;&lt;b" hidden data-n="3"><i>x</i></div>', $this->engine->render('a', ['componentId' => 'c', 'cls' => 'a"><b']));
    }

    public function test_a_region_without_component_id_fails(): void
    {
        $this->write('a', "@region('r')<i>x</i>@endregion");

        $this->expectExceptionMessage('componentId');
        $this->engine->render('a');
    }

    public function test_two_regions_of_one_name_fail_to_compile(): void
    {
        $this->write('a', "@region('r')<i>x</i>@endregion @region('r')<i>y</i>@endregion");

        $this->expectExceptionMessage("two regions named 'r'");
        $this->engine->render('a', ['componentId' => 'c']);
    }

    public function test_regions_do_not_nest(): void
    {
        $this->write('a', "@region('a')@region('b')<i>x</i>@endregion@endregion");

        $this->expectExceptionMessage('nest');
        $this->engine->render('a', ['componentId' => 'c']);
    }

    public function test_a_region_name_is_a_constant_name(): void
    {
        $this->write('a', "@region('no space')<i>x</i>@endregion");
        $this->write('b', '@region($name)<i>x</i>@endregion');

        foreach (['a', 'b'] as $template) {
            try {
                $this->engine->render($template, ['componentId' => 'c', 'name' => 'x']);
                $this->fail($template . ' compiled');
            } catch (\Throwable $e) {
                $this->assertStringContainsString('region', $e->getMessage());
            }
        }
    }

    public function test_a_region_without_its_end_fails(): void
    {
        $this->write('a', "@region('r')<i>x</i>");
        $this->write('b', '<i>x</i>@endregion');

        foreach (['a' => '@region without @endregion', 'b' => '@endregion without @region'] as $template => $message) {
            try {
                $this->engine->render($template, ['componentId' => 'c']);
                $this->fail($template . ' compiled');
            } catch (\Throwable $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function test_a_region_body_sees_loops_and_includes(): void
    {
        $this->write('row', '<li>{{ $row }}</li>');
        $this->write('a', "@region('r')@foreach (\$rows as \$row)@include('row')@endforeach
@endregion");

        $this->assertSame('<div id="c-r"><li>1</li><li>2</li></div>', $this->engine->render('a', ['componentId' => 'c', 'rows' => [1, 2]]));
        $this->assertSame('<li>1</li><li>2</li>', $this->engine->renderRegion('a', 'r', ['componentId' => 'c', 'rows' => [1, 2]]));
    }

    public function test_unknown_region_names_template_and_regions(): void
    {
        $this->write('a', "@region('r')<i>x</i>@endregion");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("has no region 'x'; its regions: r.");
        $this->engine->renderRegion('a', 'x', ['componentId' => 'c']);
    }

    private function write(string $name, string $source): string
    {
        file_put_contents($this->dir . '/' . $name . '.blade.php', $source);

        return $this->dir . '/' . $name . '.blade.php';
    }
}
