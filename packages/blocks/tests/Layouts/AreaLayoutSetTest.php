<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Layouts;

use PHPUnit\Framework\TestCase;
use Preflow\Blocks\Layouts\AreaDefinition;
use Preflow\Blocks\Layouts\AreaLayout;
use Preflow\Blocks\Layouts\AreaLayoutSet;
use Preflow\Blocks\Layouts\AreaLayoutSets;
use Preflow\Blocks\Layouts\LayoutConfigException;

final class AreaLayoutSetTest extends TestCase
{
    private function layout(string $key, int $areas): AreaLayout
    {
        $list = [];
        for ($i = 1; $i <= $areas; $i++) {
            $list['c' . $i] = new AreaDefinition('c' . $i, 'Column ' . $i);
        }

        return new AreaLayout($key, $key, $list);
    }

    public function test_resolve_takes_same_count_then_fallback_then_first(): void
    {
        $set = new AreaLayoutSet('columns', [$this->layout('1-1', 2), $this->layout('full', 1), $this->layout('1-2', 2)], 'full');

        $this->assertSame('1-2', $set->resolve('1-2')->key, 'a known key');
        $this->assertSame('1-1', $set->resolve('2-1')->key, 'the first with the same count');
        $this->assertSame('full', $set->resolve('1-1-1-1')->key, 'no such count: the fallback');
        $this->assertSame('full', $set->resolve('gone')->key);
        $this->assertSame('full', $set->resolve(null)->key);
        $this->assertNull($set->get('2-1'));
        $this->assertCount(3, $set->all());

        $this->assertSame('1-1', (new AreaLayoutSet('c', [$this->layout('1-1', 2), $this->layout('full', 1)]))->resolve('gone')->key, 'no fallback: the first');
        $this->assertNull((new AreaLayoutSet('c', []))->resolve('x'));
    }

    public function test_sets_are_registered_once_and_built_on_first_use(): void
    {
        $sets = new AreaLayoutSets();
        $built = 0;
        $sets->add('columns', function () use (&$built): AreaLayoutSet {
            ++$built;

            return new AreaLayoutSet('columns', [$this->layout('full', 1)]);
        });

        $this->assertTrue($sets->has('columns'));
        $this->assertFalse($sets->has('other'));
        $this->assertSame($sets->get('columns'), $sets->get('columns'));
        $this->assertSame(1, $built);

        try {
            $sets->add('columns', fn (): AreaLayoutSet => new AreaLayoutSet('columns', []));
            $this->fail('registered twice');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('"columns" is registered twice', $e->getMessage());
        }
        $this->expectException(LayoutConfigException::class);
        $this->expectExceptionMessage('No package registers the area layout set "x". The package that brings the block registers it in its service provider (config/providers.php).');
        $sets->get('x');
    }
}
