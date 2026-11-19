<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Since 0.80: ai is only suggested (machine translation appears only when it is installed, Machine\AiPackage); its conflict
 * stays, so an installed ai is still 0.78 or newer.
 * Since 0.78: ai 0.78 (Limits\AiLimits and AiLimitReached, which JobRunner and TranslationWorker use); core 0.78 (Http\LocaleUrls).
 * Since 0.77: ai 0.77 (Settings\ProjectTuning, the project-level AI tuning that TranslationSettings reads).
 *
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

        $this->assertSame(['preflow/ai' => '<0.78', 'preflow/core' => '<0.78'], $composer['conflict'] ?? null);
        $this->assertSame('^0.78 || @dev', $composer['require']['preflow/core'] ?? null);
        $this->assertArrayNotHasKey('preflow/ai', $composer['require']);
        $this->assertArrayHasKey('preflow/ai', $composer['suggest'] ?? []);
        $this->assertSame('^0.78 || @dev', $composer['require-dev']['preflow/ai'] ?? null, 'its own tests use it');
    }
}
