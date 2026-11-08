<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Support;

/**
 * Array-config apps (Application::create([...])) take the working directory as base path: tests
 * that boot one work in a temporary directory, not the repository root (no stray storage/ there).
 */
trait TempWorkingDirectory
{
    private string $previousCwd = '';
    private string $tempCwd = '';

    private function enterTempDirectory(): string
    {
        $this->previousCwd = getcwd() ?: '.';
        $this->tempCwd = sys_get_temp_dir() . '/folio_boot_' . bin2hex(random_bytes(5));
        mkdir($this->tempCwd, 0777, true);
        chdir($this->tempCwd);

        return $this->tempCwd;
    }

    private function leaveTempDirectory(): void
    {
        if ($this->tempCwd === '') {
            return;
        }
        chdir($this->previousCwd);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tempCwd, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->tempCwd);
        $this->tempCwd = '';
    }
}
