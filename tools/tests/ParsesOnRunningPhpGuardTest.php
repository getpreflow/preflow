<?php

declare(strict_types=1);

namespace Preflow\Tools\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Every PHP file of the repository parses on the PHP that runs this test. CI runs the tools tests
 * on the lowest supported PHP (the floor in composer.json) as well as the newest, so a file that
 * uses newer syntax (for example the pipe operator `|>`, PHP 8.5) fails here with its file and
 * line, instead of only when a suite happens to load it.
 *
 * Scanned: every .php file under the repository except vendor, node_modules, .git, .worktrees
 * and .superpowers (hidden folders are skipped as a whole).
 */
final class ParsesOnRunningPhpGuardTest extends TestCase
{
    private const array SKIPPED = ['vendor', 'node_modules', '.git', '.worktrees', '.superpowers'];

    public function test_every_php_file_parses_on_the_running_php(): void
    {
        $root = dirname(__DIR__, 2);
        $failures = [];
        $count = 0;

        foreach ($this->files($root) as $file) {
            $count++;
            try {
                token_get_all((string) file_get_contents($file), TOKEN_PARSE);
            } catch (\ParseError $e) {
                $failures[] = substr($file, strlen($root) + 1) . ':' . $e->getLine() . ': ' . $e->getMessage();
            }
        }

        $this->assertGreaterThan(0, $count, 'No PHP files found: the guard scans nothing.');
        $this->assertSame([], $failures, 'These files do not parse on PHP ' . PHP_VERSION . ":\n" . implode("\n", $failures));
    }

    /** @return list<string> */
    private function files(string $root): array
    {
        $filter = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $f): bool => !$f->isDir() || !in_array($f->getFilename(), self::SKIPPED, true),
        );

        $files = [];
        foreach (new \RecursiveIteratorIterator($filter) as $f) {
            if ($f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
