<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests\Machine;

use PHPUnit\Framework\TestCase;
use Preflow\I18n\Machine\MachineMarks;

final class MachineMarksTest extends TestCase
{
    public function test_unchecked_until_text_changes(): void
    {
        $marks = MachineMarks::mark([], 'de', 'title', 'Hallo Welt');

        $this->assertTrue(MachineMarks::isUnchecked($marks, 'de', 'title', 'Hallo Welt'));
        $this->assertTrue(MachineMarks::isUnchecked($marks, 'de', 'title', "  Hallo Welt\n"), 'trim is part of the algorithm');
        $this->assertFalse(MachineMarks::isUnchecked($marks, 'de', 'title', 'Hallo Welt!'));
        $this->assertFalse(MachineMarks::isUnchecked($marks, 'de', 'other', 'Hallo Welt'));
        $this->assertFalse(MachineMarks::isUnchecked($marks, 'fr', 'title', 'Hallo Welt'));
    }

    public function test_fingerprint_algorithm_and_null(): void
    {
        $this->assertSame(substr(hash('sha256', 'abc'), 0, 16), MachineMarks::fingerprint(' abc '));
        $this->assertSame(MachineMarks::fingerprint(''), MachineMarks::fingerprint(null));
    }

    public function test_clear_by_prefix(): void
    {
        $marks = [];
        foreach (['steps.0.title', 'steps.0', 'steps.01', 'intro'] as $path) {
            $marks = MachineMarks::mark($marks, 'de', $path, 'x');
        }
        $marks = MachineMarks::mark($marks, 'fr', 'intro', 'x');

        $cleared = MachineMarks::clear($marks, 'de', 'steps.0');
        $this->assertSame(['steps.01', 'intro'], array_keys($cleared['de']));
        $this->assertArrayHasKey('fr', $cleared);

        $this->assertArrayNotHasKey('de', MachineMarks::clear($marks, 'de'));
    }

    public function test_prune_drops_changed_and_vanished(): void
    {
        $marks = MachineMarks::mark(MachineMarks::mark(MachineMarks::mark([], 'de', 'a', 'A'), 'de', 'b', 'B'), 'de', 'c', 'C');
        $now = ['a' => 'A', 'b' => 'B edited'];

        $pruned = MachineMarks::prune($marks, static fn (string $l, string $p): ?string => $now[$p] ?? null);

        $this->assertSame(['a'], array_keys($pruned['de']));
    }
}
