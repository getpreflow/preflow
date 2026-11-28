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
 * Scanned: every .php file, and every extensionless file whose first line is a php
 * shebang, under the repository root. Skipped: the folders named in SKIPPED (vendor,
 * node_modules, .git, .worktrees, .superpowers) wherever they occur; every other folder,
 * hidden ones such as .github included, is scanned.
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

    public function test_a_symlinked_folder_is_not_read_as_a_file(): void
    {
        $root = sys_get_temp_dir() . '/preflow-guard-' . bin2hex(random_bytes(4));
        mkdir($root . '/real', 0777, true);
        file_put_contents($root . '/real/a.php', "<?php\n");
        symlink($root . '/real', $root . '/link');

        try {
            $notices = [];
            set_error_handler(static function (int $no, string $msg) use (&$notices): bool {
                $notices[] = $msg;

                return true;
            });
            try {
                $files = (new \ReflectionMethod($this, 'files'))->invoke($this, $root);
            } finally {
                restore_error_handler();
            }
            $this->assertSame([], $notices);
            $this->assertSame([$root . '/real/a.php'], $files);
        } finally {
            @unlink($root . '/link');
            @unlink($root . '/real/a.php');
            @rmdir($root . '/real');
            @rmdir($root);
        }
    }

    /** An extensionless script (bin/…, preflow) that starts with `#!… php`. */
    private static function hasPhpShebang(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $first = (string) fgets($handle, 200);
        fclose($handle);

        return preg_match('/^#!.*\bphp/', $first) === 1;
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
            if (!$f->isFile()) {
                continue;
            }
            if ($f->getExtension() === 'php' || ($f->getExtension() === '' && self::hasPhpShebang($f->getPathname()))) {
                $files[] = $f->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
