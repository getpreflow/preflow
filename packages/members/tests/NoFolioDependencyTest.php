<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\TestCase;

/** The members core runs without Folio and rbac: its code names neither, and Composer only suggests them. */
final class NoFolioDependencyTest extends TestCase
{
    public function test_the_source_uses_no_folio_or_rbac_class(): void
    {
        $hits = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__) . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            foreach (file($file->getPathname()) ?: [] as $n => $line) {
                if (preg_match('/Preflow\\\\{1,2}(Folio|Rbac)\b/', $line) === 1) {
                    $hits[] = $file->getFilename() . ':' . ($n + 1) . ': ' . trim($line);
                }
            }
        }

        $this->assertSame([], $hits);
    }

    public function test_composer_suggests_folio_and_rbac_instead_of_requiring_them(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        foreach (['preflow/folio', 'preflow/rbac'] as $package) {
            $this->assertArrayNotHasKey($package, $composer['require'], $package);
            $this->assertArrayHasKey($package, $composer['suggest'] ?? [], $package);
        }
    }
}
