<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Assets;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Assets\ImageWidths;

final class ImageWidthsTest extends TestCase
{
    private function widths(?array $folio = null): ImageWidths
    {
        return ImageWidths::fromConfig([
            'card' => ['widths' => [480, 960], 'step' => 160],
            'cover' => ['widths' => [260, 520]],
        ], $folio);
    }

    public function test_step_adds_widths_between_the_smallest_and_largest(): void
    {
        $this->assertSame([480, 640, 800, 960], $this->widths()->forPreset('card'));
        $this->assertSame([260, 520], $this->widths()->forPreset('cover'));
    }

    public function test_eager_widths_are_the_listed_ones(): void
    {
        $this->assertSame([260, 480, 520, 960], $this->widths()->eager());
        $this->assertSame([300, 600], $this->widths([300, 600])->eager()); // folio.assets.widths wins, as before
    }

    public function test_allowed_is_the_union_of_everything_a_preset_can_ask_for(): void
    {
        $this->assertSame([260, 480, 520, 640, 800, 960], $this->widths()->allowed());
    }

    public function test_srcset_widths_never_upscale_and_include_a_narrower_original(): void
    {
        $this->assertSame([480, 640, 800, 960], $this->widths()->srcsetWidths('card', 2000));
        $this->assertSame([480, 640, 700], $this->widths()->srcsetWidths('card', 700));
        $this->assertSame([300], $this->widths()->srcsetWidths('card', 300)); // smaller than every width: just the original
    }

    public function test_unknown_or_missing_preset_uses_the_eager_widths(): void
    {
        $this->assertSame([260, 480, 520, 960], $this->widths()->forPreset(null));
        $this->assertSame([260, 480, 520, 960], $this->widths()->forPreset('nope'));
    }

    public function test_defaults_without_any_config(): void
    {
        $this->assertSame([480, 768, 1024, 1600], ImageWidths::fromConfig([], null)->eager());
    }
}
