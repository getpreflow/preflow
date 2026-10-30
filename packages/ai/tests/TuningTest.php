<?php

declare(strict_types=1);

namespace Preflow\Ai\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Ai\Tuning;

final class TuningTest extends TestCase
{
    public function test_with_appends_trimmed_non_empty(): void
    {
        $t = (new Tuning(['A']))->with(' B ', '', '  ');

        $this->assertSame(['A', 'B'], $t->instructions);
    }

    public function test_merge_appends_and_later_template_wins(): void
    {
        $merged = (new Tuning(['A'], 'T1'))->merge(new Tuning(['B'], 'T2'));
        $this->assertSame(['A', 'B'], $merged->instructions);
        $this->assertSame('T2', $merged->template);

        $kept = (new Tuning(['A'], 'T1'))->merge(new Tuning(['B']));
        $this->assertSame('T1', $kept->template);
    }

    public function test_system_joins_and_appends_template(): void
    {
        $this->assertSame("A\n\nB\n\nAnswer template:\nT", (new Tuning(['A', 'B'], 'T'))->system());
        $this->assertSame('A', (new Tuning(['A']))->system());
        $this->assertSame('', (new Tuning())->system());
    }

    public function test_is_empty(): void
    {
        $this->assertTrue((new Tuning())->isEmpty());
        $this->assertFalse((new Tuning(['A']))->isEmpty());
        $this->assertFalse((new Tuning([], 'T'))->isEmpty());
    }
}
