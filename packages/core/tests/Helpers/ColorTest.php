<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Helpers;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Helpers\Color;

final class ColorTest extends TestCase
{
    public function test_hex_to_rgb(): void
    {
        $this->assertSame([255, 158, 27], Color::hexToRgb('#FF9E1B'));
    }

    public function test_hex_to_rgb_shorthand(): void
    {
        $this->assertSame([255, 0, 0], Color::hexToRgb('#F00'));
    }

    public function test_hex_to_rgb_without_hash(): void
    {
        $this->assertSame([255, 158, 27], Color::hexToRgb('FF9E1B'));
    }

    public function test_rgb_to_hex(): void
    {
        $this->assertSame('#ff9e1b', Color::rgbToHex(255, 158, 27));
    }

    public function test_lighten(): void
    {
        $result = Color::lighten('#000000', 0.5);
        $this->assertSame('#808080', $result);
    }

    public function test_darken(): void
    {
        $result = Color::darken('#ffffff', 0.5);
        $this->assertSame('#808080', $result);
    }

    public function test_luminance_black(): void
    {
        $this->assertEqualsWithDelta(0.0, Color::luminance('#000000'), 0.001);
    }

    public function test_luminance_white(): void
    {
        $this->assertEqualsWithDelta(1.0, Color::luminance('#ffffff'), 0.001);
    }

    public function test_contrast_ratio_black_white(): void
    {
        $ratio = Color::contrastRatio('#000000', '#ffffff');
        $this->assertEqualsWithDelta(21.0, $ratio, 0.1);
    }

    public function test_adjust_for_contrast_already_sufficient(): void
    {
        $result = Color::adjustForContrast('#000000', '#ffffff', 4.5);
        $this->assertSame('#000000', $result);
    }

    public function test_adjust_for_contrast_needs_adjustment(): void
    {
        $result = Color::adjustForContrast('#cccccc', '#ffffff', 4.5);
        $ratio = Color::contrastRatio($result, '#ffffff');
        $this->assertGreaterThanOrEqual(4.5, $ratio);
    }
}
