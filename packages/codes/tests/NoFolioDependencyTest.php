<?php

declare(strict_types=1);

namespace Preflow\Codes\Tests;

use PHPUnit\Framework\TestCase;

/** preflow/codes runs without Folio: Folio carries the codes admin (Preflow\Folio\Codes), not the other way round. */
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

    public function test_composer_does_not_require_or_declare_folio(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertArrayNotHasKey('preflow/folio', $composer['require']);
        $this->assertArrayNotHasKey('preflow/folio', $composer['suggest'] ?? []);
        // Only a guard: Folio carries the admin and must be 0.40 or later; codes never needs it.
        $this->assertSame('<0.40', $composer['conflict']['preflow/folio'] ?? null);
        $this->assertArrayNotHasKey('folio', $composer['extra'] ?? []);
        $this->assertArrayNotHasKey('codes', $composer['extra']['preflow']['translations'] ?? []);
    }
}
