<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Image;

use PHPUnit\Framework\TestCase;
use Preflow\Media\CropRect;
use Preflow\Media\Image\Geometry;

final class GeometryTest extends TestCase
{
    public function test_focal_box_is_centred_on_the_focal_point_and_clamped(): void
    {
        $this->assertSame(['x' => 200, 'y' => 0, 'w' => 600, 'h' => 600], Geometry::focalBox(800, 600, 1.0, 100, 100));
        $this->assertSame(['x' => 100, 'y' => 0, 'w' => 600, 'h' => 600], Geometry::focalBox(800, 600, 1.0, 50, 50));
        $this->assertSame(['x' => 0, 'y' => 0, 'w' => 800, 'h' => 267], Geometry::focalBox(800, 600, 3.0, 50, 0));
    }

    public function test_fit_ratio_centres_in_the_region(): void
    {
        $this->assertSame(['x' => 100, 'y' => 50, 'w' => 200, 'h' => 200], Geometry::fitRatio(['x' => 100, 'y' => 50, 'w' => 200, 'h' => 200], 1.0));
        $this->assertSame(['x' => 150, 'y' => 0, 'w' => 100, 'h' => 100], Geometry::fitRatio(['x' => 100, 'y' => 0, 'w' => 200, 'h' => 100], 1.0));
        $this->assertSame(['x' => 0, 'y' => 25, 'w' => 200, 'h' => 50], Geometry::fitRatio(['x' => 0, 'y' => 0, 'w' => 200, 'h' => 100], 4.0));
    }

    public function test_contain_size_fits_the_box_and_never_upscales(): void
    {
        $this->assertSame([400, 300], Geometry::containSize(800, 600, 400, 400));
        $this->assertSame([100, 50], Geometry::containSize(100, 50, 400, 400));
    }

    public function test_focal_after_keeps_the_same_picture_point(): void
    {
        $this->assertSame([100, 100], Geometry::focalAfter(50, 50, null, CropRect::of(0, 0, 50, 50)));
        $this->assertSame([50, 50], Geometry::focalAfter(50, 50, null, null));
        // (50 %, 50 %) of a crop at 0..50 is the full-image point 25; back to the full image it is 25.
        $this->assertSame([25, 25], Geometry::focalAfter(50, 50, CropRect::of(0, 0, 50, 50), null));
        $this->assertSame([0, 100], Geometry::focalAfter(0, 100, CropRect::of(10, 10, 80, 80), CropRect::of(10, 10, 80, 80)));
    }

    public function test_a_point_turns_with_the_image(): void
    {
        $this->assertSame([80, 30], Geometry::pointRotated(30, 20, 1));
        $this->assertSame([70, 80], Geometry::pointRotated(30, 20, 2));
        $this->assertSame([20, 70], Geometry::pointRotated(30, 20, -1));
        $this->assertSame([30, 20], Geometry::pointRotated(30, 20, 4));
    }

    public function test_a_point_is_mirrored_with_the_image(): void
    {
        $this->assertSame([70, 20], Geometry::pointMirrored(30, 20, true));
        $this->assertSame([30, 80], Geometry::pointMirrored(30, 20, false));
    }
}
