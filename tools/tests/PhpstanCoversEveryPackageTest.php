<?php

declare(strict_types=1);

namespace Preflow\Tools\Tests;

use PHPUnit\Framework\TestCase;

/**
 * PHPStan paths take no globs, so phpstan.neon lists every packages/{pkg}/src. This guard keeps
 * that list equal to the directories on disk: a new package cannot escape analysis silently, and
 * a removed package cannot leave a dead path that makes PHPStan fail.
 */
final class PhpstanCoversEveryPackageTest extends TestCase
{
    public function test_phpstan_paths_are_every_package_src_directory(): void
    {
        $root = dirname(__DIR__, 2);
        $neon = (string) file_get_contents($root . '/phpstan.neon');

        $listed = [];
        if (preg_match('/^    paths:\n((?:        - .+\n)+)/m', $neon, $block) === 1) {
            preg_match_all('/^        - (\S+)$/m', $block[1], $m);
            $listed = $m[1];
        }
        $this->assertNotSame([], $listed, 'phpstan.neon has no parameters.paths list');

        $onDisk = array_map(
            static fn (string $dir): string => substr($dir, strlen($root) + 1),
            glob($root . '/packages/*/src', GLOB_ONLYDIR) ?: [],
        );

        $missing = array_values(array_diff($onDisk, $listed));
        $dead = array_values(array_diff($listed, $onDisk));
        sort($missing);
        sort($dead);

        $this->assertSame([], $missing, "Add these to phpstan.neon paths:\n" . implode("\n", $missing));
        $this->assertSame([], $dead, "These phpstan.neon paths do not exist:\n" . implode("\n", $dead));
    }
}
