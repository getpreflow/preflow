<?php

declare(strict_types=1);

namespace Preflow\Media\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "^0.34 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing media with a release that lacks what it calls: data 0.42 (RecordTables, typed record tables).
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_conflicts_with_releases_before_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame(
            ['preflow/core' => '<0.34', 'preflow/data' => '<0.42', 'preflow/view' => '<0.34'],
            $composer['conflict'] ?? null,
        );
        // Each conflict matches its require's lower bound.
        foreach ($composer['conflict'] as $package => $below) {
            $this->assertSame('^' . substr($below, 1) . ' || @dev', $composer['require'][$package] ?? null, $package);
        }
        $this->assertArrayHasKey('preflow/i18n', $composer['suggest'] ?? []);
    }
}
