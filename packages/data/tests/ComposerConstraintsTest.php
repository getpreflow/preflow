<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "^0.52 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing data with a validation release that lacks the texts it raises
 * (validation.unique, shipped in validation 0.42; Slug::clean() for Slugs, in 0.52), or with a core release before 0.58, which lacks
 * Config\ParsedJsonFiles (TypeRegistry reads model files through it), or with a folio release before 0.67, which still reads the
 * TypeDefinition's matrixable and views that 0.67 dropped, or with a publishing release before 0.72, whose shadow
 * tables mark translatable unique fields as indexed (data 0.72 warns about those on every migrate).
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_conflicts_with_validation_before_its_unique_text(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame(['preflow/core' => '<0.58', 'preflow/validation' => '<0.52', 'preflow/folio' => '<0.67', 'preflow/publishing' => '<0.72'], $composer['conflict'] ?? null);
        $this->assertSame('^0.58 || @dev', $composer['require']['preflow/core'] ?? null);
        $this->assertSame('^0.52 || @dev', $composer['require']['preflow/validation'] ?? null);
    }
}
