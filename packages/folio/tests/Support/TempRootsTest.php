<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Support;

use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\TestCase;

final class TempRootsTest extends TestCase
{
    public function test_a_directory_tree_is_removed_with_its_links_left_unfollowed(): void
    {
        $outside = TempRoots::directory('temproots_outside_');
        file_put_contents($outside . '/keep.txt', 'x');

        $root = TempRoots::directory('temproots_tree_');
        mkdir($root . '/a/b', 0777, true);
        file_put_contents($root . '/a/b/f.txt', 'x');
        symlink($outside, $root . '/link');

        TempRoots::remove($root);

        $this->assertDirectoryDoesNotExist($root);
        $this->assertFileExists($outside . '/keep.txt', 'a link in the tree is removed, not followed');
    }

    public function test_clean_removes_every_registered_path_and_a_file_too(): void
    {
        $dir = TempRoots::directory('temproots_clean_');
        $file = TempRoots::track((string) tempnam(sys_get_temp_dir(), 'temproots_file_'));
        $unused = TempRoots::path('temproots_unused_');

        TempRoots::clean();

        $this->assertDirectoryDoesNotExist($dir);
        $this->assertFileDoesNotExist($file);
        $this->assertFileDoesNotExist($unused);
    }

    /**
     * A forked child inherits the registered roots and the shutdown sweep; it must leave the parent's roots alone.
     * The child ends with exit(0) on purpose: the sweep running at its shutdown is what is under test (a SIGKILL
     * would skip it). PHPUnit's own shutdown handling in the child does not print or report anything after exit.
     */
    #[RequiresFunction('pcntl_fork')]
    public function test_a_forked_child_leaves_the_parents_roots_alone_and_removes_its_own(): void
    {
        $mine = TempRoots::directory('temproots_parent_');
        $report = TempRoots::path('temproots_report_');

        $pid = pcntl_fork();
        if ($pid === 0) {
            $own = TempRoots::directory('temproots_child_');
            file_put_contents($report, $own);
            exit(0);
        }
        pcntl_waitpid($pid, $status);

        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertDirectoryExists($mine, 'the child did not remove the parent\'s root');
        $this->assertDirectoryExists(session_save_path(), 'nor the parent\'s session folder');
        $this->assertDirectoryDoesNotExist((string) file_get_contents($report), 'the child removed the root it made itself');
    }

    /** Folio tests make their temp roots with TempRoots, so each is removed when its test ends. */
    public function test_no_folio_test_makes_a_temp_root_by_hand(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__), \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || $file->getFilename() === 'TempRoots.php' || $file->getFilename() === basename(__FILE__)) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match('/sys_get_temp_dir\(\)\s*\.\s*([\'\"])[^\'\"]*\1\s*\.\s*(?:bin2hex|uniqid)|(?<!TempRoots::track\(\(string\) )tempnam\(\s*sys_get_temp_dir|mkdir\(\s*sys_get_temp_dir/', $source) === 1) {
                $offenders[] = substr($file->getPathname(), strlen(dirname(__DIR__)) + 1);
            }
        }

        $this->assertSame([], $offenders, 'use TempRoots::path() / directory() / track() for temp roots');
    }
}
