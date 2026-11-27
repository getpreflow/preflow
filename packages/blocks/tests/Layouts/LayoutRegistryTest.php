<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Layouts;

use PHPUnit\Framework\TestCase;
use Preflow\Blocks\Layouts\LayoutConfigException;
use Preflow\Blocks\Layouts\LayoutRegistry;
use Preflow\Blocks\Tests\Fixtures\Layouts\BadKey;
use Preflow\Blocks\Tests\Fixtures\Layouts\DuplicateLanding;
use Preflow\Blocks\Tests\Fixtures\Layouts\MinAboveMax;
use Preflow\Blocks\Tests\Fixtures\Layouts\NoAreas;
use Preflow\Blocks\Tests\Fixtures\Layouts\ReservedArea;
use Preflow\Blocks\Tests\Fixtures\Layouts\Standard;

final class LayoutRegistryTest extends TestCase
{
    private const string DIR = __DIR__ . '/../Fixtures/app/app/Layouts';

    protected function setUp(): void
    {
        require_once self::DIR . '/Landing.php';
    }

    public function test_discovers_app_layouts_package_layouts_and_config_classes(): void
    {
        $registry = LayoutRegistry::discover(self::DIR, [Standard::class, \stdClass::class, 'No\\Such\\Class']);

        $this->assertSame(['landing', 'standard'], array_keys($registry->all()));
        $landing = $registry->get('landing');
        $this->assertSame('Landing', $landing->label);
        $this->assertSame('layouts/landing.twig', $landing->template);
        $this->assertSame('rocket', $landing->icon);
        $this->assertSame(\App\Layouts\Landing::class, $landing->class);
        $this->assertSame(['hero', 'main'], $landing->areaNames(), 'declaration order is area order');
        $hero = $landing->areas['hero'];
        $this->assertSame('Hero', $hero->label);
        $this->assertSame(['hero'], $hero->allowed);
        $this->assertSame(1, $hero->max);
        $this->assertTrue($hero->accepts('hero'));
        $this->assertFalse($hero->accepts('text'));
        $this->assertFalse($landing->areas['main']->accepts('columns'), 'except');
        $this->assertTrue($landing->areas['main']->accepts('text'));
        $this->assertNull($registry->get('missing'));

        $field = $hero->blocksField();
        $this->assertSame('blocks', $field->type);
        $this->assertSame(['allowed' => ['hero'], 'max' => 1], $field->config);
    }

    public function test_to_array_and_from_array_round_trip(): void
    {
        $registry = LayoutRegistry::discover(self::DIR, [Standard::class]);

        $this->assertEquals($registry->all(), LayoutRegistry::fromArray($registry->toArray())->all());
    }

    public function test_layout_registry_is_cached_and_rebuilt_on_change(): void
    {
        $cache = sys_get_temp_dir() . '/layouts_' . bin2hex(random_bytes(4)) . '/layouts.cache';
        try {
            LayoutRegistry::discover(self::DIR, [Standard::class], $cache, useCache: true);
            $this->assertFileExists($cache);

            // Same classes and mtimes: read from the cache, whatever it holds besides the signature.
            $stored = unserialize((string) file_get_contents($cache));
            $stored['layouts'] = [];
            file_put_contents($cache, serialize($stored));
            $this->assertSame([], LayoutRegistry::discover(self::DIR, [Standard::class], $cache, useCache: true)->all());

            // Another class list: rebuilt.
            $this->assertSame(['landing'], array_keys(LayoutRegistry::discover(self::DIR, [], $cache, useCache: true)->all()));
            // Not used while debugging.
            file_put_contents($cache, 'garbage');
            $this->assertCount(2, LayoutRegistry::discover(self::DIR, [Standard::class], $cache)->all());
        } finally {
            @unlink($cache);
            @rmdir(dirname($cache));
        }
    }

    public function test_refuses_duplicate_key_naming_both_classes(): void
    {
        $this->expectException(LayoutConfigException::class);
        $this->expectExceptionMessage('Layout key "landing" is declared by both App\Layouts\Landing and ' . DuplicateLanding::class);
        LayoutRegistry::discover(self::DIR, [DuplicateLanding::class]);
    }

    public function test_refuses_reserved_area_name(): void
    {
        $this->expectException(LayoutConfigException::class);
        $this->expectExceptionMessage('Area "_meta" on ' . ReservedArea::class . ' uses a reserved name');
        new LayoutRegistry([ReservedArea::class]);
    }

    public function test_refuses_a_bad_key_a_layout_without_areas_and_min_above_max(): void
    {
        foreach ([
            BadKey::class => 'Layout key "Bad Key"',
            NoAreas::class => 'has no areas',
            MinAboveMax::class => 'min 3 above max 1',
        ] as $class => $message) {
            try {
                new LayoutRegistry([$class]);
                $this->fail("$class was not refused");
            } catch (LayoutConfigException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
                $this->assertStringContainsString($class, $e->getMessage());
            }
        }
    }
}
