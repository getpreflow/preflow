<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Support;

use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\TestCase;

final class TempRootsTest extends TestCase
{
    /** A forked child inherits the registered roots and the shutdown sweep; it must leave the parent's roots alone. */
    #[RequiresFunction('pcntl_fork')]
    public function test_a_forked_child_leaves_the_parents_roots_alone_and_removes_its_own(): void
    {
        $mine = TempRoots::directory('temproots_parent_');
        $report = TempRoots::path('temproots_report_');

        $pid = pcntl_fork();
        if ($pid === 0) {
            $own = TempRoots::directory('temproots_child_');
            file_put_contents($report, $own);
            exit(0); // the shutdown sweep runs here
        }
        pcntl_waitpid($pid, $status);

        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertDirectoryExists($mine, 'the child did not remove the parent\'s root');
        $this->assertDirectoryExists(session_save_path(), 'nor the parent\'s session folder');
        $this->assertDirectoryDoesNotExist((string) file_get_contents($report), 'the child removed the root it made itself');
    }
}
