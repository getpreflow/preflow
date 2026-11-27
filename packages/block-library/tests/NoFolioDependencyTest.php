<?php

declare(strict_types=1);

namespace Preflow\BlockLibrary\Tests;

use PHPUnit\Framework\TestCase;

/** preflow/block-library runs without Folio: Folio draws the columns' editor (the areas field), not the other way round. */
final class NoFolioDependencyTest extends TestCase
{
    public function test_no_folio_dependency(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__) . '/src', \FilesystemIterator::SKIP_DOTS));
        $checked = 0;
        foreach ($files as $file) {
            if (!in_array($file->getExtension(), ['php', 'twig', 'css'], true)) {
                continue;
            }
            $checked++;
            $this->assertStringNotContainsString('Preflow\\Folio', (string) file_get_contents($file->getPathname()), $file->getPathname());
        }
        $this->assertGreaterThan(0, $checked);

        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);
        $this->assertSame(['php', 'preflow/blocks', 'preflow/components', 'preflow/core', 'preflow/i18n', 'preflow/view'], array_keys($composer['require']));
        $this->assertArrayNotHasKey('preflow/folio', $composer['require-dev'] ?? []);
        $this->assertArrayNotHasKey('preflow/folio', $composer['suggest'] ?? []);
        $this->assertArrayNotHasKey('preflow/folio', $composer['conflict'] ?? []);
        $this->assertArrayNotHasKey('folio', $composer['extra'] ?? []);
    }
}
