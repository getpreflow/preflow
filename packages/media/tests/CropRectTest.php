<?php

declare(strict_types=1);

namespace Preflow\Media\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Media\CropRect;

final class CropRectTest extends TestCase
{
    public function test_of_clamps_to_the_image(): void
    {
        $this->assertSame(['x' => 0.0, 'y' => 20.0, 'width' => 1.0, 'height' => 80.0], CropRect::of(-10, 50, 0, 80)->toArray());
        $this->assertSame(['x' => 50.0, 'y' => 0.0, 'width' => 50.0, 'height' => 50.0], CropRect::of(90, 0, 50, 50)->toArray());
        $this->assertSame(['x' => 12.35, 'y' => 0.0, 'width' => 50.0, 'height' => 33.33], CropRect::of(12.346, 0, 50, 33.333)->toArray());
        $this->assertSame(['x' => 0.0, 'y' => 0.0, 'width' => 100.0, 'height' => 100.0], CropRect::of(5, 5, 150, 150)->toArray());
    }

    public function test_from_array_reads_a_stored_rect_and_rejects_the_rest(): void
    {
        $rect = CropRect::of(10, 20, 30, 40);
        $this->assertEquals($rect, CropRect::fromArray($rect->toArray()));
        $this->assertEquals($rect, CropRect::fromArray(['x' => '10', 'y' => '20', 'width' => '30', 'height' => '40']));
        $this->assertNull(CropRect::fromArray('nonsense'));
        $this->assertNull(CropRect::fromArray(['x' => 'a']));
        $this->assertNull(CropRect::fromArray(['x' => 1, 'y' => 1, 'width' => 5]));
    }

    public function test_rotated_turns_clockwise(): void
    {
        $rect = CropRect::of(10, 20, 30, 40);
        $this->assertSame(['x' => 40.0, 'y' => 10.0, 'width' => 40.0, 'height' => 30.0], $rect->rotated(1)->toArray());
        $this->assertEquals($rect, $rect->rotated(4));
        $this->assertEquals($rect, $rect->rotated(0));
        $this->assertEquals($rect->rotated(3), $rect->rotated(-1));
    }

    public function test_mirrored(): void
    {
        $rect = CropRect::of(10, 20, 30, 40);
        $this->assertSame(['x' => 60.0, 'y' => 20.0, 'width' => 30.0, 'height' => 40.0], $rect->mirrored(true)->toArray());
        $this->assertSame(['x' => 10.0, 'y' => 40.0, 'width' => 30.0, 'height' => 40.0], $rect->mirrored(false)->toArray());
    }

    public function test_pixels_stay_inside_the_image(): void
    {
        $this->assertSame(['x' => 400, 'y' => 300, 'w' => 400, 'h' => 300], CropRect::of(50, 50, 50, 50)->pixels(800, 600));
        $this->assertSame(['x' => 0, 'y' => 0, 'w' => 800, 'h' => 600], CropRect::of(0, 0, 100, 100)->pixels(800, 600));
        $tiny = CropRect::of(99, 99, 1, 1)->pixels(10, 10);
        $this->assertSame(['x' => 9, 'y' => 9, 'w' => 1, 'h' => 1], $tiny);
        $this->assertSame(['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1], CropRect::of(0, 0, 1, 1)->pixels(10, 10));
    }

    public function test_from_pixels_is_the_area_in_percent(): void
    {
        $this->assertEquals(CropRect::of(50, 25, 25, 50), CropRect::fromPixels(['x' => 400, 'y' => 100, 'w' => 200, 'h' => 200], 800, 400));
        $this->assertSame(['x' => 0.0, 'y' => 16.67, 'width' => 50.0, 'height' => 16.67], CropRect::fromPixels(['x' => 0, 'y' => 100, 'w' => 400, 'h' => 100], 800, 600)->toArray());
    }

    public function test_is_whole(): void
    {
        $this->assertTrue(CropRect::of(0, 0, 100, 100)->isWhole());
        $this->assertFalse(CropRect::of(0, 0, 100, 99.5)->isWhole());
    }
}
