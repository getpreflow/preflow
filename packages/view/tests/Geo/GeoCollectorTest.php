<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Geo;

use PHPUnit\Framework\TestCase;
use Preflow\View\Geo\GeoCollector;

final class GeoCollectorTest extends TestCase
{
    public function test_not_capturing_records_nothing(): void
    {
        $geo = new GeoCollector();

        $this->assertFalse($geo->capturing());
        $geo->add('Page text');
        $geo->enter();
        $geo->add('Component text');

        $this->assertNull($geo->leave());
        $this->assertSame([], $geo->pageBlocks());
    }

    public function test_nested_frames_keep_their_own_blocks(): void
    {
        $geo = new GeoCollector();
        $geo->capture(true);

        $geo->enter();
        $geo->enter();
        $geo->add('B');
        $this->assertSame('B', $geo->leave());
        $geo->add('A');

        $this->assertSame('A', $geo->leave());
        $this->assertSame([], $geo->pageBlocks());
    }

    public function test_frame_without_block_gives_null(): void
    {
        $geo = new GeoCollector();
        $geo->capture(true);

        $geo->enter();

        $this->assertNull($geo->leave());
    }

    public function test_empty_block_gives_empty_string(): void
    {
        $geo = new GeoCollector();
        $geo->capture(true);

        $geo->enter();
        $geo->add('  ');

        $this->assertSame('', $geo->leave());
    }

    public function test_blocks_are_trimmed_and_joined_within_a_frame(): void
    {
        $geo = new GeoCollector();
        $geo->capture(true);

        $geo->enter();
        $geo->add("\n  ## Title\n");
        $geo->add('Text');

        $this->assertSame("## Title\n\nText", $geo->leave());
    }

    public function test_blocks_outside_frames_are_page_blocks(): void
    {
        $geo = new GeoCollector();
        $geo->capture(true);

        $geo->add(' First ');
        $geo->enter();
        $geo->add('Inside');
        $geo->leave();
        $geo->add('Second');

        $this->assertSame(['First', 'Second'], $geo->pageBlocks());
    }

    public function test_leave_without_open_frame_gives_null(): void
    {
        $geo = new GeoCollector();
        $geo->capture(true);

        $this->assertNull($geo->leave());
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

    public function test_reset_clears_collected_state_but_keeps_capture_flag(): void
    {
        $geo = new GeoCollector();
        $geo->capture(true);
        $geo->add('Page');
        $geo->placeholder('Block');
        $geo->enter();

        $geo->reset();

        $this->assertTrue($geo->capturing());
        $this->assertSame([], $geo->pageBlocks());
        $this->assertSame('', $geo->block(0));
        $this->assertNull($geo->leave());
        $this->assertSame('<preflow-geo data-geo="0"></preflow-geo>', $geo->placeholder('Again'));
    }
}
