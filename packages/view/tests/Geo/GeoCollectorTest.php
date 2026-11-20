<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Geo;

use PHPUnit\Framework\TestCase;
use Preflow\View\Geo\GeoCapture;
use Preflow\View\Geo\GeoCollector;

final class GeoCollectorTest extends TestCase
{
    /** What component() gives for a component that adds $blocks and prints '<p>html</p>'. */
    private static function component(GeoCollector $geo, string ...$blocks): string
    {
        return $geo->component(static function () use ($geo, $blocks): string {
            foreach ($blocks as $block) {
                $geo->add($block);
            }

            return '<p>html</p>';
        });
    }

    public function test_not_capturing_records_nothing(): void
    {
        $geo = new GeoCollector();

        $this->assertFalse($geo->capturing());
        $geo->add('Page text');

        $this->assertSame('<p>html</p>', self::component($geo, 'Component text'));
        $this->assertSame([], $geo->pageBlocks());
        $this->assertSame('', $geo->block(0));
    }

    public function test_nested_components_keep_their_own_blocks(): void
    {
        $geo = new GeoCollector();
        $geo->capture(true);

        $outer = $geo->component(function () use ($geo): string {
            $inner = self::component($geo, 'B');
            $geo->add('A');

            return $inner;
        });

        $this->assertSame('<preflow-geo data-geo="1"></preflow-geo>', $outer);
        $this->assertSame('B', $geo->block(0));
        $this->assertSame('A', $geo->block(1));
        $this->assertSame([], $geo->pageBlocks());
    }

    public function test_component_without_block_keeps_its_html(): void
    {
        $geo = new GeoCollector();
        $geo->capture(true);

        $this->assertSame('<p>html</p>', self::component($geo));
    }

    public function test_empty_block_gives_an_empty_block(): void
    {
        $geo = new GeoCollector();
        $geo->capture(true);

        $this->assertSame('<preflow-geo data-geo="0"></preflow-geo>', self::component($geo, '  '));
        $this->assertSame('', $geo->block(0));
    }

    public function test_blocks_are_trimmed_and_joined_within_a_component(): void
    {
        $geo = new GeoCollector();
        $geo->capture(true);

        self::component($geo, "\n  ## Title\n", 'Text');

        $this->assertSame("## Title\n\nText", $geo->block(0));
    }

    public function test_blocks_outside_components_are_page_blocks(): void
    {
        $geo = new GeoCollector();
        $geo->capture(true);

        $geo->add(' First ');
        self::component($geo, 'Inside');
        $geo->add('Second');

        $this->assertSame(['First', 'Second'], $geo->pageBlocks());
    }

    public function test_a_component_that_throws_closes_its_block(): void
    {
        $geo = new GeoCollector();
        $geo->capture(true);

        try {
            $geo->component(static function () use ($geo): string {
                $geo->add('Lost');
                throw new \RuntimeException('render failed');
            });
        } catch (\RuntimeException) {
        }
        $geo->add('Page');

        $this->assertSame(['Page'], $geo->pageBlocks(), 'the block was closed: what follows is page level again');
    }

    public function test_placeholder_round_trip(): void
    {
        $geo = new GeoCollector();

        $first = $geo->placeholder('## One');
        $second = $geo->placeholder('## Two');

        $this->assertSame('<preflow-geo data-geo="0"></preflow-geo>', $first);
        $this->assertSame('<preflow-geo data-geo="1"></preflow-geo>', $second);
        $this->assertSame('## One', $geo->block(0));
        $this->assertSame('## Two', $geo->block(1));
        $this->assertSame('', $geo->block(7));
    }

    public function test_capture_into_hands_the_blocks_to_whoever_asked_and_restores_the_own_capture(): void
    {
        $geo = new GeoCollector();
        $capture = new GeoCapture();

        $geo->captureInto($capture, static function () use ($geo): void {
            $geo->add('Page');
        });

        $this->assertSame(['Page'], $capture->pageBlocks());
        $this->assertFalse($geo->capturing(), 'outside every render the own capture is back');
        $geo->add('Later');
        $this->assertSame(['Page'], $capture->pageBlocks(), 'nothing later reaches the capture');

        // Also when the render throws.
        $failed = new GeoCapture();
        try {
            $geo->captureInto($failed, static fn () => throw new \RuntimeException('render failed'));
        } catch (\RuntimeException) {
        }
        $this->assertFalse($geo->capturing());
    }

    public function test_capture_into_takes_over_what_was_recorded_before(): void
    {
        $geo = new GeoCollector();
        $geo->capture(true);
        $geo->add('Earlier');
        $html = self::component($geo, 'Card');
        $capture = new GeoCapture();

        $geo->captureInto($capture, static fn () => $geo->add('During'));

        $this->assertSame(['Earlier', 'During'], $capture->pageBlocks());
        $this->assertSame('Card', $capture->block(0), 'the placeholder already printed still resolves');
        $this->assertSame('<preflow-geo data-geo="0"></preflow-geo>', $html);

        $used = new GeoCapture();
        $used->add('Own');
        $this->expectException(\LogicException::class);
        $geo->captureInto($used, static fn () => null);
    }
}
