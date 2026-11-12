<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "^0.58 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing i18n with a release that lacks what it calls: core 0.67
 * (Composer\InstalledPackages keeps composer extra whole, which TranslationRegistry::fromInstalled() reads; since 0.58 InstalledPackages) and ai 0.47
 * (machine translation).
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_conflicts_with_releases_before_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame(['preflow/ai' => '<0.47', 'preflow/core' => '<0.67'], $composer['conflict'] ?? null);
        $this->assertSame('^0.67 || @dev', $composer['require']['preflow/core'] ?? null);
        $this->assertSame('^0.47 || @dev', $composer['require']['preflow/ai'] ?? null);
    }
}
