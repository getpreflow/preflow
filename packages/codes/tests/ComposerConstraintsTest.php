<?php

declare(strict_types=1);

namespace Preflow\Codes\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "^0.37 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing codes with a release that lacks the APIs it calls: i18n 0.37 (translation scopes), data/core 0.38 (ensureTypeRegistry, Container::bound, RecordTableSync).
 * Folio is not required (it carries the codes admin and conflicts with codes before 0.40).
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_conflicts_with_releases_before_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame([
            'preflow/core' => '<0.38',
            'preflow/data' => '<0.38',
            'preflow/folio' => '<0.40',
            'preflow/i18n' => '<0.37',
        ], $composer['conflict'] ?? null);
        $this->assertSame('^0.37 || @dev', $composer['require']['preflow/i18n'] ?? null);
        foreach (['preflow/notify', 'preflow/view'] as $package) {
            $this->assertArrayHasKey($package, $composer['require'], $package);
        }
    }
}
