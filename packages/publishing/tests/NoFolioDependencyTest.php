<?php

declare(strict_types=1);

namespace Preflow\Publishing\Tests;

use PHPUnit\Framework\TestCase;

/** preflow/publishing runs without Folio: Folio binds its rules into it, not the other way round. */
final class NoFolioDependencyTest extends TestCase
{
    public function test_the_source_names_no_folio_class(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__) . '/src', \FilesystemIterator::SKIP_DOTS));
        $checked = 0;
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $checked++;
            $this->assertStringNotContainsString('Preflow\\Folio', (string) file_get_contents($file->getPathname()), $file->getPathname());
        }
        $this->assertGreaterThan(0, $checked);
    }

    public function test_composer_requires_core_data_and_validation_only(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame(['php', 'preflow/core', 'preflow/data', 'preflow/validation'], array_keys($composer['require']));
        $this->assertArrayNotHasKey('preflow/folio', $composer['suggest'] ?? []);
    }
}
