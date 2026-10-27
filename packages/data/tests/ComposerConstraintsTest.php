<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "^0.42 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing data with a validation release that lacks the texts it raises
 * (validation.unique, shipped in validation 0.42).
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_conflicts_with_validation_before_its_unique_text(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame(['preflow/validation' => '<0.42'], $composer['conflict'] ?? null);
        $this->assertSame('^0.42 || @dev', $composer['require']['preflow/validation'] ?? null);
    }
}
